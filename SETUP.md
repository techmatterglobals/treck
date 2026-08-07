# Treck — Setup Guide

End-to-end setup for the **Employee Productivity & PC Activity Monitoring
System** (Laravel 11 API + Livewire dashboard, with a .NET desktop agent).

For architecture and per-module detail, see [`docs/`](docs/) (start with
[`docs/README.md`](docs/README.md)). Implementation status is in
[`docs/23-requirements-review.md`](docs/23-requirements-review.md).

---

## 1. Requirements

| Tool | Version |
| ---- | ------- |
| PHP | 8.2 or 8.3 (+ extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `bcmath`, `curl`, `fileinfo`, `zip`; `redis` if using Redis) |
| Composer | 2.x |
| MySQL | 8.x |
| Node.js | 20 LTS (18+ ok) + npm |
| Redis | 7.x (recommended; optional in local/dev) |

Verify:

```bash
php -v && composer -V && node -v && mysql --version
```

---

## 2. Quick start (local)

```bash
# 1. Install PHP dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Create the database (MySQL), then edit .env DB_* to match
mysql -u root -p -e "CREATE DATABASE treck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Migrate + seed roles/admin (+ optional demo data)
php artisan migrate
php artisan db:seed            # RolePermissionSeeder + DemoDataSeeder (non-production)

# 5. Front-end assets
npm install
npm run build                  # or: npm run dev  (hot reload)

# 6. Storage symlink (screenshots / exports)
php artisan storage:link

# 7. Run
php artisan serve              # http://localhost:8000
```

Then in separate terminals (needed for telemetry processing + rollups):

```bash
php artisan queue:work         # process queued jobs
php artisan schedule:work      # run reconcile-sessions + daily-rollup on schedule
```

Or run everything together:

```bash
composer run dev               # serve + queue + logs (pail) + vite
```

### Default login
After `db:seed`: **`admin@treck.test` / `password`** → redirected to
`/dashboard`. **Change this password immediately** outside local dev.

---

## 3. Environment configuration (`.env`)

Key values (see [`docs/08-getting-started.md`](docs/08-getting-started.md) §8.3
and [`config/treck.php`](config/treck.php)):

```dotenv
APP_NAME="Treck"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=treck
DB_USERNAME=treck_user
DB_PASSWORD=secret

# Local defaults use the database driver; switch to redis when available:
CACHE_STORE=database          # or redis
SESSION_DRIVER=database       # or redis
QUEUE_CONNECTION=database     # or redis

SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8000,127.0.0.1,127.0.0.1:8000

# Desktop agent registration (REQUIRED for the agent to obtain a token)
TRECK_AGENT_PROVISIONING_KEY=change-me-to-a-strong-secret

# Domain tunables (idle threshold, work hours, retention) — see config/treck.php
TRECK_IDLE_THRESHOLD=300
TRECK_OFFLINE_GRACE=180
TRECK_WORKDAY_START=09:00
```

---

## 4. Seeding options

```bash
php artisan db:seed --class=RolePermissionSeeder   # roles, permissions, admin only
php artisan db:seed --class=DemoDataSeeder         # + 15 employees, computers, ~2 weeks
                                                    #   of activity, then runs the rollups
```

`DemoDataSeeder` is skipped automatically when `APP_ENV=production`.

---

## 5. Included packages (already in `composer.json`)

No extra install steps needed — `composer install` pulls:

- `laravel/sanctum` — API/device token auth
- `spatie/laravel-permission` — roles & permissions
- `livewire/livewire` — dashboard
- `maatwebsite/excel` — report Excel export
- `barryvdh/laravel-dompdf` — report PDF export
- `laravel/breeze` (dev) — optional full auth suite

### Optional: full authentication suite
The repo ships a minimal login. To add registration, password reset, email
verification, and profile management:

```bash
php artisan breeze:install livewire --dark
npm install && npm run build
```

> This regenerates auth scaffolding and may overwrite `routes/auth.php` and the
> layout components — review the diff and re-apply the module `require`s in
> `routes/web.php` if needed.

---

## 6. Scheduler & queues

The scheduler (`routes/console.php`) runs:

- `treck:reconcile-sessions` — every minute (marks stale computers offline).
- `treck:presence-sweep` — every minute (transitions quiet computers to Offline).
- `treck:daily-rollup` — hourly + a nightly finalize (attendance + productivity).
- `treck:prune-screenshots` — daily (enforces screenshot retention).
- `treck:prune-events` — daily (enforces raw `agent_events` retention,
  `TRECK_RAW_RETENTION` days; prevents unbounded table growth).

In production, a single cron entry drives it:

```cron
* * * * * cd /path/to/treck && php artisan schedule:run >> /dev/null 2>&1
```

Run queue workers under Supervisor/Horizon (see
[`deploy/supervisor-treck-worker.conf.example`](deploy/supervisor-treck-worker.conf.example)).

Verify:

```bash
php artisan schedule:list
php artisan treck:daily-rollup        # manual run for today
```

---

## 7. Windows desktop agent

Reference .NET 8 project in [`agent/`](agent/) (see
[`docs/17-windows-agent.md`](docs/17-windows-agent.md) for design and
[`docs/24-windows-agent-build.md`](docs/24-windows-agent-build.md) for the
milestone build/install runbook). Bootstrap flow:

1. Set `TRECK_AGENT_PROVISIONING_KEY` in `.env` and configure the agent's
   `appsettings.json` (`BaseUrl`, `ProvisioningKey`, `EmployeeCode`).
2. The agent calls `POST /api/agent/register` once to obtain a device token,
   then `login` → `activity` (every ~60s) → `logout`. Heartbeat and session
   events are queued locally (SQLite) and drained to
   `POST /api/agent/events` (idempotent), so nothing is lost during a network
   outage.

### Install as a Windows Service (production)

The agent runs as a console app for development (`dotnet run`) and as a Windows
Service in production. The default build is framework-dependent, so install the
**.NET 8 Desktop Runtime (x64)** on the target first
(`winget install Microsoft.DotNet.DesktopRuntime.8`) — the Desktop runtime is
required because session detection uses `Microsoft.Win32.SystemEvents`. Then,
from an **elevated PowerShell** on the target machine:

```powershell
cd agent\deploy
# 1. Per-deployment config (git-ignored; no secrets in source control):
Copy-Item ..\appsettings.Production.json.example ..\appsettings.Production.json
notepad ..\appsettings.Production.json     # BaseUrl, ProvisioningKey, EmployeeCode
# 2. Publish (framework-dependent) and install + start the service:
./install-service.ps1 -Publish             # Service "TreckAgent" (display: "Treck Agent")
# Air-gapped target with no runtime? Bundle it: ./install-service.ps1 -Publish -SelfContained
```

Verify / manage:

```powershell
Get-Service TreckAgent                      # Status: Running
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-*.jsonl" -Tail 20
Stop-Service TreckAgent ; Start-Service TreckAgent
```

Uninstall with `agent\deploy\uninstall-service.ps1` (add `-PurgeData` to also
remove the local identity, token, and offline queue). Full runbook and
end-to-end verification: [`agent/deploy/README.md`](agent/deploy/README.md) and
[`docs/24-windows-agent-build.md`](docs/24-windows-agent-build.md).

Quick server-side smoke test with curl:

```bash
KEY=change-me-to-a-strong-secret
# employee_code must exist — grab a real one after seeding demo data:
CODE=$(php artisan tinker --execute="echo App\Models\Employee::value('employee_code');")
curl -sX POST http://localhost:8000/api/agent/register -H 'Accept: application/json' \
  -d provisioning_key=$KEY -d device_uuid=TEST -d employee_code=$CODE \
  -d computer_name=TEST-PC -d os='Windows 11'
```

---

## 7a. Real-time presence dashboard (Phase 6)

An admin-only live dashboard at **`/presence`** ("Live Presence" in the nav)
shows every computer's current status (Active / Idle / Locked / Logged Out /
Offline) projected from the agent events ingested in M6. It reads a materialized
`computer_presence` table (never a history scan) and updates over websockets with
no polling. Full design: [`docs/25-realtime-presence.md`](docs/25-realtime-presence.md).

It works out of the box with `BROADCAST_CONNECTION=log` (correct, but only
refreshes on navigation). For live push updates, enable **Laravel Reverb**:

```bash
composer require laravel/reverb
npm install                        # pulls laravel-echo + pusher-js (already in package.json)
npm run build

# .env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=treck REVERB_APP_KEY=local-key REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http

php artisan reverb:start           # websocket server (run under Supervisor in prod)
```

The "missing heartbeat -> Offline" transition is produced by a scheduled sweep
(already wired in `routes/console.php`); ensure the scheduler is running:

```bash
php artisan schedule:work          # or the production cron entry (see §6)
php artisan treck:presence-sweep   # manual one-off
```

Tune the timeout with `TRECK_PRESENCE_OFFLINE_TIMEOUT` (seconds, default 180).

---

## 7b. Application usage dashboard (Phase 7)

An admin-only dashboard at **`/application-usage`** ("App Usage" in the nav)
reports which applications employees use and for how long: summary totals, top
applications, a daily timeline, per-employee and per-department breakdowns, and a
searchable recent-sessions table (filter by employee / computer / department /
application / date range). The computer details page (`/presence/computers/{id}`)
also shows the current application, window title, current app duration, recent app
history and today's top applications.

The Windows agent tracks the foreground application with WinEvent hooks (no
polling) and uploads **completed usage sessions** through the same offline queue
and `POST /api/agent/events` endpoint used for heartbeats — a new `app_usage`
event kind projected into the `application_usage` table (idempotent per session).
No configuration is required for the dashboard; agent tracking is toggled by the
`ApplicationTracking` section of the agent's `appsettings.json` (enabled by
default, with configurable ignore rules).

**Privacy:** only usage *metadata* (process, executable, sanitized window title,
timestamps, duration) is ever collected — never keystrokes, mouse input,
clipboard, screen contents, file contents, browser history or typed text. Full
design: [`docs/26-application-usage.md`](docs/26-application-usage.md).

---

## 7c. Screenshot module (Phase 8, opt-in)

An admin-only **Screenshots** dashboard (`/screenshots`, "Screenshots" in the
nav) shows a filterable, paginated grid of captures with a dedicated viewer
(prev/next, zoom, full-screen, download). The Windows agent captures the desktop
on a configurable policy (interval/jitter, active-only, per-monitor) and uploads
completed captures through the same offline queue used for events — a dedicated
multipart endpoint (`POST /api/agent/screenshots`) with duplicate detection by
image hash.

The feature is **disabled by default** on both sides. Enable and configure it:

```bash
# .env (server)
TRECK_SCREENSHOTS=true
TRECK_SCREENSHOT_DISK=local        # any non-public disk; use s3 for object storage
TRECK_SCREENSHOT_RETENTION=30      # days to keep (0 = keep forever)
TRECK_SCREENSHOT_URL_TTL=5         # signed view-URL lifetime (minutes)
```

Agent side: set `Screenshots.Enabled = true` (and interval/quality/ignore rules)
in the agent's `appsettings.json`.

**Storage & security:** image bytes are stored via Laravel Storage on a
**non-public** disk (never under `public/`) and are served only through
short-lived **signed** URLs behind an admin policy — a filesystem path is never
exposed. Only administrators may view or download screenshots. Retention is
enforced by `treck:prune-screenshots` (scheduled daily; deletes row + file).

**Privacy:** the module captures the visible desktop image and metadata only —
never keyboard input, mouse, clipboard or file contents — and never captures the
Windows secure desktop (UAC / Ctrl+Alt+Del / login / lock screen). Full design:
[`docs/27-screenshot-module.md`](docs/27-screenshot-module.md).

---

## 7d. Notifications (Phase 9)

An admin-only **Notifications** dashboard (`/notifications`, "Notifications" in
the nav, plus a live bell) turns monitoring signals into rule-driven alerts
delivered **in-app** (live, no polling) and by **email**. A settings page
(`/notifications/settings`) configures everything from the DB — no code changes:
per-rule enable/severity/channels/throttle, key thresholds (idle, long-usage,
restricted apps, blacklisted processes) and each admin's own preferences
(channels, minimum severity, digest, quiet hours).

It observes the existing presence (Phase 6) and application-usage (Phase 7)
pipelines without modifying them, and processes everything **asynchronously**
(queued evaluation + queued delivery + queued mail) so agent sync, presence,
app tracking and screenshot uploads are never blocked. Requirements: a running
`php artisan queue:work`, a configured mail transport, and Reverb for live
in-app updates (see §7a). Optional tunables:

```bash
# .env (server)
TRECK_NOTIFY_THROTTLE=300          # default per-rule dedupe window (seconds)
TRECK_NOTIFY_DIGEST_HOURS=24       # digest batching window
```

Migrations seed one rule per event type (with `presence.online` disabled by
default). Full design: [`docs/29-notifications.md`](docs/29-notifications.md).

---

## 7e. Manager hierarchy & shared computers (Phase 11)

Treck supports a **Super Admin → Manager → Employee** hierarchy with role-scoped
dashboards, and **shared computers** used by multiple employees across shifts.

- **Roles:** the existing `admin` role is the **Super Admin** (unrestricted); a
  new `manager` role supervises an assigned set of employees; `employee` is
  self-service. `php artisan db:seed --class=RolePermissionSeeder` idempotently
  adds the manager role.
- **Manager Management** (`/admin/managers`, "Managers" in the nav, Super Admin
  only): create/promote/demote managers and assign/transfer/remove employees.
- **Scoping:** a Manager sees only their team across presence, application usage,
  screenshots, employees and reports; the Super Admin sees everything.
- **Shared computers:** the agent reports the logged-in Windows username on every
  event; the server maps `computer + windows_username → employee`
  (`computer_users` table) and attributes each event to the right person.
  Unrecognized accounts become *pending* mappings and alert the Super Admin.
- **Backward compatible:** existing single-user computers and legacy agents
  (no username) keep attributing to the computer's assigned employee. Migrations
  are additive/nullable — no existing column is removed. Full design:
  [`docs/31-multi-user-computer-and-manager-hierarchy.md`](docs/31-multi-user-computer-and-manager-hierarchy.md).

---

## 7f. File download monitoring (Phase 12, opt-in)

An admin/manager **Downloads** dashboard (`/downloads`) lists files the agent
observed being saved into user download locations — **metadata only**, never file
contents. It reuses the Phase 11 resolution (attributed to the employee behind
the active Windows account) and the same role scoping (Super Admin all; Manager
their team). Includes a detail page, grouped reports (`/downloads/reports`),
Excel/CSV export, and configurable alerts (executable / archive / large /
restricted downloads) through the existing notification engine.

**Disabled by default** on the agent. Enable and tune it in the agent's
`appsettings.json` `FileDownloads` section (`Enabled`, watched folders, ignore
rules, `HashEnabled`, `MaxHashBytes`). Backend alert thresholds live under
`config/treck.php` → `downloads`:

```bash
# .env (server, optional)
TRECK_DOWNLOAD_LARGE_BYTES=104857600   # "large file" alert threshold (100 MB)
```

`php artisan migrate` creates `file_downloads` and seeds the download alert
rules. Full design:
[`docs/32-file-download-monitoring.md`](docs/32-file-download-monitoring.md).

---

## 8. Running tests

```bash
php artisan test                    # full suite (SQLite in-memory via phpunit.xml)
php artisan test --testsuite=Feature
```

Security suite highlights (P1): `AgentIdentityTest`, `ApiAuthTest`,
`RateLimitTest`, `SchedulerTest`.

---

## 9. Production

Follow [`docs/19-production-deployment.md`](docs/19-production-deployment.md) —
server requirements, SSL, queues, cron, backups, API security, rate limiting,
monitoring, and the full deployment checklist. Use
[`deploy/.env.production.example`](deploy/.env.production.example) as the env
template. On first deploy, seed **roles only** (`RolePermissionSeeder`), never
`DemoDataSeeder`.

For the **v1.0.0 production-release runbook** — final architecture, upgrade and
uninstall procedures, backup/disaster-recovery, operational checklist, and
release notes — see [`docs/30-production-release.md`](docs/30-production-release.md).

---

## 10. Troubleshooting

| Symptom | Fix |
| ------- | --- |
| `No application encryption key` | `php artisan key:generate` |
| Migrations fail on permission tables | ensure `config/permission.php` present (it is) and `php artisan config:clear` |
| Agent registration returns 403 | `TRECK_AGENT_PROVISIONING_KEY` is unset/mismatched |
| Agent calls return 401 | token missing/expired — re-register the device |
| Dashboard shows zeros | seed `DemoDataSeeder` or let agents report, then run `treck:daily-rollup` |
| Assets missing / unstyled | `npm run build` (and `php artisan storage:link`) |
| Queued work not processing | start `php artisan queue:work` |

---

## 11. Project status

See [`docs/23-requirements-review.md`](docs/23-requirements-review.md) for the
module-by-module completion table. Known gaps at the time of writing:
Attendance UI/correction, full auth suite, and Department & Computer admin UIs.
Real-time presence (Phase 6), application usage (Phase 7), the Screenshot module
(Phase 8) and Notifications (Phase 9) are implemented — see §§7a–7d.
