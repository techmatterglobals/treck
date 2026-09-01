# SaaS Tenancy Phase B3

Phase B3 makes Windows agent enrollment, registration, and agent bearer tokens tenant-aware while preserving the installed agent's existing HTTP contract.

## Starting Point

Phase B3 starts from `feature/saas-tenancy-foundation` at:

```text
4f65a90560daeaea26e3bcf785cae4c51f832bd1
```

The authoritative pre-B3 backup is:

```text
backup/pre-saas-phase-b3-after-baseline-fix-2026-09-01
4f65a90560daeaea26e3bcf785cae4c51f832bd1
```

The earlier pre-hotfix backup remains unchanged:

```text
backup/pre-saas-phase-b3-2026-09-01
bdf9032f25e5c12eb47a79504cb5954afea3e6bf
```

The B2 baseline regression was fixed before B3 in:

```text
4f65a90560daeaea26e3bcf785cae4c51f832bd1
fix: stabilize tenant monitoring export date coverage
```

## Discovery Summary

The installed Windows agent already posts an opaque `enrollment_secret` to `POST /api/agent/register` and stores the returned bearer token in its encrypted token store. B3 keeps that client contract stable.

Pre-B3 server behavior trusted a single global `TRECK_AGENT_ENROLLMENT_SECRET`, looked up employees globally by `employee_code`, upserted computers globally by `device_uuid`, and created Sanctum Computer tokens without organization ownership.

B3 replaces the global trust source with organization-owned, hashed enrollment credentials and binds new agent tokens to the same organization as the registered computer.

## Schema

Phase B3 adds:

- `agent_enrollment_credentials`
- nullable `personal_access_tokens.organization_id`
- tenant lookup indexes for Computer token ownership

Enrollment credential rows include:

- `organization_id`
- `name`
- `public_id`
- `secret_hash`
- `expires_at`
- `max_uses`
- `uses_count`
- `last_used_at`
- `revoked_at`
- `created_by`
- `revoked_by`

The credential table uses a restrictive organization foreign key. Production databases also receive a restrictive foreign key from `personal_access_tokens.organization_id` to `organizations.id`. SQLite tests receive the token ownership column and indexes but skip alter-table foreign key attachment.

Both migrations are forward-only. Rollback intentionally does not drop credentials, token ownership, hashes, usage history, or audit-relevant tenant state.

## Enrollment Credentials

Enrollment credentials are created per organization and stored as hashes. Plaintext credentials are shown only once:

```powershell
& "C:\php\php.exe" artisan treck:agent-enrollment-create --organization=<id-or-slug> --name="Laptop rollout" --expires="2026-09-30 17:00:00" --max-uses=25
```

List credentials without secrets or hashes:

```powershell
& "C:\php\php.exe" artisan treck:agent-enrollment-list --organization=<id-or-slug>
```

Revoke a credential:

```powershell
& "C:\php\php.exe" artisan treck:agent-enrollment-revoke --organization=<id-or-slug> --credential=<id-or-public-id>
```

The web surface is available to organization owners and admins:

```text
GET  /agent-enrollment-credentials
POST /agent-enrollment-credentials
POST /agent-enrollment-credentials/{credential}/revoke
```

Organization members without a scoped `owner` or `admin` role fail closed. Legacy global roles alone do not authorize credential management.

## Registration

`POST /api/agent/register` still accepts:

- `enrollment_secret`
- `device_uuid`
- `employee_code`
- optional computer metadata

The request cannot supply `organization_id`.

Registration resolves the organization from the enrollment credential inside a database transaction. The credential row is locked before validation and usage increment so one-use and limited-use credentials are consumed safely under concurrent requests.

Employee lookup is scoped to the credential's organization. Computer lookup is scoped to the credential's organization and still respects the existing global `device_uuid` uniqueness boundary. If the same device UUID already belongs to another organization or to an unowned legacy row, registration fails with a generic validation error and does not consume the credential.

When registration succeeds:

- the computer receives the credential organization id
- the computer is paired to an employee in the same organization
- previous device tokens are deleted
- one new `agent:report` token is issued
- the token receives `personal_access_tokens.organization_id`
- the credential usage count and last-used timestamp are updated

`platform-super-admin` is never created or assigned by registration.

## Agent Token Enforcement

Authenticated agent routes now use the `agent.token` middleware after Sanctum authentication.

The middleware requires:

- tokenable identity is a `Computer`
- token has the requested `agent:report` ability
- the computer is not deleted
- the computer has an organization
- the organization is active
- the token organization is either equal to the computer organization or is a legacy null-owned token allowed by compatibility configuration

Legacy null-owned Computer tokens are accepted only when the tokenable computer has a valid active organization and:

```text
TRECK_AGENT_LEGACY_TOKEN_COMPATIBILITY=true
```

Set the flag to `false` after token backfill and rollout verification to reject null-owned legacy agent tokens.

Human user Sanctum tokens and Admin Desktop routes are not changed by B3.

## Token Backfill

Preview existing agent token ownership:

```powershell
& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug> --dry-run
```

Run the backfill:

```powershell
& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug>
```

Verify afterward:

```powershell
& "C:\php\php.exe" artisan treck:backfill-agent-token-ownership --organization=<id-or-slug> --verify
```

Behavior:

- Only `personal_access_tokens` rows for Computer tokenables are considered.
- Only rows where token `organization_id` is null are updated.
- The selected organization must match the tokenable computer's `organization_id`.
- Existing non-null token ownership is never overwritten.
- Missing or null-owned computers are reported as unresolved.
- Token/computer organization mismatches are reported as conflicts.
- `--verify` is read-only and fails if selected-organization rows remain backfillable or conflicts exist.
- The command reports `platform_super_admin_assignments=0`.

## Endpoint Tenant Behavior

Agent config now returns the authenticated computer's organization id in policy data. Agent health continues to stamp ownership from the authenticated computer. Agent event and screenshot ingestion ignore tenant payloads and derive organization from trusted server-side computer ownership.

Windows user attribution through `computer_users` is tenant-safe. If an existing mapping points to an employee in another organization, B3 ignores the employee id, logs a security event without secrets, and stores the incoming monitoring row under the computer's organization with a null employee id where endpoint semantics allow it.

## Security Logging

B3 emits security events for enrollment creation, revocation, invalid attempts, exhausted or revoked usage attempts, registration conflicts, legacy token compatibility, token/computer organization mismatch, inactive organization access, and employee mapping conflicts.

Sensitive values are redacted by key name. Enrollment plaintext, secret hashes, bearer tokens, and authorization headers must not be written to logs.

## Verification

Recommended checks:

```powershell
& "C:\php\php.exe" artisan test --filter=PhaseB3
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan test
& "C:\Program Files\dotnet\dotnet.exe" test agent/tests/Treck.Agent.Tests/Treck.Agent.Tests.csproj -c Release --logger "console;verbosity=normal"
& "C:\php\php.exe" vendor\bin\pint --test <changed files>
git diff --check
```

Repository-wide Pint may still report pre-existing formatting or line-ending issues outside Phase B3. Do not rewrite unrelated files for this phase.

## Deployment Notes

Do not deploy these tenancy migrations to production until the migration rollout plan has been reviewed with production data owners.

Suggested order for a controlled deployment:

1. Deploy Phase B3 code.
2. Run migrations.
3. Create organization-specific enrollment credentials.
4. Distribute credentials through an approved secure channel.
5. Run token backfill dry-runs by organization.
6. Review unresolved and conflicted token counts.
7. Run token ownership backfill for approved organizations.
8. Verify backfill.
9. Observe legacy compatibility logs.
10. Disable legacy null-token compatibility after rollout is complete.

## Deferred Phase B Work

The following remain deferred:

- Phase B4 Admin Desktop tenant isolation and desktop API contract changes.
- Billing, subscriptions, limits, plans, invoices, and organization lifecycle automation.
- Public registration, onboarding, invitations, domains, and marketing pages.
- Deployment migration rollout and production data execution.
- Global tenant scopes, tenant-aware broadcast channel redesign, cache partitioning, and storage path migration.
- Scoped uniqueness migration for employee codes and device UUIDs after duplicate transition planning.
- Agent installer UX and centralized credential delivery workflow beyond the current opaque credential input.
