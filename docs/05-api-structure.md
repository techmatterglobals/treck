# 5. API Structure

The API is versioned under `/api/v1` and split into two authenticated groups
with **separate Sanctum token audiences**:

- **Agent API** (`/api/v1/agent/*`) — device tokens, ability `agent:report`.
- **User API** (`/api/v1/*`) — user tokens, abilities derived from role.

Public endpoints are limited to device registration and user login.

## 5.1 Token ability matrix

| Token type | Abilities | Can access |
| ---------- | --------- | ---------- |
| Device (agent) | `agent:report` | agent session/heartbeat/app-usage/status/config endpoints only |
| User: Employee | `employee:self` | own attendance, own activity, own reports |
| User: Manager | `team:read` | team members' data (read), correction requests |
| User: Admin/HR | `org:manage` | employees, devices, attendance edits, reports |
| User: Super Admin | `*` | everything incl. settings, roles |

Abilities are checked with Sanctum's `tokenCan()` plus a Policy for record-level
and team-scope authorization.

## 5.2 Standard conventions

- **Format**: JSON; responses wrapped by API Resources.
- **Errors**: RFC-7807-ish envelope — `{ "message": "...", "errors": { field: [...] } }`
  (Laravel's default 422 shape), correct HTTP status codes.
- **Pagination**: cursor pagination for large collections (`?cursor=...`).
- **Idempotency**: agent write batches carry an `Idempotency-Key` header and
  per-sample keys; replays are deduped.
- **Rate limiting**: named limiters — `agent` (generous, per-device) and
  `user` (per-user) — configured in `bootstrap/app.php`.
- **Versioning**: URI-versioned (`/v1`); breaking changes bump the prefix.

## 5.3 Agent API (device token)

Authenticated by `Authorization: Bearer <device-token>` + `EnsureAgentToken`
middleware.

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| POST | `/api/v1/agent/register` | **Public.** Register a device by fingerprint; returns a pairing code (token minted on pairing) |
| GET  | `/api/v1/agent/config` | Fetch current agent config (intervals, thresholds, feature flags) |
| POST | `/api/v1/agent/sessions/start` | Report a PC login → opens a `work_session` |
| POST | `/api/v1/agent/sessions/end` | Report a PC logout/shutdown → closes the session |
| POST | `/api/v1/agent/heartbeats` | Submit a **batch** of activity samples |
| POST | `/api/v1/agent/app-usage` | Submit a **batch** of foreground app-usage intervals (optional feature) |
| POST | `/api/v1/agent/status` | Push an immediate status change (locked/unlocked) |
| POST | `/api/v1/agent/screenshots` | Upload a screenshot (only if feature enabled) |

### Example: heartbeat batch

`POST /api/v1/agent/heartbeats`

```jsonc
// Request
{
  "session_uuid": "8f2b...e1",          // client-side session correlation id
  "samples": [
    { "key": "b1e...", "at": "2026-07-15T09:00:00Z", "active": true  },
    { "key": "b2f...", "at": "2026-07-15T09:01:00Z", "active": true  },
    { "key": "b3a...", "at": "2026-07-15T09:02:00Z", "active": false }
  ]
}
```

```jsonc
// 202 Accepted
{
  "ack_cursor": "2026-07-15T09:02:00Z",  // agent may drop buffered samples up to here
  "accepted": 3,
  "duplicates": 0
}
```

### Example: agent config

`GET /api/v1/agent/config` → `200`

```json
{
  "heartbeat_interval_seconds": 60,
  "idle_threshold_seconds": 300,
  "app_usage_enabled": true,
  "screenshots": { "enabled": false, "interval_seconds": 600, "blur": true },
  "server_time": "2026-07-15T09:00:00Z"
}
```

## 5.4 User API (user token)

Authenticated by `Authorization: Bearer <user-token>` + `auth:sanctum`. All
collection endpoints apply the caller's team/department scope.

### Auth

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| POST | `/api/v1/auth/login` | **Public.** Issue a user token |
| POST | `/api/v1/auth/logout` | Revoke current token |
| GET  | `/api/v1/auth/me` | Current user + roles + abilities |

### Employees

| Method | Endpoint | Ability |
| ------ | -------- | ------- |
| GET | `/api/v1/employees` | `team:read` / `org:manage` |
| POST | `/api/v1/employees` | `org:manage` |
| GET | `/api/v1/employees/{employee}` | scoped |
| PATCH | `/api/v1/employees/{employee}` | `org:manage` |
| DELETE | `/api/v1/employees/{employee}` | `org:manage` (soft delete) |

### Devices

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| GET | `/api/v1/devices` | List devices + live status |
| POST | `/api/v1/devices/{device}/pair` | Pair device to employee, mint agent token |
| POST | `/api/v1/devices/{device}/revoke` | Revoke device token |

### Attendance

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| GET | `/api/v1/attendance` | Query by date range / employee / team |
| GET | `/api/v1/attendance/{employee}/{date}` | Single day detail (sessions + idle timeline) |
| PATCH | `/api/v1/attendance/{attendance}` | Correct attendance (audited) |

### Activity

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| GET | `/api/v1/activity/live` | Live status of all in-scope devices |
| GET | `/api/v1/activity/{employee}/timeline` | Active/idle timeline for a day |

### Reports

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| GET | `/api/v1/reports/productivity` | Productivity report (filters: employee/team/dept, range, period) |
| GET | `/api/v1/reports/attendance-summary` | Attendance summary + punctuality |
| POST | `/api/v1/reports/export` | Queue an export (CSV/PDF), returns a job id |

### Example: productivity report

`GET /api/v1/reports/productivity?team_id=3&from=2026-07-01&to=2026-07-15&period=daily`

```jsonc
{
  "data": [
    {
      "employee": { "id": 42, "name": "A. Rahman", "code": "EMP-0042" },
      "period": "2026-07-14",
      "work_seconds": 28800,
      "active_seconds": 24100,
      "idle_seconds": 4700,
      "productive_seconds": 19800,
      "unproductive_seconds": 2100,
      "neutral_seconds": 2200,
      "productivity_score": 82.15,
      "attendance_status": "present"
    }
  ],
  "meta": { "next_cursor": null }
}
```

## 5.5 Route registration (illustrative)

```php
// routes/api.php
Route::prefix('v1')->group(function () {

    // ---- Agent (device tokens) ----
    Route::prefix('agent')->group(function () {
        Route::post('register', [DeviceRegistrationController::class, 'store']); // public

        Route::middleware(['auth:sanctum', 'ability:agent:report', EnsureAgentToken::class])
            ->group(function () {
                Route::get('config', AgentConfigController::class);
                Route::post('sessions/start', [WorkSessionController::class, 'start']);
                Route::post('sessions/end',   [WorkSessionController::class, 'end']);
                Route::post('heartbeats',     [HeartbeatController::class, 'store'])
                     ->middleware('throttle:agent');
                Route::post('app-usage',      [AppUsageController::class, 'store']);
                Route::post('status',         [WorkSessionController::class, 'status']);
            });
    });

    // ---- User (user tokens) ----
    Route::post('auth/login', [AuthController::class, 'login']); // public

    Route::middleware(['auth:sanctum', 'throttle:user'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me',      [AuthController::class, 'me']);

        Route::apiResource('employees', EmployeeController::class);
        Route::get('attendance',                 [AttendanceController::class, 'index']);
        Route::patch('attendance/{attendance}',  [AttendanceController::class, 'correct']);
        Route::get('activity/live',              [ActivityController::class, 'live']);
        Route::get('reports/productivity',       [ReportController::class, 'productivity']);
    });
});
```

Rate limiters are declared in `bootstrap/app.php` (Laravel 11):

```php
RateLimiter::for('agent', fn (Request $r) =>
    Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('user', fn (Request $r) =>
    Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
```
