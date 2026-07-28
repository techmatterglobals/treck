# 29. Notifications (Phase 9)

The notifications module turns raw monitoring signals — presence changes,
application usage, and agent/system health — into **rule-driven alerts** that
reach administrators in-app (live, no polling) and by email. It is centralized,
fully asynchronous, configurable **without code changes**, and additive: it
observes the existing Phase 6 (presence) and Phase 7 (application usage)
pipelines without modifying them.

> **Doc numbering note.** `docs/28` was already taken by
> `28-phase8-windows-validation.md`, so this Phase 9 document is numbered `29`.

---

## 29.1 Architecture overview

```
 Existing pipelines (unmodified)             Notification engine (Phase 9)
 ───────────────────────────────             ─────────────────────────────
 PresenceChanged event (Phase 6) ─┐
                                  ├─▶ EvaluatePresenceNotifications (queued listener)
 ApplicationUsage created (Ph 7) ─┼─▶ ApplicationUsageObserver ─▶ EvaluateNotificationsJob (queued)
                                  │
 report() call (agent/system) ────┘        │
                                            ▼
                                   NotificationEngine.dispatch(context)
                                       ├─ NotificationRuleService.ruleSet()   (DB rules)
                                       ├─ evaluate rules → NotificationDraft[] (Presence/App/Screenshot/Agent)
                                       ├─ throttle check (dedupe_key + window)
                                       ├─ resolve recipients (admins + preferences)
                                       └─ write NotificationLog rows  ─┐
                                                                       ▼
                                                       SendNotificationJob (queued, per row)
                                                                       ▼
                                                       NotificationDeliveryService
                                                          ├─ InAppChannel  ─▶ NotificationCreated (broadcast)
                                                          └─ EmailChannel  ─▶ NotificationMail (queued mailable)
                                                                       ▼
                                              In-app: bell + dashboard update live (Reverb/Echo)
                                              Email:  HTML mail with dashboard link
```

Design principles:

- **Centralized engine.** All events flow through `NotificationEngine`; rules,
  throttling, recipient resolution and logging live in one place.
- **Configurable without code.** Rules, severities, channels, throttles,
  thresholds and watch-lists are DB rows (`notification_rules`) edited from the
  admin settings UI.
- **Asynchronous throughout.** Evaluation runs off the ingest path (queued
  listener / job) and delivery is a separate queued job + queued mailable, so
  agent sync, presence, application tracking and screenshot uploads never block.
- **Reuse, don't duplicate.** Broadcasting reuses the existing Reverb/Echo
  setup; queues reuse the existing database queue; authorization reuses Spatie
  roles.

---

## 29.2 Notification workflow

1. **A signal occurs.** A presence change fires `PresenceChanged`; a completed
   application-usage row is created; or server code calls
   `NotificationEngine::report()` for an agent/system condition.
2. **Evaluation is queued.** The presence listener and the app-usage observer
   are queued, so the originating request/ingest returns immediately.
3. **Rules evaluate.** `NotificationEngine::dispatch()` builds the current
   `RuleSet` from the DB and asks each matching rule to produce
   `NotificationDraft`s (title, message, dedupe key, severity source).
4. **Throttle.** Each draft's `dedupe_key` is checked against recent
   `NotificationLog` rows within the rule's `throttle_seconds` window. Duplicates
   inside the window are dropped.
5. **Recipient resolution.** Active admins are resolved, then filtered by each
   admin's `NotificationPreference` (min severity, enabled channels, digest,
   quiet hours).
6. **Persist.** One `NotificationLog` row per recipient × channel is written
   with status `pending`.
7. **Deliver.** A `SendNotificationJob` is queued per row. `InAppChannel` marks
   it delivered and broadcasts `NotificationCreated`; `EmailChannel` queues a
   `NotificationMail`. Failures mark the row `failed`.
8. **Consume.** The bell and dashboard update live over the recipient's private
   channel; email arrives with a link back to the dashboard.

---

## 29.3 Rule engine

Rules implement `NotificationRuleContract`:

```php
interface NotificationRuleContract
{
    public function supports(NotificationContext $context): bool;
    public function evaluate(NotificationContext $context, RuleSet $rules): iterable; // NotificationDraft[]
}
```

Registered rules (injected into the engine):

| Rule | Source | Produces |
|------|--------|----------|
| `PresenceNotificationRule`   | `presence`             | online / reconnected / idle-beyond-threshold / locked / logged-out / offline |
| `ApplicationNotificationRule`| `app_usage`            | blacklisted process, restricted app opened, usage beyond max duration |
| `ScreenshotNotificationRule` | `screenshot`           | capture failed, sync failed |
| `AgentNotificationRule`      | `agent` / `system`     | registration failed, heartbeat stopped, sync failures, queue growing, system inactive |

Each rule reads its behaviour from the matching `notification_rules` row:
`enabled`, `severity`, `channels`, `throttle_seconds`, and a JSON `config`
(e.g. `idle_threshold_seconds`, `offline_threshold_seconds`, `applications`,
`processes`, `max_usage_seconds`). Rules that don't match a context or whose
DB row is disabled produce nothing.

**Event types** are enumerated in `NotificationEventType` (17 cases across
`presence.*`, `app.*`, `screenshot.*`, `agent.*`, `system.*`); **severity** in
`NotificationSeverity` (Info / Warning / Critical, each with `label()`,
`color()` and `rank()`).

### Server-observable vs agent-fed signals

Presence and application-usage rules fire automatically today from existing
events. Screenshot/agent/system failures are not yet observable purely
server-side; they are wired through `NotificationEngine::report()` and are ready
to be driven by dedicated agent-fed signals in a later phase. The rules and the
`report()` entry point are implemented and tested now.

---

## 29.4 Database schema

**`notification_rules`** — one row per event type (seeded by migration):

| Column | Type | Notes |
|--------|------|-------|
| `event_type` | string, unique | matches `NotificationEventType` |
| `enabled` | bool | `presence.online` seeded disabled |
| `severity` | string | Info / Warning / Critical |
| `channels` | json | e.g. `["in_app","email"]` |
| `config` | json | thresholds & watch-lists |
| `throttle_seconds` | int | default 300 |

**`notification_logs`** — history + delivery ledger:

| Column | Type | Notes |
|--------|------|-------|
| `recipient_id` | FK users, null-on-delete | in-app inbox owner |
| `computer_id`, `employee_id` | int, nullable | context |
| `event_type`, `severity`, `title`, `message` | | rendered content |
| `channel` | string | `in_app` / `email` |
| `dedupe_key` | string(191) | throttling key |
| `status` | string | `pending` / `delivered` / `failed` |
| `delivered_at`, `read_at` | datetime, nullable | lifecycle |
| `metadata` | json | context detail (never rendered raw) |

Indexes: `(recipient_id, read_at)`, `(severity, created_at)`,
`(event_type, created_at)`, `(channel, status)`, `(dedupe_key, created_at)`.

**`notification_preferences`** — per-user:

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | FK users, unique, cascade | |
| `channels` | json | enabled channels |
| `min_severity` | string, default `info` | floor |
| `digest` | bool | suppress immediate email |
| `quiet_hours_start` / `quiet_hours_end` | time, nullable | cross-midnight aware |

---

## 29.5 Channels & preferences

`NotificationDeliveryService` maps a channel key to a `NotificationChannel`:

- **`InAppChannel`** (`in_app`) — marks the row delivered and broadcasts
  `NotificationCreated` on `notifications.user.{recipient_id}` (admin- and
  owner-authorized private channel).
- **`EmailChannel`** (`email`) — queues `NotificationMail` (HTML markdown mail)
  to the recipient; blank address → `failed`.

The design is channel-agnostic: adding Teams/Slack/SMS/Push/Webhook later means
implementing `NotificationChannel` and registering it — no engine changes.

`NotificationPreferenceResolver` resolves active admins and applies each
recipient's preference: it drops recipients below their `min_severity`,
intersects rule channels with the recipient's enabled channels, and suppresses
**email** for non-critical alerts when the recipient is in **digest** mode or
within **quiet hours** (in-app is retained so nothing is lost).

---

## 29.6 Real-time updates

The `NotificationCreated` event is `ShouldBroadcast` on the recipient's private
channel and carries a compact, secret-free payload. The Livewire
`NotificationBell` and `NotificationDashboard` listen via Echo
(`echo-private:notifications.user.{id},.NotificationCreated`) and re-render on
arrival — **no AJAX polling**. Unread badges and counts update live.

---

## 29.7 Email

`NotificationMail` is a queued Mailable (`ShouldQueue`) rendering
`resources/views/mail/notification.blade.php` (markdown). Subject:
`[Treck SEVERITY] title`. The body includes the event summary, employee,
computer, timestamp, severity and a **dashboard link**
(`route('notifications.index')`). Delivery is queue-based with retry
(`SendNotificationJob`: `tries=3`, `backoff=30`), and each attempt updates the
`notification_logs` status.

---

## 29.8 Duplicate prevention

Every draft carries a `dedupe_key` (event type + computer + qualifier). Before
persisting, the engine checks for a row with the same key within the rule's
`throttle_seconds`; matches are suppressed. Repeated events therefore collapse
to a single alert per window. Digest mode further batches non-critical email.

---

## 29.9 Security

- **Admin-only.** Routes are gated by `role:admin` and by `NotificationPolicy`
  (bound explicitly in `AppServiceProvider` because the policy name doesn't
  follow model auto-discovery). Non-admins are forbidden from the pages and
  from mounting the Livewire components.
- **Recipient-scoped.** Dashboard/bell read only the current admin's inbox;
  mark-read is scoped to the recipient, so one admin cannot alter another's
  notifications.
- **No secret leakage.** Broadcast payloads and rendered views never expose
  device tokens, API credentials, signed URLs or filesystem paths. `metadata`
  is stored for audit but not rendered raw.

---

## 29.10 Performance

- Evaluation runs in a queued listener / `EvaluateNotificationsJob` — off the
  request/ingest path.
- Delivery is a separate queued job per row, plus a queued mailable.
- The app-usage observer only reacts to agent-projected rows (those with a
  `session_id`), avoiding re-notification on imports.
- Queries are covered by the indexes in §29.4.

Net effect: agent synchronization, presence updates, application tracking and
screenshot uploads are never blocked by notification work.

---

## 29.11 Configuration

Admin UI: **Notifications → Settings** (`/notifications/settings`):

- Per-rule enable, severity, in-app/email channels, throttle seconds.
- Thresholds & lists: idle threshold, long-usage max, restricted applications,
  blacklisted processes.
- Per-admin preferences: channels, minimum severity, digest, quiet hours.

`config/treck.php` adds a `notifications` block:

```php
'notifications' => [
    'default_throttle_seconds' => env('TRECK_NOTIFY_THROTTLE', 300),
    'digest_hours'             => env('TRECK_NOTIFY_DIGEST_HOURS', 24),
],
```

Requirements: a running queue worker (`php artisan queue:work`), a mail
transport configured in `.env`, and Reverb running for live in-app updates
(see `docs/25-realtime-presence.md`).

---

## 29.12 Manual verification

1. Run migrations: `php artisan migrate` (seeds one rule per event type).
2. Start `php artisan queue:work` and `php artisan reverb:start`.
3. Sign in as an admin; open `/notifications` and `/notifications/settings`.
4. Add a restricted application (e.g. `Steam`) and save.
5. Let an agent report `Steam` usage (or create an `ApplicationUsage` row with a
   `session_id`) → the bell badge increments live and a row appears on the
   dashboard; if the rule includes email, a mail is queued.
6. Trigger the same event again within the throttle window → no duplicate.
7. Set your preference min severity to Critical → info/warning alerts stop
   reaching you; critical still arrives.

---

## 29.13 Troubleshooting

| Symptom | Likely cause |
|---------|--------------|
| No in-app updates | Reverb not running, or Echo not authorized on `notifications.user.{id}` |
| No emails | Queue worker not running, or mail transport not configured |
| Nothing generated | Rule disabled, below preference min severity, or throttled |
| 403 on pages | Not an admin, or `NotificationPolicy` not bound |
| Duplicates | `throttle_seconds` too low for the event's cadence |

---

## 29.14 Known limitations

- Screenshot/agent/system rules await dedicated agent-fed signals; today they
  are driven via `report()`.
- Digest currently suppresses immediate email (in-app retained); a scheduled
  digest-send command is a future enhancement.
- Channels shipped: in-app + email. Teams/Slack/SMS/Push/Webhook are designed
  for but not implemented.

---

## Phase 11 note — unknown Windows user alert

A new `system.unknown_user` notification (seeded, in-app + email) alerts the
Super Admin when an unrecognized Windows account is seen on a shared computer so
it can be mapped to an employee. Notifications remain Super-Admin-scoped in this
phase. Full design:
[`docs/31-multi-user-computer-and-manager-hierarchy.md`](31-multi-user-computer-and-manager-hierarchy.md).

---

## Phase 12 note — download alerts

Phase 12 adds configurable download-alert event types
(`download.executable` / `download.archive` / `download.large` /
`download.restricted`) evaluated by a new `DownloadNotificationRule` through this
same engine, fired asynchronously when a download is recorded. Full design:
[`docs/32-file-download-monitoring.md`](32-file-download-monitoring.md).
