# SaaS Tenancy Phase A2

Phase A2 enables the authorization foundation for organization-scoped roles while still deferring direct tenant columns on domain, monitoring, agent and token tables to Phase B.

## Authorization architecture

Spatie Permission teams are enabled with `organization_id` as the team key. Organization role records are scoped by `roles.organization_id`; organization role assignments are scoped by `model_has_roles.organization_id`.

Platform-global roles remain separate. The platform role is `platform-super-admin`, stored with `roles.organization_id = null` and a global assignment. It is never assigned automatically by Phase A2 commands.

Organization roles are:

- `owner`
- `admin`
- `manager`
- `employee`

The `OrganizationAuthorization` service is the authoritative helper for checking active memberships, platform-super-admin status and organization roles. It avoids relying on stale Spatie team context by checking both role and assignment organization ids.

Legacy helpers such as `User::isSuperAdmin()` still preserve old single-organization behavior for existing dashboards and tests during the transition. They must not be used for new platform administration checks. New platform-only authorization must call `User::isPlatformSuperAdmin()` or `OrganizationAuthorization::isPlatformSuperAdmin()`.

## Current organization lifecycle

The `CurrentOrganization` resolver remains request-scoped. It resolves only active memberships in active organizations. Suspended organizations, inactive memberships and unrelated organization ids fail closed.

When an organization is resolved, the resolver sets Spatie's permission team id to the current `organization_id`. When resolution fails or the context is cleared, the team id is reset to `null`. Tests also reset the team id during teardown to protect against long-running process and test leakage.

If a web session contains a stale or unauthorized organization id, the resolver removes it and clears team context. If no organization is selected, exactly one active organization is selected automatically; otherwise explicit selection is required.

## Selection flow

Authenticated active users can open:

```text
GET /organizations/select
```

They can switch organization context with:

```text
POST /organizations/select
```

The selector lists only the user's active organizations. The POST endpoint accepts only organizations where the user has an active membership, stores the selected id in the authenticated web session, regenerates the session id and redirects to a safe local destination.

JSON requests protected by organization middleware receive JSON errors instead of HTML redirects. Missing or ambiguous selection returns `409`; inactive, suspended or unauthorized selections return `403`.

## Migration commands

Deploy Phase A2 code, then run:

```powershell
& "C:\php\php.exe" artisan migrate
```

The Phase A2 migration is forward-only for production safety. Its rollback does not remove team columns or mutate role assignments.

## Backfill commands

Preview existing legacy-admin conversion:

```powershell
& "C:\php\php.exe" artisan treck:backfill-organization-roles --slug="default" --dry-run
```

Run the conversion:

```powershell
& "C:\php\php.exe" artisan treck:backfill-organization-roles --slug="default"
```

You may target a specific organization id:

```powershell
& "C:\php\php.exe" artisan treck:backfill-organization-roles --organization-id=1 --dry-run
```

The command finds users with the legacy global `admin` role, ensures they have an active membership in the default organization and assigns the organization-scoped `admin` role there. It preserves existing memberships and the legacy role during this phase. It never creates or assigns `platform-super-admin`.

## Verification

Useful checks after migration and backfill:

```powershell
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan test
& "C:\php\php.exe" vendor\bin\pint --test
git diff --check
```

Database checks:

```sql
select id, organization_id, name, guard_name from roles order by organization_id, name;
select organization_id, role_id, model_type, model_id from model_has_roles order by organization_id, role_id, model_id;
select count(*) from organization_user where status = 'active';
select count(*) from roles where name = 'platform-super-admin';
```

## Deployment order

1. Confirm the Phase A1 default organization and memberships exist.
2. Deploy Phase A2 code.
3. Run migrations.
4. Clear configuration and application cache if the deployment cached `config/permission.php`.
5. Run `treck:backfill-organization-roles --dry-run`.
6. Review counts and invalid target messages.
7. Run `treck:backfill-organization-roles`.
8. Clear Spatie permission cache.
9. Restart long-running queue, Octane or websocket workers if deployed.
10. Run focused tenancy tests and the complete suite.

## Cache reset

The migration and backfill command call Spatie's permission cache reset. Operators should still clear caches during deployment when config is cached:

```powershell
& "C:\php\php.exe" artisan config:clear
& "C:\php\php.exe" artisan cache:clear
& "C:\php\php.exe" artisan permission:cache-reset
```

## Rollback

Phase A2 schema changes are intentionally additive and forward-only. A safe behavioral rollback is to redeploy the previous code, clear config/application/permission caches and leave the team columns in place.

Do not blindly drop team columns after role assignments have been written. First export and inspect `roles`, `model_has_roles`, `model_has_permissions` and `organization_user`.

## Deferred Phase B work

- No `organization_id` columns were added to employees, departments, computers, monitoring tables, agent tables, personal access tokens or report tables.
- Existing dashboard and reporting queries still need direct tenant columns before strict data isolation can be safely enforced.
- Agent registration, desktop APIs, broadcasts, storage paths, jobs and monitoring projectors remain single-organization compatible until later phases.
- Platform support access remains undesigned beyond the explicit `platform-super-admin` role boundary.

## Warning

`platform-super-admin` is never automatically assigned. Organization administrators are not platform administrators.
