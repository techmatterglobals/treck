# Treck Agent Installer (Phase 3)

Builds **`TreckAgentSetup.exe`** — a WiX v5 MSI wrapped in a Burn bootstrapper —
that installs the self-contained agent, collects a one-time enrollment code **in the
installer window**, enrolls the **computer**, and registers the `TreckAgent` Windows
Service.

> **Status:** authored but **not yet built or tested here** — this environment has no
> Windows / .NET SDK / WiX toolset. Build and run the tests on a Windows machine
> (below). The custom theme layout (control positions/sizes) is best verified
> visually on the first Windows build.

## What it does
1. Shows a **“Connect this computer to Treck”** page with an **Enrollment code** field (custom WixStdBA theme, `theme\EnrollTheme.xml`). Install is blocked until a code is entered (`bal:Condition`). An optional **Server URL** field targets a specific LAN/VPS server; leave blank to use the agent's preconfigured server.
2. Installs binaries to **`C:\Program Files\TreckAgent\`** (self-contained win-x64 — no .NET runtime prerequisite).
3. Creates **`C:\ProgramData\TreckAgent\{config,secrets,data,logs}`** (Permanent — preserved on upgrade/uninstall). `secrets\` is ACL-locked to SYSTEM + Administrators.
4. Runs the agent's enrollment (Phase 2) via a deferred custom action **before** the service starts, passing the code through a **process-scoped environment variable** (`TRECK_ENROLLMENT_CODE`) — never the command line. On failure the whole install rolls back — no half-configured service.
5. Registers service **`TreckAgent`** / display **“Treck Agent”**, **LocalSystem**, **Automatic**, recovery = restart on 1st/2nd/subsequent failures (reset daily), then starts it.

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
| `ca/EnrollAction.cs`, `ca/Treck.Agent.Installer.CA.csproj` | managed DTF custom action; runs the agent's enrollment, passing the code via the `TRECK_ENROLLMENT_CODE` env var (never logged, never on a command line) |
| `Bundle.wxs`, `Treck.Agent.Bootstrapper.wixproj` | Burn bootstrapper → `TreckAgentSetup.exe` |
| `theme/EnrollTheme.xml`, `theme/EnrollTheme.wxl` | custom WixStdBA theme: the in-window enrollment-code UI |
| `build-installer.ps1` | publish → CA → MSI → bundle |

## Enrollment code entry
- **Interactive (default):** the installer window shows an **Enrollment code** field on the “Connect this computer to Treck” page; Install is blocked until it's filled. Optional **Server URL** field for LAN/VPS targeting.
- **Silent / GPO:** the same values can be supplied on the command line — `TreckAgentSetup.exe EnrollmentCode=TRK-XXXX-XXXX-XXXX [BaseUrl=http://treck.local:8080]`, or `msiexec /i Treck.Agent.Installer.msi ENROLLMENT_CODE=TRK-... /qn`.

## Security — how the code/token are protected
- **Enrollment code:** captured into the `Hidden` bundle variable `EnrollmentCode` (masked in the Burn log) → MSI property `ENROLLMENT_CODE` (`Secure`, listed in `MsiHiddenProperties`, so masked in the MSI log and not persisted) → deferred CA `CustomActionData` (also hidden). The CA hands it to the agent through a **process-scoped environment variable** (`TRECK_ENROLLMENT_CODE`) set on that child process only — so the code **never appears on any command line** (not visible in Process Explorer / WMI) and is not persisted anywhere. Neither the CA nor the agent logs it.
- **Required agent change (Phase 2, additive):** the agent's `--enroll` path now reads the code from `TRECK_ENROLLMENT_CODE` when no code argument is given (the `--enroll <code>` argument form still works and takes precedence). ~10 lines in `agent/Program.cs`; the endpoint, `--enroll`, and DPAPI storage are unchanged.
- **Device token:** never touched by the installer. Enrollment stores it via the existing `DpapiTokenStore`/`DpapiTokenProtector` (DPAPI, LocalMachine, ciphertext only) under `C:\ProgramData\TreckAgent`. The installer neither displays nor logs it.
- No credentials are exposed via MSI properties (hidden), the command line (code travels via a process env var), or logs.

## Upgrade behavior
`MajorUpgrade` + a `token.dat` `AppSearch`: on upgrade the enroll CA is **skipped** (no code required), and the Permanent ProgramData components preserve **DeviceId, token, offline queue, logs**. Same computer/identity after upgrade.

## Uninstall behavior
Stops + removes the service, removes `C:\Program Files\TreckAgent`. **Preserves** `C:\ProgramData\TreckAgent` (identity/token/queue) by design — mirrors `deploy/uninstall-service.ps1` default. No server deactivation call is made. (A `-PurgeData` equivalent can be added later if wanted.)

## Legacy safety
This installer targets `C:\Program Files\TreckAgent` only. It does **not** touch `C:\TreckAgent-Install\`. Migration from the legacy folder is a separate, later task.

## Windows test procedure (run these; paste results)
- **Test 1 — Clean install (in-window field):** `php artisan treck:enroll-code --label="Installer test" --expires-days=1` → double-click `TreckAgentSetup.exe`, type the code into the **Enrollment code** field, click **Install** → installer succeeds; `Get-Service TreckAgent` = Running; Laravel shows one Computer with `employee_id = NULL`. (Silent equivalent: `TreckAgentSetup.exe EnrollmentCode=TRK-...`.)
- **Test 1b — Empty code blocked:** launch the installer and click **Install** with the field blank → the "Please enter your enrollment code" message appears and install does not proceed.
- **Test 2 — Invalid code:** enter a bad/expired code → install fails/rolls back and shows the failure page; `Get-Service TreckAgent` absent; no `token.dat`; code not in `%TEMP%\*.log` (verbose MSI log: add `/l*v`).
- **Test 3 — Normal operation:** service auto-starts, loads DPAPI token (no ProvisioningKey), heartbeat/events reach Laravel.
- **Test 4 — Shared PC:** log in as `hasnain.qari` then `mehmood.alam`; same computer, attribution follows `computer_users`; no reinstall.
- **Test 5 — Reboot:** service auto-starts and resumes.
- **Test 6 — Upgrade:** install v1 → v-next; same DeviceId/Computer/token, offline data preserved, **no** new code prompted.
- **Test 7 — Legacy safety:** confirm `C:\TreckAgent-Install\` untouched and still working.

Capture a verbose MSI log with `msiexec /i Treck.Agent.Installer.msi /l*v install.log ENROLLMENT_CODE=...` and grep it for `TRK-` — expect **no** match (proves the code isn't logged).
