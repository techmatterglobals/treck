# Treck Windows Agent (.NET 8 Worker Service)

Desktop agent that reports workstation activity to the Treck Laravel API. Built
incrementally in milestones (see [`docs/24-windows-agent-build.md`](../docs/24-windows-agent-build.md)).
Design rationale: [`docs/17-windows-agent.md`](../docs/17-windows-agent.md).

## Milestones

| # | Scope | Status |
| - | ----- | ------ |
| M1 | Skeleton, configuration, structured logging | ✅ Complete |
| M2 | API client, device registration, token storage | ✅ Complete |
| M3 | Windows session detection (logon/logoff/lock/unlock/shutdown) | ✅ Complete |
| M4 | Idle-time (Win32) + 60s heartbeat | ✅ Complete |
| M5 | Reconnect + SQLite offline cache | ✅ Complete |
| M6 | Windows Service packaging & install | ⏳ Planned |

## Layout (through M2)

```
agent/
├── Treck.Agent.sln           # solution (Treck.Agent + Treck.Agent.Tests)
├── Treck.Agent.csproj        # net8.0-windows Worker Service (excludes tests/**)
├── Program.cs                # Host, Serilog, options validation, DI, HttpClient + Polly
├── Worker.cs                 # BackgroundService (ensures registration on start)
├── Api/                      # ITreckApiClient + TreckApiClient + ApiException
├── Configuration/            # AgentOptions (validated)
├── Models/                   # request/response DTOs + ApiEnvelope
├── Security/                 # ITokenProtector + DpapiTokenProtector (DPAPI)
├── Storage/                  # device-id + encrypted token stores, path provider
├── Services/                 # IDeviceRegistrationService + impl
├── Sessions/                 # ISessionMonitor + WindowsSessionMonitor (+ base, events)
├── Activity/                 # IIdleDetector + heartbeat scheduler/calculator/event
├── Offline/                  # IOfflineEventStore + SqliteEventStore (durable queue)
├── Sync/                     # ISyncService + SyncWorker + IEventUploader
├── appsettings.json / appsettings.Development.json
├── .gitignore
└── tests/Treck.Agent.Tests/  # xUnit + Moq
```

## Requirements

- .NET 8 SDK, on Windows (targets `net8.0-windows`).

## Build, run & test

```powershell
cd agent
dotnet build Treck.Agent.sln     # builds both projects
dotnet test  Treck.Agent.sln     # runs the unit tests
dotnet run   --project Treck.Agent.csproj
```

You should see a structured startup banner, then a `Debug` "alive tick" every
`HeartbeatIntervalSeconds`. A rolling compact-JSON log is written to
`agent/logs/treck-agent-<date>.jsonl`. Press Ctrl+C for graceful shutdown.

Override config via environment for a quick test:

```powershell
$env:Agent__HeartbeatIntervalSeconds = "10"; dotnet run
```

Invalid config fails fast at startup (e.g. blank `BaseUrl` → validation error).

## Tests

```powershell
dotnet test
```

Covers DPAPI encryption (Windows), device-id persistence, the registration
client, and registration orchestration. See
[`docs/24-windows-agent-build.md`](../docs/24-windows-agent-build.md) for the
full M2 walkthrough and sequence diagram.
