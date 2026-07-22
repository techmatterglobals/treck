# Changelog

All notable changes to Treck are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-07-22

First production release. Delivered across ten phases (architecture → backend →
security → documentation → Windows agent → real-time presence → application usage
→ screenshots → notifications → production release).

### Added

- **Backend (Laravel 11):** domain model, 23 migrations, service layer, REST API
  (agent + user), Sanctum authentication with ability gates, Spatie roles.
- **Windows agent (.NET 8 Windows Service):** device registration (DPAPI-encrypted
  token), work sessions, activity heartbeats, presence, foreground application
  tracking (WinEvent hooks), opt-in screenshots, SQLite offline queue with
  resilient ordered sync (Polly retry/backoff), Serilog logging.
- **Real-time presence:** materialized `computer_presence`, event projection,
  broadcasting, and a live Livewire admin dashboard (no polling).
- **Application usage:** usage projection and an admin dashboard (summary, top
  apps, timeline, per-employee/department breakdowns, searchable sessions).
- **Screenshots:** opt-in scheduled capture, private-disk storage, short-lived
  signed access behind an admin policy, hash-based dedup, retention pruning.
- **Notifications:** centralized rule engine, in-app + email channels, per-user
  preferences (channels, min severity, digest, quiet hours), live bell and
  dashboard, fully asynchronous evaluation and delivery.
- **Reporting:** productivity reports with Excel and PDF export.
- **Operations:** scheduled rollups, session reconciliation, presence sweep, and
  retention pruning for screenshots and raw agent events.

### Phase 10 (production release) changes

- Added `treck:prune-events` command and daily schedule to enforce the
  previously-declared raw-event retention (`treck.retention.raw_heartbeat_days`),
  preventing unbounded growth of `agent_events`; covered by tests.
- Project-wide Laravel Pint formatting pass (clean `pint --test`).
- Added `phpstan.neon` (Larastan, level 6) for CI static analysis.
- Aligned version to **1.0.0** across `agent/Treck.Agent.csproj`,
  `config/treck.php`, and `composer.json`.
- Updated README, SETUP, and module docs; added
  `docs/30-production-release.md` (final architecture, deployment, upgrade,
  operations, disaster recovery, release notes) and this changelog.

### Security

- No secrets committed; no sensitive logging; signed-URL screenshot access;
  device-bound agent tokens; rate-limited registration and login; validated
  input and escaped output. No existing control was weakened.

[1.0.0]: https://github.com/techmatterglobals/treck/releases/tag/v1.0.0
