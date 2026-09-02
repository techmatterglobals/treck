# Treck Agent Installer — Windows Build & Test Runbook (Phase 3 acceptance)

> **STATUS: PENDING — must be executed on a real Windows machine.**
> This repo's CI/dev environment has **no Windows, .NET SDK, or WiX toolset**, so
> the steps below have **not** been run here. Only static validation was done
> (XML well-formedness of every `.wxs`/`.wxl`/`.wixproj`, C# brace/paren balance,
> and schema/attribute checks against the WiX v5 source). Run this runbook on a
> Windows host and paste the output back; **do not consider Phase 3 complete until
> steps 1–22 and the security checks pass on Windows.**

Legend for expected results: ✅ = must be true to pass.

---

## 0. Prerequisites (build host)

- **Windows 10/11 or Windows Server 2019+.**
- **.NET 8 SDK** (`dotnet --version` → 8.x).
- **.NET Framework 4.7.2 developer pack** (the managed custom action targets `net472`).
- **WiX v5** is restored automatically by `dotnet build` because the installer
  projects use `Sdk="WixToolset.Sdk/5.0.2"` + `PackageReference` for the Util/Bal
  extensions. The global CLI is optional; if you prefer it:
  ```powershell
  dotnet tool install --global wix --version 5.0.2
  wix extension add -g WixToolset.Util.wixext/5.0.2
  wix extension add -g WixToolset.Bal.wixext/5.0.2
  ```
- A reachable **Treck Laravel server** (your local/LAN instance), and a way to run
  `php artisan` and query the DB on it.
- Do the install tests on a **separate clean Windows VM** (snapshot it first so you
  can re-run fresh installs and roll back).

Paths below are relative to the repo root.

---

## PART A — Build (steps 1–5)

### 1. .NET Release build
```powershell
dotnet build agent\Treck.Agent.sln -c Release
```
✅ Build succeeds, 0 errors. (Includes the `agent/Program.cs` env-var change.)

### 2. .NET unit tests
```powershell
dotnet test agent\tests\Treck.Agent.Tests\Treck.Agent.Tests.csproj -c Release
```
✅ All tests pass, including the Phase 2 `EnrollmentServiceTests` (11 tests).

Server-side enrollment tests (run on the Laravel host):
```bash
php artisan test --filter=EnrollmentTest
```
✅ All 10 enrollment tests pass (employee-neutral enroll, single/multi-use,
expired, revoked, unknown, plaintext-never-stored, re-enroll preserves employee
link, **legacy register still works**, artisan generate/revoke).

### 3. Self-contained win-x64 publish
```powershell
agent\deploy\publish.ps1 -SelfContained -RuntimeIdentifier win-x64 -OutputDir agent\installer\publish
```
✅ `agent\installer\publish\TreckAgent.exe` exists, alongside the bundled runtime
(no separate .NET install needed on the target).

### 4. WiX MSI build   &   ### 5. Burn bootstrapper build (`TreckAgentSetup.exe`)
One command does publish → CA → MSI → bundle:
```powershell
agent\installer\build-installer.ps1
```
✅ Produces `agent\installer\artifacts\TreckAgentSetup.exe` **and**
`agent\installer\artifacts\Treck.Agent.Installer.msi`.

<details><summary>Granular equivalents (if a step fails and you want to isolate it)</summary>

```powershell
# 4a. managed custom action (net472)
dotnet build agent\installer\ca\Treck.Agent.Installer.CA.csproj -c Release
#   → note the packaged CA DLL under agent\installer\ca\bin\...\ (commonly
#     Treck.Agent.Installer.CA.dll). build-installer.ps1 locates it automatically.

# 4b. MSI (pass the publish dir and the CA DLL's directory)
dotnet build agent\installer\Treck.Agent.Installer.wixproj -c Release `
  "-p:PublishDir=$PWD\agent\installer\publish" `
  "-p:CaDir=$PWD\agent\installer\ca\bin\Release\net472"

# 5. bundle (pass the built MSI)
dotnet build agent\installer\Treck.Agent.Bootstrapper.wixproj -c Release `
  "-p:MsiPath=$PWD\agent\installer\<path-to>\Treck.Agent.Installer.msi"
```
</details>

**If any of steps 1–5 fail: STOP, capture the exact error, and fix only that
issue before continuing.** The most likely first-build tweak is the custom-theme
control layout (positions/sizes) in `theme\EnrollTheme.xml`, which can only be
eyeballed on Windows.

---

## PART B — Fresh install + in-installer enrollment (steps 6–8)

On the Laravel host, mint a one-time code:
```bash
php artisan treck:enroll-code --label="Installer test" --expires-days=1
# prints e.g.  TRK-ABCD-EFGH-JKLM   (this plaintext is shown once)
```

### 6 + 7 + 8. Clean install, in-window code entry, real enrollment
On the clean Windows VM, double-click **`TreckAgentSetup.exe`** (or run it from an
elevated prompt). In the **“Connect this computer to Treck”** window:
- type the code into the **Enrollment code** field;
- (optional) put your server in **Server URL**, e.g. `http://treck.local:8080`
  — leave blank to use the agent's preconfigured `appsettings.json` BaseUrl;
- click **Install** (UAC shield → elevate).

✅ (6) Installer runs to the **Success** page.
✅ (7) The code was entered **in the installer window** (no command line).
✅ (8) Enrollment happened during install against your Laravel server.

For a **verbose MSI log** (used by the security checks), install the MSI directly:
```powershell
msiexec /i agent\installer\artifacts\Treck.Agent.Installer.msi /l*v install.log ENROLLMENT_CODE=TRK-ABCD-EFGH-JKLM
```

---

## PART C — Verification (steps 9–17)

### 9. Computer created with `employee_id = NULL`   &   ### 10. no EmployeeCode/ProvisioningKey required
On the Laravel host:
```bash
php artisan tinker --execute="\$c = App\Models\Computer::latest('id')->first(); echo json_encode(['id'=>\$c->id,'name'=>\$c->computer_name,'device_uuid'=>\$c->device_uuid,'employee_id'=>\$c->employee_id]);"
```
✅ (9) One new `Computer`, `employee_id` = **null**.
✅ (10) It was created by the code alone — the enroll request carries only
`code`, `device_uuid`, `computer_name`, `os`, `agent_version` (no `employee_code`,
no `provisioning_key`). Confirm the installed `appsettings.json` has **no**
`ProvisioningKey`/`EmployeeCode`:
```powershell
Select-String -Path "C:\Program Files\TreckAgent\appsettings.json" -Pattern "ProvisioningKey|EmployeeCode"
```
✅ No matches.

### 11. Device token stored as DPAPI-protected data under `%ProgramData%\TreckAgent`
```powershell
Get-ChildItem "C:\ProgramData\TreckAgent" -Force | Select-Object Name,Length
Get-Content "C:\ProgramData\TreckAgent\token.dat" -Encoding Byte -TotalCount 32
```
✅ `token.dat` exists at the **root** of `C:\ProgramData\TreckAgent`, and its
bytes are **ciphertext** (DPAPI, LocalMachine), not a readable JWT. `device.id`
also present. (Note: the installer also creates a `secrets\` folder ACL-locked to
SYSTEM+Administrators; the agent currently keeps `token.dat` at the root, already
DPAPI-protected — the locked folder is defense-in-depth for future secrets.)

### 12. Enrollment code / token not written to installer or agent logs
```powershell
# Installer (Burn + MSI) logs — code must NOT appear:
Select-String -Path "$env:TEMP\*.log","install.log" -Pattern "TRK-" -SimpleMatch
# Agent enrollment log — code and token must NOT appear:
Select-String -Path "C:\ProgramData\TreckAgent\logs\treck-agent-enroll-*.jsonl" -Pattern "TRK-"
```
✅ **No** matches for the code in any installer or agent log.
✅ The enroll log shows only device facts (`DeviceUuid`, `ComputerName`, `Os`,
`AgentVersion`) and an exit/status — never the code, token, or Authorization header.

### 13. `C:\Program Files\TreckAgent` contains the installed application
```powershell
Get-ChildItem "C:\Program Files\TreckAgent" | Select-Object Name
```
✅ Contains `TreckAgent.exe`, `appsettings.json`, and the self-contained runtime files.

### 14. `C:\ProgramData\TreckAgent` contains runtime data
```powershell
Get-ChildItem "C:\ProgramData\TreckAgent" -Force | Select-Object Name
```
✅ Contains `config`, `secrets`, `data`, `logs` subfolders plus `device.id` and `token.dat`.

### 15. Service installed Automatic + starts successfully
```powershell
Get-Service TreckAgent | Select-Object Name,Status,StartType
Get-CimInstance Win32_Service -Filter "Name='TreckAgent'" | Select-Object Name,StartName,State,StartMode
sc.exe qfailure TreckAgent
```
✅ `Status = Running`, `StartType = Automatic`, `StartName = LocalSystem`.
✅ `qfailure` shows RESTART actions (5s delay) with a 1-day reset — recovery config applied.

### 16. Normal agent startup after enrollment
```powershell
Get-Content "C:\ProgramData\TreckAgent\logs\treck-agent-*.jsonl" -Tail 40
```
✅ Service starts using the DPAPI token (no ProvisioningKey path), no
`run --enroll` guard error, topology logged (service session-0 + helper).

### 17. Agent events / heartbeat reach Laravel
Wait one heartbeat interval, then on the Laravel host:
```bash
php artisan tinker --execute="\$c = App\Models\Computer::latest('id')->first(); echo json_encode(['last_seen'=>\$c->last_seen_at,'presence'=>\$c->presence_status ?? null]);"
```
✅ `last_seen_at` updates; heartbeat/events arrive. (Screenshots/app-usage per your config.)

---

## PART D — Lifecycle (steps 18–22)

### 18. Shared-PC employee resolution via `computer_users`
Map two Windows usernames to employees, e.g.:
```bash
php artisan tinker --execute="App\Models\ComputerUser::updateOrCreate(['computer_id'=>C,'windows_username'=>'hasnain.qari'],['employee_id'=>E1]); App\Models\ComputerUser::updateOrCreate(['computer_id'=>C,'windows_username'=>'mehmood.alam'],['employee_id'=>E2]);"
```
(substitute real ids). Log into the Windows VM as `hasnain.qari`, generate
activity, then log in as `mehmood.alam`.
✅ Same `Computer` (no reinstall), and activity attributes to the employee mapped
for the currently signed-in Windows user via `computer_users`.

### 19. Reinstall / repair
```powershell
msiexec /i agent\installer\artifacts\Treck.Agent.Installer.msi /l*v repair.log
# or "Repair" from Apps & features
```
✅ Repair completes; **no enrollment-code prompt** (existing `token.dat`
detected); service still Running; `device.id`/`token.dat` unchanged.

### 20. Upgrade without a new code
Bump the agent + installer version (e.g. 1.0.0 → 1.0.1), rebuild
`TreckAgentSetup.exe`, run it on the already-installed VM.
✅ Upgrades in place; **no code prompted** (the bundle's `util:FileSearch` finds
`token.dat`, and the condition is scoped to fresh installs only); same
`device.id`/Computer/token; offline queue and logs preserved.

### 21. Uninstall (ProgramData preserved by design)
```powershell
msiexec /x agent\installer\artifacts\Treck.Agent.Installer.msi /l*v uninstall.log
Get-Service TreckAgent -ErrorAction SilentlyContinue
Test-Path "C:\Program Files\TreckAgent"
Test-Path "C:\ProgramData\TreckAgent\token.dat"
```
✅ Service stopped + removed; `C:\Program Files\TreckAgent` gone;
`C:\ProgramData\TreckAgent` (identity/token/queue) **preserved** by design
(mirrors `deploy/uninstall-service.ps1` default). Uninstall is **not** blocked by
the code condition.

### 22. Legacy `/register` compatibility
The legacy endpoint is untouched. Confirm it still works (server-side test already
covers it in step 2; to exercise the HTTP path directly):
```bash
curl -sS -X POST http://treck.local:8080/api/agent/register \
  -H "Content-Type: application/json" \
  -d '{"provisioning_key":"<key>","employee_code":"<code>","device_uuid":"legacy-test-uuid","computer_name":"LEGACY-PC"}'
```
✅ Returns a device token (201) exactly as before — the new enroll flow did not
change `agent/register`.

---

## PART E — Security checks (must all hold)

| Check | How to verify on Windows | Expected |
|---|---|---|
| Code **not** on the child process command line | While enrolling (or by design review of `ca/EnrollAction.cs`): `Get-CimInstance Win32_Process -Filter "name='TreckAgent.exe'" \| Select CommandLine` | Command line is `--enroll` (± `--base-url ...`) with **no code**. |
| CA uses `TRECK_ENROLLMENT_CODE` env var | `ca/EnrollAction.cs` sets `startInfo.EnvironmentVariables["TRECK_ENROLLMENT_CODE"]`; agent reads it in `agent/Program.cs` | Confirmed in code; enrollment succeeds with no code arg. |
| Env var **not** persisted | `[Environment]::GetEnvironmentVariable('TRECK_ENROLLMENT_CODE','Machine')` and `...,'User')` | Both **null** (set only on the transient child process). |
| Code **not** logged | `Select-String ... -Pattern "TRK-"` over `%TEMP%\*.log`, `install.log`, `treck-agent-enroll-*.jsonl` | No matches. |
| Token **not** logged | `Select-String` the agent logs for a JWT fragment / `token` value | No token value in any log. |
| Authorization headers **not** logged | `Select-String` agent logs for `Bearer ` | No `Bearer <token>` in logs (headers are set on requests, never logged). |
| No ProvisioningKey/EmployeeCode in installer config | `Select-String "C:\Program Files\TreckAgent\appsettings.json" -Pattern "ProvisioningKey\|EmployeeCode"` and inspect installer inputs | Neither is collected or written by the installer. |

---

## Known limitations (carry into the Windows run)

- **Custom theme layout** (`theme\EnrollTheme.xml`): control X/Y/width/height are
  authored blind — verify spacing visually on the first build and nudge if needed.
- **Packaged CA DLL name**: `build-installer.ps1` auto-locates it, but confirm the
  DTF output name (commonly `Treck.Agent.Installer.CA.dll`) on your host.
- **`secrets\` folder is currently unused** by the agent (token lives DPAPI-protected
  at the ProgramData root); it's ACL-hardened for future use.
- **“Verify” is install-time**, not a live pre-check: an invalid code fails during
  install (rollback + failure page), which is the authoritative validation.
- No updater/auto-update (out of Phase 3 scope).

---

## What was committed

Implementation + this runbook are committed and pushed (no PR opened) — see the
installer commits on `claude/laravel-project-continue-bff4il` and cherry-picked to
`claude/employee-monitoring-laravel-arch-mbjgyp`. **No Windows build/install was
performed here; those results are PENDING your run.**
