# 10. Eloquent Models Reference

The models in [`app/Models/`](../app/Models) follow current Laravel 11 best
practices:

- **Mass-assignment safety** via explicit `$fillable`.
- **`casts()` method** (Laravel 11 style) instead of the `$casts` property.
- **Native PHP enums** cast onto status/rating columns
  (see [`app/Enums/`](../app/Enums)).
- **Accessors** written with the `Illuminate\Database\Eloquent\Casts\Attribute`
  API (not the legacy `getXAttribute`).
- **Query scopes** for reusable, readable query fragments.
- **Soft deletes** on `Employee` and `Computer` to preserve history.

## 10.1 Enums

| Enum | Values | Cast on |
| ---- | ------ | ------- |
| `ComputerStatus` | online, idle, locked, offline | `computers.status`, `activity_logs.status` |
| `AttendanceStatus` | present, late, absent, half_day, on_leave | `attendance.status` |
| `ProductivityRating` | productive, unproductive, neutral | `application_usage.productivity` |
| `SessionEndReason` | logout, shutdown, timeout, reconciled | `activity_logs.end_reason` |
| `UserRole` | super_admin, admin, manager, employee | (Spatie role names; not a column) |

Each enum exposes `label()` (and status/rating enums also `color()`) for the
dashboard, plus `values()` for validation rules, e.g.:

```php
'status' => ['required', Rule::enum(ComputerStatus::class)],
// or: Rule::in(ComputerStatus::values())
```

## 10.2 Useful methods per model

### User
- **Scopes:** `active()`, `withRole($role)`
- **Helpers:** `isAdministrator()`
- **Relations:** `employee()`, `managedDepartments()`

### Department
- **Accessors:** `headcount` (uses `withCount('employees')` when eager-loaded)
- **Relations:** `manager()`, `employees()`

### Employee
- **Accessors:** `name`, `email` (proxied from the linked `User`)
- **Scopes:** `inDepartment($id)`, `search($term)`
- **Helpers:** `openSession()`, `attendanceFor($date)`, `isOnline()`
- **Relations:** `user()`, `department()`, `computers()`, `attendance()`,
  `activityLogs()`, `applicationUsage()`, `screenshots()`

### Computer
- **Accessors:** `isPaired`
- **Scopes:** `withStatus($status)`, `connected()`, `stale($minutes)`
- **Helpers:** `isOnline()`, `openSession()`, `markSeen($status)`
- **Relations:** `employee()`, `activityLogs()`, `applicationUsage()`,
  `screenshots()`

### Attendance
- **Accessors:** `workedHours`, `activeHours`, `idleHours`, `activeRatio`
- **Scopes:** `forDate($date)`, `forEmployee($id)`, `withStatus($status)`,
  `between($from, $to)`
- **Helpers:** `isPresent()`, `isLate()`
- **Relations:** `employee()`

### ActivityLog
- **Accessors:** `isOpen`, `durationSeconds` (live for in-progress sessions)
- **Scopes:** `open()`, `forDate($date)`, `forEmployee($id)`
- **Helpers:** `close($reason, $at)`
- **Relations:** `employee()`, `computer()`, `applicationUsage()`, `screenshots()`

### ApplicationUsage
- **Accessors:** `durationMinutes`
- **Scopes:** `rated($rating)`, `productive()`, `unproductive()`,
  `forDate($date)`, `forEmployee($id)`
- **Relations:** `employee()`, `computer()`, `activityLog()`

### Screenshot
- **Accessors:** `url`, `thumbnailUrl` (via `Storage::url()`)
- **Scopes:** `forEmployee($id)`, `forDate($date)`
- **Relations:** `employee()`, `computer()`, `activityLog()`

## 10.3 Examples

```php
// Live monitor: connected computers with their employee + user
Computer::connected()->with('employee.user')->get();

// Mark a workstation active on heartbeat
$computer->markSeen(ComputerStatus::Online);

// A department's present employees today
Attendance::forDate()->withStatus(AttendanceStatus::Present)
    ->whereHas('employee', fn ($q) => $q->inDepartment($deptId))
    ->with('employee.user')
    ->get();

// Productive minutes for an employee today
$employee->applicationUsage()->forDate()->productive()->sum('duration_seconds') / 60;

// Close an abandoned session during reconciliation
$session->close(SessionEndReason::Reconciled);

// Status badge in a Blade/Livewire view
<span class="badge badge-{{ $computer->status->color() }}">
    {{ $computer->status->label() }}
</span>
```
