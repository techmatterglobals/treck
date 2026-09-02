# SaaS Production Rollout Runbook

This runbook is a rehearsal guide for A1 through B5. It is not approval to run
the tenancy migrations in production.

## Preconditions

- Confirm the release commit and protected backup refs.
- Take verified database and storage backups.
- Put the application into maintenance mode for schema and backfill execution.
- Stop queue workers before migration and restart them only after verification.
- Keep production credentials out of logs, tickets, screenshots, and command
  output.

## Required Order

Run against a production-like copy before production:

```powershell
& "C:\php\php.exe" artisan migrate --pretend
& "C:\php\php.exe" artisan migrate

& "C:\php\php.exe" artisan treck:backfill-default-organization --dry-run
& "C:\php\php.exe" artisan treck:backfill-default-organization

& "C:\php\php.exe" artisan treck:backfill-organization-roles --dry-run
& "C:\php\php.exe" artisan treck:backfill-organization-roles

& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization=<id-or-slug> --dry-run
& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization=<id-or-slug>
& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization=<id-or-slug> --verify

& "C:\php\php.exe" artisan treck:backfill-monitoring-organization-ownership --organization=<id-or-slug> --dry-run
& "C:\php\php.exe" artisan treck:backfill-monitoring-organization-ownership --organization=<id-or-slug>
& "C:\php\php.exe" artisan treck:backfill-monitoring-organization-ownership --organization=<id-or-slug> --verify

& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug> --dry-run
& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug>
& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug> --verify

& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug> --dry-run
& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug>
& "C:\php\php.exe" artisan treck:migrate-tenant-storage --organization=<id-or-slug> --verify

& "C:\php\php.exe" artisan treck:verify-saas-readiness
& "C:\php\php.exe" artisan treck:verify-saas-readiness --json
```

Repeat organization-scoped backfills for every approved organization. Resolve
all conflicts before production rollout. Do not assign `platform-super-admin`
automatically.

## Rollback Posture

A1 through B5 migrations are additive or forward-only for security state.
Behavioral rollback should use the previous application release while preserving
organization ownership, scoped roles, token ownership, and copied storage files.

Do not drop tenant columns, scoped Spatie authorization data, token ownership, or
copied screenshot objects as part of rollback.

## Storage

The B5 storage migration copies legacy screenshot objects into tenant paths and
updates rows only after target verification. It never deletes legacy files. Keep
legacy files until:

- every organization verifies cleanly
- application fallback has been disabled in a later maintenance window
- backups containing legacy and tenant paths have been verified

## Final Verification

Before ending maintenance mode:

```powershell
& "C:\php\php.exe" artisan test
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan treck:verify-saas-readiness
```

Restart queue workers after the final readiness check. Monitor queue failures,
broadcast authorization errors, screenshot fallback warnings, and tenant
backfill verification output.

## Deferred

Do not include these in the B5 rollout:

- billing or subscription enforcement
- public signup/onboarding/marketing
- organization deletion/offboarding
- destructive legacy screenshot cleanup
- scoped employee-code/device-UUID uniqueness changes
- production deployment without a completed rehearsal
