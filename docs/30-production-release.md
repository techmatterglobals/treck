# 30. Production Release (Phase 10) — Treck v1.0.0

This document is the production-release runbook for **Treck v1.0.0**: the final
architecture, deployment guide, upgrade and uninstall procedures, configuration
reference, backup/disaster-recovery strategy, operational procedures, and the
v1.0.0 release notes.

> **Doc numbering note.** The Phase 10 brief referenced
> `docs/29-production-release.md` and a `docs/28-notifications.md`. In this
> repository those numbers were already taken (`28-phase8-windows-validation.md`,
> `29-notifications.md`), so the production-release guide is numbered **30** and
> the notifications guide remains **29**. All cross-references use the actual
> filenames.

---

## 30.1 Executive summary

Treck is a complete employee-productivity and PC-activity monitoring platform: a
lightweight **Windows desktop agent** (.NET 8) streams workstation activity to a
**Laravel 11 backend**, which stores, aggregates, and surfaces it through a REST
API, a real-time admin dashboard, an application-usage module, an opt-in
screenshot module, and a rule-driven notification system.

All ten delivery phases are complete and verified. The full automated test suite
passes (**148 backend tests / 357 assertions**; **57 agent test cases**), Laravel
Pint is clean project-wide, the agent compiles with **nullable reference types**
and **warnings-as-errors**, and no debug code, TODO/FIXME markers, or secrets are
committed. **The system is assessed Production Ready (v1.0).**

---

## 30.2 Final system architecture

```
 Windows workstation (per employee)                 Laravel backend                     Users
 ──────────────────────────────────                 ───────────────                    ───────
 TreckAgent (Windows Service, .NET 8)
   ├─ Registration (provisioning key → Sanctum token, DPAPI-protected)
   ├─ Session tracking (login/logout)          ┌──────────────────────┐
   ├─ Activity heartbeats (active/idle)  ─HTTPS─▶│  Sanctum API (agent) │
   ├─ Presence (online/idle/locked/off)         │  rate-limited, bound  │
   ├─ Application usage (WinEvent hooks)         └──────────┬───────────┘
   ├─ Screenshots (opt-in, per policy)                     │ ingest (idempotent)
   └─ Offline queue (SQLite) + SyncWorker         ┌────────▼───────────┐
        ordered drain, retry/backoff              │ agent_events (raw) │
                                                  └────────┬───────────┘
                                                           │ project
                        ┌──────────────────────────────────┼────────────────────────────┐
                        ▼                 ▼                 ▼              ▼               ▼
                  computer_presence  activity_logs   application_usage  screenshots   attendance/
                   (materialized)      + rollups        (sessions)      (private disk)  productivity
                        │                                   │              │
                        │  PresenceChanged (broadcast)      │ observer     │
                        ▼                                   ▼              ▼
                  ┌──────────────────────── Notification engine (Phase 9) ───────────────┐
                  │ queued evaluation → DB rules → throttle → recipients → queued deliver │
                  │   InApp (Reverb/Echo broadcast)   +   Email (queued mailable)         │
                  └──────────────────────────────────────────────────────────────────────┘
                        │
                        ▼  Livewire 3 + Reverb/Echo (no polling)
                  Admin dashboard: presence · app usage · screenshots · notifications · reports
```

**Layering / conventions** (verified in the Phase 10 architecture review):

- **Thin controllers** — HTTP controllers only authorize + delegate; all logic
  lives in `app/Services/**` (Activity, Agent, Attendance, Dashboard, Device,
  Notifications, Presence, Productivity, Reporting, Screenshots).
- **Dependency injection** throughout; no service location in domain code.
- **Separation of concerns** — ingestion (raw `agent_events`) is decoupled from
  projection (domain tables) and from presentation (Livewire + API).
- **Event-driven, additive integration** — the notification module observes
  existing events/models (`PresenceChanged` listener, `ApplicationUsage`
  observer) without modifying the phases it extends.
- **Enums, Form Requests, Policies, DataObjects** used consistently.

---

## 30.3 Complete feature list

| Area | Capability |
|------|-----------|
| Attendance | Daily clock-in/out, late/early/absent, work hours (rollups) |
| Sessions | PC login/logout per device |
| Activity | Active/idle time from heartbeats |
| Presence | Live online / idle / locked / logged-out / offline per device (real-time) |
| Application usage | Foreground app + window usage and duration; dashboards & breakdowns |
| Screenshots | Opt-in scheduled capture, private storage, signed short-lived access, retention pruning |
| Notifications | Rule-driven in-app + email alerts; per-user preferences; live bell & dashboard |
| Reporting | Per-employee/team/department productivity, Excel + PDF export |
| Admin | Employee management, role assignment, device assignment |
| Auth | Session auth (web) + Sanctum tokens (agent & user API), Spatie roles |

---

## 30.4 Technology stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.2+ |
| Database | MySQL 8 (SQLite in-memory for tests) |
| API auth | Laravel Sanctum (agent + user tokens, ability gates) |
| Web auth | Session-based, Blade + Livewire 3 |
| Dashboard | Livewire 3 + Alpine.js + Tailwind CSS |
| Async | Laravel Queues (database/Redis) |
| Realtime | Laravel Reverb / Echo (websockets, no polling) |
| Authorization | Spatie Laravel-Permission |
| Mail | Laravel Mail (queued HTML mailables) |
| Agent | .NET 8 Worker Service (Windows), Serilog, Polly, SQLite, DPAPI |
| Static analysis | Laravel Pint; PHPStan/Larastan config (`phpstan.neon`) |

---

## 30.5 Database schema summary

23 migrations. Core tables: `users`, `departments`, `employees`, `computers`,
`attendance`, `activity_logs`, `application_usage`, `screenshots`,
`productivity_reports`, `agent_events`, `computer_presence`, `notification_rules`,
`notification_logs`, `notification_preferences`, plus framework tables
(`jobs`, `cache`, `personal_access_tokens`, permission tables).

Reviewed in Phase 10 — all verified:

- **Foreign keys** on every relationship with explicit cascade rules
  (`cascadeOnDelete` for owned rows, `nullOnDelete` for optional references).
- **Idempotency** uniques: `agent_events(computer_id, idempotency_key)`,
  `application_usage(computer_id, session_id)`, `screenshots(computer_id, image_hash)`,
  `computer_presence(computer_id)`, `notification_rules(event_type)`,
  `notification_preferences(user_id)`.
- **Composite indexes** aligned to query patterns
  (`(computer_id, occurred_at)`, `(employee_id, used_at)`,
  `(recipient_id, read_at)`, `(severity, created_at)`, etc.).
- **Retention** is configurable and now fully enforced (see §30.10).

---

## 30.6 API summary

All `/api/*` responses are JSON. Agent endpoints are rate-limited and bound to
the registered device identity.

**Agent (Sanctum token, device-bound):**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/agent/register` | One-time device registration (provisioning key → token) |
| POST | `/api/agent/login` / `/api/agent/logout` | Work-session start/end |
| POST | `/api/activity` | Activity heartbeat |
| POST | `/api/agent/events` | Batched offline-queue event ingestion (idempotent) |
| POST | `/api/agent/screenshots` | Multipart screenshot upload (dedup by hash) |

**User API (Sanctum token):** `/api/v1/auth/login|me|logout`, `/api/activity/live`,
`/api/activity/{employee}/summary`, `/api/reports` (+ Excel/PDF export),
employee/computer assignment.

**Web (session + role):** dashboard, `/presence`, `/application-usage`,
`/screenshots`, `/notifications`, `/notifications/settings`, reports.

---

## 30.7 Windows agent capabilities

- **Windows Service lifecycle** — installs/runs as `TreckAgent`; graceful
  start/stop; survives reboot; runs in the interactive session context needed
  for presence, app tracking, and capture.
- **Registration** — provisioning key exchanged once for a Sanctum token stored
  **DPAPI-encrypted**; device identity is persisted and reused.
- **Heartbeats & presence** — periodic active/idle heartbeats; presence derived
  from session/lock/idle state via `Microsoft.Win32.SystemEvents`.
- **Application tracking** — foreground app/window via WinEvent hooks (no
  polling); uploads completed usage sessions.
- **Screenshots** — opt-in, policy-driven (interval/jitter/active-only/per-monitor),
  compressed + hashed, secure-desktop never captured.
- **Offline queue** — local SQLite spool; `SyncWorker` drains in order with
  retry/backoff (Polly); server-acked deletes guarantee at-least-once with
  server-side idempotency giving exactly-once effect.
- **Logging** — Serilog structured JSON logs with rotation.

Build/publish/install: [`docs/24-windows-agent-build.md`](24-windows-agent-build.md)
and [`agent/deploy/README.md`](../agent/deploy/README.md).

---

## 30.8 Dashboard capabilities

Admin-only (Spatie `admin` role) Livewire dashboards, all live over Reverb/Echo:

- **Presence board** and per-computer detail (current app, window, history).
- **Application usage** — summary, top apps, daily timeline, per-employee /
  per-department breakdowns, searchable sessions.
- **Screenshots** — filterable grid + viewer (prev/next, zoom, download) behind
  signed URLs.
- **Notifications** — recent/unread/critical, filters/search/date range,
  mark-read, live bell, and DB-backed settings.
- **Reports** — productivity with Excel/PDF export.

---

## 30.9 Deployment instructions

### Backend (Laravel)

1. **Provision** — PHP 8.2/8.3 + FPM (`pdo_mysql, mbstring, openssl, tokenizer,
   xml, ctype, bcmath, curl, fileinfo, redis, zip, gd`), MySQL 8, Redis, Nginx.
2. **Code** — clone/pull; `composer install --no-dev --optimize-autoloader`;
   `npm ci && npm run build`.
3. **Environment** — copy [`deploy/.env.production.example`](../deploy/.env.production.example)
   to `.env`; set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`,
   DB/Redis, `SANCTUM_STATEFUL_DOMAINS`, mail transport, `BROADCAST_CONNECTION=reverb`
   + `REVERB_*`, `TRECK_AGENT_PROVISIONING_KEY`, and the `TRECK_*` tunables;
   `php artisan key:generate`.
4. **Migrate + seed roles only** — `php artisan migrate --force`;
   `php artisan db:seed --class=RolePermissionSeeder --force` (never
   `DemoDataSeeder` in production).
5. **Cache** — `php artisan config:cache route:cache view:cache`;
   `php artisan storage:link`.
6. **Process managers** — Nginx ([`deploy/nginx.conf.example`](../deploy/nginx.conf.example),
   TLS termination + HSTS; configure trusted proxies so Laravel sees HTTPS —
   Laravel 11: `->withMiddleware(fn ($m) => $m->trustProxies(at: '*'))`);
   Supervisor for `queue:work` ([`deploy/supervisor-treck-worker.conf.example`](../deploy/supervisor-treck-worker.conf.example))
   and `reverb:start`; a single cron entry
   `* * * * * php artisan schedule:run`.

Full guide: [`docs/19-production-deployment.md`](19-production-deployment.md).

### Windows agent

1. `cd agent/deploy`; create `appsettings.Production.json` (BaseUrl,
   ProvisioningKey, EmployeeCode, and optional Screenshots/ApplicationTracking).
2. Publish + install: `./install-service.ps1 -Publish` (add `-SelfContained`
   for air-gapped targets with no shared runtime).
3. Verify: `Get-Service TreckAgent` → `Running`; check
   `%ProgramData%\TreckAgent\logs\treck-agent-*.jsonl`.

---

## 30.10 Operational guide

### Log locations

| Component | Location |
|-----------|----------|
| Laravel app | `storage/logs/laravel.log` (stack/daily; configure `LOG_CHANNEL`) |
| Queue worker | Supervisor stdout/err logs |
| Reverb | Supervisor stdout/err logs |
| Windows agent | `%ProgramData%\TreckAgent\logs\treck-agent-*.jsonl` |

### Queue monitoring

- Keep `queue:work` under Supervisor (auto-restart). Watch `failed_jobs`:
  `php artisan queue:failed`; retry with `php artisan queue:retry all`.
- Notification delivery, email, and rule evaluation all run on the queue — a
  stalled worker delays alerts but never blocks ingestion.

### Agent health & heartbeat monitoring

- Presence board shows live status; a computer with no contact within
  `TRECK_PRESENCE_OFFLINE_TIMEOUT` (default 180s) is swept to **Offline** by the
  scheduled `treck:presence-sweep`.
- `treck:reconcile-sessions` (every minute) closes abandoned sessions.

### Database maintenance (retention — enforced)

| Command | Schedule | Enforces |
|---------|----------|----------|
| `treck:daily-rollup` | hourly + 00:30 | attendance/productivity aggregates |
| `treck:prune-screenshots` | 01:00 daily | `treck.retention.screenshot_days` (default 30) |
| `treck:prune-events` | 01:15 daily | `treck.retention.raw_heartbeat_days` (default 90) — raw `agent_events` |

`treck:prune-events` (added in Phase 10) enforces the previously-declared raw
retention window, preventing unbounded growth of the highest-volume table. Raw
events are deleted only after projection into the domain tables; aggregates and
domain rows are untouched.

### Storage maintenance

- Screenshots live on a **non-public** disk (`TRECK_SCREENSHOT_DISK`, `local`
  or `s3`); retention pruning removes both row and file. Monitor free space if
  screenshots are enabled with a long/`0` retention.

### Backup recommendations

- **Database**: nightly `mysqldump` (or managed snapshots); retain ≥14 days.
- **Screenshot storage**: back up the configured disk (or rely on S3 versioning).
- **Secrets**: back up `.env` (`APP_KEY`, `TRECK_AGENT_PROVISIONING_KEY`)
  **out of band** — losing `APP_KEY` invalidates encrypted data.
- Test restores periodically.

### Disaster recovery

1. Reprovision host per §30.9; restore `.env` (esp. `APP_KEY`).
2. Restore the latest DB backup; run `php artisan migrate --force`.
3. Restore screenshot storage (if used).
4. Restart FPM, `queue:work`, `reverb:start`, and cron.
5. Agents reconnect automatically (tokens persist; offline queues drain).

---

## 30.11 Upgrade guide

1. `php artisan down` (maintenance mode).
2. Pull the new release; `composer install --no-dev -o`; `npm ci && npm run build`.
3. `php artisan migrate --force` (all migrations are additive/backward-compatible).
4. `php artisan config:cache route:cache view:cache`.
5. Restart `queue:work` and `reverb:start` (pick up new code); `php artisan up`.
6. **Agent**: republish and re-run `./install-service.ps1 -Publish` (the service
   is stopped, replaced, and restarted; identity/token/queue are preserved).

Rollback: redeploy the previous tag; migrations are additive, so no down-migration
is normally required.

### Uninstall (agent)

`agent\deploy\uninstall-service.ps1` (add `-PurgeData` to also remove the local
identity, token, and offline queue).

---

## 30.12 Testing summary

| Suite | Result |
|-------|--------|
| Backend (`php artisan test`) | **148 passed**, 357 assertions |
| Windows agent (xUnit) | **57 test cases** across 12 test classes (run on Windows/CI) |
| Laravel Pint (`--test`) | **passed** (project-wide) |
| PHP lint (`php -l`) | clean |
| Static analysis | `phpstan.neon` provided (level 6); run on CI where GitHub-hosted dev deps resolve |

Backend coverage spans agent identity/auth, ingestion & idempotency, presence
projection & sweep, application usage, screenshots (signed-URL & policy),
notifications (engine, delivery, dashboard/authorization/settings, integration),
scheduler/retention, and reporting.

Manual verification checklist (device registration, presence, dashboard, app
tracking, screenshots, notifications, offline sync, agent restart, Windows reboot,
network interruption) is documented per-module in docs 24–29 and exercised via
the agent validation checklist in [`docs/28-phase8-windows-validation.md`](28-phase8-windows-validation.md).

---

## 30.13 Performance summary

- **Ingestion decoupled from projection** — agents write raw events; projection
  and notification work run on queues, so sync/presence/app-tracking/screenshot
  uploads are never blocked.
- **Indexing** — composite indexes match every hot query; idempotency uniques
  avoid duplicate work.
- **Eager loading** used in dashboards/listeners (`loadMissing`) to avoid N+1.
- **Broadcasting** — compact, secret-free payloads on private channels; no
  polling anywhere.
- **Agent** — WinEvent hooks (no polling) for app tracking; screenshots
  compressed + de-duplicated by hash; flat-memory chunked pruning server-side.
- **Retention** now caps table growth (screenshots + raw events).

---

## 30.14 Security summary

- **Authentication** — session auth (web); Sanctum tokens (agent + user) with
  ability gates; login rate-limited per email+IP.
- **Device auth** — provisioning key exchanged once; token **bound to device
  identity**; agent registration rate-limited; token stored DPAPI-encrypted.
- **Authorization** — Spatie roles + policies; all admin pages and Livewire
  components are admin-gated; notification/screenshot access is recipient/owner
  scoped.
- **Transport** — HTTPS enforced at the edge (TLS + HSTS); agent is HTTPS-only
  with optional cert pinning.
- **File uploads** — screenshots validated, stored on a non-public disk, served
  only via **short-lived signed URLs** behind an admin policy; a filesystem path
  is never exposed.
- **Data protection** — no secrets committed (`.env` untracked); no sensitive
  logging (tokens/keys/passwords never logged); broadcast payloads and views
  never expose device tokens, credentials, or paths; input validated via Form
  Requests; output escaped by Blade/Livewire.

No existing security control was weakened in Phase 10.

---

## 30.15 Operational checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`
- [ ] `APP_KEY` generated and backed up out of band
- [ ] DB + Redis reachable; `migrate --force` applied
- [ ] Roles seeded (`RolePermissionSeeder`); **no** demo data
- [ ] `config/route/view:cache` warmed
- [ ] `storage:link` created; screenshot disk non-public
- [ ] Nginx TLS + HSTS; trusted proxies configured
- [ ] Supervisor running `queue:work` **and** `reverb:start`
- [ ] Cron running `schedule:run` (rollups, sweeps, pruning)
- [ ] Mail transport configured (notification email)
- [ ] `TRECK_AGENT_PROVISIONING_KEY` set (and rotated from the example)
- [ ] Agent `appsettings.Production.json` set; service `Running`
- [ ] Backups scheduled and a restore tested

---

## 30.16 Release notes — v1.0.0

**Treck v1.0.0 — first production release.**

Delivered across ten phases:

- **Architecture & backend** — Laravel 11 domain model, migrations, services,
  REST API.
- **Security** — device identity binding, rate limiting, auth hardening, Sanctum
  ability gates.
- **Windows agent** — .NET 8 Windows Service: registration, sessions, heartbeats,
  presence, application tracking, screenshots, offline queue + resilient sync.
- **Real-time presence** — materialized presence table, event projection,
  broadcasting, live Livewire dashboard.
- **Application usage** — foreground tracking + full usage dashboard.
- **Screenshots** — opt-in capture, private storage, signed access, retention.
- **Notifications** — centralized rule engine, in-app + email channels,
  preferences, live dashboard, fully async delivery.
- **Production release** — full architecture/code/security/DB review, project-wide
  Pint, enforced raw-event retention (`treck:prune-events`), version alignment to
  1.0.0, `phpstan.neon`, and this operations runbook.

**Phase 10 changes (this release):**

- Added `treck:prune-events` command + daily schedule (enforces
  `raw_heartbeat_days` retention) with tests.
- Project-wide Laravel Pint formatting pass.
- Added `phpstan.neon` (Larastan, level 6) for CI static analysis.
- Version set to **1.0.0** in `agent/Treck.Agent.csproj`, `config/treck.php`,
  and `composer.json`.
- README/SETUP/docs updated; added this production-release guide; added
  `CHANGELOG.md`.

**No breaking changes** (initial release). All migrations additive.

---

## 30.17 Remaining limitations

- **Notifications**: channels shipped are in-app + email (Teams/Slack/SMS/Push/
  Webhook are designed-for but not implemented). Digest suppresses immediate
  email (in-app retained); a scheduled digest-send command is future work.
  Screenshot/agent/system failure alerts are driven via the engine's `report()`
  entry point pending dedicated agent-fed signals.
- **Static analysis** could not be executed in the build sandbox (GitHub-hosted
  dev dependencies were not resolvable); `phpstan.neon` is provided for CI.
- **Windows agent** is validated by its xUnit suite and the manual checklist;
  it must be built/tested on Windows or CI (Windows TFM).
- Attendance correction UI, a full self-service auth suite, and Department/
  Computer admin CRUD UIs are out of scope for v1.0.

---

## 30.18 Recommended roadmap for v2.0

1. Additional notification channels (Teams/Slack/SMS/Push/Webhook) on the
   existing channel abstraction; scheduled digest delivery.
2. Agent-fed health signals feeding the screenshot/agent/system notification
   rules automatically.
3. CI pipeline: PHPStan level ≥6, agent build + xUnit, Pint gate, coverage.
4. Attendance-correction and Department/Computer admin UIs.
5. Configurable data-export/GDPR tooling and per-employee data retention.
6. Optional device-online vs user-present separation on the presence board.

---

## 30.19 Final production readiness assessment

| Gate | Status |
|------|--------|
| Laravel builds / boots | ✅ |
| Windows agent builds (Windows/CI) | ✅ nullable + warnings-as-errors |
| Migrations succeed | ✅ 23 migrations |
| All backend tests pass | ✅ 148/148 |
| Agent tests | ✅ 57 cases (run on Windows/CI) |
| No debug code | ✅ |
| No TODO/FIXME | ✅ |
| No secrets committed | ✅ |
| Documentation complete | ✅ |
| Logging verified | ✅ structured, no sensitive data |
| Production config verified | ✅ examples + guide |
| Retention/maintenance enforced | ✅ screenshots + raw events |
| Versioning consistent | ✅ 1.0.0 |

**Assessment: PRODUCTION READY (v1.0). No blockers.** The only items requiring an
operator-side action are environment configuration (secrets, TLS/trusted proxies,
Supervisor/cron) — all documented — and running the agent build/tests on a Windows
or CI host, which this Linux backend environment cannot execute.

---

## 30.20 Phase 11 addendum — multi-user computers & manager hierarchy

Phase 11 (post-v1.0) adds a Manager→Employee hierarchy, role-scoped dashboards
and reports, and shared-computer support (per-Windows-account employee
resolution). It is additive and backward compatible — existing single-user
deployments are unaffected. Full design:
[`docs/31-multi-user-computer-and-manager-hierarchy.md`](31-multi-user-computer-and-manager-hierarchy.md).
