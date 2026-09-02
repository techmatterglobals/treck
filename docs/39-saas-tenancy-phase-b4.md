# SaaS Tenancy Phase B4

Phase B4 makes the Laravel Admin Desktop API and the Windows WPF Admin Desktop explicitly organization-aware. Authentication remains human user bearer authentication; selected organization context is transported separately and validated on every protected desktop request.

## Starting Point

Phase B4 starts from `feature/saas-tenancy-foundation` at:

```text
75e9a32aea36b6eb669b43b2190ff15b34eca6dc
```

The authoritative pre-B4 backup is:

```text
backup/pre-saas-phase-b4-2026-09-02
75e9a32aea36b6eb669b43b2190ff15b34eca6dc
```

## Discovery Summary

The pre-B4 Admin Desktop login returned a Sanctum user token, identity, legacy role names, and token abilities from `POST /api/v1/auth/login`. The desktop bootstrap route returned user identity, roles, permissions, features, and server metadata, but it did not list organizations or require an explicit tenant context.

The WPF client persisted only the bearer token with DPAPI and injected it through `AccessTokenHandler`. Polling lived in overview, presence, and agent-health view models, each with its own cancellation token. Navigation was built from bootstrap features and permissions.

Phase B4 keeps that shape but adds one authoritative selected-organization state service in the Admin Desktop application layer and a server-side desktop organization resolver that uses the A2 Spatie team authorization path.

## Organization Context Contract

Tenant-protected Admin Desktop requests use:

```text
X-Treck-Organization-Id: <organization id>
```

The header value is not proof of access. The Laravel middleware validates:

- authenticated tokenable is a `User`, not an agent `Computer`
- header is present and parseable as an integer
- organization exists and is active
- authenticated user has an active membership
- authenticated user has a scoped organization role of `owner`, `admin`, or `manager`
- Spatie permissions team context is set to the selected organization for the request
- context is cleared when the request finishes or resolution fails

Login, logout, and bootstrap do not require the organization header. Query-string or body `organization_id` values are ignored by the Desktop API tenant resolver.

Stable JSON error codes:

- `401 unauthenticated`
- `403 organization_forbidden`
- `409 organization_required`
- `409 organization_inactive`

Desktop API requests render JSON errors and do not redirect to login or HTML organization selection.

## Bootstrap Contract

`GET /api/v1/desktop/bootstrap` is callable with a human bearer token and no selected organization. It returns:

- `contract_version`
- authenticated `user`
- aggregate desktop `roles` and `permissions`
- authorized `organizations`
- `organization_selection_required`
- `recommended_organization` when exactly one active authorized organization exists
- existing `features`
- existing server version/timezone metadata

Each organization row contains non-secret fields only:

- `id`
- `name`
- `slug`
- `status`
- effective scoped `role`
- desktop `permissions`
- role-derived `features`

Bootstrap lists only active organizations with an active membership and an allowed scoped desktop role. Membership alone, employee scoped roles, inactive memberships, inactive organizations, and legacy global roles do not create Admin Desktop organization access.

## Role And Capability Mapping

Owners and admins receive organization-wide desktop monitoring capabilities. Managers receive monitoring capabilities limited to assigned employees inside the selected organization. Employees and membership-only users are excluded from the Admin Desktop organization list.

`platform-super-admin` is not automatically assigned by Phase B4. A platform-level user still needs an explicit selected organization and an allowed scoped organization role for tenant screens.

## Server-Side Isolation

Protected Desktop endpoints now apply tenant boundaries in this order:

```text
current organization
AND scoped role permissions
AND manager-assigned employee visibility
AND requested filters
```

The protected endpoints are:

- `GET /api/v1/desktop/overview`
- `GET /api/v1/desktop/presence`
- `GET /api/v1/desktop/employees/{employee}`
- `GET /api/v1/desktop/agent-health`

Overview employee totals, present counts, presence summary, and active/idle totals are filtered by the selected organization first. Presence rows and agent-health rows hide null-owned and foreign-owned records. Foreign employee detail IDs return a non-disclosing JSON 404. Managers see only assigned employees and their computers inside the selected organization.

Agent endpoints, enrollment credentials, registration payloads, event ingestion, and agent token rules are unchanged by Phase B4.

## WPF Selection Flow

The Admin Desktop application layer owns organization state through `CurrentOrganizationService`. It stores only the selected organization ID and uses the latest bootstrap response as the authority for metadata, roles, and capabilities.

After sign-in or session restoration:

1. The bearer token is restored or stored using the existing DPAPI token store.
2. Bootstrap is called without organization context.
3. A saved selected organization ID is revalidated against bootstrap organizations.
4. If the saved ID is invalid, it is cleared.
5. If exactly one organization is authorized, it is selected automatically.
6. If multiple organizations are authorized, the user must select one.
7. Polling starts only after a selected organization exists.

The selector is shown in the authenticated shell. It lists only organizations returned by bootstrap and shows the current organization name. Changing organizations cancels current polling, clears tenant data, rebuilds navigation from the new organization's capabilities, and restarts the initial screen only after selection succeeds.

## Header Injection

The Admin Desktop HTTP pipeline now has two handlers:

- `AccessTokenHandler` adds the bearer token.
- `OrganizationContextHandler` adds `X-Treck-Organization-Id` to tenant-protected Desktop API requests.

The organization header is read at request-send time from `CurrentOrganizationService`. Bootstrap is deliberately excluded from organization header requirements. The auth client is not given the organization context handler, so logout remains usable without a valid selected organization.

No bearer token, authorization header, organization secret, enrollment credential, or hash is logged or displayed by this phase.

## Polling And Race Safety

Overview, presence, and agent-health polling remain screen-owned and cancellable. Each refresh captures the current organization generation before the HTTP request and discards the result if the generation changes before completion.

Organization switching:

- cancels active polling
- increments organization generation
- clears overview, presence, employee detail, and agent-health data
- clears stale navigation
- applies the newly selected organization
- rebuilds navigation from server-provided capabilities
- starts polling only after the new selection is valid

Logout and `401` responses clear token state, organization state, and displayed tenant data. Organization-required, inactive, and forbidden organization responses clear protected data and refresh the organization list without trusting cached roles.

## Backward Compatibility

Existing installed Admin Desktop builds that do not understand the B4 bootstrap organization list or do not send `X-Treck-Organization-Id` will be able to authenticate and call bootstrap, but tenant-protected endpoints will return `organization_required`.

The persisted selected organization ID is not authoritative. It is restored only after the server includes the same organization in a fresh bootstrap response.

## Local Testing

Recommended checks:

```powershell
& "C:\php\php.exe" artisan test --filter=PhaseB4
& "C:\php\php.exe" artisan test --filter=Desktop
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan test
& "C:\Program Files\dotnet\dotnet.exe" test admin-desktop/tests/Treck.Admin.Application.Tests/Treck.Admin.Application.Tests.csproj -c Release
& "C:\Program Files\dotnet\dotnet.exe" build admin-desktop/Treck.Admin.Desktop.sln -c Release
& "C:\Program Files\dotnet\dotnet.exe" test agent/tests/Treck.Agent.Tests/Treck.Agent.Tests.csproj -c Release
& "C:\php\php.exe" vendor\bin\pint --test <changed PHP files>
git diff --check
```

Repository-wide Pint may still report pre-existing formatting or line-ending issues outside Phase B4. Do not rewrite unrelated files for this phase.

## Production Rollout Notes

Do not deploy A1 through B4 to production until migration and backfill rehearsal has been completed with production data owners.

Suggested rollout order:

1. Finish B5 cache, broadcast, and remaining infrastructure isolation.
2. Rehearse A1-B4 migrations and backfills against a production-like copy.
3. Deploy API and Admin Desktop versions together.
4. Confirm bootstrap returns expected organization lists for pilot admins and managers.
5. Confirm old Admin Desktop clients receive `organization_required` for tenant screens.
6. Roll out the new Admin Desktop client.
7. Monitor authorization denials without logging tokens or secrets.

Rollback can disable the new client rollout, but migrated tenant ownership, organization memberships, and audit-relevant state are forward-only and should not be destroyed.

## Deferred B5 Work

The following remain deferred:

- Cache partitioning by tenant.
- Broadcast channel partitioning by tenant.
- Storage path migration or tenant-aware physical storage layout.
- Public SaaS onboarding, billing, subscriptions, and marketing pages.
- Production deployment execution and production data backfill.
- Scoped uniqueness migration for employee codes and device UUIDs after duplicate transition planning.
