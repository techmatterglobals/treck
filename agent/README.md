# Treck Windows Agent (reference)

.NET 8 Windows Service that reports workstation activity to the Treck Laravel
API. Design and rationale: [`docs/17-windows-agent.md`](../docs/17-windows-agent.md).

> Reference implementation. It targets `net8.0-windows` and uses Win32 APIs, so
> it builds on a Windows/.NET toolchain — not in this Linux repo. Treat it as a
> correct-by-inspection starting point.

## Layout

| File | Role |
| ---- | ---- |
| `Program.cs` | Host builder, Windows Service registration, DI |
| `Worker.cs` | Lifecycle: register → login → tick → logout |
| `Configuration/AgentOptions.cs` | Typed config |
| `Services/ApiClient.cs` | Typed HttpClient (Bearer, snake_case, retry) |
| `Services/IdleDetector.cs` | `GetLastInputInfo` idle time |
| `Services/SessionMonitor.cs` | Lock/unlock/logoff events |
| `Services/ActivityTracker.cs` | Active/idle/status classification (pure) |
| `Services/TokenStore.cs` | DPAPI-encrypted token + device id |
| `Models/ApiModels.cs` | Request/response DTOs |

## Build & install (Windows, elevated)

```powershell
dotnet publish -c Release -r win-x64 --self-contained -o C:\Program Files\Treck
sc.exe create TreckAgent binPath= "C:\Program Files\Treck\TreckAgent.exe" start= auto
sc.exe failure TreckAgent reset= 86400 actions= restart/5000/restart/5000/restart/5000
sc.exe start TreckAgent
```

Configure `appsettings.json` (or MDM-deployed config) with your `BaseUrl`,
`ProvisioningKey`, and `EmployeeCode` before first run. The provisioning key is
used once to obtain a device token and can be removed afterward.
