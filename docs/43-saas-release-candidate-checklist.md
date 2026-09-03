# SaaS Release Candidate Checklist

This checklist prepares the A1-B6 tenancy foundation for review. It is not a
production deployment approval.

## Required References

- `backup/pre-saas-phase-b6-2026-09-03`
- [`34-saas-tenancy-phase-a1.md`](34-saas-tenancy-phase-a1.md)
- [`35-saas-tenancy-phase-a2.md`](35-saas-tenancy-phase-a2.md)
- [`36-saas-tenancy-phase-b1.md`](36-saas-tenancy-phase-b1.md)
- [`37-saas-tenancy-phase-b2.md`](37-saas-tenancy-phase-b2.md)
- [`38-saas-tenancy-phase-b3.md`](38-saas-tenancy-phase-b3.md)
- [`39-saas-tenancy-phase-b4.md`](39-saas-tenancy-phase-b4.md)
- [`40-saas-tenancy-phase-b5.md`](40-saas-tenancy-phase-b5.md)
- [`41-saas-production-rollout.md`](41-saas-production-rollout.md)
- [`42-saas-phase-b6-security-audit.md`](42-saas-phase-b6-security-audit.md)

## Go/No-Go Gates

Run all gates below before a release-candidate recommendation. Repository-wide
formatting or static-analysis environment exceptions must be documented and must
not be caused by B6 changes.

```powershell
& "C:\php\php.exe" artisan test --filter=PhaseB6
& "C:\php\php.exe" artisan test --filter=Tenancy
& "C:\php\php.exe" artisan test
& "C:\php\php.exe" artisan test
& "C:\php\php.exe" vendor\bin\pint --test
& "C:\php\php.exe" vendor\bin\phpstan analyse --memory-limit=1G
git diff --check
```

Admin Desktop:

```powershell
& "C:\Program Files\dotnet\dotnet.exe" test admin-desktop\tests\Treck.Admin.Application.Tests\Treck.Admin.Application.Tests.csproj -c Release
& "C:\Program Files\dotnet\dotnet.exe" build admin-desktop\src\Treck.Admin.Desktop\Treck.Admin.Desktop.csproj -c Release
```

Windows agent:

```powershell
& "C:\Program Files\dotnet\dotnet.exe" test agent/tests/Treck.Agent.Tests/Treck.Agent.Tests.csproj -c Release
```

Readiness rehearsal:

Historical migrations remain immutable. A full historical `migrate --pretend`
run is not a reliable release gate for this repository because older migrations
include Eloquent and Spatie data operations whose side effects are intentionally
not persisted in pretend mode.

Use migration status for inspection:

```powershell
& "C:\php\php.exe" artisan migrate:status
```

Execute actual migrations only on an isolated, production-equivalent staging
database. Never run this rehearsal against production:

```powershell
# Staging-only: isolated production-equivalent database.
& "C:\php\php.exe" artisan migrate --force
```

Required release evidence is a fresh-database rehearsal plus a synthetic
legacy-upgrade rehearsal that exercises the A1-B5 backfills, tenant storage
migration and B6 readiness checks:

```powershell
& "C:\php\php.exe" artisan treck:verify-saas-readiness
& "C:\php\php.exe" artisan treck:verify-saas-readiness --json
```

## Required Manual Confirmations

- Local and remote feature branch SHAs match.
- Local and remote B6 backup SHAs match
  `3ee9227d63c8e97ca2e6bc687e2624b1e519eb99`.
- Working tree contains no `.env` files, credentials, database dumps, logs,
  build artifacts or unrelated formatting changes.
- No `platform-super-admin` role or assignment was created automatically.
- B6 docs and tests contain no enrollment secrets, bearer tokens, hashes,
  authorization headers or production credentials.
- No Admin Desktop feature behavior, billing, public site, deployment script or
  unrelated infrastructure file changed outside the approved B6 audit scope.

## Production-Like Rehearsal

Use a restored copy of production data and storage. Execute the A1-B5 runbook in
order, then run B6 readiness. A release candidate is blocked by:

- tenant-owned tables with null `organization_id`
- parent-child organization mismatches
- computer token ownership gaps or mismatches
- tenant-owned screenshot rows that still reference legacy paths
- storage migration verification failures
- any critical or high audit finding

## Recommendation Language

- `GO`: all executable checks pass, no Critical or High findings remain, and
  production-like rehearsal is clean.
- `CONDITIONAL GO`: code checks pass but a documented non-B6 exception or
  operational action remains before production rollout, such as a pre-existing
  formatting backlog, unavailable static-analysis executable, or compatibility
  flags that must be disabled after rehearsal.
- `NO-GO`: any complete suite, migration rehearsal, readiness, formatting or
  static-analysis gate fails because of B6 work, or any Critical/High finding
  remains unresolved.

Do not merge, tag, deploy or start later SaaS phases from this checklist alone.
