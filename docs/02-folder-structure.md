# 2. Laravel Folder Structure

Laravel 11 ships a slimmer skeleton (no `app/Http/Kernel.php`, no
`app/Console/Kernel.php`; middleware, routing, and scheduling are configured in
`bootstrap/app.php` and `routes/console.php`). We build on that skeleton with a
**layered, service/action-oriented** structure — pragmatic and readable, without
the ceremony of full DDD.

## 2.1 Guiding patterns

- **Controllers stay thin** — validate (via Form Requests), authorize (via
  Policies), delegate to a Service or Action, return a Resource.
- **Actions** encapsulate a single write use-case (e.g. `StartWorkSession`,
  `RecordActivityBatch`). Easy to test and reuse from controllers, jobs, and
  console commands.
- **Services** hold multi-step domain logic and orchestration
  (e.g. `AttendanceService`, `ProductivityCalculator`).
- **DTOs** carry validated, typed data across boundaries (agent payload →
  action).
- **Enums** model fixed vocabularies (`DeviceStatus`, `AttendanceStatus`,
  `ProductivityRating`).
- **Jobs** run ingestion/aggregation off the request cycle.
- **Policies** enforce role + team-scope authorization consistently.

## 2.2 Directory tree

```
treck/
├── app/
│   ├── Actions/                      # Single-purpose write use-cases
│   │   ├── Agent/
│   │   │   ├── RegisterDevice.php
│   │   │   ├── StartWorkSession.php
│   │   │   ├── EndWorkSession.php
│   │   │   └── RecordActivityBatch.php
│   │   ├── Attendance/
│   │   │   ├── DeriveDailyAttendance.php
│   │   │   └── CorrectAttendance.php
│   │   └── Productivity/
│   │       └── ScoreEmployeeDay.php
│   │
│   ├── DataObjects/                   # DTOs (typed, immutable)
│   │   ├── HeartbeatData.php
│   │   ├── SessionEventData.php
│   │   └── AppUsageData.php
│   │
│   ├── Enums/
│   │   ├── DeviceStatus.php           # online | idle | locked | offline
│   │   ├── AttendanceStatus.php       # present | late | absent | half_day | on_leave
│   │   ├── ProductivityRating.php     # productive | unproductive | neutral
│   │   └── UserRole.php               # super_admin | admin | manager | employee
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── V1/
│   │   │   │   │   ├── Agent/         # Device-token authenticated
│   │   │   │   │   │   ├── DeviceRegistrationController.php
│   │   │   │   │   │   ├── WorkSessionController.php
│   │   │   │   │   │   ├── HeartbeatController.php
│   │   │   │   │   │   ├── AppUsageController.php
│   │   │   │   │   │   └── AgentConfigController.php
│   │   │   │   │   └── User/          # User-token authenticated
│   │   │   │   │       ├── AuthController.php
│   │   │   │   │       ├── EmployeeController.php
│   │   │   │   │       ├── AttendanceController.php
│   │   │   │   │       ├── ActivityController.php
│   │   │   │   │       └── ReportController.php
│   │   │   └── Auth/                  # Breeze web controllers
│   │   ├── Middleware/
│   │   │   ├── EnsureAgentToken.php
│   │   │   └── SetOrganizationContext.php
│   │   ├── Requests/                  # Form Requests (validation)
│   │   │   ├── Agent/
│   │   │   │   ├── HeartbeatBatchRequest.php
│   │   │   │   └── SessionEventRequest.php
│   │   │   └── Attendance/
│   │   │       └── CorrectAttendanceRequest.php
│   │   └── Resources/                 # API response transformers
│   │       ├── EmployeeResource.php
│   │       ├── AttendanceResource.php
│   │       └── DeviceResource.php
│   │
│   ├── Livewire/                      # Dashboard components (see doc 06)
│   │   ├── Dashboard/
│   │   ├── Employees/
│   │   ├── Attendance/
│   │   ├── Devices/
│   │   ├── Activity/
│   │   ├── Reports/
│   │   └── Settings/
│   │
│   ├── Jobs/
│   │   ├── ProcessActivityBatch.php
│   │   ├── ReconcileStaleSessions.php
│   │   ├── RollUpDailyAttendance.php
│   │   └── GenerateProductivityReport.php
│   │
│   ├── Models/
│   │   ├── User.php
│   │   ├── Employee.php
│   │   ├── Department.php
│   │   ├── Team.php
│   │   ├── Device.php
│   │   ├── WorkSession.php
│   │   ├── ActivityHeartbeat.php
│   │   ├── IdlePeriod.php
│   │   ├── Attendance.php
│   │   ├── Application.php
│   │   ├── AppUsageLog.php
│   │   ├── ProductivityReport.php
│   │   ├── Screenshot.php
│   │   ├── Setting.php
│   │   └── AuditLog.php
│   │
│   ├── Policies/
│   │   ├── EmployeePolicy.php
│   │   ├── AttendancePolicy.php
│   │   ├── DevicePolicy.php
│   │   └── ReportPolicy.php
│   │
│   ├── Services/
│   │   ├── Attendance/AttendanceService.php
│   │   ├── Activity/ActivityAggregator.php
│   │   ├── Productivity/ProductivityCalculator.php
│   │   ├── Device/DeviceStatusService.php     # Redis live status
│   │   └── Reporting/ReportBuilder.php
│   │
│   ├── Support/                       # Cross-cutting helpers
│   │   └── Scopes/TeamScope.php
│   │
│   └── Providers/
│       └── AppServiceProvider.php     # bindings, gates, morph map
│
├── bootstrap/
│   └── app.php                        # middleware aliases, routing, scheduling (L11)
│
├── config/
│   ├── sanctum.php
│   ├── permission.php                 # Spatie
│   ├── treck.php                      # domain config: idle threshold, work hours, retention
│   └── queue.php
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│       ├── RolePermissionSeeder.php
│       └── ApplicationCatalogSeeder.php
│
├── routes/
│   ├── web.php                        # Breeze + Livewire dashboard
│   ├── api.php                        # agent + user API groups
│   └── console.php                    # scheduled commands (L11)
│
├── resources/
│   ├── views/
│   │   ├── layouts/                   # app shell (sidebar, topbar)
│   │   └── livewire/                  # component blade views
│   ├── css/
│   └── js/
│
├── tests/
│   ├── Feature/
│   │   ├── Api/Agent/
│   │   ├── Api/User/
│   │   └── Livewire/
│   └── Unit/
│       ├── Services/
│       └── Actions/
│
└── docs/                             # this documentation
```

## 2.3 Where domain config lives

A dedicated `config/treck.php` centralizes tunables so they are not scattered as
magic numbers:

```php
return [
    'activity' => [
        'heartbeat_interval_seconds' => env('TRECK_HEARTBEAT_INTERVAL', 60),
        'idle_threshold_seconds'     => env('TRECK_IDLE_THRESHOLD', 300),
        'offline_grace_seconds'      => env('TRECK_OFFLINE_GRACE', 180),
    ],
    'attendance' => [
        'workday_start' => env('TRECK_WORKDAY_START', '09:00'),
        'late_grace_minutes' => env('TRECK_LATE_GRACE', 15),
        'full_day_hours' => env('TRECK_FULL_DAY_HOURS', 8),
    ],
    'screenshots' => [
        'enabled'          => env('TRECK_SCREENSHOTS', false),
        'interval_seconds' => env('TRECK_SCREENSHOT_INTERVAL', 600),
        'blur'             => env('TRECK_SCREENSHOT_BLUR', true),
    ],
    'retention' => [
        'raw_heartbeat_days' => env('TRECK_RAW_RETENTION', 90),
        'aggregate_days'     => env('TRECK_AGG_RETENTION', 730),
    ],
];
```

Most of these are also overridable per-organization via the `settings` table so
different clients/tenants can tune behavior without redeploying.

## 2.4 Example of the thin-controller pattern

```php
// app/Http/Controllers/Api/V1/Agent/HeartbeatController.php
final class HeartbeatController extends Controller
{
    public function store(
        HeartbeatBatchRequest $request,
        RecordActivityBatch $recordBatch,
    ): JsonResponse {
        $device = $request->user();               // resolved via agent token
        $batch  = HeartbeatData::collectionFrom($request->validated('samples'));

        $cursor = $recordBatch($device, $batch);  // fast insert + dispatch job

        return response()->json(['ack_cursor' => $cursor], Response::HTTP_ACCEPTED);
    }
}
```

The controller validates, authenticates, delegates to one Action, and returns —
no business logic inline.
