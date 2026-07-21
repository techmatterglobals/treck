# 28. Phase 8 — Windows Validation Checklist (final)

The final go/no-go checklist for the screenshot module + interactive helper on a
**Windows host**. Phase 8 is marked complete once every box below is checked on a
real machine.

> **Why this is operator-run.** The agent targets `net8.0-windows` (Win32 +
> `System.Drawing.Common`) and the CI/dev environment is Linux with no Windows
> .NET SDK, so the `dotnet` / `sc` / PowerShell steps below must be executed on a
> Windows host. Each item notes what has been **statically verified in code** and
> what the **operator confirms** by running it.
>
> Legend: ✅ verified in code · ☐ operator confirms on Windows.

---

## 1. Agent build validation

```powershell
cd agent
dotnet restore
dotnet build -c Release
dotnet test  tests
dotnet publish -c Release -r win-x64 --self-contained false -o publish
```

- ☐ `dotnet restore` / `build` succeed with **no warnings** (`TreatWarningsAsErrors=true`).
- ☐ `dotnet test` passes (`ScreenshotSyncServiceTests`, `AgentEventSpoolTests`,
  `ApplicationSessionManagerTests`, and the existing suites).
- ☐ `dotnet publish` produces `publish\TreckAgent.exe`.

Output must contain:

- ✅ **TreckAgent service executable** — `Treck.Agent.csproj` sets
  `<OutputType>Exe</OutputType>` and `<AssemblyName>TreckAgent</AssemblyName>` →
  `TreckAgent.exe`. ☐ confirm the file exists in `publish\`.
- ✅ **Helper mode support (`--capture-helper`)** — `Program.cs` branches on
  `args.Contains("--capture-helper")` and `--capture-helper-test`. ☐ confirm
  `TreckAgent.exe --capture-helper-test` runs (see item 4).
- ✅ **Required Windows runtime dependencies** — `System.Drawing.Common`,
  `Microsoft.Data.Sqlite`, `Microsoft.Extensions.Hosting.WindowsServices`,
  `Microsoft.Win32.SystemEvents` are referenced in the csproj. ☐ confirm their
  DLLs are present in `publish\` (framework-dependent publish uses the shared
  runtime; the desktop pack supplies System.Drawing at runtime).
- ✅ **P/Invokes compile** — `WTSGetActiveConsoleSessionId`, `WTSQueryUserToken`,
  `WTSQuerySessionInformation`, `DuplicateTokenEx`, `CreateEnvironmentBlock`,
  `CreateProcessAsUser`, `EnumDisplayMonitors`, `OpenInputDesktop`,
  `SetProcessDpiAwarenessContext` are declared with explicit marshalling. ☐ a
  clean `dotnet build` is the confirmation (no `DllImport` errors).

## 2. Install / update validation

```powershell
cd agent\deploy
./install-service.ps1 -Publish
sc.exe qc TreckAgent
Get-Service TreckAgent
```

- ✅ **Executable path** — `install-service.ps1` registers
  `New-Service -BinaryPathName '"…\TreckAgent.exe"'` in the install dir. ☐ `sc qc`
  shows `BINARY_PATH_NAME : "C:\Program Files\TreckAgent\TreckAgent.exe"`.
- ✅ **Runs as LocalSystem** — `New-Service` is called **without** `-Credential`,
  which defaults to `LocalSystem`. ☐ `sc qc` shows
  `SERVICE_START_NAME : LocalSystem`.
- ☐ **Service starts** — `Get-Service TreckAgent` → `Status: Running` (the install
  script also `Start-Service`s it and prints the status).

## 3. Interactive helper validation

Inspect the service + helper logs after a user is logged in:

```powershell
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-*.jsonl"        -Tail 20 | Select-String "Capture helper launched"
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-helper-*.jsonl" -Tail 20
```

- ✅ The launcher logs `Capture helper launched: session={id} user={domain\user}
  pid={pid} desktop=winsta0\default` (`WindowsInteractiveSessionLauncher`), and the
  helper logs `Starting in capture-helper mode: CollectionMode=InteractiveHelper
  User=… SessionId=… PID=… Desktop=WinSta0\Default` (`Program.cs`), plus
  `Screenshot capture available: interactive desktop "Default"`
  (`WindowsScreenshotCaptureService`).
- ☐ Confirm the helper log shows:
  - [ ] `CollectionMode=InteractiveHelper`
  - [ ] `SessionId=<interactive session>` (1, 2, … — **not** 0)
  - [ ] `User=<logged-in user>` (**not** SYSTEM)
  - [ ] `Desktop=WinSta0\Default`
- ☐ Confirm it is **not** `SessionId=0` / `User=SYSTEM` (that would mean capture is
  wrongly running in the service; see item 6).

## 4. Screenshot validation (one-shot)

```powershell
Stop-Service TreckAgent
& "C:\Program Files\TreckAgent\TreckAgent.exe" --capture-helper-test
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-selftest-*.jsonl" -Tail 30
```

`ScreenshotSelfTest` captures once in the current session, spools the result, and
exits 0 (ok) / 1 (unavailable). Confirm in the log:

- ☐ **Monitor enumeration succeeds** — `Self-test OK: N monitor(s) captured …`
  (`CaptureAll` returned ≥ 1; a `Capture cycle produced 0 monitors` / exit 1 means
  no interactive desktop).
- ☐ **JPEG created** — per-monitor line `Monitor 0: 1920x1080, <bytes> bytes,
  hash=…, file=…\helper\screenshots\<hash>.jpg`.
- ☐ **Hash / size logged** — same line (`hash=` + byte count).
- ☐ **Spool file created** — `N spooled` and a sidecar under
  `%ProgramData%\TreckAgent\helper\spool\<hash>.json` (also proves the spool dir is
  writable). ☐ `Get-ChildItem "$env:ProgramData\TreckAgent\helper\spool"`.

## 5. End-to-end event validation

```powershell
Start-Service TreckAgent
Start-Sleep -Seconds 120
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-helper-*.jsonl" -Tail 60
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-*.jsonl"        -Tail 60
Get-ChildItem  "$env:ProgramData\TreckAgent\helper\spool"        # trends to empty
```

Flow to confirm end-to-end:

- ☐ **Helper produces** — helper log shows `Capture cycle complete` (screenshot),
  `Application-usage collection running` + app sessions (foreground),
  `Heartbeat collection running` + heartbeats (idle).
- ✅→☐ **Spool** — events land in `%ProgramData%\TreckAgent\helper` (spool
  sidecars + image temp files).
- ✅→☐ **Service ingests** — `AgentEventSpoolWorker` logs `Ingested {kind} from
  spool (…)`; the spool directory trends to empty.
- ☐ **Database** — rows appear in `agent_events` (heartbeat/app_usage/session) and
  `screenshots` (see item 7).
- ✅→☐ **Sync** — the service log shows `Sync cycle: uploaded=N …`
  (`SyncWorker`/`SyncService`).

## 6. Duplicate-collection verification

Structurally guaranteed and now proven by a startup log. In the **service** log:

```powershell
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-*.jsonl" | Select-String "Interactive collectors disabled"
```

- ✅ The service (`AgentRuntime.CollectInteractiveInProcess = false`) logs:
  `Interactive collectors disabled in service mode: ScreenshotWorker not hosted,
  ApplicationSessionManager (foreground) not started, idle/heartbeat collector not
  started. …` (`Worker.cs`).
- ✅ `ScreenshotWorker` / `HeartbeatSpoolForwarder` / `ApplicationUsageSpoolForwarder`
  are hosted **only** in `--capture-helper` mode (`Program.cs`); the service hosts
  only `Worker` + `SyncWorker` + `ScreenshotHelperSupervisor` + `AgentEventSpoolWorker`.
- ☐ Confirm the line appears in the **service** log and does **not** appear in the
  helper log, and that no `Capture cycle complete` lines appear in the **service**
  log.

## 7. Backend verification

On the Laravel host:

```bash
# Screenshots — collection source
php artisan tinker --execute="\$s = App\Models\Screenshot::whereNotNull('image_hash')->latest()->first(); echo \$s ? (\$s->collection_mode.' session='.\$s->source_session_id.' user='.\$s->source_user) : 'none';"

# JSON events — collection source (in agent_events.payload)
php artisan tinker --execute="\$e = App\Models\AgentEvent::latest()->first(); echo \$e->kind->value.' '.(\$e->payload['CollectionMode'] ?? 'n/a').' session='.(\$e->payload['SourceSessionId'] ?? 'n/a');"
```

- ☐ Screenshot records show:
  - [ ] `CollectionMode = InteractiveHelper`
  - [ ] `SourceSessionId = 1` (or the interactive session — **not** 0)
  - [ ] `SourceUser = <user>`
- ☐ In the **admin dashboard** viewer, the capture shows *"Collected via
  InteractiveHelper (session 1, <user>)"*.
- ☐ `app_usage` / `heartbeat` events in `agent_events.payload` show
  `CollectionMode = InteractiveHelper` with a non-zero `SourceSessionId`.

---

## Sign-off

Phase 8 is **complete** when every ☐ above is checked on the Windows host and the
build (item 1) passes clean. Backend, tests and docs are already green in CI
(112 PHP tests passing; agent OS-agnostic unit tests included). The remaining work
is exclusively the on-device Windows verification captured here.
