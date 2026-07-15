# 8. Getting Started — Creating the Laravel Project

This guide takes you from an empty machine to a running **Treck** Laravel 11
application with Breeze authentication (Livewire stack), Sanctum API tokens, and
Spatie roles — matching the architecture in docs 01–07. **No steps are skipped**,
and every command is explained.

> Run these on the machine where you develop. Commands are shown for a
> Unix-like shell (macOS/Linux/WSL). Windows users can use WSL2 or adapt paths.

---

## 8.0 Prerequisites

Install these first. Treck targets **Laravel 11**, which requires **PHP 8.2+**.

| Tool | Version | Why |
| ---- | ------- | --- |
| PHP | 8.2 or 8.3 | Laravel 11 runtime |
| Composer | 2.x | PHP dependency manager |
| Node.js | 20 LTS (18+ ok) | Builds front-end assets with Vite |
| MySQL | 8.x | Primary database |
| Redis | 7.x (recommended) | Cache, queue, live device status |

Verify each is available:

```bash
php -v          # expect PHP 8.2.x / 8.3.x
composer -V     # expect Composer 2.x
node -v         # expect v20.x (or >=18)
npm -v
mysql --version # expect Ver 8.x
redis-cli ping  # expect PONG (if Redis installed)
```

Also confirm the PHP extensions Laravel needs are enabled: `pdo_mysql`,
`mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`,
`fileinfo`. Check with:

```bash
php -m | grep -Ei 'pdo_mysql|mbstring|openssl|tokenizer|xml|ctype|bcmath|curl|fileinfo'
```

---

## 8.1 Create the Laravel project

You have two situations. Pick the one that matches you.

### Case A — Fresh project (empty directory)

Use Composer's `create-project` to download Laravel 11 into a new `treck` folder:

```bash
composer create-project laravel/laravel treck "11.*"
cd treck
```

**What this does:** downloads the `laravel/laravel` skeleton (a starter template,
not the framework itself — the framework `laravel/framework` is pulled in as a
dependency), installs all Composer dependencies into `vendor/`, generates a
`.env` file from `.env.example`, and sets a fresh `APP_KEY`.

> Alternatively, if you have the global Laravel installer
> (`composer global require laravel/installer`), you can run `laravel new treck`,
> which is interactive and offers starter kits.

### Case B — This repository (docs already exist)

This repo already contains `docs/` and a `README.md`, so you can't run
`create-project` directly into it (it refuses a non-empty directory). Scaffold in
a temporary folder and move the app files in:

```bash
# From the parent of your clone
composer create-project laravel/laravel treck-app "11.*"

# Copy the Laravel app over your repo, preserving docs/ and .git/
rsync -a --exclude='.git' treck-app/ treck/
rm -rf treck-app
cd treck
```

**What this does:** `rsync -a` mirrors the generated Laravel app into your repo
without touching `.git/`. Your existing `docs/` stays; Laravel's `README.md`
overwrites the stub (you can restore the Treck README from git afterward with
`git checkout README.md`).

After either case, confirm the framework version:

```bash
php artisan --version   # expect "Laravel Framework 11.x.y"
```

**`php artisan`** is Laravel's command-line tool; you'll use it constantly.

---

## 8.2 Required Composer packages

Laravel 11 ships lean. Add the packages the architecture calls for.

### 8.2.1 API layer — Laravel Sanctum

```bash
php artisan install:api
```

**What this does (Laravel 11):** installs `laravel/sanctum`, publishes its
migration, creates the `routes/api.php` file, and registers the `api` route
group in `bootstrap/app.php`. Sanctum gives us token-based auth for both the
**desktop agent** and **user/mobile** API clients (the two token audiences from
doc 05).

Then enable API tokens on the `User` model — open `app/Models/User.php` and add
the trait:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    // ...
}
```

### 8.2.2 Authentication scaffolding — Laravel Breeze

```bash
composer require laravel/breeze --dev
```

**What this does:** installs Breeze as a **dev-only** dependency (it's a
scaffolding generator, not a runtime dependency). We run its installer in
§8.5.

### 8.2.3 Roles & permissions — Spatie Laravel-Permission

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

**What this does:** the first command installs the package; the second publishes
its config (`config/permission.php`) and the migration that creates the
`roles`, `permissions`, `model_has_roles`, and related tables. This backs the
Super Admin / Admin / Manager / Employee RBAC model.

Add the `HasRoles` trait to `app/Models/User.php`:

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;
    // ...
}
```

### 8.2.4 (Optional, recommended) Queue dashboard — Laravel Horizon

Only if you use the Redis queue driver (recommended for telemetry back-pressure):

```bash
composer require laravel/horizon
php artisan horizon:install
```

**What this does:** installs Horizon and publishes its assets/config. Horizon
gives you a metrics dashboard and failed-job visibility for the ingestion
workers described in doc 01.

### 8.2.5 (Optional) Dev quality tools

```bash
composer require --dev laravel/pint          # code style (bundled by default in L11)
composer require --dev larastan/larastan     # static analysis
```

> **Note on Livewire:** you do **not** install Livewire separately — Breeze's
> Livewire stack (next section) pulls in `livewire/livewire` for you.

---

## 8.3 Environment configuration

Laravel reads runtime config from the `.env` file (created in §8.1). Never
commit `.env` — it holds secrets. Edit it now.

### 8.3.1 Generate the app key (if not already set)

```bash
php artisan key:generate
```

**What this does:** writes a random `APP_KEY` used to encrypt sessions and
cookies. `create-project` usually does this automatically; run it if `APP_KEY=`
is empty.

### 8.3.2 Core application settings

Edit `.env`:

```dotenv
APP_NAME="Treck"
APP_ENV=local
APP_KEY=base64:...            # set by key:generate
APP_DEBUG=true                # false in production
APP_URL=http://localhost:8000
APP_TIMEZONE=UTC              # keep server time authoritative (see doc 07 risks)
```

### 8.3.3 Database (MySQL)

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=treck
DB_USERNAME=treck_user
DB_PASSWORD=change_me_strong
```

### 8.3.4 Cache, session, queue (Redis recommended)

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis          # or 'database' if you skip Redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis        # or 'database'; drives async ingestion

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

> If you don't have Redis, set `CACHE_STORE=database`, `SESSION_DRIVER=database`,
> and `QUEUE_CONNECTION=database`. Live device status still works (it just uses
> the cache store you configured), but Redis is strongly recommended at scale.

### 8.3.5 Sanctum (SPA/stateful domains)

```dotenv
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8000,127.0.0.1,127.0.0.1:8000
```

**What this does:** tells Sanctum which first-party domains may use cookie-based
(stateful) auth. The desktop agent and mobile clients use **bearer tokens**, so
they don't need to be listed here.

### 8.3.6 Treck domain settings

These map to `config/treck.php` (doc 02 §2.3). Add them to `.env`:

```dotenv
TRECK_HEARTBEAT_INTERVAL=60
TRECK_IDLE_THRESHOLD=300
TRECK_OFFLINE_GRACE=180
TRECK_WORKDAY_START=09:00
TRECK_LATE_GRACE=15
TRECK_FULL_DAY_HOURS=8
TRECK_SCREENSHOTS=false
TRECK_RAW_RETENTION=90
TRECK_AGG_RETENTION=730
```

Create `config/treck.php` with the contents shown in doc 02 §2.3 so these
values are readable via `config('treck.activity.idle_threshold_seconds')`.

After editing `.env`, clear any cached config:

```bash
php artisan config:clear
```

---

## 8.4 Database setup

### 8.4.1 Create the database and user (MySQL)

Log into MySQL as an admin and create the schema and a dedicated user:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE treck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'treck_user'@'127.0.0.1' IDENTIFIED BY 'change_me_strong';
GRANT ALL PRIVILEGES ON treck.* TO 'treck_user'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

**What this does:** creates the `treck` database with the `utf8mb4` charset
(full Unicode incl. emoji), a scoped application user, and grants it rights on
just that database. These credentials must match §8.3.3.

### 8.4.2 Verify Laravel can connect

```bash
php artisan db:show
```

**What this does:** prints connection details and confirms Laravel reaches MySQL
using your `.env`. If it errors, re-check host/port/credentials.

### 8.4.3 Run the initial migrations

At this point the migrations present are Laravel's defaults plus Sanctum and
Spatie (from §8.2). Run them:

```bash
php artisan migrate
```

**What this does:** creates `users`, `password_reset_tokens`, `sessions`,
`cache`, `jobs` (Laravel defaults), `personal_access_tokens` (Sanctum), and the
Spatie permission tables. The Treck domain tables (`departments`, `employees`,
`devices`, `work_sessions`, …) are added as you build each module per the
roadmap; their DDL reference is in `docs/database/schema.sql`.

> You'll create those with `php artisan make:migration` during Phases 1–5.

---

## 8.5 Authentication setup — Laravel Breeze (Livewire stack)

Breeze was installed as a dev dependency in §8.2.2. Now run its installer.

```bash
php artisan breeze:install livewire --dark --pest
```

**What this does, argument by argument:**

- `breeze:install` — scaffolds authentication (login, registration, password
  reset, email verification, profile management).
- `livewire` — selects the **Livewire stack** (Blade + Livewire 3 + Alpine +
  Tailwind), matching doc 06. This also installs `livewire/livewire`.
- `--dark` — includes dark-mode Tailwind styles.
- `--pest` — scaffolds tests using Pest (omit for PHPUnit).

> To use functional **Volt** components, add `--volt`. We use standard class-based
> Livewire components in doc 06, so it's optional.

The installer also updates `package.json` with Tailwind/Vite tooling. Install
and build the front-end assets:

```bash
npm install
npm run build      # one-off production build
# — or, during development —
npm run dev        # Vite dev server with hot reload (keep running)
```

**What this does:** `npm install` downloads Tailwind, Vite, and Alpine;
`npm run dev` compiles `resources/css` and `resources/js` and watches for
changes.

Breeze adds new migrations only if not already present — re-run migrations to be
safe:

```bash
php artisan migrate
```

### 8.5.1 Seed roles and a first admin

Create a seeder for the RBAC roles (referenced as `RolePermissionSeeder` in
doc 02):

```bash
php artisan make:seeder RolePermissionSeeder
```

Fill it in (illustrative):

```php
use Spatie\Permission\Models\Role;
use App\Models\User;

public function run(): void
{
    foreach (['super_admin', 'admin', 'manager', 'employee'] as $role) {
        Role::findOrCreate($role);
    }

    $admin = User::firstOrCreate(
        ['email' => 'admin@treck.test'],
        ['name' => 'Treck Admin', 'password' => bcrypt('password'), 'is_active' => true],
    );
    $admin->assignRole('super_admin');
}
```

Run it:

```bash
php artisan db:seed --class=RolePermissionSeeder
```

**What this does:** creates the four roles and a `super_admin` user you can log
in with immediately.

### 8.5.2 Start the app and verify auth

Laravel 11 ships a convenience script that runs the server, queue, logs, and
Vite together:

```bash
composer run dev
```

**What this does:** launches `php artisan serve` (app on
`http://localhost:8000`), a queue worker, log tailing (`pail`), and `npm run dev`
concurrently. Prefer separate terminals? Run them individually:

```bash
php artisan serve            # http://localhost:8000
php artisan queue:work       # process queued ingestion jobs
php artisan schedule:work    # run scheduled rollups locally (cron in prod)
npm run dev                  # asset hot-reload
```

Open `http://localhost:8000`, click **Log in**, and sign in as
`admin@treck.test` / `password`. You now have a working, role-aware,
authenticated Laravel 11 app — roadmap **Phase 0 complete**.

---

## 8.6 Resulting folder structure

After the steps above, your project matches the layout in
[doc 02](02-folder-structure.md). Highlights of what now exists vs. what you add
per the roadmap:

```
treck/
├── app/
│   ├── Http/Controllers/         # Breeze auth controllers (Auth/…) present
│   ├── Livewire/                 # Breeze profile/nav components present;
│   │                             #   add Dashboard/, Employees/, … (doc 06)
│   ├── Models/User.php           # now uses HasApiTokens + HasRoles
│   ├── Actions/  Services/  DataObjects/  Enums/  Policies/  Jobs/
│   │                             # ← you create these dirs per doc 02
│   └── Providers/AppServiceProvider.php
├── bootstrap/app.php             # middleware, api routes, scheduling (L11)
├── config/
│   ├── sanctum.php               # published by install:api
│   ├── permission.php            # published by Spatie
│   └── treck.php                 # ← you create (doc 02 §2.3)
├── database/
│   ├── migrations/               # defaults + sanctum + spatie present
│   └── seeders/RolePermissionSeeder.php
├── routes/
│   ├── web.php                   # Breeze auth + dashboard routes
│   ├── api.php                   # created by install:api (add agent/user groups)
│   └── console.php               # scheduled commands (L11)
├── resources/views/              # Breeze Blade + Livewire views
├── tests/                        # Pest tests (Breeze scaffolded)
├── .env                          # your local config (never commit)
└── docs/                         # this documentation
```

Create the custom application directories when you start their modules:

```bash
mkdir -p app/Actions/Agent app/Actions/Attendance app/Actions/Productivity \
         app/Services/Attendance app/Services/Activity app/Services/Productivity \
         app/DataObjects app/Enums app/Policies app/Jobs app/Support/Scopes
```

---

## 8.7 Full command recap (copy-paste order)

```bash
# 1. Create project
composer create-project laravel/laravel treck "11.*" && cd treck

# 2. Packages
php artisan install:api                                   # Sanctum
composer require laravel/breeze --dev                     # Breeze (dev)
composer require spatie/laravel-permission                # RBAC
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# 3. Environment
php artisan key:generate
#   → edit .env per §8.3 (DB, Redis, Sanctum, TRECK_*), then:
php artisan config:clear

# 4. Database
#   → create MySQL db + user per §8.4.1, then:
php artisan db:show
php artisan migrate

# 5. Breeze auth (Livewire) + assets
php artisan breeze:install livewire --dark --pest
npm install
php artisan migrate
php artisan make:seeder RolePermissionSeeder              # fill in, then:
php artisan db:seed --class=RolePermissionSeeder

# 6. Run
composer run dev        # serve + queue + logs + vite
```

You're now ready to begin **Phase 1 — Organization & Employees** from the
[development roadmap](07-development-roadmap.md).
