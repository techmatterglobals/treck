# Treck SaaS Tenancy Foundation Audit

## 1. Executive summary

This audit covers the current Laravel application, database schema, web and Sanctum authentication, Spatie authorization, Windows agent APIs, Windows Admin desktop APIs, background jobs, storage, reports, exports and realtime infrastructure as the design basis for Treck's first shared-application, shared-database SaaS tenancy model.

The current system is a secure single-customer product. It has useful defenses already: inactive users are blocked centrally, device tokens are Sanctum tokenables bound to `computers`, agent payloads generally resolve ownership from the authenticated device rather than trusting request bodies, manager visibility is centralized in `EmployeeVisibility`, and screenshot bytes are served through signed, authorized routes. It is not yet tenant-safe. No table stores `organization_id`, Spatie roles are global, route-model binding resolves records globally, cache/broadcast/storage identifiers are global, and queued jobs carry record IDs without explicit tenant context.

Recommendation: introduce explicit `organizations` and `organization_user` membership records, use Spatie teams with `team_id = organization_id` for organization roles, keep `platform-super-admin` as a platform-global role outside organization membership, and add direct non-null `organization_id` columns to all organization-owned, monitoring, aggregate, notification, export and device tables after a nullable/backfilled transition. Organization isolation must be enforced centrally before manager/team visibility and record-specific authorization.

No migrations, models, middleware or application code should be created until the decisions at the end of this document are approved.

## 2. Existing architecture

Treck is a Laravel application with Blade/Livewire web dashboards, Sanctum token APIs, Spatie Laravel Permission roles, database-backed queues/cache/sessions by default, Maatwebsite Excel exports, DomPDF reports, and optional Reverb/Pusher-compatible private broadcasts.

Primary domain tables are `users`, `employees`, `departments`, `computers`, `computer_users`, `activity_logs`, `agent_events`, `computer_presence`, `application_usage`, `screenshots`, `file_downloads`, `attendance`, `productivity_reports`, `notification_rules`, `notification_logs`, `notification_preferences`, and `agent_health_reports`.

Current access model:

- `admin` is treated as Super Admin and has organization-wide access.
- `manager` can reach selected dashboards and is scoped to `employees.manager_user_id` through `EmployeeVisibility`.
- `employee` has self-service style access.
- Agent devices authenticate as `Computer` models through Sanctum tokens with `agent:report`.
- Windows Admin desktop users authenticate as `User` models through Sanctum and are limited to administrators or managers.

Current tenant gap: all "organization-wide" code paths assume there is only one customer organization.

## 3. Proposed shared-database tenancy model

Create one shared Laravel application and initially one shared database. Each customer company is an `Organization`.

Core tables:

- `organizations`: `id`, `name`, `slug`, lifecycle/status fields, optional billing placeholders only if explicitly approved later, `suspended_at`, `created_at`, `updated_at`.
- `organization_user`: `organization_id`, `user_id`, `status`, `is_owner`, `joined_at`, `invited_by_id`, timestamps. This is the durable membership record and the correct place to model inactive memberships.
- Current organization context: resolved per request from membership, session/API token metadata, or explicit route/header once switching exists.
- Platform support access: explicit, audited, time-bound impersonation/support grants; never implicit through organization-admin privileges.

Lifecycle behavior:

- A user with exactly one active membership is automatically placed into that organization.
- A future organization switcher should update an explicit current-organization session value or issue organization-bound API tokens.
- Suspended organizations reject organization-scoped web, API, desktop and device requests before role checks.
- Inactive memberships reject access even when the user account is globally active.
- Platform-super-admin can access platform administration without membership and can enter organization support mode only through an explicit audited path.

Tenant enforcement order:

1. Organization boundary.
2. Role or team boundary.
3. Record-specific authorization.

## 4. Complete table ownership matrix

| Table | Class | Current ownership path | Proposed ownership | Direct `organization_id` | Nullability | FK behavior | Required indexes | Tenant uniqueness | Migration/backfill | Retention/storage |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `organizations` | New `Organization` | None | Platform-global owner of tenant data | N/A | N/A | N/A | `slug`, `status`, `suspended_at` | `slug` global | Create in Phase A; seed one default org for existing data | Keep indefinitely except explicit customer deletion policy |
| `organization_user` | New membership model | None | Join/pivot data | Both FK columns | Non-null after create | Cascade on organization; cascade or restrict on user per account policy | `organization_id,status`, `user_id,status`, `organization_id,user_id` | Unique `organization_id,user_id` | Backfill all non-platform users into default org | Keep as audit-relevant membership history; prefer inactive over delete |
| `users` | `User` | Global login account | Platform-global identity with organization memberships | No, except optional `last_organization_id` preference | N/A | Membership links carry ownership | `email`, `is_active`; optional `last_organization_id` | Keep email globally unique unless product approves same email in multiple identities | Backfill default membership for all existing users except platform admin decisions | Sessions/tokens must be revoked on deactivation |
| `password_reset_tokens` | Framework | `email` | Framework/system | No | N/A | Email-based | Existing primary `email` | Follows chosen email uniqueness | No tenant backfill | Short-lived; framework pruning/expiry |
| `sessions` | Framework | Optional `user_id` | Framework/system with current-org session payload | No column required initially | N/A | `user_id` null-on-user-delete behavior is framework-managed | `user_id`, `last_activity` | Session IDs global | Regenerate sessions when activating tenancy; store current org in encrypted session | Session lifetime per config |
| `cache` | Framework | Global keys | Framework/system | No | N/A | N/A | Primary `key` | Prefix keys with `org:{id}` for tenant data | No row backfill; flush during cutover | TTL based; flush tenant keys on suspension/deletion |
| `cache_locks` | Framework | Global lock keys | Framework/system | No | N/A | N/A | Primary `key` | Prefix locks with `org:{id}` | No backfill; ensure new lock keys are tenant-prefixed | TTL based |
| `jobs` | Framework | Queue payload only | Framework/system carrying explicit org context | No schema change required | N/A | N/A | `queue` | Queue names may include org for high-volume isolation | Add job DTO fields before dispatch; restart workers | Pruned by queue tooling |
| `job_batches` | Framework | Batch metadata only | Framework/system | No schema change required | N/A | N/A | Primary `id` | Batch names/options must include org for tenant jobs | Add metadata convention | Framework retention |
| `failed_jobs` | Framework | Failed queue payload | Framework/system | Optional JSON context only | Nullable if added | N/A | `uuid`, `failed_at` | N/A | Ensure failed payloads contain `organization_id` | Keep per ops retention; sensitive data redaction |
| `personal_access_tokens` | Sanctum | Polymorphic `User` or `Computer` tokenable | Framework/system plus tenant-bound credentials | Add nullable `organization_id` or enforce tokenable ownership; recommended direct column for user and device tokens | Nullable during Phase A/B, non-null for organization-scoped tokens in Phase D; platform tokens may remain null | Cascade on org if added only for organization tokens; tokenable still cascades by model lifecycle | `tokenable_type,tokenable_id`, `organization_id,tokenable_type`, `expires_at` | Token hash stays global unique | Backfill device tokens from `computers.organization_id`; user tokens from current/default org or revoke | Revoke on membership inactive, organization suspension, device transfer |
| `permissions` | Spatie | Global catalog | Platform-global | No | N/A | N/A | Existing `name,guard_name` | Global permission names | No tenant backfill | Cache must be cleared after role/team changes |
| `roles` | Spatie `Role` | Global role rows | Platform-global for platform roles; organization-scoped for org roles using teams | Use Spatie `team_id` as organization id, not separate `organization_id` | Null for platform roles, non-null for org roles | `team_id` should reference organizations if Spatie teams enabled with compatible migration | `team_id,name,guard_name` | Unique `team_id,name,guard_name`; allow same role names in each org | Enable teams carefully; migrate existing `admin` to platform-super-admin/org-owner split | Spatie cache clear required |
| `model_has_roles` | Spatie pivot | User-role global pivot | Join/pivot data scoped by organization team | `team_id` from Spatie teams | Null only for platform roles | Cascade role/user | `team_id,model_id,model_type`, `role_id` | Primary includes `team_id` | Backfill existing users into default org roles; separately create platform-super-admin | Permission cache clear; no retention beyond role history unless audited separately |
| `model_has_permissions` | Spatie pivot | Direct user permissions globally | Join/pivot data scoped by organization team | `team_id` from Spatie teams | Null only for platform direct grants | Cascade permission/user | `team_id,model_id,model_type`, `permission_id` | Primary includes `team_id` | Avoid direct grants if possible; backfill none unless existing data found | Same as roles |
| `role_has_permissions` | Spatie pivot | Role-permission global pivot | Join/pivot data | No direct org if role row has team | Non-null role/permission | Cascade role/permission | Existing primary | Global per role id | Rebuild role permission grants for platform and org roles | Permission cache clear |
| `departments` | `Department` | Global by `name`; optional `manager_id` user | Organization-owned | Yes | Nullable in Phase B; non-null Phase D | Cascade or restrict on org; `manager_id` null-on-delete and must reference same org membership | `organization_id,name`, `organization_id,manager_id` | Unique `organization_id,name` | Backfill to default org; drop global `name` unique | Business data, retain while org exists |
| `employees` | `Employee` | Belongs to `users`, optional `departments`, optional `manager_user_id` | Organization-owned | Yes | Nullable in Phase B; non-null Phase D | Cascade/restrict on org; `department_id` null-on-delete; `user_id` may cascade today but SaaS may prefer restrict/deactivate | `organization_id,user_id`, `organization_id,department_id`, `organization_id,manager_user_id`, `organization_id,status` | Unique `organization_id,employee_code`; probably unique `organization_id,user_id` if one profile per org | Backfill from default org; drop global employee code uniqueness | Soft-deleted rows retain org; employee identifiers repeat across orgs |
| `computers` | `Computer` | Optional `employee_id`; global unique `device_uuid` | Organization-owned device | Yes | Nullable in Phase B; non-null Phase D for registered devices | Cascade/restrict on org; `employee_id` null-on-delete but must be same org | `organization_id,device_uuid`, `organization_id,employee_id`, `organization_id,status,last_seen_at` | Unique `organization_id,device_uuid`; decide whether hardware fingerprint should also have optional global collision guard | Backfill from assigned employee/default org; unassigned computers need explicit default assignment | Soft-deleted devices retained; revoke tokens on transfer |
| `computer_users` | `ComputerUser` | `computer_id + windows_username`, optional employee | Join/pivot data within org-owned computer | Yes, recommended direct column for queries and constraints | Nullable Phase B; non-null Phase D | Cascade on org and computer; employee null-on-delete | `organization_id,computer_id,is_active`, `organization_id,employee_id`, `organization_id,windows_username` | Unique `organization_id,computer_id,windows_username` | Backfill from computer org; validate mapped employee matches same org | Retain while computer history is retained |
| `activity_logs` | `ActivityLog` | Employee + computer session | Monitoring/event data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; employee/computer cascades today but retention may prefer restrict or set null for history | `organization_id,employee_id,login_at`, `organization_id,computer_id,login_at`, `organization_id,work_date` | No new uniqueness unless open-session invariant added | Backfill from employee/computer org; verify pairs match | Aggregate source; retain per monitoring policy |
| `agent_events` | `AgentEvent` | Computer token; employee denormalized at ingest | Monitoring/event data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; cascade on computer today | `organization_id,computer_id,occurred_at`, `organization_id,kind,occurred_at`, `organization_id,employee_id,occurred_at` | Unique `organization_id,computer_id,idempotency_key` | Backfill from computer org; reject mismatched employee orgs | Raw retention currently `treck.retention.raw_heartbeat_days` |
| `computer_presence` | `ComputerPresence` | One row per computer | Derived or aggregate data | Yes, recommended direct column | Nullable Phase C; non-null Phase D | Cascade on org and computer | Unique `organization_id,computer_id`; `organization_id,status,last_synced_at` | Unique `organization_id,computer_id` | Backfill from computer org; rebuild if mismatch | Current materialized state, no historical retention need |
| `application_usage` | `ApplicationUsage` | Employee + computer + optional activity log | Monitoring/event data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; parent links same org | `organization_id,employee_id,used_at`, `organization_id,computer_id,used_at`, `organization_id,application_name,used_at` | Unique `organization_id,computer_id,session_id` for non-null sessions | Backfill from employee/computer org; verify activity log same org | Retain per monitoring/aggregate policy |
| `screenshots` | `Screenshot` | Employee + computer + optional activity log; file path includes computer id only | Monitoring/event data plus storage metadata | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; parent links same org | `organization_id,employee_id,captured_at`, `organization_id,computer_id,captured_at`, `organization_id,captured_at` | Unique `organization_id,computer_id,image_hash` for non-null hashes | Backfill from employee/computer org; move storage paths or support legacy lookup | Row and file pruned by screenshot retention |
| `file_downloads` | `FileDownload` | Computer + optional employee; metadata only | Monitoring/event data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; parent links same org | `organization_id,employee_id,downloaded_at`, `organization_id,computer_id,downloaded_at`, `organization_id,file_extension,downloaded_at` | Unique `organization_id,computer_id,event_key` | Backfill from computer org; validate employee same org | Metadata retention policy required |
| `attendance` | `Attendance` | Employee daily rollup | Derived or aggregate data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; employee same org | `organization_id,work_date`, `organization_id,employee_id,work_date` | Unique `organization_id,employee_id,work_date` | Backfill from employee org; regenerate for mismatches | Retain longer than raw events per aggregate policy |
| `productivity_reports` | `ProductivityReport` | Employee period rollup | Derived or aggregate data | Yes | Nullable Phase C; non-null Phase D | Cascade/restrict on org; employee same org | `organization_id,period_type,period_date`, `organization_id,employee_id,period_type,period_date` | Unique `organization_id,employee_id,period_type,period_date` | Backfill from employee org; regenerate if needed | Aggregate retention currently `treck.retention.aggregate_days` |
| `notification_rules` | `NotificationRule` | Global event type config | Organization-owned settings with optional platform defaults | Yes, if org-customizable; null row may represent platform default template | Nullable for platform default rows; non-null for org overrides | Cascade on org for org rows | `organization_id,event_type`, `organization_id,enabled` | Unique `organization_id,event_type`; keep default `null,event_type` convention carefully | Clone current rules into default org; decide platform default override model | Keep while org exists; audit setting changes separately |
| `notification_logs` | `NotificationLog` | Recipient + optional computer/employee | Organization-owned monitoring/event data | Yes | Nullable Phase C; non-null for org notifications in Phase D; null allowed for platform notifications | Null-on-delete for recipient/computer/employee today; org deletion policy must decide purge vs archive | `organization_id,recipient_id,read_at`, `organization_id,severity,created_at`, `organization_id,event_type,created_at`, `organization_id,dedupe_key,created_at` | Dedupe key should include org or be indexed with org | Backfill from recipient membership or computer/employee org; inspect ambiguous rows | Notification retention and redaction required |
| `notification_preferences` | `NotificationPreference` | Unique per user | Organization-owned user preference | Yes, unless preferences are truly global | Nullable Phase B; non-null Phase D for org prefs | Cascade on org/user | `organization_id,user_id` | Unique `organization_id,user_id` | Backfill current prefs into default org | Keep while membership exists |
| `agent_health_reports` | `AgentHealthReport` | One row per computer | Monitoring/current-state data | Yes | Nullable Phase C; non-null Phase D | Cascade on org/computer | Unique `organization_id,computer_id`; `organization_id,received_at`; `organization_id,agent_version,config_revision` | Unique `organization_id,computer_id` | Backfill from computer org | Current snapshot; optional history later |

## 5. Organization and membership design

`organizations` should be the only tenant boundary. Every organization-owned model must either store `organization_id` directly or inherit it through a join only for true pivots where all access is mediated by parents. In this codebase, direct `organization_id` is recommended for nearly every domain table because monitoring data is queried at volume, jobs receive IDs, route-model binding resolves standalone records, and storage/broadcast/cache keys need a stable tenant prefix.

`organization_user` should carry membership status independent from `users.is_active`. A globally active user may have one active organization membership, several active memberships, only inactive memberships, or platform-only access. Current organization resolution should reject requests when there is no active selected organization, when the selected organization is suspended, or when the user's membership is inactive.

Single-organization behavior should be automatic: after login, if the user has one active membership, set current organization in session/token context. Multi-organization switching should be an explicit later UI/API operation that changes current context and rechecks role grants.

## 6. Authentication and role design

Web authentication currently uses session login by email/password and redirects to `/dashboard`. Sanctum user authentication issues tokens from `POST /api/v1/auth/login`, with `['*']` for admins and `['employee:self']` for non-admins. Agent authentication issues Sanctum tokens to `Computer` models after global enrollment-secret registration. Windows Admin desktop APIs use user Sanctum tokens and admit administrators/managers through `AuthorizesDesktopAccess`.

Recommended role approach: use Spatie teams for organization-scoped roles, with `team_id` equal to `organizations.id`, and keep platform roles global (`team_id = null`). This fits the current Spatie dependency, avoids custom permission reimplementation, lets role names repeat by organization, and preserves a familiar `hasRole`/`can` API once the team context is set centrally.

Required role names:

- Platform-global: `platform-super-admin`.
- Organization-scoped: `organization-owner`, `organization-admin`, `manager`, `employee`.

Migration risks and trade-offs:

- Existing `admin` currently means Super Admin. It must be split deliberately; blindly renaming it would either over-grant platform access or remove customer admin access.
- Spatie `teams` is currently false. Enabling it affects role uniqueness, pivots, middleware behavior and cache keys; it needs a dedicated migration and full permission-cache clearing.
- Team context must be set before any Spatie authorization check for web, Sanctum, Livewire and desktop requests.
- Direct permissions should be avoided or tightly audited because membership-scoped roles are easier to reason about than direct user grants.

## 7. Authorization execution order

All request and job entry points must enforce this order:

1. Resolve current organization and reject suspended organizations/inactive memberships.
2. Set Spatie team context and evaluate platform-vs-organization roles.
3. Apply manager/employee/team visibility, preferably through an organization-aware `EmployeeVisibility`.
4. Apply record-specific policies such as screenshot/download ownership.

Central mechanisms recommended:

- `CurrentOrganization` resolver service.
- `EnsureOrganizationContext` middleware for organization-scoped web/API routes.
- `SetPermissionTeamContext` middleware that runs before role/permission middleware.
- Tenant-aware route-model binding or scoped binding helpers for `Employee`, `Computer`, `Screenshot`, `FileDownload`, `NotificationLog`, `Department` and report filters.
- Organization-aware base query scopes or repository/query helper methods for high-volume reads.
- Policy helpers that fail closed when a record's `organization_id` differs from the current organization.

Explicit predicates are still necessary in raw `DB::table()` reports, rollups, pruning commands, export queries, broadcast channel authorization, storage deletion, device registration, and background job handlers.

## 8. Identified IDOR and cross-tenant risks

High-risk current locations once multiple organizations exist:

- `EmployeeController` route-model binding for `employees/{employee}` and form dropdowns load all departments and unassigned computers globally.
- `AssignComputerRequest` validates `computer_id` with global `exists:computers,id`; `assignComputer` then uses global `Computer::findOrFail`.
- `ReportFilterRequest` validates `employee_id`, `department_id` and `manager_user_id` globally; report services join global tables unless filters are pre-scoped.
- `PresenceController::show` binds `Computer` globally; Livewire detail authorization catches manager scope, but organization binding should happen first.
- `ScreenshotController` and `FileDownloadController` bind records globally, then policies check manager ownership. Tenant-aware binding should produce 404/403 before loading another tenant's sensitive metadata.
- `ScreenshotViewer` builds previous/next navigation by `computer_id` and must include organization and viewer scope.
- `ActivityController` user API `activity/live` returns all computers, and `activity/{employee}/summary` allows any user with `view attendance` unless tenant and role scoping are added.
- Windows Admin desktop `employees/{employee}` binds globally before `EmployeeVisibility`.
- `DeviceRegistrationController` finds employees by global `employee_code` and computers by global `device_uuid`.
- Agent `ActivityReportRequest` and `AgentLogoutRequest` validate global `activity_logs.id`; controllers check computer ownership, but future organization checks should happen before session mutation.
- Broadcast channels `presence` and `presence.computer.{id}` authorize only active administrators, not organization membership or manager visibility.
- Notification logs are recipient-scoped but rule settings are global, recipients are all active admins, and dedupe keys are global.

Manager scoping must become: current organization first, then manager's assigned employees within that organization, then record-specific checks.

## 9. Agent and device tenancy design

Every device token must be permanently bound to:

Organization -> Employee -> Computer -> Device token

Design requirements:

- `computers.organization_id` is the owning device tenant.
- `employees.organization_id` must match the computer's organization when assigned.
- `computer_users.organization_id` must match `computer_id` and any mapped `employee_id`.
- `personal_access_tokens.organization_id` should be set for every `Computer` token and for organization-scoped user tokens.
- Agent ingest must ignore organization identifiers from the request body. Organization comes from the authenticated `Computer` token.
- `agent_events`, `activity_logs`, `application_usage`, `screenshots`, `file_downloads`, `computer_presence` and `agent_health_reports` should copy `organization_id` from the authenticated computer at write time.
- Device transfer between organizations should be treated as re-enrollment: revoke existing tokens, close/open state carefully, and preserve old monitoring rows under the original organization.

Enrollment transition:

- Current state uses one global `TRECK_AGENT_ENROLLMENT_SECRET` and employee lookup by global `employee_code`.
- Later state should use short-lived, single-use, organization-specific enrollment tokens that encode or reference `organization_id`, intended employee, allowed device metadata, expiry, and creator.
- Do not implement that transition in this audit milestone.

## 10. Windows Admin desktop tenancy design

Windows Admin desktop APIs should use the same current-organization resolver as web requests. Bootstrap must return current organization identity, available organizations for future switching, membership role, and suspended/inactive rejection reasons only when safe.

`DesktopOverviewService`, `DesktopPresenceService`, `DesktopAgentHealthService`, and employee detail endpoints must query by `organization_id` before applying manager visibility. User tokens issued to organization admins/managers should either be organization-bound or require an explicit organization header/session context validated against active membership.

Organization admins must not access platform administration. Platform administration should require `platform-super-admin` with no active organization role substitution.

## 11. Background-job isolation

Tenant context must be included explicitly in every asynchronous operation. It must never depend only on `auth()->user()`, request middleware, or mutable global state.

Current jobs/listeners needing updates:

- `EvaluateNotificationsJob`: add `organizationId`; load computer/employee/logs inside that organization only.
- `SendNotificationJob`: add or verify `organizationId`; fetch `NotificationLog` by `organization_id + id`.
- `RollUpDailyAttendance` and `GenerateDailyProductivity`: accept `organizationId` and run per organization/date.
- `EvaluatePresenceNotifications`: derive organization from `ComputerPresence`/`Computer` and pass it into `NotificationContext`.
- Observers for `ApplicationUsage` and `FileDownload`: dispatch organization context from the created row.
- Scheduler commands: iterate active organizations or accept an explicit organization option for operational repair.

Queue workers must be restarted after deploying tenant-aware job payloads so old code does not process new payloads incorrectly.

## 12. Cache, Redis and WebSocket isolation

Current cache defaults are database-backed and global. Rate limiter keys use `device:{id}`, `user:{id}` and IP. Spatie permission cache uses `spatie.permission.cache`. Broadcast channels are global.

Recommended identifiers:

- Cache keys: `org:{organization_id}:...` for all tenant data.
- Cache locks: `org:{organization_id}:lock:...`.
- Redis keys: `treck:org:{organization_id}:...`.
- Rate limits: keep token identity, but add org when the same user/device may act in multiple organizations: `org:{id}:user:{id}` and `org:{id}:device:{id}`.
- Spatie cache: clear after role/team changes; verify team-aware package behavior under Octane/workers.
- Broadcast board channel: `private-org.{organization_id}.presence`.
- Broadcast computer channel: `private-org.{organization_id}.presence.computer.{computer_id}`.
- Notification channel: `private-org.{organization_id}.notifications.user.{user_id}` for org notifications; separate platform notification channel for platform admins.

Channel authorization must check current organization membership and, for manager computer channels, `EmployeeVisibility::canSeeComputer` within the same organization.

## 13. Screenshot, download and export storage isolation

Current screenshot paths are `screenshots/{computerId}/{date}/{hash.ext}` on a private disk, with signed routes for viewing. This leaks no direct filesystem path to users but does not encode tenant ownership.

Recommended paths:

- Screenshots: `organizations/{organization_id}/screenshots/computers/{computer_id}/{date}/{hash.ext}`.
- Thumbnails if added: `organizations/{organization_id}/screenshots/thumbnails/...`.
- Exports: `organizations/{organization_id}/exports/{user_id}/{timestamp}/...` if persisted; direct streamed exports must still be generated from tenant-scoped queries.
- File downloads: metadata only; never collect content. Any future stored evidence must use `organizations/{organization_id}/downloads/...`.

Signed screenshot routes must authorize by `organization_id` before checking manager/team ownership. Prune commands must delete only within the intended organization prefix or must query rows by organization and delete exact recorded paths.

## 14. Phased migration and backfill strategy

Phase A: foundation

- Create `organizations` and `organization_user`.
- Create a default organization for existing installations.
- Backfill all current organization users into the default organization, except any account explicitly designated as platform-only.
- Introduce `platform-super-admin`; map existing `admin` users either to platform-super-admin, organization-owner, or organization-admin based on an approved operator decision.
- Implement current-organization resolver and suspended/inactive membership checks.
- Enable Spatie teams in a controlled migration path.

Phase B: core identity ownership

- Add nullable `organization_id` to departments, employees, computers, computer_users, notification preferences, and organization-scoped Sanctum tokens.
- Backfill from default organization or parent rows.
- Add tenant-aware indexes while keeping old global unique indexes until data is clean.
- Replace global uniqueness:
  - `departments.name` -> unique `organization_id,name`.
  - `employees.employee_code` -> unique `organization_id,employee_code`.
  - `computers.device_uuid` -> unique `organization_id,device_uuid` if hardware fingerprints may repeat by customer.
  - `notification_preferences.user_id` -> unique `organization_id,user_id`.
- Update validators and factories/seeders to include organization.

Phase C: monitoring, aggregate and infrastructure ownership

- Add nullable `organization_id` to activity logs, agent events, computer presence, application usage, screenshots, file downloads, attendance, productivity reports, notification rules/logs, agent health reports and token rows.
- Backfill from computer/employee/recipient ownership and flag ambiguous rows.
- Update all jobs, commands, observers, reports, exports, storage paths, cache keys and broadcast channels.
- Start writing organization-prefixed storage paths while preserving read access for legacy paths until migrated.

Phase D: strict enforcement

- Verify no null tenant rows remain where non-null is required.
- Enforce non-null `organization_id` constraints.
- Add FK constraints and composite tenant indexes.
- Remove compatibility paths that infer organization only through joins.
- Activate strict tenant middleware, policies and tenant route bindings.
- Revoke or rotate old user/device tokens that lack organization context.

Deployment ordering:

- Deploy additive nullable columns first.
- Backfill in id-ordered chunks.
- Deploy dual-read/dual-write compatibility.
- Rebuild/verify indexes.
- Restart queue workers and websocket workers.
- Clear config, route, view, cache and Spatie permission cache at the cutover.
- Only then enforce non-null constraints.

Verification queries:

- Count null `organization_id` per tenant-owned table.
- Count mismatches where child `organization_id` differs from parent employee/computer/activity log.
- Count duplicate customer identifiers under planned tenant uniqueness.
- Count active users with zero active memberships.
- Count organization admins with platform-global roles.
- Count tokens with missing or mismatched organization context.

## 15. Cross-tenant security test matrix

Required automated tests:

- Organization A cannot list, view, edit or delete Organization B employees.
- Organization A cannot list or open Organization B computers.
- Cross-tenant URL ID changes for employees, computers, screenshots, downloads and desktop employee details return 403 or 404.
- Managers see only their assigned team inside their current organization.
- Manager visibility never expands across organizations with matching `manager_user_id`.
- Organization admins cannot enter `/admin/*` platform administration.
- Platform role grants do not leak into organization roles.
- User tokens are rejected for organizations where membership is inactive.
- Suspended organizations reject web, user API, desktop API and agent/device requests.
- Desktop tokens cannot switch or access another organization without active membership.
- Computer tokens cannot post activity, events, screenshots, downloads or health for another organization.
- Agent configuration is organization-specific.
- Agent health dashboard is organization-isolated.
- Screenshot signed image URLs cannot stream across organizations.
- Screenshot previous/next navigation cannot cross organizations.
- Download metadata detail and reports cannot cross organizations.
- Productivity, attendance and computer-usage exports contain only the active organization.
- Notification rules/preferences/logs are organization-isolated.
- Jobs preserve explicit organization context after queue serialization.
- Cache and Redis keys do not collide across organizations.
- WebSocket channels do not leak presence or notification events across organizations.
- Platform support access is explicit, time-bound and audited.

## 16. Risks and blockers

- Existing `admin` role ambiguity is the largest authorization risk. The product must decide how each existing admin maps to platform or organization authority.
- Enabling Spatie teams after production use needs careful migration and package-cache validation.
- Global employee codes, department names and device UUIDs must be de-duplicated or reindexed before tenant uniqueness can replace global uniqueness.
- Route-model binding currently resolves globally; tenant-safe binding is required before exposing multiple organizations.
- Background jobs and scheduler commands currently operate globally.
- Notification rules are global; product must decide whether organizations own all rules or inherit platform defaults.
- Agent enrollment uses a global secret and global employee code lookup.
- Screenshot storage paths do not include organization and need a legacy read/move plan.
- Current tests prove single-organization role/team behavior, not cross-tenant isolation.

## 17. Ordered implementation milestones

1. Approve tenancy decisions in this audit.
2. Create organizations, memberships, current-organization resolver and suspended/inactive membership middleware.
3. Split platform-super-admin from organization owner/admin roles.
4. Enable and migrate Spatie teams for organization roles.
5. Add nullable organization ownership to identity/device tables and backfill.
6. Add tenant-aware validators, route binding and policies for core records.
7. Add nullable organization ownership to monitoring, aggregate, notification and token tables and backfill.
8. Make agent registration/config/ingest tenant-aware while retaining the current global secret temporarily.
9. Make desktop APIs, Livewire dashboards, reports and exports tenant-aware.
10. Prefix cache, Redis, broadcast and storage identifiers.
11. Add the full cross-tenant security test matrix.
12. Enforce non-null constraints and remove compatibility paths.
13. Design and implement organization-specific short-lived enrollment tokens in a later approved milestone.

## 18. Explicit non-goals

- No billing or subscription implementation.
- No public marketing website.
- No production agent deployment changes.
- No migrations, models, middleware, policies, controllers, services, factories, seeders or agent/Admin desktop code in this audit milestone.
- No transition from the global enrollment secret to organization-specific single-use enrollment tokens yet.
- No force-push, rebase or modification of the protected backup branch.

## 19. Rollback strategy

For future implementation phases:

- Phase A rollback: disable tenant middleware, restore pre-tenancy role checks, keep additive organizations/memberships if harmless, and revoke newly issued organization-bound tokens if the resolver is rolled back.
- Phase B/C rollback: because columns are additive and nullable, stop dual writes, keep backfilled columns in place, and restore global unique indexes only after confirming no tenant-duplicate values would violate them.
- Phase D rollback: do not attempt a blind rollback after non-null enforcement. First redeploy compatibility code, pause queue workers, relax constraints if needed, restore old cache/broadcast/storage names, and only then roll back behavior.
- Storage rollback: keep legacy screenshot path reads until all files are migrated and verified.
- Queue rollback: drain or fail old tenant-aware jobs explicitly; do not process tenant-aware payloads with pre-tenancy handlers.
- Verification after rollback: run baseline tests, tenant smoke tests, token authentication checks, queue health checks and storage signed-route checks.

## 20. Decisions requiring approval before implementation

- Which existing `admin` users become platform-super-admins versus organization owners/admins.
- Whether user email remains globally unique or becomes membership/customer scoped. Recommendation: keep globally unique for account identity.
- Whether `device_uuid` is unique per organization or globally unique. Recommendation: tenant-scoped unique plus operational collision alerts.
- Whether notification rules are fully organization-owned, platform-default-plus-org-override, or platform-only. Recommendation: platform defaults plus organization overrides.
- Whether organization deletion purges monitoring data or suspends/archive-retains it. Recommendation: suspend first, define retention/legal purge separately.
- Whether direct `organization_id` should be added to Sanctum tokens. Recommendation: yes for revocation and diagnostics.
- How platform support access is approved, scoped, logged and expired.
- Whether membership roles allow one role per membership or multiple roles. Recommendation: one primary organization role per membership, with permissions through roles.
- When to schedule the later enrollment-token transition.
