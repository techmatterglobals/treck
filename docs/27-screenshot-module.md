# 27. Screenshot Module (Phase 8)

The screenshot module captures the interactive user's desktop on an
administrator-defined policy and uploads the images through the existing
synchronization pipeline. It is **opt-in** (disabled by default), lightweight,
offline-first, privacy-aware and secure.

> **Privacy first.** The module captures the visible desktop image only, on a
> configurable schedule. It never captures keyboard input, mouse movement,
> clipboard contents or file contents, and it never captures the Windows secure
> desktop (UAC / Ctrl+Alt+Del / login / lock screen). See §27.7.

---

## 27.1 Architecture overview

```
 Windows desktop (interactive session)                Laravel server
 ─────────────────────────────────────                ──────────────────────────
 ScreenshotWorker (own cadence)
   ├─ policy: enabled? interactive? active? not ignored?
   ├─ ScreenshotCaptureService  ── per-monitor Bitmap (GDI, DPI-aware)
   ├─ ScreenshotProcessingService ─ compress + SHA-256 + dedup + temp file
   └─ enqueue Screenshot event ─▶ offline queue (SQLite; payload = metadata+path)
                                         │
                         SyncWorker ─────┤ (existing drain loop, ordered, backoff)
                                         ▼
                         AgentEventUploader ── kind==Screenshot ──▶ ScreenshotSyncService
                                         │                            reads temp file,
                                         │                            POST multipart,
                                         ▼                            deletes temp on 2xx
                              POST /api/agent/screenshots
                                         ▼
                         ScreenshotUploadController (thin)
                                         ▼
                         ScreenshotService.ingest()
                           ├─ SHA-256 (server-side) → dedup (computer_id, image_hash)
                           ├─ ScreenshotStorageService.store() → private disk
                           └─ Screenshot row (metadata)
                                         ▼
                         Dashboard  ◀── /screenshots  (admin only)
                         Viewer     ◀── /screenshots/{id}  (signed image route)
```

### Components

**Agent (`agent/Screenshots/`)**

| Type | Role |
| ---- | ---- |
| `IScreenshotCaptureService` / `WindowsScreenshotCaptureService` | GDI capture per monitor; secure-desktop guard; DPI-aware. |
| `IScreenshotProcessingService` / `ScreenshotProcessingService` | Compress (JPEG/PNG), SHA-256, per-monitor dedup, temp file. |
| `IScreenshotSyncService` / `ScreenshotSyncService` | Upload one screenshot; delete temp file on success. |
| `ScreenshotWorker` (hosted, **interactive session**) | Capture cadence + policy; hands captures to the sink. |
| `IScreenshotSink` → `OfflineQueueScreenshotSink` / `SpoolScreenshotSink` | Capture destination: offline queue directly (in-process) or spool sidecar (helper). |
| `IInteractiveSessionLauncher` / `WindowsInteractiveSessionLauncher` | Launches the helper into the active console session (WTS + CreateProcessAsUser). |
| `ScreenshotHelperSupervisor` (hosted, **service/session 0**) | Launches/monitors/relaunches the helper; grants the screenshots-dir ACL. |
| `ScreenshotSpoolWorker` (hosted, **service/session 0**) | Ingests spool sidecars into the offline queue (single DB owner). |
| `ScreenshotMetadata`, `MonitorCapture`, `ScreenshotOptions` | Payload record, capture holder, configuration. |

**Server**

| Type | Role |
| ---- | ---- |
| `ScreenshotUploadController` | Thin multipart ingest endpoint (agent). |
| `Services/Screenshots/ScreenshotService` | Ingest (dedup) + read model (dashboard/viewer). |
| `Services/Screenshots/ScreenshotStorageService` | Disk I/O, signed URLs, streaming, delete. |
| `Models/Screenshot` (extended) | Capture metadata + scopes. |
| `DataObjects/ScreenshotFilter` | Immutable dashboard/viewer filter. |
| `Http/Controllers/ScreenshotController` | Admin dashboard/viewer + signed image + download. |
| `Policies/ScreenshotPolicy` | Admin-only view/download. |
| `Livewire/Screenshots/ScreenshotDashboard` + `ScreenshotViewer` | UI. |
| `Console/Commands/PruneScreenshots` | Retention (row + file). |

### Design principles honored

- **Reuse the sync pipeline.** Screenshots ride the existing offline SQLite
  queue, the `SyncWorker` drain loop, its ordering and exponential backoff, and
  the device-token auth. Only the binary transport differs (multipart), isolated
  in `ScreenshotSyncService` + a single branch in `AgentEventUploader`.
- **Thin controllers, services own logic.** Both the agent controller and the web
  controller delegate to services.
- **Never scan the filesystem for reads.** The dashboard/viewer read the indexed
  `screenshots` rows; images are fetched by signed route only.
- **Asynchronous & non-blocking.** Capture runs in its own hosted service;
  upload is deferred to the sync loop. The heartbeat, presence and app-tracking
  loops are never blocked.

---

## 27.1a Session-0 isolation & the capture helper (critical)

A Windows service runs in **Session 0**, which has **no access to the interactive
user's desktop** (Session 1+). GDI capture, `GetForegroundWindow`, and idle input
are all per-desktop, so a `LocalSystem` service **cannot** screenshot the user's
screen — `OpenInputDesktop` fails and `CanCapture()` returns false (the symptom is
"worker started, nothing captured", with no error). Capture therefore must run in
the **interactive session**.

The module handles this by process topology (chosen automatically):

| Run context | Where interactive collection runs | Wiring |
| ----------- | ------------------ | ------ |
| Windows service (session 0) | A **helper** the service launches into the active console session | `ScreenshotHelperSupervisor` → `WindowsInteractiveSessionLauncher` → `TreckAgent.exe --capture-helper` |
| Console / dev (already interactive) | **In-process** | `Worker` (heartbeat + app-usage) + `ScreenshotWorker` |

**What the helper runs (all interactive-session collection).** Because the same
Session-0 limitation affects Phase 4 idle detection (`GetLastInputInfo`) and
Phase 7 foreground tracking (`GetForegroundWindow`/WinEvent hooks), the helper
hosts **all** desktop-bound collection, not just screenshots:

- `ScreenshotWorker` → screenshots;
- `ApplicationUsageSpoolForwarder` → Phase 7 application-usage (foreground);
- `HeartbeatSpoolForwarder` → Phase 4 heartbeat with **accurate idle**.

Each emits into a shared **event spool** (`FileAgentEventSpool`); the service's
`AgentEventSpoolWorker` ingests every kind (screenshot / app_usage / heartbeat)
into the single-owner offline queue, and the existing `SyncWorker` uploads. The
service's `Worker` keeps registration, session monitoring and sync, but no longer
collects heartbeat/idle or foreground data itself (`AgentRuntime.CollectInteractiveInProcess = false`).

> **Consequence (by design):** heartbeats now flow only while a user is logged in
> interactively. With no interactive session (login screen / logged off) there is
> no heartbeat and the device presents as offline — which matches *user*-presence
> semantics. If you also need "device powered on" monitoring independent of login,
> add a lightweight service-side liveness ping (not included).

**Service → helper handoff (session 0):**

```
Service (session 0)                         Helper (session 1, as the user)
──────────────────                          ──────────────────────────────
ScreenshotHelperSupervisor
  ├─ WTSGetActiveConsoleSessionId
  ├─ WTSQueryUserToken → DuplicateTokenEx
  ├─ CreateEnvironmentBlock
  └─ CreateProcessAsUser("… --capture-helper",
        lpDesktop = winsta0\default) ─────▶ ScreenshotWorker + SpoolScreenshotSink
                                              ├─ capture + compress + hash + temp file
  grants Users:Modify on \screenshots  ◀──┘  └─ write spool sidecar (.json)
ScreenshotSpoolWorker (polls spool)
  └─ enqueue Screenshot event ─▶ offline queue ─▶ SyncWorker ─▶ upload + delete temp
```

Why a **spool** rather than sharing the SQLite queue across both processes: it
keeps the service the **single writer** of `offline.db` (no cross-process lock
contention), and it lets the service grant the interactive user write access to
**only** the `helper` directory (`%ProgramData%\TreckAgent\helper`, containing the
image temp files and spool sidecars) — the offline queue and the DPAPI-encrypted
device token at the data-dir root are never exposed. The supervisor relaunches the
helper on crash, log-off→on, and fast-user-switch (active session change); with no
interactive session (login screen) it simply waits.

**Launch diagnostics & startup checks.** The supervisor/launcher log the target
**session id, user (domain\\user) and PID** on every helper launch; the helper
logs its own `user / session / pid` at startup; and `FileAgentEventSpool` performs
a **write probe** of the spool directory at startup, logging a clear error if the
ACL grant did not take. Run a **one-shot validation** any time with:

```
TreckAgent.exe --capture-helper-test
```

It captures once in the current session and logs a per-monitor report
(dimensions, byte size, hash, temp path, foreground app), then exits 0 (captured)
or 1 (capture unavailable — e.g. run from session 0 or a secure desktop). Logs go
to `treck-agent-selftest-*.jsonl`.

## 27.2 Screenshot capture lifecycle

1. **Tick.** `ScreenshotWorker` waits `IntervalSeconds` (optionally jittered by
   `RandomJitterSeconds`).
2. **Policy.** Skip unless: enabled, the input desktop is interactive
   (`CanCapture()`), the user is active (idle < threshold, if
   `CaptureOnlyWhenActive`), and the foreground app is not on the ignore list.
3. **Capture.** `CaptureAll()` returns one `Bitmap` per monitor (or just the
   primary), at native resolution.
4. **Process.** Each bitmap is compressed (JPEG quality / PNG), SHA-256 hashed,
   compared to the previous frame for that monitor (identical → dropped), and
   written to a temp file under `{ProgramData}\TreckAgent\screenshots`.
5. **Queue.** Each survivor is enqueued as a `Screenshot` offline event whose
   payload is the `ScreenshotMetadata` (including the temp-file path).
6. **Sync.** `SyncWorker` drains it; `ScreenshotSyncService` uploads the bytes and
   deletes the temp file on success.

---

## 27.3 Upload workflow

```
Capture ─▶ Compress ─▶ Queue ─▶ Synchronize ─▶ Delete local temp file
```

- The queue row stores the **metadata + temp-file path**, not the image bytes, so
  the SQLite queue stays small; the image lives as a temp file until uploaded.
- On a successful `2xx`, `ScreenshotSyncService` deletes the temp file and the
  `SyncWorker` marks the queue row synced. On failure the row and file remain and
  are retried next cycle; **order is preserved** (the drain stops at the first
  non-ack). Nothing is lost offline.
- A missing temp file (already cleaned up) is treated as done so the queue never
  wedges.

---

## 27.4 Storage architecture

- Image bytes are stored via **Laravel Storage** on the configurable disk
  `treck.screenshots.disk` (default `local` → `storage/app`, **outside** the
  public directory). Set it to `s3` (or any driver) for object storage — no code
  change.
- Path layout: `screenshots/{computer_id}/{Y-m-d}/{sha256}.{ext}`.
- Clients never receive a path or a raw disk URL. Viewing goes through a
  short-lived **signed** route (`screenshots.image`) that streams the file after
  an admin policy check; downloading goes through an authorized route. This is
  disk-agnostic (works for local and cloud) and never leaks a path.
- Retention: `treck:prune-screenshots` deletes the row **and** the file for
  captures older than `treck.retention.screenshot_days` (default 30; 0 disables),
  scheduled daily.

---

## 27.5 Database schema

`screenshots` (existing table, extended additively in Phase 8):

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | bigint PK | |
| `employee_id` | FK, nullable | Owner (from the capturing computer). |
| `computer_id` | FK | Capturing device. |
| `activity_log_id` | FK, nullable | Legacy correlation (unused by agent captures). |
| `path` | string | Storage key on `disk` (reused as the storage path). |
| `disk` | string | Storage disk the bytes live on. |
| `filename` | string, nullable | Display file name. |
| `captured_at` | timestamp | Capture time. |
| `image_hash` | string(64), nullable | SHA-256 hex (dedup key). |
| `monitor_number` | small uint | 0-based monitor index. |
| `width` / `height` | uint | Resolution. |
| `file_size` | big uint | Compressed byte size. |
| `active_process` / `active_window_title` | string, nullable | Foreground context. |
| `session_id` | string(64), nullable | Capture-session id. |
| `timestamps` | | |

Constraints added (`2026_07_19_000001_add_capture_columns_to_screenshots`):

- `unique(computer_id, image_hash)` — idempotency + duplicate detection (existing
  rows keep `image_hash = null`; multiple NULLs are allowed, so they are
  unaffected).
- `index(computer_id, captured_at)`, `index(captured_at)` — dashboard/viewer.

---

## 27.6 Sequence diagrams

**Capture → stored image**

```
Worker      Capture       Processing      Queue        SyncWorker     Server
 │ tick       │              │              │              │            │
 │ policy ok  │              │              │              │            │
 │───────────▶│ CaptureAll() │              │              │            │
 │            │──bitmaps────▶│ compress+hash│              │            │
 │            │              │ dedup? new   │              │            │
 │            │              │──temp file──▶│ enqueue      │            │
 │            │              │              │◀─────────────│ drain      │
 │            │              │              │              │ POST multipart │
 │            │              │              │              │───────────▶│ ingest+dedup+store
 │            │              │              │              │◀──2xx──────│
 │            │              │              │ delete temp  │            │
```

**Locked / secure desktop**

```
Worker.tick ─▶ Capture.CanCapture() == false  (input desktop != "Default")
            └─ skip cycle (no capture, nothing queued)
```

---

## 27.7 Privacy model

| Concern | How it is addressed |
| ------- | ------------------- |
| Keyboard input / typed text | Never captured. No keyboard hooks. |
| Mouse input | Never captured. |
| Clipboard | Never read. |
| File contents | Never read. |
| Secure desktop (UAC, Ctrl+Alt+Del, login) | Never captured — `CanCapture()` returns false unless the input desktop is "Default". |
| Lock screen | Not captured — the locked state uses the secure desktop, which fails `CanCapture()`. |
| Logged out | Not captured — no interactive input desktop. |
| Sensitive apps / windows | Configurable `IgnoredProcesses` and `IgnoredWindowTitles` skip the whole cycle. |
| Idle sessions | With `CaptureOnlyWhenActive`, idle users are not captured. |
| Opt-in | Disabled by default (`Screenshots.Enabled = false` agent-side; `TRECK_SCREENSHOTS` server-side). |
| Access | Viewing/downloading is admin-only (route `role:admin` + `ScreenshotPolicy`). |
| Transport/storage | Bytes on a non-public disk; served only via short-lived signed URLs; no path exposed. |

The only data captured is the visible desktop image plus the metadata in §27.5.

---

## 27.8 Security considerations

- **Authorization.** Only administrators may list, view or download screenshots
  (`ScreenshotPolicy` + `role:admin` middleware). The Livewire components also
  `abort_unless(isAdministrator)` on mount.
- **Signed access.** Image bytes are reachable only through
  `URL::temporarySignedRoute('screenshots.image', …)` (default 5-minute TTL),
  which is additionally behind auth + the admin policy. An unsigned or expired
  request is rejected (403).
- **No secrets exposed.** Responses never include device tokens, API credentials
  or filesystem paths (test-covered).
- **Device identity.** Uploads authenticate with the Sanctum device token
  (`agent:report`); the owning employee is taken from the `Computer`, never the
  request body (SEC-1).
- **Integrity/dedup.** The SHA-256 is computed server-side from the received
  bytes; the client-supplied hash is advisory only.

---

## 27.9 Configuration

**Server (`config/treck.php` → `screenshots` / `retention`):**

| Key | Env | Default | Purpose |
| --- | --- | ------- | ------- |
| `screenshots.enabled` | `TRECK_SCREENSHOTS` | `false` | Feature flag. |
| `screenshots.disk` | `TRECK_SCREENSHOT_DISK` | `local` | Storage disk (keep non-public). |
| `screenshots.directory` | `TRECK_SCREENSHOT_DIR` | `screenshots` | Path prefix. |
| `screenshots.max_upload_kb` | `TRECK_SCREENSHOT_MAX_KB` | `8192` | Upload size cap. |
| `screenshots.url_ttl_minutes` | `TRECK_SCREENSHOT_URL_TTL` | `5` | Signed-URL lifetime. |
| `retention.screenshot_days` | `TRECK_SCREENSHOT_RETENTION` | `30` | Prune age (0 = keep). |

**Agent (`appsettings.json` → `Screenshots`):**

| Key | Default | Purpose |
| --- | ------- | ------- |
| `Enabled` | `false` | Feature flag. |
| `IntervalSeconds` | `600` | Base interval. |
| `RandomJitterSeconds` | `0` | Add up to N random seconds per cycle. |
| `CaptureOnlyWhenActive` | `true` | Skip idle users. |
| `MultiMonitor` | `true` | Capture each monitor separately. |
| `Format` | `jpeg` | `jpeg` or `png`. |
| `JpegQuality` | `60` | 1–100 (JPEG only). |
| `IgnoredProcesses` | `[]` | Skip when these are foreground. |
| `IgnoredWindowTitles` | `[]` | Skip when the title contains these. |

---

## 27.10 Manual verification

Server / dashboard (works today on any OS with seeded data):

```bash
php artisan migrate
php artisan test tests/Feature/Agent/ScreenshotUploadTest.php \
                 tests/Feature/Screenshots
```

1. Sign in as an admin, open **Screenshots**, confirm the grid, filters and
   capture-status cards render; open a capture in the viewer (prev/next, zoom,
   download).
2. As a non-admin, confirm `/screenshots` returns **403**, and that an image
   URL without a valid signature returns **403**.

Agent (on a Windows host, `Screenshots.Enabled = true`):

1. **One-shot self-test (fastest):** in the interactive user session run
   `TreckAgent.exe --capture-helper-test` and read `treck-agent-selftest-*.jsonl` —
   it reports session/user/pid and a per-monitor capture result. Exit 0 = healthy.
2. Install/run the service; confirm in `treck-agent-*.jsonl`:
   "Capture helper launched: session=… user=… pid=…", and in the **helper** log
   `treck-agent-helper-*.jsonl`: "Spool directory is writable", "Capture cycle
   complete", "Application-usage collection running", "Heartbeat collection running".
3. Confirm rows arrive:
   `php artisan tinker --execute="echo App\Models\Screenshot::whereNotNull('image_hash')->count();"`
4. Lock the workstation — no captures are queued while locked.
5. Go offline, keep working, come back — queued items drain on the next sync
   cycle; temp files under `%ProgramData%\TreckAgent\helper\screenshots` clear.

---

## 27.11 Troubleshooting

| Symptom | Likely cause / fix |
| ------- | ------------------ |
| No screenshots appear | `Screenshots.Enabled=false`, device not registered, or **session-0 isolation** (see below). Check the service log for "Screenshot helper supervisor started" / "Capture helper launched into session N", and the **helper** log (`treck-agent-helper-*.jsonl`) for "Screenshot worker started" + "Capture cycle complete". |
| Service log: "Screenshot capture UNAVAILABLE … process session=0" | Session-0 isolation. The **service** must not capture directly; ensure `ScreenshotHelperSupervisor` is launching the helper (service log). If you deployed an older build that captured in-process, upgrade — capture now runs in the interactive helper. |
| Helper never launches | The service account lacks `SeTcbPrivilege` (needed for `WTSQueryUserToken`/`CreateProcessAsUser`) — run as `LocalSystem`; or no user is logged into the console (login screen). |
| Helper can't spool (access denied) | The icacls grant on `\screenshots` failed; grant BUILTIN\Users (or the interactive user) Modify on `%ProgramData%\TreckAgent\screenshots`. |
| Captures only while locked are missing | Expected — the secure/lock desktop is never captured. |
| Everything is skipped | `CaptureOnlyWhenActive` with an idle user, or the foreground app is on the ignore list. |
| Image 403 in the dashboard | The signed URL expired (raise `url_ttl_minutes`) or the viewer is not an admin. |
| Disk fills up | Lower `JpegQuality`/interval, or lower `retention.screenshot_days`; ensure `treck:prune-screenshots` is scheduled. |
| Upload 422 (`image`) | File exceeds `max_upload_kb`, or is not JPEG/PNG. |
| Blank/black captures | The app uses hardware-protected output (DRM); GDI capture cannot read it. |
| Agent won't build on Linux | Expected — the module targets `net8.0-windows` (Win32 + System.Drawing); build on Windows. |

---

## 27.12 Known limitations

- **Windows only.** Capture uses Win32 + System.Drawing; other OSes need their
  own `IScreenshotCaptureService`.
- **GDI capture** cannot read DRM-protected surfaces (some video players); those
  regions appear black.
- **Server-side thumbnails** are not generated; the grid renders the full image
  scaled by the browser. A thumbnail pass is a possible enhancement.
- **Per-frame dedup** is only against the immediately-preceding frame per monitor
  (agent) plus the exact-hash unique constraint (server); near-identical frames
  are still stored.
- **Build.** Agent code is delivered written and unit-test-designed (sync step
  covered) but is not compiled in this repo's Linux CI.

---

## 27.13 Production readiness

The server side is production-ready: additive/backward-compatible migration,
idempotent ingest + dedup, admin-only signed access, retention command, and full
test coverage. The agent follows the established architecture (interfaces, DI,
options, hosted service) and reuses the proven offline/sync path; it requires a
**Windows build + on-device smoke test** before rollout — the one step this
environment cannot perform.

---

*Phase 8 complete.*
