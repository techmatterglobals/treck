# 24. Windows Agent — Milestone Build

The desktop agent (`agent/`) is built incrementally as a **.NET 8 Worker
Service**. Each milestone is self-contained and testable before the next
begins. This supersedes the earlier all-at-once reference sketch (the design
rationale in [doc 17](17-windows-agent.md) still applies).

## Milestone roadmap

| # | Scope | Requirements | Status |
| - | ----- | ------------ | ------ |
| **M1** | Skeleton, configuration, structured logging, service lifecycle | 9, 10 | ✅ Complete |
| M2 | API client + device registration + token storage | 1, 2 | Planned |
| M3 | Windows login/logout detection | 3, 4 | Planned |
| M4 | Idle-time calculation (Win32) + 60s heartbeat | 5, 6 | Planned |
| M5 | Reconnect on network failure + SQLite offline cache | 7, 8 | Planned |
| M6 | Windows Service packaging & install | — | Planned |

---

## M1 — Skeleton, configuration & structured logging ✅

### Architecture

- **Generic Host + Worker Service.** `Program.cs` builds a
  `HostApplicationBuilder`, registers the app as a Windows Service
  (`AddWindowsService`), and hosts a single `BackgroundService` (`Worker`). The
  same binary runs as a console app in development and as a service in
  production.
- **Configuration.** `AgentOptions` is bound from the `Agent` section of
  `appsettings.json` and **validated at startup** (`ValidateDataAnnotations` +
  `ValidateOnStart`) — the process refuses to start on invalid config
  (fail-fast), which is far safer than discovering a bad URL mid-run.
- **Structured logging.** Serilog is configured from the `Serilog` section:
  a human-readable **console** sink and a rolling **compact-JSON** file sink
  (`logs/treck-agent-<date>.jsonl`, 14-day retention). A bootstrap logger
  captures failures that occur before the host is built. All logs carry
  `MachineName`; later milestones add correlation properties (session id, etc.).
- **Lifecycle.** `Worker` logs a startup banner, then ticks on a
  `PeriodicTimer` at `HeartbeatIntervalSeconds`, and logs a clean stop on
  cancellation. No API/idle/session logic yet — that is deliberately deferred.

### Folder structure

```
agent/
├── Treck.Agent.csproj
├── Program.cs
├── Worker.cs
├── Configuration/AgentOptions.cs
├── appsettings.json
├── appsettings.Development.json
└── .gitignore
```

### How to test

1. **Build:** `cd agent && dotnet restore && dotnet build` — succeeds with no
   warnings (`TreatWarningsAsErrors` is on).
2. **Run:** `dotnet run` — expect a structured startup banner naming the server,
   employee code, and intervals, then a `Debug` "alive tick" each interval.
   (Set `DOTNET_ENVIRONMENT=Development` to see Debug ticks; production level is
   Information.)
3. **Logging:** confirm `agent/logs/treck-agent-<date>.jsonl` is created and each
   line is valid JSON.
4. **Config validation (fail-fast):** blank out `Agent:BaseUrl` in
   `appsettings.json` and run — startup should throw an
   `OptionsValidationException` and exit non-zero.
5. **Config override:** `Agent__HeartbeatIntervalSeconds=10 dotnet run` (env var)
   → ticks every 10s, proving configuration binding.
6. **Graceful shutdown:** Ctrl+C → a single "Treck Agent stopped." line, exit 0.

### Definition of done

Service boots, validates config, emits structured console + JSON-file logs, and
ticks on schedule; invalid config fails fast. ✅

---

*Next: M2 — API client, device registration, and secure token storage. Not
started until M1 is confirmed.*
