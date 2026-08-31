# Phase B2 - Monitoring and Reporting Tenant Isolation

## Baseline

Phase B2 started from `feature/saas-tenancy-foundation` at
`d24bc24138ecf0d9e38e6581b149736e3c3d5b85` with a clean working tree. The
pre-B2 backup branch is `backup/pre-saas-phase-b2-2026-08-30` and was created
from that same commit.

The baseline Laravel suite passed before B2 edits:

```text
280 passed (791 assertions)
```

## Scope Delivered

Phase B2 adds explicit nullable organization ownership to monitoring and
reporting records that are independently queried, routed, exported, authorized,
processed, or aggregated:

- `agent_events`
- `agent_health_reports`
- `computer_presence`
- `activity_logs`
- `application_usage`
- `screenshots`
- `file_downloads`
- `attendance`
- `productivity_reports`
- `notification_logs`

The migration is forward-only. Rollback intentionally does not drop columns,
indexes, or foreign keys, so existing authorization and monitoring data cannot
be deleted by a rollback.

SQLite compatibility is preserved for tests by skipping foreign key attachment
on SQLite. Production databases receive restrictive organization foreign keys so
organizations with monitoring data cannot be deleted accidentally.

## Ownership Rules

New monitoring records derive `organization_id` on the server from trusted
parents:

- Computer ownership is authoritative when a computer has an organization.
- Employee-only fallback is allowed only for rollups whose source is already an
  employee aggregate (`activity_logs`, `attendance`, `productivity_reports`, and
  notification rows that can be employee-only).
- Request payload `organization_id` is ignored for agent event and screenshot
  ingestion.
- If trusted parents are unowned, the monitoring row remains `organization_id =
  null`.
- If trusted computer and employee parents disagree, the row is treated as
  conflicted by the backfill command and is not assigned.

`platform-super-admin` is never created or assigned by B2 code or backfill
logic.

## Authorization Boundary

Monitoring and reporting UI access now uses Phase A2 organization-scoped roles
through `OrganizationAuthorization` and B1 current-organization selection.
Legacy global roles alone do not grant monitoring authority.

Allowed scoped roles:

- Monitoring dashboards and reports: `organization-owner`,
  `organization-admin`, `organization-manager`
- Notification settings and inbox management: `organization-owner`,
  `organization-admin`

Managers remain restricted to employees assigned to them inside the current
organization. Members without an organization-scoped monitoring role fail
closed.

The implementation deliberately avoids global Eloquent tenant scopes. Each
monitoring read surface applies an explicit current-organization filter at the
service, policy, controller, or component boundary.

## Isolated Surfaces

Phase B2 applies current-organization filtering to:

- Application usage dashboard, detail lookups, summaries, top apps, timelines,
  employee and department breakdowns.
- Live presence board and computer presence detail event history.
- Dashboard KPI cards, productivity chart, department performance chart, and
  employee status table.
- Screenshot dashboard, viewer, signed image stream, and download route.
- File download dashboard, detail route, reports, and exports.
- Productivity reports, computer-usage history, Excel export, and PDF export.
- Notification inbox, unread counts, mark-read actions, delivery jobs, and
  notification recipient selection.

Null-owned and foreign-owned monitoring records are hidden from tenant web
views and exports. Lower-level services still support unscoped direct calls when
no tenant filter is supplied so legacy maintenance and focused service tests can
continue to inspect historical/unowned rows deliberately.

## Backfill

Phase B2 adds:

```text
php artisan treck:backfill-monitoring-organization-ownership --organization=<id-or-slug> [--dry-run] [--verify]
```

Behavior:

- `--dry-run` reports planned assignments, conflicts, unresolved rows, and rows
  belonging to other organizations without writing data.
- Real execution updates only rows where `organization_id` is currently null and
  trusted parent ownership resolves to the selected organization.
- Existing non-null ownership is never overwritten.
- Conflicted rows are reported and left unchanged.
- `--verify` is read-only and fails if rows remain backfillable or conflicted
  for the selected organization.
- The command reports `platform_super_admin_assignments=0`.

## Deferred Phase B Work

The following remain deferred:

- B3 Admin Desktop tenant isolation and desktop API contract changes.
- Billing, subscriptions, limits, plans, invoices, and organization lifecycle
  automation.
- Public registration, onboarding, invitations, domains, and marketing pages.
- Deployment migration rollout and production data execution.
- Token/Sanctum redesign, enrollment changes, and agent endpoint contract
  changes.
- Global tenant scopes, broadcast/channel tenancy redesign, cache partitioning,
  and storage path migration.
