# 18. Aggregation Rollups & Demo Seeding

The final layer: scheduled jobs that turn raw `activity_logs` into the derived
`attendance` and `productivity_reports` rows the dashboard/reports consume, plus
factories and a demo seeder so the whole system shows real numbers end-to-end.

## 18.1 Delivered files

| File | Purpose |
| ---- | ------- |
| `database/migrations/…100009_create_productivity_reports_table.php` | Aggregate table (per employee/period) |
| `app/Models/ProductivityReport.php` | Model + scopes |
| `app/Services/Attendance/AttendanceService.php` | Derive daily attendance |
| `app/Services/Productivity/ProductivityService.php` | Generate daily productivity |
| `app/Jobs/RollUpDailyAttendance.php` | Queued attendance rollup |
| `app/Jobs/GenerateDailyProductivity.php` | Queued productivity rollup |
| `app/Console/Commands/RunDailyRollup.php` | `treck:daily-rollup {date?}` |
| `database/factories/*.php` | Department, Employee, Computer, ActivityLog, ApplicationUsage |
| `database/seeders/DemoDataSeeder.php` | Realistic demo data + rollups |
| `database/seeders/DatabaseSeeder.php` | Calls RolePermission + (non-prod) Demo |

## 18.2 Attendance rollup

`AttendanceService::deriveDaily($date)` groups the day's `activity_logs` per
employee and upserts one `attendance` row each (all employees — those with no
session get `absent`). It computes:

- `first_in_at` = `MIN(login_at)`, `last_out_at` = `MAX(logout_at)`
- `active_seconds` / `idle_seconds` = sums; `work_seconds` = active + idle
- **status** from `config('treck.attendance.*')`:
  - `half_day` if work < half a full day,
  - `late` if first-in is past `workday_start` + `late_grace_minutes`,
  - else `present`; `absent` when there was no activity.

Manual corrections (`is_corrected = true`) are never overwritten.

## 18.3 Productivity rollup

`ProductivityService::generateDaily($date)` writes a daily
`productivity_reports` row per active employee:

- With app-usage data: sums `productive` / `unproductive` / `neutral` seconds
  and `score = productive / active`.
- Without it: falls back to the **active-ratio proxy**
  (`active / (active + idle)`) — the same proxy the dashboard/reports already
  use — so numbers are consistent and meaningful before app tracking is on.

## 18.4 Scheduling

`treck:daily-rollup` runs both rollups for a date (default today). Schedule in
`routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('treck:daily-rollup')->hourly();  // keep today near-live
Schedule::command('treck:daily-rollup '.now()->subDay()->toDateString())
    ->dailyAt('00:30');                             // finalize yesterday
```

The jobs implement `ShouldQueue`, so with a queue worker they run off-request;
the command uses `dispatchSync` for immediate CLI feedback.

## 18.5 Factories & demo seeding

`DemoDataSeeder` (local/staging only — guarded out of production in
`DatabaseSeeder`) creates 4 departments and 15 employees (each with a user +
`employee` role and a computer), ~2 weeks of weekday sessions with app usage,
marks a few computers online, then runs the rollups for each day so
`attendance` and `productivity_reports` are populated.

```bash
php artisan migrate:fresh --seed
# → admin@treck.test / password, 15 employees, ~2 weeks of activity,
#   attendance + productivity rolled up, ~6 computers online now.
php artisan serve
```

Now `/dashboard` shows real cards/table/charts, and `/reports` returns
populated daily/weekly/monthly data with working Excel/PDF export.

## 18.6 Manual run

```bash
php artisan treck:daily-rollup                 # today
php artisan treck:daily-rollup 2026-07-14      # a specific day
```

## 18.7 Notes

- Requires `config('treck.attendance.*')` (doc 02 §2.3); the service falls back
  to sensible defaults (09:00 start, 15-min grace, 8h day) if absent.
- The dashboard and ReportService still read the active-ratio proxy from
  `activity_logs`; they can be repointed at `productivity_reports` now that the
  table is populated, without changing their component/response contracts.
- Weekly/monthly `productivity_reports` can be added later by aggregating the
  daily rows; on-the-fly weekly/monthly reporting already works via
  `ReportService` bucketing.
