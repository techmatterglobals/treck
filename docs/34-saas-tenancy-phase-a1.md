# SaaS Tenancy Phase A1

Phase A1 adds the organization and membership foundation without activating tenant filtering on existing production routes.

## Database design

`organizations` stores tenant records with `name`, globally unique `slug`, `status`, optional `suspended_at` and timestamps. Supported statuses are `active` and `suspended`. Organization deletion is intentionally not part of Phase A1.

`organization_user` stores memberships with `organization_id`, `user_id`, `status`, `role`, `is_owner`, optional `joined_at`, optional `invited_by_id` and timestamps. The table enforces one membership per organization and user. Inactive memberships are retained instead of deleted.

The `role` column is foundation metadata for Phase A2. It does not replace current Spatie permissions yet, and Spatie teams remain disabled in Phase A1.

## Current organization rules

The request-scoped `CurrentOrganization` contract resolves an organization only after membership validation.

- A selected session organization is honored only when the authenticated user has an active membership.
- A user with exactly one active membership in a non-suspended organization resolves automatically.
- Multiple active organizations without an explicit selection fail closed.
- Suspended organizations fail before role checks.
- Inactive memberships fail before role checks.
- Users with no membership, including future platform-only users, fail closed for organization-scoped contexts.

The opt-in middleware alias is `organization`. It is registered for future integration but is not applied globally in Phase A1.

## Backfill command

Run the explicit existing-installation backfill after migrating:

```powershell
& "C:\php\php.exe" artisan migrate
& "C:\php\php.exe" artisan treck:backfill-default-organization --name="Default Organization" --slug="default"
```

Preview first:

```powershell
& "C:\php\php.exe" artisan treck:backfill-default-organization --name="Default Organization" --slug="default" --dry-run
```

The command creates or locates one default organization, attaches existing non-platform users idempotently, marks existing `admin` users as temporary organization owners, never creates `platform-super-admin`, and never deletes users, roles or monitoring data.

## Deployment order

1. Deploy Phase A1 code.
2. Run migrations.
3. Run the backfill command with `--dry-run`.
4. Review counts.
5. Run the backfill command without `--dry-run`.
6. Clear application/config cache if deployment tooling cached container bindings.
7. Run the focused tenancy tests and the complete Laravel suite.

## Rollback

No existing authorization behavior is replaced in Phase A1. If rollback is required before later phases, deploy the previous code and roll back the Phase A1 migration only if no later tenancy data depends on it:

```powershell
& "C:\php\php.exe" artisan migrate:rollback --step=1
```

Do not roll back the table after Phase A2 or later phases start using memberships for authorization decisions.

## Intentionally inactive until later phases

- No `organization_id` columns on employees, computers, monitoring tables or Sanctum tokens.
- No Spatie teams activation.
- No global route tenancy middleware activation.
- No agent/device authorization changes.
- No Windows Admin desktop tenancy changes.
- No billing, subscription or public website work.
- No organization deletion flow.

## Verification commands

```powershell
& "C:\php\php.exe" artisan test --filter=PhaseA1TenancyFoundationTest
& "C:\php\php.exe" artisan test
git diff --check
git status --short
git diff --stat
```
