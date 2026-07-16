# Treck Windows Agent (.NET 8 Worker Service)

Desktop agent that reports workstation activity to the Treck Laravel API. Built
incrementally in milestones (see [`docs/24-windows-agent-build.md`](../docs/24-windows-agent-build.md)).
Design rationale: [`docs/17-windows-agent.md`](../docs/17-windows-agent.md).

## Milestones

| # | Scope | Status |
| - | ----- | ------ |
| M1 | Skeleton, configuration, structured logging | ✅ Complete |
| M2 | API client, device registration, token storage | ⏳ Planned |
| M3 | Windows login/logout detection | ⏳ Planned |
| M4 | Idle-time (Win32) + 60s heartbeat | ⏳ Planned |
| M5 | Reconnect + SQLite offline cache | ⏳ Planned |
| M6 | Windows Service packaging & install | ⏳ Planned |

## Layout (M1)

```
agent/
├── Treck.Agent.csproj        # net8.0-windows Worker Service
├── Program.cs                # Host builder, Serilog, options validation, DI
├── Worker.cs                 # BackgroundService (lifecycle + heartbeat tick)
├── Configuration/
│   └── AgentOptions.cs       # validated config (BaseUrl, keys, intervals)
├── appsettings.json          # Agent + Serilog config
├── appsettings.Development.json
└── .gitignore
```

## Requirements

- .NET 8 SDK, on Windows (targets `net8.0-windows`).

## Run (development)

```powershell
cd agent
dotnet restore
dotnet run
```

You should see a structured startup banner, then a `Debug` "alive tick" every
`HeartbeatIntervalSeconds`. A rolling compact-JSON log is written to
`agent/logs/treck-agent-<date>.jsonl`. Press Ctrl+C for graceful shutdown.

Override config via environment for a quick test:

```powershell
$env:Agent__HeartbeatIntervalSeconds = "10"; dotnet run
```

Invalid config fails fast at startup (e.g. blank `BaseUrl` → validation error).
