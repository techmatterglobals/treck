# 26. Application Usage Tracking (Phase 7)

Application usage tracking records **which foreground application** an employee
uses and **for how long**, as a stream of completed *sessions*. It is built
entirely on top of the infrastructure delivered in earlier phases — the Windows
agent's offline queue, the `POST /api/agent/events` ingest pipeline, and the
admin dashboard — adding one new agent-event kind (`app_usage`) and one read
model. No new synchronization mechanism was introduced.

> **Privacy first.** The system collects **usage metadata only**: process name,
> executable name, a sanitized window title, timestamps and duration. It never
> captures keystrokes, mouse input, clipboard contents, screen contents, file
> contents, browser history, passwords or any typed text. See §26.7.

---

## 26.1 Architecture overview

```
 Windows desktop (interactive session)                 Laravel server
 ─────────────────────────────────────                 ──────────────────────────
 WinEvent hooks ─▶ WindowsApplicationTracker
   (foreground / title change, no polling)
        │ ApplicationChanged
        ▼
 ApplicationSessionManager  ──SessionCompleted──▶ offline queue (SQLite)
   (session state machine)                              │  kind = app_usage
                                                        ▼  POST /api/agent/events
                                             EventIngestionController
                                                        ▼
                                             AgentEventIngestionService
                                              (store agent_events, idempotent)
                                                        │ new app_usage event
                                                        ▼
                                             ApplicationUsageProjector
                                              (one application_usage row / session)
                                                        ▼
                                             ApplicationUsageService (read model)
                                                        ▼
                                   Dashboard  ◀── /application-usage  (admin only)
                                   Computer details page (current app, history, …)
```

### Components

**Agent (`agent/Applications/`)**

| Type | Role |
| ---- | ---- |
| `IActiveWindowService` / `WindowsActiveWindowService` | Read the foreground app on demand via Win32 (§26.4). Metadata only. |
| `IApplicationTracker` / `WindowsApplicationTracker` | Install WinEvent hooks; raise `ApplicationChanged` the instant focus or title changes. **No polling.** |
| `IApplicationSessionManager` / `ApplicationSessionManager` | Platform-agnostic state machine turning the change stream into completed sessions (§26.3). |
| `ApplicationInfo`, `ApplicationChangedEventArgs`, `ApplicationUsageEvent` | Snapshot, event args, and the uploaded completed-session payload. |
| `ApplicationTrackingOptions` | Ignore rules, minimum session duration, title length cap. |
| `Worker` (modified) | Wires tracker → session manager → offline queue; flushes on lock/logoff/shutdown/stop. |

**Server**

| Type | Role |
| ---- | ---- |
| `App\Enums\AgentEventKind::AppUsage` | The `app_usage` event kind. |
| `App\Services\Presence\ApplicationUsageProjector` | Idempotent projection into `application_usage`. |
| `App\Services\Agent\AgentEventIngestionService` (modified) | Routes `app_usage` to the projector (not the presence projector). |
| `App\Models\ApplicationUsage` (extended) | Adds `session_id`, `ended_at`, scopes and accessors. |
| `App\Services\Reporting\ApplicationUsageService` | Read model + reporting (summary, top apps, per-employee/-department, daily, recent). |
| `App\DataObjects\AppUsageFilter` | Immutable filter (date range + employee/computer/department/application). |
| `App\Livewire\ApplicationUsage\ApplicationUsageDashboard` | Admin dashboard component. |
| `App\Http\Controllers\ApplicationUsageController` | Thin page controller. |
| `App\Livewire\Presence\ComputerPresenceDetail` (extended) | Adds the application-usage panel to the computer details page. |

### Design principles honored

- **Reuse, don't duplicate.** The agent's offline queue and the server's
  idempotent `/api/agent/events` ingest are reused verbatim; `app_usage` is just
  another kind. Controllers stay thin; logic lives in services.
- **Never scan raw events for reads.** Just as presence reads
  `computer_presence`, usage reads the materialized `application_usage` rows via
  indexed scopes.
- **Lightweight agent.** Event-driven detection (WinEvent hooks) means near-zero
  idle CPU; the agent transmits completed sessions only, not per-second samples.

---

## 26.2 Tracking lifecycle

1. **Start.** `Worker` starts `WindowsApplicationTracker`, which installs the
   hooks and immediately emits the *current* foreground app so the first session
   opens right away.
2. **Change.** Each foreground switch or window-title change fires a WinEvent;
   the tracker reads the new foreground app and raises `ApplicationChanged`.
3. **Sessionize.** `ApplicationSessionManager.Track(app, now)` compares the app
   against the open session and closes/opens sessions accordingly (§26.3).
4. **Complete.** When a session closes, `SessionCompleted` fires with an
   `ApplicationUsageEvent`; the `Worker` enqueues it as an `app_usage` offline
   event.
5. **Sync.** The existing `SyncWorker` drains the queue to
   `POST /api/agent/events`; the server stores and projects it.
6. **Flush.** On `Lock`/`Logoff`/`Shutdown` (and on agent stop) the `Worker`
   calls `Flush(now)` so the open session is completed and not lost.

---

## 26.3 Session state machine

A **session** is one contiguous period on the *same process* **and** the *same
window title*. The session key is `ProcessName + ExecutableName + WindowTitle`.

`ApplicationSessionManager` holds at most one open session:

| Observed (`Track`) | Action |
| ------------------ | ------ |
| Same key as the open session | No-op (session continues). |
| Different process **or** title | Close the open session (emit), open a new one. |
| `null` (no foreground / locked) | Close the open session (emit), open nothing. |
| Ignored app (§26.6) | Treated as `null`. |
| `Flush(now)` | Close the open session (emit), open nothing. |

```
                  different process OR different title
   ┌─────────┐  ────────────────────────────────────▶  ┌──────────────┐
   │  none   │                                          │ open session │──┐ same
   └─────────┘  ◀────────────────────────────────────  └──────────────┘  │ key
        ▲     null / ignored / Flush (emit completed)         ▲───────────┘ (no-op)
        └─────────────────────────────────────────────────────
```

On close, the manager computes `DurationSeconds = EndedAt − StartedAt`,
**drops** sessions shorter than `MinimumSessionSeconds` (rapid Alt-Tab flicker),
truncates the title to `MaxWindowTitleLength`, stamps a fresh `SessionId` (GUID),
and raises `SessionCompleted`. **Only completed sessions are ever transmitted** —
the open session is never sent.

The manager is platform-agnostic (no Win32), so it is fully unit-tested
(`ApplicationSessionManagerTests`) on any OS.

---

## 26.4 Windows API usage

All native calls read window/process **identity only**.

| API | Use |
| --- | --- |
| `SetWinEventHook(EVENT_SYSTEM_FOREGROUND, …)` | Fire when a different window comes to the foreground. |
| `SetWinEventHook(EVENT_OBJECT_NAMECHANGE, …)` | Fire when the foreground window's title changes (e.g. browser tab switch). Filtered to `OBJID_WINDOW`. |
| `GetForegroundWindow()` | Handle of the active window. |
| `GetWindowThreadProcessId(hWnd, out pid)` | Owning process id. |
| `GetWindowTextLength` / `GetWindowText` | Window title text (then sanitized). |
| `System.Diagnostics.Process` | Friendly process name + executable module name. |

Hooks are installed `WINEVENT_OUTOFCONTEXT | WINEVENT_SKIPOWNPROCESS` on a
dedicated background thread that runs a standard `GetMessage`/`DispatchMessage`
pump. `GetMessage` **blocks** until a hooked event (or `WM_QUIT` on stop)
arrives, so there is **no busy-wait / no polling**. The callback delegate is held
in a field for the hook's lifetime (so the GC cannot collect it).

Because these APIs observe the caller's interactive desktop, the agent runs in
the per-user session context (as described in [doc 17](17-windows-agent.md));
this matches how the idle detector and session monitor already operate.

---

## 26.5 Sync flow

`app_usage` reuses the M6 pipeline unchanged:

```
ApplicationSessionManager.SessionCompleted
   → Worker.Enqueue(OfflineEventKind.AppUsage, event, endedAt)
   → SqliteEventStore (local, chronological, survives restart/offline)
   → SyncWorker  →  AgentEventUploader  (maps AppUsage → "app_usage")
   → POST /api/agent/events   (bearer device token, agent:report)
   → AgentEventIngestionService  (store agent_events, idempotent per device)
       └─ if newly stored and kind == app_usage:
            ApplicationUsageProjector.project(event)
              → ApplicationUsage::firstOrCreate((computer_id, session_id), …)
```

**Idempotency is two-layer:**

1. `agent_events` is unique on `(computer_id, idempotency_key)` — a re-uploaded
   queue row is stored once.
2. `application_usage` is unique on `(computer_id, session_id)` — even if two
   different queue rows carried the same session, the row is created once.

Duplicate submissions return success (so the agent clears its local copy) but do
not create extra rows. The payload uses PascalCase keys and is read
case-tolerantly (also accepts snake_case); timestamps are parsed with Carbon;
`DurationSeconds` is derived from `StartedAt`/`EndedAt` when absent.

---

## 26.6 Configuration (agent)

`appsettings.json → ApplicationTracking`:

```json
{
  "Enabled": true,
  "IgnoredExecutables": [
    "explorer.exe", "SearchHost.exe", "SearchApp.exe", "LockApp.exe",
    "ShellExperienceHost.exe", "StartMenuExperienceHost.exe", "TextInputHost.exe"
  ],
  "IgnoredProcessNames": ["System", "Idle"],
  "MinimumSessionSeconds": 1,
  "MaxWindowTitleLength": 255
}
```

- **`Enabled`** — master switch; when `false` the tracker is never started.
- **`IgnoredExecutables` / `IgnoredProcessNames`** — case-insensitive; matched
  apps are treated as "no foreground" (they close the open session but start
  none), so shell surfaces do not pollute the data.
- **`MinimumSessionSeconds`** — sessions shorter than this are discarded.
- **`MaxWindowTitleLength`** — titles are truncated before upload.

---

## 26.7 Privacy assessment

| Concern | How it is addressed |
| ------- | ------------------- |
| Keystrokes / typed text | Never read. No keyboard hooks anywhere in the agent. |
| Mouse input | Never read (idle detection uses `GetLastInputInfo` **timing** only, unrelated to this feature). |
| Clipboard | Never read. |
| Screen contents / screenshots | Never captured; explicitly out of scope. |
| File contents | Never read. |
| Browser history / URLs | Not collected. Only the window **title** the OS already displays is read. |
| Passwords | Never collected. |
| Window titles | Sanitized (control characters stripped, trimmed, length-bounded) on the **agent** *and* again in the **server** projector before storage. |
| System/shell noise | Excluded via configurable ignore rules. |
| Access control | The dashboard and computer-details usage panels are **admin-only** (route `role:admin` + component `abort_unless(isAdministrator)`). |
| Secrets exposure | Device tokens / API credentials / internal identifiers are never surfaced in any usage view (covered by tests). |

The only data leaving the workstation is: process name, executable name,
sanitized window title, process id, start/end timestamps, duration, interactive
session id, and an `IsSystemProcess` flag.

---

## 26.8 Database schema

`application_usage` (pre-existing table, extended additively in Phase 7):

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | bigint PK | |
| `employee_id` | FK → employees | Owning employee (from the resolved computer). |
| `computer_id` | FK → computers | |
| `activity_log_id` | FK, nullable | Legacy correlation (unused by agent sessions). |
| `application_name` | string | Process name (or executable if unknown). |
| `executable` | string, nullable | Executable file name. |
| `window_title` | string, nullable | Sanitized title. |
| `category`, `productivity` | — | Pre-existing productivity fields (untouched). |
| `used_at` | timestamp | **Session start.** |
| `ended_at` | timestamp, nullable | **Session end** (added Phase 7). |
| `duration_seconds` | unsigned int | Whole seconds. |
| `session_id` | string(64), nullable | **Agent session GUID** (added Phase 7). |
| `timestamps` | | |

Indexes / constraints added in `2026_07_18_000001_add_session_columns_to_application_usage`:

- `unique(computer_id, session_id)` — idempotent projection (multiple NULLs are
  allowed, so pre-existing rows are unaffected).
- `index(application_name, used_at)` — top-applications / time-per-application
  over a range.

Existing indexes on `(employee_id, used_at)` and `(computer_id, used_at)` serve
the per-employee and per-computer queries.

A companion migration
`2026_07_18_000002_relax_agent_events_kind_to_string` relaxes `agent_events.kind`
from a hard enum to a short string, so `app_usage` (and any future kind) needs no
schema change — validity is enforced by `AgentEventKind` + request validation.

---

## 26.9 Dashboard & reporting

Admin dashboard at `/application-usage` ("App Usage" in the nav):

- **Summary cards** — total usage time, session count, distinct applications,
  selected range.
- **Top applications** — time per application (with session counts).
- **Daily timeline** — total usage per day across the range.
- **Usage by employee** and **Usage by department**.
- **Recent application sessions** — paginated, newest first.
- **Filters** — from/to date, employee, computer, department, and an
  application/window-title search; all bound to the URL (`#[Url]`) so views are
  shareable.

`ApplicationUsageService` builds every result from `application_usage` via the
model's indexed scopes (`between`, `forEmployee`, `forComputer`,
`matchingApplication`) and driver-aware date bucketing (SQLite `strftime` /
MySQL `DATE_FORMAT`), so it runs identically under tests (SQLite) and production
(MySQL). Aggregates scale with the number of sessions, not raw events.

Computer details page additions (`ComputerPresenceDetail`): current application,
current window title, current app duration, recent application history, and
today's top applications.

---

## 26.10 Sequence diagrams

**Foreground change → stored session**

```
User        WinEvent      Tracker            SessionMgr        OfflineQueue   Server
 │  switch app │            │                   │                  │            │
 │────────────▶│            │                   │                  │            │
 │             │ EVENT_SYSTEM_FOREGROUND        │                  │            │
 │             │───────────▶│ GetActiveApp()    │                  │            │
 │             │            │ ApplicationChanged │                  │            │
 │             │            │──────────────────▶│ Track(new, now)  │            │
 │             │            │                   │ close prev ▶ SessionCompleted │
 │             │            │                   │──────────────────▶ enqueue    │
 │             │            │                   │                  │ POST /events│
 │             │            │                   │                  │───────────▶│ store+project
```

**Lock closes the open session**

```
SessionMonitor ── Lock ──▶ Worker.OnSessionChanged ──▶ SessionMgr.Flush(now)
                                                          └─ SessionCompleted ▶ enqueue(app_usage)
```

---

## 26.11 Manual verification

Server / dashboard (works today on any OS with seeded data):

```bash
php artisan migrate
php artisan test tests/Feature/Agent/ApplicationUsageIngestionTest.php \
                 tests/Feature/ApplicationUsage/ApplicationUsageDashboardTest.php
```

1. Sign in as an admin, open **App Usage**, and confirm the summary cards, top
   applications, timeline and recent sessions render; adjust the filters.
2. Open a computer's details page and confirm the **Application usage** panel.
3. As a non-admin, confirm `/application-usage` returns **403**.

Agent (on a Windows host):

1. Run the agent (`dotnet run` in `agent/`); switch between applications and
   browser tabs. Logs show `App session: <process> "<title>" for <n>s.`
2. Confirm rows arrive:
   `php artisan tinker --execute="echo App\Models\ApplicationUsage::whereNotNull('session_id')->count();"`
3. Disconnect the network, keep switching apps, reconnect — queued sessions
   drain on the next sync cycle; none are lost.

---

## 26.12 Troubleshooting

| Symptom | Likely cause / fix |
| ------- | ------------------ |
| No usage rows appear | Agent not registered, or `ApplicationTracking.Enabled=false`. Check the agent log for "Application tracker started". |
| Sessions look too short / fragmented | A noisy app changes its title often; raise `MinimumSessionSeconds` or add it to the ignore list. |
| Shell surfaces (Explorer, Start) appear | Add the executable to `IgnoredExecutables`. |
| Titles truncated | Increase `MaxWindowTitleLength` (server column caps at 255). |
| `422` on upload with `kind` error | Server predates Phase 7 — run `php artisan migrate` so `AgentEventKind::AppUsage` and the relaxed `kind` column are present. |
| Dashboard empty for a known-busy machine | Check the date-range filter (defaults to the last 7 days) and that the computer/employee filter is not over-narrowing. |
| Hooks inactive (Windows) | WinEvent hooks require an interactive desktop session; a pure session-0 service will not receive them (see [doc 17](17-windows-agent.md)). |

---

## 26.13 Known limitations & follow-ups

- **Windows only.** Foreground detection is Win32-specific; macOS/Linux agents
  would need their own `IActiveWindowService` / `IApplicationTracker`.
- **Idle time within a session** is not subtracted — a session's duration is
  wall-clock foreground time. Idle is tracked separately by the heartbeat
  pipeline; correlating the two is a possible enhancement.
- **No per-application productivity classification** for agent sessions yet — the
  existing `productivity` column defaults to `neutral`; classifying by
  application/category is a follow-up.
- **Retention.** `application_usage` has no automatic pruning job; add one
  alongside the `agent_events` retention follow-up if long-term growth matters.
- **Build.** The Phase 7 agent code targets `net8.0-windows`; it is delivered
  written and unit-test-designed but is not compiled in this repo's Linux CI.

## 26.14 Related: Screenshot module (Phase 8)

Phase 8 reuses this phase's `IActiveWindowService` to record the foreground
process/window title with each screenshot, and rides the same agent offline queue
+ sync pipeline (a dedicated `Screenshot` event kind and a multipart upload
endpoint). See [`docs/27-screenshot-module.md`](27-screenshot-module.md).

---

*Phase 7 complete.*

---

## Phase 9 note — Notifications

Phase 9 observes completed application-usage rows (via an Eloquent observer, so
this phase's projection is untouched) and evaluates them against configurable
rules — restricted applications, blacklisted processes and usage-beyond-duration
— generating admin notifications asynchronously. Full design:
[`docs/29-notifications.md`](29-notifications.md).
