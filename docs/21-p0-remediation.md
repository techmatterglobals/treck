# 21. P0 Remediation — Application Skeleton

Executes the **P0** items from the [audit](20-audit-report.md): turn the module
library into a bootable Laravel 11 application. **No business logic was
changed** — only scaffolding, wiring, config, and framework migrations.

## 21.1 What was added

**Project + entry points**
- `composer.json` (Laravel 11 + Sanctum, Spatie Permission, Livewire, Excel,
  dompdf; dev: Breeze, Pint, PHPUnit, Pail, Sail, Collision)
- `package.json`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js`
- `artisan`, `public/index.php`, `public/.htaccess`
- `bootstrap/providers.php`, `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/Controller.php` (base class every controller extends)
- `.env.example`, `.gitignore`, storage/bootstrap runtime dirs

**Config** (`config/`)
- Domain: `treck.php` (incl. `agent.provisioning_key` — fixes CFG-1 blocker)
- Packages: `sanctum.php`, `permission.php` (fixes CFG-2), `cors.php`
- Core: `app, auth, cache, database, filesystems, logging, mail, queue,
  services, session`

**Routes** — all existing module routes registered
- `routes/web.php` → dashboard, admin role route, `require`s `modules/employees.php`
  + `modules/reports.php` + `auth.php`
- `routes/api.php` → user auth (fixes ROUTE-3), `require`s `modules/agent.php`
  + `modules/activity.php`
- `routes/console.php` → schedules `treck:reconcile-sessions` + `treck:daily-rollup`
  (fixes ROUTE-2)
- `routes/auth.php` → minimal session login/logout (replaceable by `breeze:install`)

**Framework migrations** (fixes MIG-1/MIG-2)
- `create_cache_table`, `create_jobs_table` (jobs + job_batches + failed_jobs),
  `create_personal_access_tokens_table` (Sanctum)

**Minimal UI shell** so existing Blade/Livewire views render
- Anonymous components: `app-layout`, `guest-layout`, `input-label`,
  `text-input`, `input-error`, `primary-button`
- `auth/login.blade.php`, `resources/css/app.css`, `resources/js/{app,bootstrap}.js`

## 21.2 Commands to run

```bash
# 1. PHP dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate
#   edit .env: DB_*, (optional) REDIS_*, SANCTUM_STATEFUL_DOMAINS,
#   and TRECK_AGENT_PROVISIONING_KEY (required for agent registration)

# 3. Database (create the schema/user first), then migrate + seed
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder      # roles + admin@treck.test
#   optional demo data (local only):
php artisan db:seed --class=DemoDataSeeder

# 4. Front-end
npm install
npm run build            # or: npm run dev

# 5. Storage symlink (screenshots/exports)
php artisan storage:link

# 6. Serve (+ queue + scheduler in separate terminals)
php artisan serve
php artisan queue:work
php artisan schedule:work
```

> Optional: `php artisan breeze:install livewire --dark` swaps the minimal
> `routes/auth.php` login for the full Breeze auth suite. `composer require
> maatwebsite/excel barryvdh/laravel-dompdf` are already in `composer.json`.

## 21.3 Testing steps

1. **Boot**: `php artisan about` and `php artisan route:list` succeed and list
   the module routes (`employees.*`, `reports.*`, `agent.*`, `activity.*`,
   `dashboard`, `login`).
2. **Migrate**: `php artisan migrate:status` shows all migrations run, including
   `cache`, `jobs`, `failed_jobs`, `personal_access_tokens`, permission tables.
3. **Health**: `curl -I http://localhost:8000/up` → 200.
4. **Login**: visit `/login`, sign in as `admin@treck.test` / `password` →
   redirected to `/dashboard` (cards/charts render; seed DemoData for numbers).
5. **Agent API** (set `TRECK_AGENT_PROVISIONING_KEY` first):
   ```bash
   curl -sX POST http://localhost:8000/api/agent/register \
     -H 'Accept: application/json' \
     -d provisioning_key=$KEY -d device_uuid=TEST -d employee_code=EMP-0042 \
     -d computer_name=TEST-PC -d os='Windows 11'
   ```
   → 201 with a token; then `/api/agent/login`, `/api/activity`, `/api/agent/logout`.
6. **Scheduler**: `php artisan schedule:list` shows `treck:reconcile-sessions`
   (every minute) and `treck:daily-rollup` (hourly + 00:30).
7. **Rollup**: `php artisan treck:daily-rollup` runs without error.

## 21.4 Not in this change (later phases)

P1 (SEC-1 agent employee binding, SEC-2 rate limiters), P2 (tests, pagination,
login throttle, CORS tightening), P3 (polish). Business logic untouched here.
