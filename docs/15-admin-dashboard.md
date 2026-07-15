# 15. Admin Dashboard (Livewire)

The admin dashboard implemented with **Livewire 3**. It shows KPI cards, an
employee status table (with active/idle time), and two charts — all fed by a
single `DashboardMetricsService` so the components stay thin and the numbers are
computed one way.

## 15.1 Layout

```
┌──────────────────────────────────────────────────────────────┐
│  Cards:  Total employees │ Online now │ Attendance │ Avg prod. │  ← Overview
├───────────────────────────────┬──────────────────────────────┤
│  Daily productivity (chart)    │  Department performance (chart)│
├───────────────────────────────┴──────────────────────────────┤
│  Employee status  |  Active time  |  Idle time  |  Last activity│  ← table
└──────────────────────────────────────────────────────────────┘
```

`DashboardController@index` returns `dashboard.admin` for admins (and
`dashboard.employee` otherwise); the admin page composes the Livewire
components.

## 15.2 Delivered files

| File | Purpose |
| ---- | ------- |
| `app/Services/Dashboard/DashboardMetricsService.php` | All dashboard queries/metrics |
| `app/Livewire/Dashboard/Overview.php` | KPI cards |
| `app/Livewire/Dashboard/EmployeeStatusTable.php` | Status + active/idle table |
| `app/Livewire/Dashboard/ProductivityChart.php` | Daily productivity chart |
| `app/Livewire/Dashboard/DepartmentPerformanceChart.php` | Department chart |
| `resources/views/livewire/dashboard/*.blade.php` | Component views |
| `resources/views/dashboard/admin.blade.php` | Admin page (composes components) |
| `resources/views/dashboard/employee.blade.php` | Employee landing (placeholder for personal widgets) |

## 15.3 Cards (`Overview`)

| Card | Source |
| ---- | ------ |
| Total employees | `Employee::count()` |
| Online now | distinct employees with a connected computer (status ≠ offline and `last_seen_at` within grace) |
| Today's attendance | distinct employees with a session today, + % of total |
| Avg productivity | company-wide active ratio for today |

Polls every 30s (`wire:poll.30s`) so live numbers refresh without a reload.

## 15.4 Table (`EmployeeStatusTable`)

One row per employee: name, department, **status badge** (color from
`ComputerStatus::color()`), **active time** and **idle time** for today
(`Xh YYm`), and **last activity** (`diffForHumans`). Today's active/idle come
from a single query using constrained `withSum` aggregates — no N+1.

## 15.5 Charts

Rendered as **dependency-free CSS bar charts** (Tailwind), so they work with no
JS build step and stay theme-aware:

- **Daily productivity** — vertical bars of the company-wide active ratio over
  a selectable window (7/14/30 days, `#[Url]`-bound). Gap-filled so missing days
  show as empty bars. Bar color: green ≥ 70%, amber ≥ 40%, red below.
- **Department performance** — horizontal bars of each department's average
  active ratio for the day.

> **Swapping in Chart.js / ApexCharts:** the service already returns plain
> arrays. Feed them to a JS chart via `@json($series)` inside an Alpine
> component and dispatch a Livewire event on `days` change — no service changes
> needed. CSS bars are the zero-dependency default.

## 15.6 Metrics service

`DashboardMetricsService` centralizes every query (cards, table, charts). It
depends on `DeviceStatusService` for status/last-activity, so online/offline
logic matches the rest of the app.

**Productivity today = the active-ratio proxy** (`active / (active + idle)`) read
from `activity_logs`. When the `productivity_reports` rollup lands, point the
`averageProductivity`/`dailyProductivity`/`departmentPerformance` methods at
those pre-aggregated rows — the component contracts don't change.

## 15.7 Authorization

Each component's `mount()` calls `abort_unless($user->can(...))` — `view
attendance` for the cards/table, `view reports` for the charts — so even if a
component is embedded elsewhere, org-wide data stays admin-only.

## 15.8 Try it

```bash
php artisan migrate:fresh --seed     # roles + admin
# (seed some employees, computers, and activity_logs, or let agents report)
php artisan serve
```

Sign in as `admin@treck.test` / `password` → `/dashboard` renders the admin
dashboard. With no activity data yet, cards show zeros and charts render empty
bars; as agents report (doc 13) the numbers populate live.
