# Treck Agent Installer (Phase 3)

Builds **`TreckAgentSetup.exe`** — a WiX v5 MSI wrapped in a Burn bootstrapper —
that installs the self-contained agent, enrolls the **computer** with a one-time
enrollment code, and registers the `TreckAgent` Windows Service.

> **Status:** authored but **not yet built or tested** — the CI environment has no
> Windows / .NET SDK / WiX toolset. Build and run the tests on a Windows machine
> (below). Treat the WiX/theme/DTF-packaging details as a first cut that may need
> minor Windows-side adjustment.

## What it does
1. Installs binaries to **`C:\Program Files\TreckAgent\`** (self-contained win-x64 — no .NET runtime prerequisite).
2. Creates **`C:\ProgramData\TreckAgent\{config,secrets,data,logs}`** (Permanent — preserved on upgrade/uninstall). `secrets\` is ACL-locked to SYSTEM + Administrators.
3. Runs **`TreckAgent.exe --enroll <code>`** (Phase 2) via a deferred custom action **before** the service starts. On failure the whole install rolls back — no half-configured service.
4. Registers service **`TreckAgent`** / display **“Treck Agent”**, **LocalSystem**, **Automatic**, recovery = restart on 1st/2nd/subsequent failures (reset daily).
5. Starts the service and finishes.

The employee only enters the **enrollment code** — never EmployeeCode, ProvisioningKey, device token, paths, or service details. Employee attribution stays runtime: Windows username → `computer_users` → Employee (shared PCs need no reinstall).

## Prerequisites (Windows build host)
```powershell
# .NET 8 SDK, then WiX v5:
dotnet tool install --global wix --version 5.0.2
wix extension add -g WixToolset.Util.wixext/5.0.2
wix extension add -g WixToolset.Bal.wixext/5.0.2
```

## Build
```powershell
cd agent\installer
.\build-installer.ps1          # → artifacts\TreckAgentSetup.exe (+ .msi)
```

## Files
| File | Purpose |
|---|---|
| `Package.wxs` | MSI: files, ProgramData dirs + `secrets` ACL, service, deferred enroll CA, MajorUpgrade, token-exists (upgrade) detection |
| `Treck.Agent.Installer.wixproj` | MSI project (references `WixToolset.Util.wixext`) |
| `ca/EnrollAction.cs`, `ca/Treck.Agent.Installer.CA.csproj` | managed DTF custom action that runs `--enroll` without logging the code |
| `Bundle.wxs`, `Treck.Agent.Bootstrapper.wixproj` | Burn bootstrapper → `TreckAgentSetup.exe` |
| `build-installer.ps1` | publish → CA → MSI → bundle |

## Enrollment code entry
- **Now (works):** `TreckAgentSetup.exe EnrollmentCode=TRK-XXXX-XXXX-XXXX` (optionally `BaseUrl=http://treck.local:8080`). Silent MSI: `msiexec /i Treck.Agent.Installer.msi ENROLLMENT_CODE=TRK-... /qn`.
- **Follow-up (pending Windows theme work):** an in-window “Enrollment code” field via a custom WixStdBA `theme.xml`/`theme.wxl` bound to the `EnrollmentCode` variable. Not shipped yet because it can't be authored/verified without a Windows WiX build.

## Security — how the code/token are protected
- **Enrollment code:** `ENROLLMENT_CODE` is in `MsiHiddenProperties`; the bundle variable is `Hidden="yes"` (masked in Burn logs). The managed CA never logs it. **Residual:** the code is passed as an argument to the child `TreckAgent.exe` during enrollment, so it is briefly visible on *that one process's* command line. To remove even that, add a ~10-line agent change to read the code from a `TRECK_ENROLLMENT_CODE` env var — **not done** (would touch approved Phase 2 code; awaiting your approval).
- **Device token:** never touched by the installer. Enrollment stores it via the existing `DpapiTokenStore`/`DpapiTokenProtector` (DPAPI, LocalMachine, ciphertext only) under `C:\ProgramData\TreckAgent`. The installer neither displays nor logs it.
- No credentials are exposed via MSI properties (hidden), command line (code only, transient), or logs.

## Upgrade behavior
`MajorUpgrade` + a `token.dat` `AppSearch`: on upgrade the enroll CA is **skipped** (no code required), and the Permanent ProgramData components preserve **DeviceId, token, offline queue, logs**. Same computer/identity after upgrade.

## Uninstall behavior
Stops + removes the service, removes `C:\Program Files\TreckAgent`. **Preserves** `C:\ProgramData\TreckAgent` (identity/token/queue) by design — mirrors `deploy/uninstall-service.ps1` default. No server deactivation call is made. (A `-PurgeData` equivalent can be added later if wanted.)

## Legacy safety
This installer targets `C:\Program Files\TreckAgent` only. It does **not** touch `C:\TreckAgent-Install\`. Migration from the legacy folder is a separate, later task.

## Windows test procedure (run these; paste results)
- **Test 1 — Clean install:** `php artisan treck:enroll-code --label="Installer test" --expires-days=1` → `TreckAgentSetup.exe EnrollmentCode=TRK-...` → installer succeeds; `Get-Service TreckAgent` = Running; Laravel shows one Computer with `employee_id = NULL`.
- **Test 2 — Invalid code:** run with a bad/expired code → install fails/rolls back; `Get-Service TreckAgent` absent; no `token.dat`; code not in `%TEMP%\*.log` (verbose MSI log: add `/l*v`).
- **Test 3 — Normal operation:** service auto-starts, loads DPAPI token (no ProvisioningKey), heartbeat/events reach Laravel.
- **Test 4 — Shared PC:** log in as `hasnain.qari` then `mehmood.alam`; same computer, attribution follows `computer_users`; no reinstall.
- **Test 5 — Reboot:** service auto-starts and resumes.
- **Test 6 — Upgrade:** install v1 → v-next; same DeviceId/Computer/token, offline data preserved, **no** new code prompted.
- **Test 7 — Legacy safety:** confirm `C:\TreckAgent-Install\` untouched and still working.

Capture a verbose MSI log with `msiexec /i Treck.Agent.Installer.msi /l*v install.log ENROLLMENT_CODE=...` and grep it for `TRK-` — expect **no** match (proves the code isn't logged).
