# 6. Admin Dashboard Structure

The dashboard is built with **Livewire 3** (server-driven components) on top of
Breeze's Blade + Livewire starter, styled with **Tailwind CSS** and enhanced
with **Alpine.js** for small client-side interactions. Charts use a lightweight
JS charting library fed by Livewire.

## 6.1 Layout & navigation

```
┌───────────────────────────────────────────────────────────────┐
│  Topbar:  [logo]  search        live-clock   [alerts 🔔] [user] │
├────────────┬──────────────────────────────────────────────────┤
│  Sidebar   │                                                    │
│  ────────  │              Main content (Livewire page)          │
│  Dashboard │                                                    │
│  Employees │                                                    │
│  Devices   │                                                    │
│  Activity  │                                                    │
│  Attendance│                                                    │
│  Reports   │                                                    │
│  Settings  │                                                    │
└────────────┴──────────────────────────────────────────────────┘
```

The sidebar is role-filtered: an Employee sees only *Dashboard*, *My Activity*,
and *My Reports*; a Manager additionally sees team scopes; Admin/Super Admin see
everything.

## 6.2 Livewire component tree

```
app/Livewire/
├── Dashboard/
│   ├── Overview.php                # KPI cards + charts landing page
│   ├── KpiCards.php                # present today, active now, avg productivity
│   ├── LiveDevicesWidget.php       # polls Redis status (wire:poll)
│   └── ProductivityTrendChart.php
│
├── Employees/
│   ├── EmployeeList.php            # searchable, filterable, paginated table
│   ├── EmployeeForm.php            # create / edit (modal)
│   └── EmployeeProfile.php         # profile + attendance + productivity tabs
│
├── Devices/
│   ├── DeviceList.php              # devices + live status badges
│   ├── DevicePairing.php           # pair device ↔ employee, mint token
│   └── DeviceDetail.php
│
├── Activity/
│   ├── LiveMonitor.php             # real-time grid of who is online/idle/offline
│   └── ActivityTimeline.php        # per-employee active/idle timeline for a day
│
├── Attendance/
│   ├── AttendanceBoard.php         # date-range table by team/department
│   ├── AttendanceDetail.php        # day breakdown (sessions + idle)
│   └── AttendanceCorrection.php    # audited edit modal
│
├── Reports/
│   ├── ProductivityReport.php      # filters + table + chart + export
│   └── AttendanceSummary.php
│
└── Settings/
    ├── GeneralSettings.php         # work hours, thresholds, feature toggles
    ├── ApplicationCatalog.php      # classify apps productive/unproductive/neutral
    ├── RolesUsers.php              # manage users & roles
    └── AuditLogViewer.php
```

## 6.3 Pages & their purpose

| Page | Primary component | Shows |
| ---- | ----------------- | ----- |
| **Dashboard** | `Dashboard/Overview` | KPIs (present today, currently active, avg productivity), live device widget, productivity trend, recent alerts |
| **Employees** | `Employees/EmployeeList` | Directory with department/team filters; drill into a profile |
| **Devices** | `Devices/DeviceList` | Registered PCs, live status, pairing & token controls |
| **Activity (Live Monitor)** | `Activity/LiveMonitor` | Real-time grid of every in-scope workstation with status color + last-seen |
| **Attendance** | `Attendance/AttendanceBoard` | Daily attendance matrix, punctuality, corrections |
| **Reports** | `Reports/ProductivityReport` | Productivity & attendance analytics with export |
| **Settings** | `Settings/GeneralSettings` | Thresholds, work hours, feature flags, app catalog, roles, audit |

## 6.4 Real-time strategy

Two complementary mechanisms keep the dashboard fresh without hammering MySQL:

1. **`wire:poll` (default)** — the `LiveDevicesWidget` and `LiveMonitor` poll
   every ~10–15s and read device status from **Redis**, not the OLTP tables.
   Simple, robust, no extra infrastructure.

2. **WebSockets (optional upgrade)** — with **Laravel Reverb** + **Echo**, the
   ingestion pipeline broadcasts a `DeviceStatusChanged` event so the live
   monitor updates instantly. This is a drop-in enhancement of the same
   components and is gated behind a feature flag.

```php
// Livewire/Dashboard/LiveDevicesWidget.php
class LiveDevicesWidget extends Component
{
    public function render(DeviceStatusService $status)
    {
        return view('livewire.dashboard.live-devices', [
            'devices' => $status->snapshotForUser(auth()->user()), // reads Redis
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/dashboard/live-devices.blade.php --}}
<div wire:poll.15s>
    @foreach ($devices as $d)
        <x-device-badge :status="$d->status" :name="$d->hostname" :seen="$d->last_seen_at" />
    @endforeach
</div>
```

## 6.5 Authorization in the UI

Every Livewire component enforces authorization on mount and scopes its queries:

```php
public function mount(): void
{
    $this->authorize('viewAny', Employee::class);   // policy check
}

protected function baseQuery(): Builder
{
    return Employee::query()->tap(new TeamScope(auth()->user())); // team/department scope
}
```

Buttons and menu items are also gated with `@can` so users never see actions
they cannot perform.

## 6.6 UX conventions

- **Status colors**: green = active, amber = idle, slate = locked, red = offline.
- **Empty & loading states** on every table (`wire:loading`).
- **Server-side filtering/pagination** — never load full datasets client-side.
- **Confirmations** on destructive/auditable actions (attendance correction,
  token revocation) with a reason field that is written to `audit_logs`.
- **Responsive**: sidebar collapses on small screens; tables scroll horizontally.
