# SaaS Tenancy Phase B6 Security Audit

Phase B6 is the final SaaS tenancy security audit, migration rehearsal and
release-candidate preparation pass for A1 through B5. It does not start Phase
B7, billing, public onboarding, organization deletion, deployment automation or
production rollout.

## Starting Point

- Branch: `feature/saas-tenancy-foundation`
- Starting commit: `3ee9227d63c8e97ca2e6bc687e2624b1e519eb99`
- Backup ref: `backup/pre-saas-phase-b6-2026-09-03`
- Backup SHA: `3ee9227d63c8e97ca2e6bc687e2624b1e519eb99`

The backup ref is a release safety marker only. Do not move, delete, overwrite,
rebase or force-update it.

## Audit Inventory

| Area | Result |
| ---- | ------ |
| Organization selection | Session/header tampering is rejected by membership and scoped-role checks. |
| Authorization source | `OrganizationAuthorization` and Spatie team-scoped roles remain authoritative for tenant screens. Legacy global roles do not grant tenant authority. |
| Core records | Departments, employees and computers are organization-scoped; request input cannot override `organization_id` on employee create. |
| Monitoring records | Presence, activity, application usage, screenshots, downloads, reports, attendance and notifications are filtered by current organization. |
| Manager access | Managers remain limited to assigned employees inside the selected organization. |
| Agent registration | Enrollment credentials are hashed, organization-owned, revocable, expiring and consumed under a transaction. |
| Agent tokens | Computer tokens are organization-bound and token/computer mismatches fail closed. |
| Admin Desktop | Tenant APIs require `X-Treck-Organization-Id`; web session state and query/body `organization_id` values are ignored. |
| Cache | Tenant cache keys require a positive organization id and use `org:{id}:...`; platform keys use explicit `platform:...`. |
| Broadcast | Organization-owned notifications and presence use tenant channels; null-owned legacy rows are not broadcast. |
| Queue context | Jobs carrying `organizationId` run inside `OrganizationContext` and clear Spatie team state afterward, including failures. |
| Storage | Screenshot paths are tenant-prefixed after B5 storage migration; legacy fallback remains an explicit temporary compatibility flag. |
| Readiness | `treck:verify-saas-readiness` is read-only and now reports backfill, relationship, token and storage-path blockers. |
| Secrets | Enrollment secrets, bearer tokens, hashes and authorization headers are not logged or documented. |
| Platform admin | `platform-super-admin` is never automatically created or assigned by A1-B6 code or commands. |

## Findings

### High - Null-owned records could still choose legacy broadcast channels

Status: fixed in B6.

`PresenceChanged` and `NotificationCreated` now emit no channels when the
persisted row has no `organization_id`. This prevents transitional or conflicting
rows from being broadcast while backfills are incomplete. Organization-owned
rows continue to use:

- `private-organization.{organization_id}.presence`
- `private-organization.{organization_id}.presence.computer.{computer_id}`
- `private-organization.{organization_id}.notifications.user.{user_id}`

### Medium - Storage migration verified size but not content

Status: fixed in B6.

`treck:migrate-tenant-storage` now verifies SHA-256 content equality after a
copy or when the target already exists. A same-size but different target object
is reported as a verification failure and the screenshot row is not updated.

### Medium - Release readiness lacked tenant integrity diagnostics

Status: fixed in B6.

`treck:verify-saas-readiness` remains read-only and now reports:

- null `organization_id` counts for tenant-owned tables
- parent-child organization mismatches
- computer token null ownership and token/computer mismatches
- tenant screenshot path mismatches

These checks are blockers for release-candidate approval, not automatic repair
actions.

### Informational - Compatibility flags remain explicit

Status: deferred.

`TRECK_AGENT_LEGACY_TOKEN_COMPATIBILITY` and
`TRECK_SCREENSHOT_LEGACY_FALLBACK` remain temporary rollout controls. Disable
them only after production-like backfill and storage verification pass for every
organization.

## Migration Rehearsal Posture

Run A1-B5 migrations on a production-like copy before production. SQLite tests
exercise the forward-only migrations without alter-table foreign keys where
Laravel/SQLite cannot attach them safely. Production engines receive restrictive
organization foreign keys from the B1-B3 migrations.

Required rehearsal order remains in
[`41-saas-production-rollout.md`](41-saas-production-rollout.md). B6 adds an
additional release-candidate gate: the final `treck:verify-saas-readiness` run
must pass after all backfills and storage migrations.

## B6 Regression Coverage

`Tests\Feature\Tenancy\PhaseB6SaasSecurityAuditTest` covers:

- cross-organization web IDOR and signed screenshot access
- desktop organization header tampering
- agent/human token confusion
- active membership without scoped role
- legacy global role denial
- inactive membership denial
- manager assigned-employee scope
- agent registration tenant tampering without credential consumption
- token/computer organization mismatch
- tenant cache key isolation
- null-owned broadcast suppression
- queue/context cleanup after failed organization context resolution
- employee create mass-assignment protection
- read-only readiness blocker reporting
- same-size storage-copy tamper detection
- no automatic `platform-super-admin` assignment

## Deferred Scope

Do not include these in B6:

- billing, subscriptions, plans or entitlements
- public signup, onboarding or marketing pages
- organization deletion/offboarding workflows
- destructive legacy screenshot cleanup
- scoped uniqueness changes for employee codes or device UUIDs
- platform support impersonation/access flows
- production deployment, tagging or merge approval
- Phase B7 or any later feature work
