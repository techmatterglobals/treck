# SaaS Tenancy Phase B1

Phase B1 adds direct organization ownership to Treck's core tenant records while leaving monitoring, agent, token, billing, public-site and deployment surfaces unchanged.

## Scope

Direct nullable `organization_id` ownership was added to:

- `departments`
- `employees`
- `computers`

`computer_users` was evaluated and intentionally left without direct ownership in B1. It is a Windows-account attribution child of `computers` and `employees`; changing it belongs with later agent/identity work.

No global Eloquent scopes were added. Tenant isolation is applied explicitly in authenticated web CRUD, hierarchy assignment paths and the backfill command.

## Schema

The B1 migration is additive and forward-only. It adds nullable `organization_id` columns and tenant lookup indexes while preserving existing primary keys, public identifiers and global uniqueness constraints.

Global uniqueness remains in place for:

- `employees.employee_code`
- `computers.device_uuid`

The migration also adds non-unique compound indexes for tenant-filtered reads and assignments, including organization/name, organization/employee-code, organization/device-uuid and common parent/status filters.

Production databases receive `restrictOnDelete` foreign keys to `organizations`, so deleting an organization cannot cascade-delete core business records. SQLite test databases receive the columns and indexes but skip alter-table foreign key attachment because SQLite cannot add those constraints to existing tables in the same production-compatible way.

## Authorization Behavior

Employee CRUD routes now require an active current organization context. The controller scopes department options, employee route records and assignable computers to the current organization.

Creates always set `employees.organization_id` from the resolved current organization on the server. Request input cannot override it.

Updates preserve the existing organization owner. Department validation only accepts departments in the current organization.

Computer assignment only accepts unassigned computers in the current organization. Unassignment first verifies both the employee and computer belong to the current organization and then verifies the computer is actually assigned to that employee.

Manager assignment remains narrower than organization membership. A manager can only receive employees in the current organization, and the selected manager must hold the manager role for that same organization. Cross-organization employee/manager links fail closed.

The current-organization resolver now fetches the active request lazily at resolution time. This prevents stale request identity from leaking into tests or long-running processes when tenancy services are resolved before a new request starts.

## Backfill

Preview planned ownership changes:

```powershell
& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization="default" --dry-run
```

Run the backfill:

```powershell
& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization="default"
```

Verify after backfill:

```powershell
& "C:\php\php.exe" artisan treck:backfill-core-organization-ownership --organization="default" --verify
```

The `--organization` value may be an organization id or slug.

The command assigns only rows where `organization_id` is null. It never moves records that already belong to another organization. It reports relationship conflicts, including employee/department and computer/employee organization mismatches, plus null-owned child rows blocked by an already-owned parent from another organization.

Backfill order is parent-before-child:

1. Departments
2. Employees
3. Computers

The command never creates or assigns `platform-super-admin`.

## Rollback

The migration rollback is intentionally a no-op. Dropping populated ownership columns would remove tenancy data and could make future isolation checks unsafe.

A behavioral rollback should redeploy the previous code while leaving the ownership columns intact. Investigate and export core ownership data before any manual schema reversal.

## Verification

Recommended checks:

```powershell
& "C:\php\php.exe" artisan test --filter=PhaseB1
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan test
& "C:\php\php.exe" vendor\bin\pint --test
git diff --check
```

Useful database checks:

```sql
select count(*) from departments where organization_id is null;
select count(*) from employees where organization_id is null;
select count(*) from computers where organization_id is null;
select count(*) from roles where name = 'platform-super-admin';
```

## Deferred Phase B Work

- Tenant ownership for `computer_users` and Windows identity mapping.
- Tenant scoping for monitoring/event tables, screenshots, file downloads, app usage, activity logs, presence, reports and rollups.
- Agent registration, enrollment, health, ingest endpoints and projectors.
- Admin Desktop and desktop API tenancy.
- Tenant-aware broadcast channels, cache keys, queues and storage paths.
- Billing, subscriptions, public registration, marketing pages and deployment configuration.
- Platform support access beyond the explicit `platform-super-admin` boundary.

## Warning

`platform-super-admin` is never automatically assigned. Organization administrators are not platform administrators.
