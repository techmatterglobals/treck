# 31. Multi-User Computers & Manager Hierarchy (Phase 11)

This phase extends Treck with a **Manager → Employee organization hierarchy**,
**role-based dashboard scoping**, and **shared (multi-user) computers** where the
active employee is resolved automatically from the logged-in Windows account.

It is a strictly **additive, backward-compatible** extension: every existing
capability (registration, device auth, heartbeats, presence, activity,
application usage, screenshots, notifications, dashboard, reports) and every
existing single-user computer keeps working unchanged.

> **Doc numbering note.** The brief referenced
> `docs/30-multi-user-computer-and-manager-hierarchy.md`, but `docs/30` is the
> Phase 10 production-release guide, so this document is numbered **31**.

---

## 31.1 Organization hierarchy

Three roles (Spatie Laravel-Permission string roles, centralized in
`App\Enums\UserRole`):

| Role | String | Access |
|------|--------|--------|
| **Super Admin** | `admin` | Unrestricted, organization-wide (the existing `admin` role — kept verbatim for backward compatibility). |
| **Manager** | `manager` | Only their assigned employees and everything belonging to them. |
| **Employee** | `employee` | Self-service only (own profile / activity). |

```
Super Admin
├── Manager (Ali)
│     ├── Hassan
│     ├── Umar
│     └── Ahmed
├── Manager (Nasir)
│     ├── Zain
│     └── Mike
└── (optional) unassigned employees
```

A Manager supervises many employees; each employee belongs to **one** manager
(`employees.manager_user_id`) or none.

`User` helpers: `isSuperAdmin()` (alias of the existing `isAdministrator()`),
`isManager()`, `isEmployee()`, and `managedEmployees()` (`hasMany(Employee,
'manager_user_id')`).

---

## 31.2 Manager workflow (Manager Management)

Super-Admin-only screen at **`/admin/managers`** ("Managers" in the nav),
driven by the `ManagerManagement` Livewire component over `ManagerService`
(all mutations live in the service — the controller/component stay thin):

- **Create Manager** — new login account with the `manager` role.
- **Promote** an existing user (e.g. an employee) to Manager.
- **Demote** a Manager to Employee — their team is automatically unassigned
  (each `manager_user_id` cleared) so no employee points at a non-manager.
- **Assign / Transfer / Remove** employees to/from a manager
  (`employees.manager_user_id`).
- **Team size** and a live **activity summary** (team size + online now) per
  manager.

---

## 31.3 Employee assignment

Assignment is a single indexed column, `employees.manager_user_id`, set through
Manager Management. Transferring an employee is the same operation with a
different manager; removing sets it to null (unassigned). No historical data
moves — reports and dashboards recompute from the current assignment.

---

## 31.4 Shared computer architecture

A physical computer can be used by several employees across shifts. A new table
maps each Windows account seen on a machine to an employee:

```
one computer ──▶ many computer_users (one per windows_username) ──▶ one employee each
```

Example — `PC-100100027`:

| windows_username | employee | manager |
|------------------|----------|---------|
| `morning_user`   | Hassan   | Ali     |
| `evening_user`   | Zain     | Nasir   |

The same machine attributes the morning shift to Hassan and the evening shift to
Zain automatically.

---

## 31.5 Windows username resolution

The agent reports only the **Windows identity**; Laravel resolves everything
else. The identity is already carried on every event: the agent folds
`Environment.UserName` into each payload via its `EventSource`/`SourceStamp`
(keys `SourceUser` **and** the explicit `WindowsUsername` alias added this
phase), and screenshots carry `source_user`/`windows_username` on the multipart
form.

`App\Services\Agent\EmployeeResolver::resolve(Computer, ?string $windowsUsername)`:

```
normalize username (trim, strip DOMAIN\, reject blank / machine$ / service accounts)
   │
   ├─ null  → legacy path: attribute to computer.employee_id (no mapping row)
   │
   └─ real  → upsert computer_users(computer_id, windows_username), touch last_seen
                 ├─ known employee            → resolved
                 ├─ first account on an assigned computer → adopt computer.employee_id
                 └─ otherwise                 → pending (employee_id null) + notify Super Admin
```

The resolver is called from `AgentEventIngestionService` (events) and
`ScreenshotService` (uploads). The resolved `employee_id` is stamped on the
`agent_events` row and flows through the existing projectors into
`application_usage`, presence, etc. — no other pipeline code changed.

### Resolution sequence (event)

```
Agent ──POST /api/agent/events (payload incl. SourceUser)──▶ EventIngestionController
   └▶ AgentEventIngestionService.ingest()
        ├─ EmployeeResolver.resolve(computer, SourceUser)
        │     └─ computer_users lookup / upsert  (indexed, no event scan)
        ├─ AgentEvent.employee_id = resolved id
        └─ projectors (presence / application_usage) inherit employee_id
```

---

## 31.6 Database schema

**`employees`** (extended, additive):

| Column | Type | Notes |
|--------|------|-------|
| `manager_user_id` | FK users, nullable, nullOnDelete, indexed | supervising manager |
| `status` | string(20), default `active`, indexed | lifecycle flag |

**`computer_users`** (new):

| Column | Type | Notes |
|--------|------|-------|
| `computer_id` | FK computers, cascade | |
| `employee_id` | FK employees, **nullable**, nullOnDelete, indexed | null = pending |
| `windows_username` | string(191) | |
| `last_seen_at` / `last_login_at` / `last_logout_at` | timestamp, nullable | |
| `is_active` | bool, default true | |
| unique | (`computer_id`, `windows_username`) | one mapping per account |
| index | (`computer_id`, `is_active`) | active-account lookups |

Also: a seeded `notification_rules` row for `system.unknown_user` (in-app +
email, throttled hourly) so the Super Admin is alerted about unmapped accounts.

---

## 31.7 Security model

- **Reuses existing authentication** (session + Sanctum) unchanged.
- **Authorization** via role middleware, policies, and scoped queries:
  - Monitoring routes are `role:admin|manager`; notifications and Manager
    Management stay `role:admin`.
  - `EmployeePolicy` / `ScreenshotPolicy` admit a Manager only for their own
    team's records; detail pages (`ComputerPresenceDetail`, `ScreenshotViewer`)
    enforce per-item ownership.
- **Single source of truth for scoping**: `App\Services\Hierarchy\EmployeeVisibility`
  returns the visible employee/computer id set (null = unrestricted for Super
  Admin), consumed by every dashboard, filter and report — including exports, so
  a scoped user can never export out-of-scope data.
- A Manager can **never** reach another Manager's employees, computers,
  activity, screenshots or reports.

---

## 31.8 Dashboard changes

| Surface | Super Admin | Manager |
|---------|-------------|---------|
| Landing dashboard | `dashboard.admin` (org-wide) | `dashboard.manager` (team-scoped) |
| Presence board / detail | all | their team's computers only |
| Application usage | all | their team only |
| Screenshots (grid / viewer / download) | all | their team only |
| Employees list | all | their team only |
| Notifications | yes | no (org-wide alerts remain Super-Admin) |
| Manager Management | yes | no |

Scoping is applied in the shared services (`ApplicationUsageService`,
`ScreenshotService`, `ReportService`, `PresenceService`) and Livewire components
via the `ScopesToViewer` trait + `EmployeeVisibility`. Super Admin passes a
`null` restriction, so the organization-wide behavior is byte-for-byte unchanged.

---

## 31.9 Reports

- Existing productivity reports gain a **Manager** filter (Super Admin only) and
  are automatically scoped for managers; exports honor the same scope.
- New **Computer Usage History** report (`/reports/computer-usage`): per-computer
  session timeline (which employee used the machine and when) — the shared-computer
  shift view (e.g. `08:00 Hassan → 12:00 Zain → 17:00 Hassan`), built from the
  indexed `activity_logs` sessions.

---

## 31.10 Windows agent changes

Additive and backward-compatible:

- `SourceStamp` now also writes an explicit `WindowsUsername` alias alongside the
  existing `SourceUser` on every event payload.
- The screenshot multipart upload adds a `windows_username` field alongside
  `source_user`.

The agent still reports **only** the Windows identity (never an employee/manager
id). Older agents that send only `SourceUser`/`source_user` — or nothing —
continue to work: the server reads any of the aliases and falls back to the
computer's assigned employee when none is present. (Agent is `net8.0-windows`;
build/test on Windows or CI.)

---

## 31.11 Migration guide

1. `php artisan migrate` — adds `employees.manager_user_id` + `status`, creates
   `computer_users`, seeds the `system.unknown_user` rule. All additive/nullable;
   **no existing column is removed and no data is rewritten**.
2. `php artisan db:seed --class=RolePermissionSeeder` — idempotently adds the
   `manager` role (existing roles/users untouched).
3. Assign managers and (optionally) map shared-computer Windows accounts under
   **Managers** → Manager Management. Unmapped accounts appear as pending and the
   Super Admin is notified; historical events for a newly-mapped account relate
   automatically once mapped (future events resolve immediately).
4. No agent redeploy is required for existing single-user computers.

---

## 31.12 Manual verification

1. Create two managers; assign Hassan to Ali, Zain to Nasir.
2. Sign in as Ali → dashboard, presence, app-usage, screenshots and reports show
   **only** Hassan; Zain never appears. Repeat as Nasir (only Zain).
3. On a shared computer, have `morning_user` and `evening_user` report events →
   confirm `computer_users` maps each and events attribute to Hassan / Zain.
4. Introduce a new Windows account → a pending `computer_users` row is created,
   events keep flowing, and the Super Admin receives an "unknown user" alert; map
   it in Manager Management.
5. Confirm a legacy single-user computer (agent without a username / a system
   account) still attributes to the computer's assigned employee.
6. Confirm the Super Admin still sees the whole organization.

---

## 31.13 Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| Manager sees nothing | No employees assigned (`manager_user_id`) yet |
| Events land as "pending" | Windows account not mapped; map it in Manager Management |
| Shared PC attributes everything to one person | Only one `computer_users` mapping exists, or the agent reports a single account (e.g. a shared login) |
| Manager 403 on a computer/screenshot | It belongs to another manager's employee (correct) |
| Super Admin unexpectedly scoped | The account lacks the `admin` role |

---

## 31.14 Performance

- Resolution is an indexed unique lookup on (`computer_id`, `windows_username`) —
  it never scans historical events.
- Scoping uses bounded, indexed id sets (`employees.manager_user_id`,
  `employee_id` columns already indexed on every event/usage/screenshot table).
- No change to heartbeat frequency or the synchronization pipeline.
- Scales to thousands of employees / hundreds of managers / shared computers.

---

## 31.15 Known limitations

- **Notifications** remain Super-Admin-scoped; per-manager notification routing
  (delivering a team's alerts to its manager) is a future phase.
- Historical re-attribution on mapping a previously-pending account applies going
  forward; a backfill of already-stored pending events is left as an operational
  script (future enhancement).
- A single shared Windows login used by multiple people cannot be split further
  than that account (the OS identity is the finest signal available).

---

## 31.16 Phase 12 note — download attribution

File Download Monitoring (Phase 12) reuses this phase's Windows-username
resolution: each `file_download` event is attributed to the employee behind the
active Windows account, and the downloads dashboard/reports are scoped by the
same `EmployeeVisibility` rules (Super Admin all; Manager their team). Full
design: [`docs/32-file-download-monitoring.md`](32-file-download-monitoring.md).
