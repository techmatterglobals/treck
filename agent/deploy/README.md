# Treck Agent — Windows Service deployment

Deployment assets for running the Treck desktop agent as a Windows Service
(Milestone 6). The agent hosts fine as a console app for development
(`dotnet run`); these scripts package it for unattended production use.

| Script                  | Purpose                                                                    |
| ----------------------- | ------------------------------------------------------------------------- |
| `publish.ps1`           | Build a `win-x64` release. Framework-dependent by default; `-SelfContained` bundles the runtime. |
| `install-service.ps1`   | Register + start the service (automatic startup).                          |
| `uninstall-service.ps1` | Stop + remove the service (data preserved unless `-PurgeData`).            |

## Service identity

| Property     | Value                                                              |
| ------------ | ----------------------------------------------------------------- |
| Service name | `TreckAgent` (SCM key)                                             |
| Display name | `Treck Agent`                                                      |
| Description  | Treck employee productivity and PC activity monitoring agent …    |
| Startup      | Automatic                                                          |
| Account      | `LocalSystem` (can write `%ProgramData%\TreckAgent`)              |
| Recovery     | Restart after 5s / 10s / 30s; counter resets daily                |

## Prerequisites

- Windows 10/11 or Windows Server (x64).
- Administrator PowerShell for install/uninstall.
- .NET 8 SDK **only if building** (`publish.ps1` / `-Publish`).
- **.NET 8 Desktop Runtime (x64)** on each target machine for the default
  framework-dependent build. Install it once with
  `winget install Microsoft.DotNet.DesktopRuntime.8` (or download the
  "Desktop Runtime" installer from dotnet.microsoft.com). The **Desktop**
  runtime is required — not just the base runtime — because the agent's session
  detection uses `Microsoft.Win32.SystemEvents`, which lives in the Windows
  Desktop shared framework.
  - Skip this only if you publish with `-SelfContained` (the runtime is then
    bundled into the build, at the cost of downloading the ~130 MB win-x64
    runtime packs on the build host).

## 1. Configure (no secrets in source control)

Copy the template and fill in real values on the build/target machine:

```powershell
Copy-Item ..\appsettings.Production.json.example ..\appsettings.Production.json
notepad ..\appsettings.Production.json   # set BaseUrl, ProvisioningKey, EmployeeCode
```

`appsettings.Production.json` is git-ignored. The service loads it because
`install-service.ps1` sets `DOTNET_ENVIRONMENT=Production`; its values layer on
top of `appsettings.json`.

## 2. Publish + install

One step (build then install) — framework-dependent (needs the Desktop Runtime
on this machine):

```powershell
# From an elevated PowerShell in this folder:
./install-service.ps1 -Publish
```

Or publish once and install the output:

```powershell
./publish.ps1
./install-service.ps1 -PublishDir ..\publish
```

Air-gapped target with no runtime installed? Bundle it (larger; downloads the
runtime packs on the build host):

```powershell
./install-service.ps1 -Publish -SelfContained
```

Plain `dotnet` equivalents:

```powershell
# Framework-dependent, RID-less (default; no downloads, needs Desktop Runtime on target):
dotnet publish ..\Treck.Agent.csproj -c Release -o ..\publish

# Self-contained (no runtime needed on target; pins the RID, downloads ~130 MB of runtime packs):
dotnet publish ..\Treck.Agent.csproj -c Release -r win-x64 --self-contained true -o ..\publish
```

## 3. Verify

```powershell
Get-Service TreckAgent                       # Status = Running
sc.exe qc TreckAgent                         # START_TYPE = AUTO_START
Get-Content "$env:ProgramData\TreckAgent\logs\treck-agent-*.jsonl" -Tail 20
```

Start/stop lifecycle:

```powershell
Stop-Service TreckAgent
Start-Service TreckAgent
Restart-Service TreckAgent
```

## 4. Uninstall

```powershell
./uninstall-service.ps1              # remove service + binaries, keep identity/queue
./uninstall-service.ps1 -PurgeData  # also wipe %ProgramData%\TreckAgent
```

## Data & log locations

Everything writable lives under `%ProgramData%\TreckAgent`:

- `device-id` / encrypted token — device identity (DPAPI, machine scope).
- `offline\*.db` — SQLite offline event queue.
- `logs\treck-agent-*.jsonl` — rolling daily JSON logs (14 retained).

The agent redirects its file log here automatically when it detects it is
running under the SCM (the process working directory would otherwise be
`System32`). In console/dev mode logs are written to `./logs` next to the binary.

See [`docs/24-windows-agent-build.md`](../../docs/24-windows-agent-build.md) for
the full build, install, and end-to-end verification runbook.

## Troubleshooting registration

The `EmployeeCode` the agent sends comes from **config** (`Agent:EmployeeCode` in
`appsettings.json` / `appsettings.Production.json`, or the `Agent__EmployeeCode`
environment variable) - not from code. The base `appsettings.json` ships the
placeholder `REPLACE_WITH_EMPLOYEE_CODE`; set it to a real, existing employee
code for the deployment.

If registration fails, the agent log now prints the exact request it sent
(provisioning key masked) and the server's HTTP status **and response body**, e.g.:

```
Registering device: DeviceUuid=... EmployeeCode=EMP-0001 ... ProvisioningKey=***(17 chars)
Device registration HTTP 422 ... Response: {"message":"The selected employee code is invalid.", ...}
```

That immediately shows the cause. Common cases:

| Log shows | Fix |
| --------- | --- |
| `422 ... employee code is invalid` | `Agent:EmployeeCode` is not a real employee - set an existing code |
| `403` | provisioning key mismatch - align `Agent:ProvisioningKey` with the server's `TRECK_AGENT_PROVISIONING_KEY` |
| the login HTML page | server was redirecting API errors (fixed server-side; ensure the server is up to date) |
