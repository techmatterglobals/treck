# SaaS Tenancy Phase B5 - Infrastructure Tenant Isolation

Phase B5 closes the infrastructure layer around the tenant foundations delivered
in A1 through B4. It does not deploy the tenancy migrations to production and it
does not begin billing, signup, organization deletion, or scoped public-identifier
work.

## Baseline

Phase B5 starts from:

```text
767d29a1e1df84d14b8631a26fcdd540628ebfce
feat: add Admin Desktop tenant isolation
```

The protected backup ref is:

```text
backup/pre-saas-phase-b5-2026-09-02
767d29a1e1df84d14b8631a26fcdd540628ebfce
```

## Discovery Note

The audited infrastructure surfaces were:

- Cache: no application domain cache keys were active before B5. Framework
  cache/session/log paths and Spatie's permission cache remain platform-global.
- Broadcasting: the active broadcast surfaces were `routes/channels.php`,
  `PresenceChanged`, and `NotificationCreated`.
- Queues: notification evaluation/delivery jobs already carried trusted
  organization ids. Daily attendance/productivity rollups needed explicit
  organization context.
- Schedule: the scheduled command names remain global, but daily rollup now
  iterates active organizations independently.
- Storage: screenshot rows were already tenant-owned, but byte paths still used
  the legacy `screenshots/{computer_id}/{date}` namespace.
- Notifications: recipient resolution already accepted an organization id; B5
  verifies it only returns active scoped organization admins.
- Worker context: request-scoped organization state remains separate from worker
  execution. B5 adds a dedicated worker context helper for Spatie team context.

Expected B5 changes were limited to tenancy support, tenant cache keys, broadcast
routes/events, Livewire Echo channel names, rollup jobs/services/command,
screenshot storage, two operational commands, focused tests, and documentation.

## Cache Keys

`App\Support\Tenancy\TenantCacheKey` is the canonical helper for future
application cache keys:

```php
TenantCacheKey::forOrganization($organization, 'presence summary');
TenantCacheKey::platform('release metadata');
```

Tenant keys are prefixed as `org:{organization_id}:...`. Platform keys must be
explicitly created with `platform(...)`. Phase B5 does not rewrite framework
cache paths or Spatie permission cache internals.

## Broadcast Isolation

Organization-owned presence and notification events now publish to private
tenant channels:

```text
private-organization.{organization_id}.presence
private-organization.{organization_id}.presence.computer.{computer_id}
private-organization.{organization_id}.notifications.user.{user_id}
```

Channel authorization verifies:

- the organization is active
- the user has active membership and a scoped organization role
- managers can subscribe only to assigned computers
- notification channels are limited to the recipient and scoped organization
- agent/device tokens are not part of the web broadcast authorization path

Payloads include `organization_id` from trusted persisted rows and continue to
exclude credentials, device tokens, bearer tokens, file paths, hashes, and
authorization headers.

Legacy global channels remain only for null-owned compatibility rows during
rollout.

## Queue And Worker Context

`App\Services\Tenancy\OrganizationContext` sets Spatie's team id for a single
worker operation and clears it in a `finally` block.

`App\Jobs\Middleware\SetOrganizationContext` wraps queued jobs that carry an
`organizationId` property. The middleware is attached to:

- `EvaluateNotificationsJob`
- `SendNotificationJob`
- `RollUpDailyAttendance`
- `GenerateDailyProductivity`

This keeps queue workers, sync dispatches, and tests from leaking organization
context between jobs.

## Scheduled Rollups

`treck:daily-rollup` now processes every active organization independently by
default. It also accepts:

```powershell
& "C:\php\php.exe" artisan treck:daily-rollup 2026-09-02 --organization=<id-or-slug>
```

Attendance and productivity services accept an optional organization id and
filter source rows, employees, and application usage to that organization.
Presence reconciliation, presence sweep, event pruning, screenshot pruning, and
framework schedule definitions remain platform-global operational commands.

## Screenshot Storage

New tenant-owned screenshot uploads are stored under:

```text
organizations/{organization_id}/screenshots/{computer_id}/{date}/{filename}
```

The agent payload did not change. Organization id is still derived on the server
from the trusted computer/employee ownership chain.

Authorized reads may temporarily fall back to the legacy path when:

- the screenshot row is already authorized by tenant ownership
- `treck.screenshots.legacy_fallback` is enabled
- the current path is missing
- the expected legacy object exists

Fallback use is logged without secrets or raw authorization data. Disable the
fallback after storage migration verifies:

```env
TRECK_SCREENSHOT_LEGACY_FALLBACK=false
```

## Storage Migration

The storage migration command is non-destructive and organization-scoped:

```powershell
& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug> --dry-run
& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug>
& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug> --verify
```

It copies only. It does not overwrite existing tenant objects, does not delete
legacy files, and updates the database path only after the tenant object exists
and size verification succeeds. The command reports:

- planned
- copied
- already_tenant
- missing_source
- target_exists
- verification_failures
- platform_super_admin_assignments=0

`--verify` is read-only and fails while tenant-owned rows still need copying,
have missing sources, or fail verification.

## Readiness Verification

`treck:verify-saas-readiness` is read-only. It verifies the A1-B5 schema,
configuration, and infrastructure assumptions required before production
rollout:

```powershell
& "C:\php\php.exe" artisan treck:verify-saas-readiness
& "C:\php\php.exe" artisan treck:verify-saas-readiness --json
```

The command exits non-zero on deployment-blocking failures and reports
`platform_super_admin_assignments=0`. It never creates roles, memberships,
organizations, credentials, tokens, or files.

## Security Notes

- `platform-super-admin` is never automatically assigned.
- Broadcast auth does not trust client-supplied organization ids alone.
- Queue organization context is set from trusted job state and cleared after
  each job.
- Notification recipients come from scoped organization roles, not legacy global
  roles.
- Screenshot paths cannot be chosen by agent payloads.
- Legacy storage fallback is row-authorized and configurable.

## Deferred Work

Deferred beyond Phase B5:

- billing, subscription plans, quotas, and plan enforcement
- public signup, tenant onboarding, and marketing flows
- organization deletion and tenant offboarding
- scoped employee-code/device-UUID uniqueness transition
- production deployment and production backfill execution
- destructive cleanup of legacy screenshot files after migration
- Phase B6+ reporting, analytics, or account-management expansion
