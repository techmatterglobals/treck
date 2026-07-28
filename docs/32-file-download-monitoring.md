# 32. File Download Monitoring (Phase 12)

Phase 12 adds enterprise-grade **file download monitoring**: the Windows agent
detects when a user saves a file into a download location and reports **metadata
only** to Laravel, which attributes it to the correct employee using the Phase 11
Windows-username resolution. The feature is lightweight, privacy-aware, secure,
configurable, and **opt-in** (disabled by default).

It is a strictly additive extension — it reuses the existing agent, offline
queue, synchronization pipeline, authentication, authorization, reporting,
notification and dashboard architecture. No existing behaviour changes.

---

## 32.1 Architecture

```
 Windows helper (interactive session)              Laravel backend
 ────────────────────────────────────              ───────────────
 FileDownloadMonitor (FileSystemWatcher)
   ├─ debounce until file is stable (FileDownloadSession)
   ├─ ignore rules (folders/extensions/apps)
   ├─ FileHashService (optional SHA-256, size-capped)
   └─ spool `file_download` event (SourceStamp: WindowsUsername)
                     │  (existing offline queue + SyncWorker; ordered, idempotent)
                     ▼
        POST /api/agent/events  ─▶ AgentEventIngestionService
                     ├─ EmployeeResolver (computer + windows_username → employee, Phase 11)
                     └─ FileDownloadProjector ─▶ file_downloads row (metadata only)
                                                        │ observer (queued)
                                                        ▼
                                    NotificationEngine (DownloadNotificationRule)
                                    executable / archive / large / restricted alerts
                     │
                     ▼  Livewire dashboard + reports (role-scoped)
        Super Admin: all · Manager: own team · (Employee: none)
```

The download event travels the **same pipeline** as heartbeats and
application-usage (a new `file_download` `AgentEventKind`), so ordering,
at-least-once delivery and server-side idempotency are inherited unchanged.

---

## 32.2 Detection strategy

- The agent runs the monitor **inside the interactive helper** (session 1+), so
  it sees the logged-in user's Downloads folder and foreground application, and
  the reported Windows identity is the real user (never the service account).
- Detection is **event-driven** via `FileSystemWatcher` (OS change
  notifications) — never a busy polling loop.
- A lightweight **debounce** (`FileDownloadSession`) waits until a file's size
  has been stable for `StabilizationMilliseconds`, so in-progress downloads
  (`.crdownload`, `.part`, `.tmp`) are reported only once complete. A single
  low-frequency timer promotes stable files; it does no I/O while idle.
- Because it watches the **file system**, it captures downloads from any source
  that writes to a watched folder — Chrome, Edge, Firefox, Office, Teams,
  Outlook attachments, and other apps — not just browsers.

---

## 32.3 Windows APIs used

| Purpose | API |
|---------|-----|
| Change notifications | `System.IO.FileSystemWatcher` (Created / Renamed / Changed) |
| Downloads folder | `Environment.SpecialFolder.UserProfile` + `Downloads` (or configured folders, env-expanded) |
| File metadata | `System.IO.FileInfo` (name, extension, size, folder) |
| Optional hash | `System.Security.Cryptography.SHA256` over a read-only `FileStream` |
| Source application | existing `IActiveWindowService` (foreground process/window) |
| Windows identity | existing `EventSource` (`Environment.UserName`) folded in by `SourceStamp` |

No new third-party dependency is introduced.

---

## 32.4 Synchronization flow

1. Monitor detects a completed download and builds a `DownloadedFile` record
   (metadata only).
2. It is serialized (PascalCase) and written to the spool as a `file_download`
   `OfflineEvent` with a unique idempotency key.
3. The Session-0 service ingests the spool into the SQLite offline queue and the
   existing `SyncWorker` drains it in order with retry/backoff.
4. `POST /api/agent/events` stores the raw `agent_events` row (idempotent per
   `computer_id + idempotency_key`) and, on first store, the
   `FileDownloadProjector` writes a `file_downloads` row.
5. Projection is additionally idempotent on `(computer_id, event_key)`, so a
   re-projected event never duplicates.

---

## 32.5 Database schema

`file_downloads`:

| Column | Type | Notes |
|--------|------|-------|
| `computer_id` | FK computers, cascade | |
| `employee_id` | FK employees, **nullable**, nullOnDelete | resolved via Phase 11; null if the Windows account is unmapped |
| `windows_username` | string(191), nullable | reported identity |
| `application_name` / `process_name` / `window_title` | nullable | source app context |
| `file_name` | string | includes extension |
| `file_extension` | string(32), nullable | lower-case, no dot |
| `file_size` | unsigned big int | bytes |
| `local_path` / `download_folder` | string(1024), nullable | |
| `sha256_hash` | char(64), nullable | only when hashing enabled |
| `downloaded_at` | timestamp | |
| `session_id` | string(64), nullable | |
| `event_key` | string(191) | agent idempotency key |

Indexes: unique `(computer_id, event_key)`; `(employee_id, downloaded_at)`,
`(computer_id, downloaded_at)`, `(file_extension, downloaded_at)`,
`downloaded_at`. These support the dashboard/report filters as indexed lookups —
never a scan of raw events — so the table scales to millions of rows.

---

## 32.6 Privacy considerations

- **Metadata only.** File contents are never read or transmitted. The only time
  bytes are read at all is to compute an optional SHA-256, and those bytes are
  discarded — only the digest is stored.
- Treck never collects clipboard, typed text, browser history or passwords.
- The dashboard shows metadata only; it does **not** offer a way to fetch the
  file from the monitored computer.
- Administrators can configure (agent side): ignored folders, ignored file
  types, ignored applications, hashing on/off, and the maximum file size for
  hashing.
- Opt-in: `FileDownloads.Enabled = false` by default.

---

## 32.7 Security model

Reuses the existing authorization stack. Routes are `role:admin|manager`;
`FileDownloadPolicy` (auto-discovered) plus scoped queries enforce:

| Role | Visibility |
|------|-----------|
| Super Admin | all downloads |
| Manager | only their own employees' downloads (list, detail, reports, export) |
| Employee | none (no download portal) |

Scoping funnels through `FileDownloadService::query()` + `EmployeeVisibility`
(Phase 11), so exports are scoped too — a manager can never export another
team's data. The detail page enforces per-record ownership via the policy.

---

## 32.8 Notifications

Reuses the Phase 9 engine via `DownloadNotificationRule` (source `download`),
fired asynchronously by a `FileDownloadObserver`. Configurable, seeded rules:

| Event type | Default severity | Trigger |
|------------|------------------|---------|
| `download.executable` | Critical | extension in `treck.downloads.executable_extensions` |
| `download.restricted` | Critical | extension in the rule's `config.extensions` (admin list) |
| `download.archive` | Warning | extension in `treck.downloads.archive_extensions` |
| `download.large` | Warning | size ≥ `treck.downloads.large_file_bytes` |

Each is enable/severity/channel/throttle-configurable from Notification Settings
like every other rule.

---

## 32.9 Configuration

**Agent** (`appsettings.json` → `FileDownloads`): `Enabled`, `WatchedFolders`
(empty = the user's Downloads folder), `IncludeSubdirectories`,
`IgnoredExtensions`, `IgnoredApplications`, `IgnoredFolders`, `HashEnabled`,
`MaxHashBytes`, `StabilizationMilliseconds`.

**Backend** (`config/treck.php` → `downloads`): `large_file_bytes`,
`executable_extensions`, `archive_extensions` (tune the alert rules).

---

## 32.10 Manual verification

1. `php artisan migrate` (creates `file_downloads`, seeds the download rules).
2. On an agent host, set `FileDownloads.Enabled = true` (optionally
   `HashEnabled = true`) and restart the agent.
3. Download a file in a browser → within a couple of seconds it appears under
   **Downloads** in the dashboard, attributed to the logged-in employee.
4. Download `setup.exe` → confirm a critical "Executable file downloaded" alert.
5. As a Manager, confirm you see only your team's downloads; open a detail page
   and confirm it shows metadata only (no file fetch).
6. Group the report by file type / employee and export to Excel.
7. Confirm a legacy agent (download monitoring off) behaves exactly as before.

---

## 32.11 Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| No downloads recorded | `FileDownloads.Enabled` is false, or the agent isn't running in the interactive helper |
| Partial files (`.crdownload`) recorded | lower `StabilizationMilliseconds` is too small, or the temp extension isn't in `IgnoredExtensions` |
| Download attributed to no employee | the Windows account isn't mapped (Phase 11) — map it in Manager Management |
| No hash stored | `HashEnabled` is false or the file exceeds `MaxHashBytes` |
| Manager sees nothing | no employees assigned, or the downloads belong to another team (correct) |

---

## 32.12 Performance

- Event-driven detection (no polling); a single debounce timer at half the
  stabilization window does no work while idle.
- Hashing is off by default and size-capped when on.
- Reuses the existing offline queue and sync loop — no extra network chatter and
  no impact on heartbeat or application-tracking cadence.
- All dashboard/report queries are covered by indexes; scoping uses bounded,
  indexed id sets.

---

## 32.13 Known limitations

- Detection is by download **location**, so a file saved outside a watched
  folder (e.g. "Save as" to Documents) is captured only if that folder is added
  to `WatchedFolders`.
- The source application is the foreground app at detection time; a background
  download completing while another app is focused may attribute to that app.
- Download monitoring runs in the interactive helper (production topology); the
  console/dev single-process mode does not host it.
