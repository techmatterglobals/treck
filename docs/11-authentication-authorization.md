# 11. Authentication & Authorization

Treck combines **Laravel Breeze** (session auth for the dashboard) + **Laravel
Sanctum** (token auth for API/mobile) for *authentication*, and **Spatie
Laravel-Permission** for *authorization* with two roles: **Admin** and
**Employee**.

- **Authentication** = "who are you?" — Breeze login/register + Sanctum tokens.
- **Authorization** = "what may you do?" — Spatie roles & permissions + middleware.

## 11.1 Delivered files

| File | Purpose |
| ---- | ------- |
| `database/migrations/2026_07_15_000001_create_permission_tables.php` | Spatie roles/permissions tables |
| `database/seeders/RolePermissionSeeder.php` | Creates roles, permissions, assignments, default admin |
| `database/seeders/DatabaseSeeder.php` | Calls the seeder above |
| `app/Enums/UserRole.php` | Role name constants (`admin`, `employee`) |
| `app/Http/Middleware/EnsureUserIsActive.php` | Blocks deactivated accounts |
| `bootstrap/app.php` | Registers `role`, `permission`, `role_or_permission`, `active` middleware aliases |
| `app/Http/Controllers/Api/V1/User/AuthController.php` | API login/logout/me (Sanctum tokens) |
| `app/Http/Controllers/DashboardController.php` | Role-based dashboard routing |
| `app/Http/Controllers/Admin/UserRoleController.php` | Admin-only role assignment |

## 11.2 Step-by-step setup

### Step 1 — Install the packages

(From [doc 08](08-getting-started.md); recap.)

```bash
php artisan install:api                                   # Sanctum
composer require laravel/breeze --dev
composer require spatie/laravel-permission
```

### Step 2 — Publish the Spatie config

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan config:clear
```

**Why:** this writes `config/permission.php`, which the permission migration
reads for table/column names. `config:clear` ensures the fresh config is loaded.

> The publish command **also** copies a `create_permission_tables` migration
> into `database/migrations`. Since this repo already ships that migration
> (`2026_07_15_000001_create_permission_tables.php`), delete the freshly
> published duplicate so you don't create the tables twice.

### Step 3 — Add the traits to the User model

Already done in `app/Models/User.php`:

```php
use Laravel\Sanctum\HasApiTokens;      // API tokens
use Spatie\Permission\Traits\HasRoles; // roles & permissions

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;
}
```

`HasRoles` adds `assignRole()`, `hasRole()`, `can()`, `getRoleNames()`, etc.
`HasApiTokens` adds `createToken()` and `currentAccessToken()`.

### Step 4 — Run the migrations

```bash
php artisan migrate
```

Creates the Spatie tables (plus `personal_access_tokens` from Sanctum and the
Treck tables). Order is guaranteed by the migration timestamps.

### Step 5 — Seed roles, permissions & the admin user

```bash
php artisan db:seed --class=RolePermissionSeeder
# or the full set:
php artisan db:seed
```

This creates:

- **Permissions:** `view dashboard`, `manage users`, `manage employees`,
  `manage departments`, `manage computers`, `view attendance`,
  `correct attendance`, `view reports`, `manage settings`, `view own data`.
- **Roles:**
  - **Admin** → *all* permissions.
  - **Employee** → `view dashboard`, `view own data`.
- **Default admin:** `admin@treck.test` / `password` (change it after first
  login).

### Step 6 — Register middleware aliases

Already done in `bootstrap/app.php` (Laravel 11 registers middleware here, not
in a Kernel):

```php
$middleware->alias([
    'role' => RoleMiddleware::class,
    'permission' => PermissionMiddleware::class,
    'role_or_permission' => RoleOrPermissionMiddleware::class,
    'active' => EnsureUserIsActive::class,
]);
```

### Step 7 — Protect routes

**Web (`routes/web.php`)** — Breeze provides `auth`/`verified`. Layer role &
permission checks on top:

```php
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\DashboardController;

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    // Everyone signed in → routed to the right dashboard by role.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Admin-only area.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::patch('users/{user}/role', [UserRoleController::class, 'update'])->name('users.role');
        // ... employees, departments, computers, settings ...
    });

    // Fine-grained permission example (works regardless of role):
    Route::get('/reports', ReportsPage::class)
        ->middleware('permission:view reports')
        ->name('reports');
});
```

**API (`routes/api.php`)** — Sanctum guards the group; abilities scope tokens:

```php
use App\Http\Controllers\Api\V1\User\AuthController;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']); // public

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me',      [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Admin-only, by role:
        Route::middleware('role:admin')->group(function () {
            // employee/device/report management endpoints
        });

        // Or by token ability (see AuthController):
        Route::get('reports/productivity', ReportController::class)
            ->middleware('ability:*');   // employees hold 'employee:self' only
    });
});
```

### Step 8 — Use authorization in code & views

Spatie registers every permission as a **Gate ability**, so all the standard
Laravel authorization helpers work:

```php
// Controllers
$this->authorize('manage employees');       // 403 if not permitted
abort_unless($request->user()->can('view reports'), 403);

// Blade / Livewire views
@can('manage users')
    <a href="{{ route('admin.users.role', $user) }}">Change role</a>
@endcan

@role('admin')
    <x-admin-nav />
@endrole
```

## 11.3 How the two token audiences work

`AuthController::login` issues a Sanctum token whose **abilities** depend on role:

| Role | Token abilities | Meaning |
| ---- | --------------- | ------- |
| Admin | `['*']` | Full API access |
| Employee | `['employee:self']` | Own data only |

Routes then gate on either the **role** (`role:admin`) or the **token ability**
(`ability:...`) — belt and suspenders. The **desktop agent** uses a separate
device token (`agent:report`) minted during pairing, never a user login (see
[doc 05](05-api-structure.md)).

## 11.4 The `active` middleware

`EnsureUserIsActive` blocks any user whose `is_active` flag is false, even with a
valid session or token: JSON clients get a `403`; web clients are logged out and
redirected to login with an error. This lets an admin disable an account
instantly without deleting it.

## 11.5 Verification checklist

```bash
php artisan migrate:fresh --seed          # rebuild + seed (dev only)
php artisan tinker
>>> $u = App\Models\User::where('email','admin@treck.test')->first();
>>> $u->hasRole('admin');                 // true
>>> $u->can('manage employees');          // true
>>> $u->isAdministrator();                // true
```

- Log in at `/dashboard` as the admin → admin dashboard.
- Create an employee user, assign the `employee` role → employee dashboard;
  hitting `/admin/...` returns `403`.
- Deactivate a user (`is_active = false`) → next request logs them out.
- `POST /api/v1/auth/login` with `device_name` → returns a bearer token;
  `GET /api/v1/auth/me` with that token returns roles + permissions.
