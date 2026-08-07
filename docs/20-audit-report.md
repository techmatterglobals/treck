# 20. Codebase Audit Report

**Reviewer role:** Senior Laravel architect
**Date:** 2026-07-15
**Scope:** all modules on branch `claude/employee-monitoring-laravel-arch-mbjgyp`
**Head commit at review:** `f896e8a`

> Analysis only — no fixes applied. This report identifies gaps and ranks them;
> remediation is deferred.

## 20.1 Executive summary

The repository is a **well-structured set of drop-in modules + documentation**,
not yet a bootable Laravel application. Per-module code quality is generally
**good** — thin controllers, Form Request validation, policies, service/DTO
separation, enum casts, indexed schema. The material risk is concentrated in
**integration and scaffolding**: there is no Laravel application skeleton
(`composer.json`, `config/`, top-level route files), the module route files are
never loaded, and required config/migrations are absent. There are also **two
genuine security findings** and a few performance/best-practice items.

**Overall verdict:** Not deployable as-is. With scaffolding + wiring (P0 items)
and the two security fixes, it becomes a coherent, production-track codebase.

### Severity legend
- **P0 – Blocker:** prevents the app from running or is a serious security hole.
- **P1 – High:** breaks a feature or is an important hardening gap.
- **P2 – Medium:** correctness/performance/quality issue to address before scale.
- **P3 – Low:** minor / cosmetic / future-proofing.

## 20.2 Findings by category

### 1. Missing dependencies

| ID | Sev | Finding |
| -- | --- | ------- |
| DEP-1 | P0 | **No `composer.json`.** No dependencies are declared or installable. Every framework/package class referenced (`Illuminate\*`, Sanctum, Spatie Permission, Livewire, maatwebsite/excel, barryvdh/laravel-dompdf) is unresolved. `vendor/` cannot exist. |
| DEP-2 | P0 | **No `package.json`.** Front-end (Tailwind/Vite/Alpine, Livewire assets) can't be built. |
| DEP-3 | P1 | Packages are referenced across modules but never required: `laravel/sanctum`, `laravel/breeze`, `spatie/laravel-permission`, `maatwebsite/excel`, `barryvdh/laravel-dompdf`, and (for prod) `laravel/horizon`, `spatie/laravel-backup`. Each doc lists the `composer require`, but nothing centralizes them. |
| DEP-4 | P3 | No `.gitignore` — once scaffolded, `vendor/`, `node_modules/`, `.env` risk being committed. |

### 2. Missing migrations / config

| ID | Sev | Finding |
| -- | --- | ------- |
| CFG-1 | P0 | **`config/treck.php` does not exist**, yet code reads `config('treck.*')`. Most calls pass defaults, but `RegisterDeviceRequest` reads `config('treck.agent.provisioning_key')` with **no default** → returns null → the guard denies **every** device registration. The agent can never obtain a token. Hard blocker for the whole agent pipeline. |
| MIG-1 | P1 | **Framework migrations missing.** The custom `users` migration replaced Laravel's default but the sibling defaults were not carried over: `create_cache_table`, `create_jobs_table` (jobs, job_batches, **failed_jobs**). With queues enabled, failed jobs have nowhere to land. |
| MIG-2 | P1 | **`personal_access_tokens` migration missing** (normally added by `php artisan install:api`). Sanctum token auth — used by both the agent API and user API — has no table. |
| CFG-2 | P2 | Spatie's `create_permission_tables` migration requires `config/permission.php` (must be `vendor:publish`ed). Not in repo; documented but a fresh clone will fail the migration. |
| CFG-3 | P2 | No `config/cors.php` tuning committed; default allows broad origins for the SPA/API. |

### 3. Broken relationships

Relationships are **sound** — every FK resolves, `constrained()` inference is
correct, and inverse/aggregate relations (`withSum('activityLogs …')`)
match. No broken relationships found. Minor notes:

| ID | Sev | Finding |
| -- | --- | ------- |
| REL-1 | P3 | `employees`/`computers` use soft deletes, but child FKs are `cascadeOnDelete`. Cascades only fire on **hard** delete; soft-deleted parents keep children (intended, but document it). |
| REL-2 | P3 | `employees.employee_code` / `user_id` are unique **including** soft-deleted rows — re-creating an employee with a reused code will collide until the old row is force-deleted. |

### 4. Missing routes

| ID | Sev | Finding |
| -- | --- | ------- |
| ROUTE-1 | P0 | **No `routes/web.php`, `routes/api.php`, or `routes/console.php`.** The module files (`routes/modules/{employees,agent,activity,reports}.php`) are never `require`d, so **none** of those routes are registered. |
| ROUTE-2 | P1 | **Scheduler not wired.** `treck:reconcile-sessions` (offline detection) and `treck:daily-rollup` (attendance/productivity) are only meaningful if scheduled in `routes/console.php` — which is absent. Rollups and offline sweeps will never run. |
| ROUTE-3 | P1 | **User `AuthController` has no route.** `Api/V1/User/AuthController@login/logout/me` is referenced in docs but not defined in any committed route file. Token issuance for dashboard/mobile users is unreachable. |
| ROUTE-4 | P1 | `DashboardController` (`/dashboard`) and `Admin\UserRoleController` routes are undefined; `route('dashboard')`/`route('login')` depend on Breeze scaffolding that isn't present. |

### 5. Security issues

| ID | Sev | Finding |
| -- | --- | ------- |
| SEC-1 | P1 | **Agent trusts client-supplied `employee_id`.** `WorkSessionController@login` opens a session with `employee_id` straight from the request body (validated only as `exists`). A valid device token can therefore attribute activity to **any** employee. It should use the computer's assigned `employee_id` (`$computer->employee_id`) or reject a mismatch. |
| SEC-2 | P1 | **Rate limiting is documented but not implemented.** The `agent`/`user` limiters exist only in `docs/05`; `bootstrap/app.php` defines no `RateLimiter::for(...)`, and no route applies `throttle:*`. Heartbeat flooding and API brute-force are unthrottled. |
| SEC-3 | P2 | **No login throttle.** No per-email/IP limiter on authentication → credential-stuffing exposure. |
| SEC-4 | P2 | Once scaffolded, ensure `APP_DEBUG=false` and Telescope disabled in prod (deploy doc covers this, but there's no guard in code). |
| SEC-5 | P3 | Provisioning-key check is correct (`$expected !== '' && hash_equals(...)` prevents the empty-key bypass) — noted as **verified OK**. |

### 6. Performance problems

| ID | Sev | Finding |
| -- | --- | ------- |
| PERF-1 | P2 | **Unpaginated tables.** `DashboardMetricsService::employeeStatusRows()` loads *all* employees (+computers) and the reports table renders every row. Fine for demo, heavy at fleet scale. |
| PERF-2 | P2 | `AttendanceService::deriveDaily()` iterates `Employee::all()` doing `firstOrNew`+`save` per employee → O(employees) queries per day. Acceptable nightly; batch/upsert if backfilling long ranges. |
| PERF-3 | P3 | Report grouping uses `DATE_FORMAT(work_date, …)`, which can't use an index for the GROUP BY (the `whereBetween` range filter still uses the `work_date` index). Watch on large ranges. |
| PERF-4 | P3 | `ActivityTrackingService::record()` issues two `increment()` queries per heartbeat; could be a single update. Negligible unless heartbeat volume is very high. |

### 7. Laravel best-practice violations

| ID | Sev | Finding |
| -- | --- | ------- |
| BP-1 | P0 | **No application skeleton.** Missing `artisan`, `public/index.php`, `bootstrap/providers.php`, `app/Providers/`, `config/`, default route files. The repo is a module library, not a runnable app — everything else depends on fixing this. |
| BP-2 | P1 | **Zero tests.** The roadmap's Definition of Done mandates unit/feature/Livewire tests; none exist. High-value targets: `ActivityTracker::classify`, `AttendanceService`, `ProductivityService`, agent endpoints, policies. |
| BP-3 | P3 | Aggregates use `DB::table(...)->selectRaw(...)` (reporting/dashboard). Reasonable for set-based aggregation, but keep raw SQL confined to the services (it is). |
| BP-4 | P3 | `verified` middleware is applied though `User` doesn't implement `MustVerifyEmail` — currently a no-op; either implement verification or drop the middleware to avoid a false sense of protection. |
| BP-5 | P3 | Some routes double-guard (route `permission:` middleware **and** Form Request `authorize()`). Harmless/defense-in-depth, but be intentional. |

## 20.3 Per-module status

| Module | Code quality | Blocking gaps |
| ------ | ------------ | ------------- |
| Authentication (Breeze/Sanctum) | Good | No app skeleton; `personal_access_tokens` migration; user auth routes undefined |
| Roles & Permissions (Spatie) | Good | `config/permission.php` not published; migration depends on it |
| Employees | Strong (policy, requests, Livewire) | Routes not wired |
| Departments | Model/migration/factory only | No controller/UI yet (by design); routes n/a |
| Computers | Good (tokenable, states) | — (depends on agent pipeline) |
| Attendance | Good (rollup service) | Scheduler not wired (ROUTE-2) |
| Activity Logs | Good (services split) | Scheduler not wired; SEC-1 |
| Agent APIs | Good structure | **CFG-1 blocks registration**; SEC-1; SEC-2; routes not wired |
| Dashboard (Livewire) | Good | Routes/skeleton; PERF-1 |
| Reports (Excel/PDF) | Good | Packages not required; routes not wired; PERF-1 |
| Deployment config | Solid reference | Assumes a scaffolded app to deploy |

## 20.4 What's solid (keep)

- Clean layering: thin controllers → Form Requests → policies → services/DTOs.
- Enum-cast domain columns with `label()/color()` helpers.
- Idempotent, guarded agent endpoints (cross-device checks, no-op close).
- Sensible schema: correct FKs, on-delete semantics, and indexes; the
  `Employee::scopeSearch` OR-grouping bug was already fixed.
- Consistent "active-ratio proxy" for productivity with a documented upgrade
  path to `productivity_reports`.
- Thorough documentation (docs 01–19) and reference deploy configs.

## 20.5 Prioritized remediation roadmap (for a follow-up change)

**P0 — make it run**
1. BP-1/DEP-1/DEP-2: scaffold Laravel 11 (composer.json, artisan, public/,
   config/, providers), add package.json.
2. ROUTE-1: create `routes/web.php`/`api.php`/`console.php` and `require` the
   module files; register Breeze auth + dashboard + admin routes.
3. CFG-1: add `config/treck.php` (incl. `agent.provisioning_key`).
4. MIG-1/MIG-2: restore framework migrations + `install:api` (Sanctum table).

**P1 — features + hardening**
5. ROUTE-2: schedule `treck:reconcile-sessions` + `treck:daily-rollup`.
6. ROUTE-3/4: define user auth, dashboard, admin routes.
7. SEC-1: bind agent sessions to the computer's employee (ignore/verify client id).
8. SEC-2: implement `agent`/`user` rate limiters in `bootstrap/app.php`.
9. DEP-3: require the referenced packages; CFG-2: publish `config/permission.php`.

**P2 — quality/perf**
10. BP-2: add the test suite (services, agent endpoints, policies).
11. SEC-3: login throttle. PERF-1: paginate dashboard/report tables.
12. CFG-3: tighten CORS.

**P3 — polish**
13. `.gitignore`, `verified` middleware decision, PERF-2/3/4, REL-1/2 docs.

## 20.6 Note

Most P0/P1 items are **integration wiring**, not module rewrites — the module
code is largely correct and will slot in once the app skeleton and routes exist.
The two security items (SEC-1, SEC-2) are the only ones requiring changes to
existing logic.
