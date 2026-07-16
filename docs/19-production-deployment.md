# 19. Production Deployment

How to run Treck (Laravel 11 API + Livewire dashboard) safely in production.
Reference configs live in [`/deploy`](../deploy).

## 19.1 Server requirements

| Component | Recommendation |
| --------- | -------------- |
| OS | Ubuntu 22.04 LTS (or similar) |
| PHP | 8.2 / 8.3 + FPM, extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `bcmath`, `curl`, `fileinfo`, `redis`, `zip`, `gd` |
| Web server | Nginx (TLS termination, reverse proxy to PHP-FPM) |
| Database | MySQL 8 (primary; add a read replica as load grows) |
| Cache / queue / status | Redis 7 |
| Process manager | Supervisor (queue workers / Horizon) |
| Scheduler | cron (`schedule:run` every minute) |
| Build toolchain | Composer 2, Node 20 (build assets, then not needed at runtime) |

**Sizing (starting point):** app node 2 vCPU / 4 GB; MySQL 2 vCPU / 8 GB;
Redis 1 GB. Scale app nodes and queue workers horizontally behind the load
balancer — the app is stateless (sessions/cache in Redis). Telemetry volume
grows with the fleet; watch queue depth and DB size (doc 03 partitioning +
retention).

## 19.2 SSL / TLS

- Terminate TLS at Nginx. Use **Let's Encrypt** (`certbot --nginx`) or a managed
  certificate; auto-renew.
- **Force HTTPS**: redirect `:80 → :443`, enable **HSTS**, TLS 1.2+ only, modern
  ciphers (see [`deploy/nginx.conf.example`](../deploy/nginx.conf.example)).
- Laravel side:
  - `APP_URL=https://treck.example.com`
  - `SESSION_SECURE_COOKIE=true`
  - Behind a load balancer, configure trusted proxies so Laravel sees HTTPS
    (Laravel 11: `->withMiddleware(fn ($m) => $m->trustProxies(at: '*'))` in
    `bootstrap/app.php`, or a specific CIDR).
- The desktop agent talks HTTPS only; optional certificate pinning (doc 17).

## 19.3 Queue configuration

Telemetry ingestion and rollups run on queues so requests stay fast.

- `QUEUE_CONNECTION=redis`; dedicated queues, e.g. `ingestion` (high volume) and
  `default` (rollups, exports, mail).
- Run workers under **Supervisor** (or **Horizon** for metrics + auto-balancing).
  See [`deploy/supervisor-treck-worker.conf.example`](../deploy/supervisor-treck-worker.conf.example).
- Worker flags: `--tries=3 --backoff=5 --max-time=3600 --timeout=60`. Ensure
  `config/queue.php` `retry_after` > longest job `timeout`.
- Ensure the `failed_jobs` table exists (`php artisan queue:failed-table` is part
  of the default migrations) and monitor it.
- **On every deploy** run `php artisan queue:restart` so workers pick up new code.
- If using Horizon: `php artisan horizon` under Supervisor; gate the
  `/horizon` dashboard with an admin gate.

## 19.4 Cron jobs

A **single** system cron entry drives Laravel's scheduler:

```cron
* * * * * cd /var/www/treck && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler (in `routes/console.php`) runs the app's periodic work:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('treck:reconcile-sessions')->everyMinute();          // offline detection (doc 14)
Schedule::command('treck:daily-rollup')->hourly();                     // keep today fresh (doc 18)
Schedule::command('treck:daily-rollup '.now()->subDay()->toDateString())
    ->dailyAt('00:30');                                                // finalize yesterday
Schedule::command('backup:run')->dailyAt('02:00');                     // DB backup (§19.5)
Schedule::command('backup:clean')->dailyAt('02:30');
Schedule::command('model:prune')->daily();                             // retention (doc 03)
```

Use `->withoutOverlapping()` on long tasks and consider
`->onOneServer()` when multiple app nodes share the schedule.

## 19.5 Database backup

- Use **spatie/laravel-backup**: `composer require spatie/laravel-backup`.
- Schedule `backup:run` daily + `backup:clean` (retention), storing **offsite**
  (S3 / object storage) — never only on the app server.
- Configure retention (e.g. keep 7 daily, 4 weekly, 3 monthly) and
  **health monitoring** (`backup:monitor`) with failure notifications.
- **Test restores** regularly — an untested backup is not a backup.
- Alternative/adjunct: managed automated backups + binlog point-in-time recovery
  from your DB provider.
- Encrypt backups at rest; restrict bucket access via IAM.

## 19.6 API security

- **HTTPS everywhere**; `APP_DEBUG=false`, `APP_ENV=production` (never leak stack
  traces).
- **Sanctum tokens** are hashed at rest and ability-scoped (`agent:report` for
  devices; role-derived for users). Revoke per device/user on loss.
- **Rotate the agent provisioning key** (`TRECK_AGENT_PROVISIONING_KEY`) and
  keep all secrets in the environment, never in the repo.
- **Validate everything** via Form Requests (already in place); authorize via
  Policies/permissions.
- **CORS** (`config/cors.php`) restricted to the dashboard/SPA origins.
- **Security headers** at Nginx (HSTS, `X-Content-Type-Options`,
  `X-Frame-Options`, referrer policy) — see the nginx example.
- Throttle authentication endpoints; lock out on repeated failures.
- Keep dependencies patched (`composer audit`, Dependabot).

## 19.7 Rate limiting

Named limiters are defined in `bootstrap/app.php` (doc 05):

- `agent` — generous, per-device (heartbeats): e.g. 120/min.
- `user` — per-user dashboard/API: e.g. 60/min.
- Add a strict `login` limiter (e.g. 5/min per email+IP) on auth routes.

Production tuning: size the `agent` limiter to
`ceil(60 / heartbeat_interval)` plus headroom for retries/batching; monitor
`429` rates and adjust. Rate limit at the edge (Nginx/LB/WAF) too for
defense in depth and to shed abusive traffic before PHP.

## 19.8 Monitoring

- **Health check**: Laravel 11 exposes `/up`; point an uptime monitor at it.
- **Errors**: integrate Sentry / Laravel Flare for exception tracking; ship logs
  to a central store (`LOG_CHANNEL=stack` with a cloud/syslog channel).
- **Queues**: Horizon dashboard (wait times, throughput, failed jobs); alert on
  queue depth and `failed_jobs` growth.
- **Scheduler**: alert if `schedule:run` stops (heartbeat/ping on key tasks via
  `->pingOnSuccess()` / a dead-man's-switch like healthchecks.io).
- **Infra**: CPU/memory/disk (telemetry tables grow — watch disk and partition
  pruning), MySQL slow-query log, Redis memory.
- **Telescope** for local/staging debugging only — do **not** enable in
  production (or gate it tightly).

## 19.9 Deployment checklist

**Provision (once)**
- [ ] PHP-FPM + extensions, MySQL 8, Redis, Nginx, Supervisor, cron installed
- [ ] TLS certificate issued + auto-renew; Nginx from `deploy/nginx.conf.example`
- [ ] DB + scoped user created; Redis secured (bind/`requirepass`)
- [ ] Supervisor worker program from `deploy/supervisor-treck-worker.conf.example`
- [ ] Cron entry: `* * * * * php artisan schedule:run`
- [ ] `spatie/laravel-backup` configured to offsite storage

**Each release**
- [ ] `git pull` / deploy artifact to release dir
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build`
- [ ] `.env` present & correct (`APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, DB/Redis, `SANCTUM_STATEFUL_DOMAINS`, `TRECK_*`, `TRECK_AGENT_PROVISIONING_KEY`)
- [ ] `php artisan key:generate` (first deploy only)
- [ ] `php artisan migrate --force`
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`
- [ ] `php artisan storage:link` (screenshots/exports)
- [ ] `php artisan queue:restart`; restart Supervisor/Horizon
- [ ] Reload PHP-FPM & Nginx
- [ ] Seed roles on first deploy: `php artisan db:seed --class=RolePermissionSeeder` (NOT DemoDataSeeder)

**Verify**
- [ ] `curl https://…/up` returns healthy
- [ ] Log in to the dashboard; cards/charts render
- [ ] Agent `register` → `login` → `activity` → `logout` round-trips (staging device)
- [ ] Agent offline queue drains: `POST /api/agent/events` accepts heartbeat/session events and duplicates are idempotent (see [`docs/24-windows-agent-build.md`](24-windows-agent-build.md))
- [ ] Windows agent installed as a service on a staging PC (`agent/deploy/install-service.ps1`); logs appear under `%ProgramData%\TreckAgent\logs`
- [ ] Queue workers processing; scheduler running (`schedule:list`)
- [ ] A backup has run and is retrievable
- [ ] Error tracking receiving events; uptime monitor green

**Security sign-off**
- [ ] `APP_DEBUG=false`, Telescope disabled, `/horizon` gated
- [ ] Secrets only in env; provisioning key rotated from any default
- [ ] HTTPS forced + HSTS; security headers present
- [ ] Rate limiters tuned; login throttle active

## 19.10 Rollback

Keep the previous release dir; roll back by repointing the `current` symlink and
`php artisan migrate:rollback` only if the release added reversible migrations.
Prefer expand/contract migrations so rollbacks don't lose data.
