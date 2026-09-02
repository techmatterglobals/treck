# Treck — Technical Design Documentation

This directory contains the complete architecture and technical design for the
**Employee Productivity & PC Activity Monitoring System**.

Read the documents in order for a full understanding of the platform, or jump
directly to the area you need.

| # | Document | Description |
| - | -------- | ----------- |
| 1 | [System Architecture](01-system-architecture.md) | Components, data flow, deployment topology, security & privacy model |
| 2 | [Laravel Folder Structure](02-folder-structure.md) | Laravel 11 directory layout, architectural patterns (Services, Actions, DTOs, Enums) |
| 3 | [Database Design](03-database-design.md) | ERD, table specifications, relationships, aggregation strategy |
| 4 | [Modules List](04-modules.md) | Functional modules and their responsibilities |
| 5 | [API Structure](05-api-structure.md) | Sanctum auth model, versioning, agent & user endpoints, payloads |
| 6 | [Admin Dashboard Structure](06-admin-dashboard.md) | Livewire component tree, pages, real-time strategy |
| 7 | [Development Roadmap](07-development-roadmap.md) | Phased delivery plan, milestones, testing strategy |
| 8 | [Getting Started](08-getting-started.md) | Step-by-step project creation: install, packages, env, DB, Breeze auth, folder structure |
| 9 | [Database Migrations & Model Relationships](09-database-migrations.md) | The delivered migration set + Eloquent models: FKs, indexes, relationships |
| 10 | [Eloquent Models Reference](10-models.md) | Model best practices, enums, scopes, accessors, and helper methods |
| 11 | [Authentication & Authorization](11-authentication-authorization.md) | Breeze + Sanctum auth, Spatie roles/permissions, middleware, controllers |
| 12 | [Employee Management Module](12-employee-management.md) | Resourceful controller + Livewire table: CRUD, department & computer assignment |
| 13 | [Desktop PC Agent API](13-agent-api.md) | Sanctum device-token API: register, login, activity, logout + Windows agent comms |
| 14 | [Employee Activity Tracking System](14-activity-tracking.md) | Active/idle time, online status, last activity — service classes, controllers, offline reconciliation |
| 15 | [Admin Dashboard (Livewire)](15-admin-dashboard.md) | KPI cards, employee status table, productivity & department charts |
| 16 | [Reporting Module](16-reporting.md) | Daily/weekly/monthly reports, filters, Excel & PDF export |
| 17 | [Windows PC Agent (C#)](17-windows-agent.md) | .NET 8 Windows Service: architecture, project structure, API comms, security |
| 18 | [Aggregation Rollups & Demo Seeding](18-rollups-and-seeding.md) | Attendance & productivity rollup jobs, scheduled command, factories & demo seeder |
| 19 | [Production Deployment](19-production-deployment.md) | Server, SSL, queues, cron, backups, API security, rate limiting, monitoring, checklist |
| 20 | [Codebase Audit Report](20-audit-report.md) | Full review: missing deps/migrations/routes, security, performance, best practices, prioritized fixes |
| 21 | [P0 Remediation — Application Skeleton](21-p0-remediation.md) | Bootable Laravel 11 skeleton: composer, config, routes wiring, framework migrations, run/test steps |
| 22 | [P1 Security Remediation](22-p1-security-remediation.md) | SEC-1 agent identity binding, SEC-2 rate limiting, auth hardening, scheduler + security tests |
| 23 | [Requirements Review](23-requirements-review.md) | Module-by-module status (complete/partial/not started), missing features, priority |
| 24 | [Windows Agent — Milestone Build](24-windows-agent-build.md) | .NET 8 agent built in milestones; M1–M6 complete (M6: Windows Service packaging, production runtime, server-side `/api/agent/events`) |
| 25 | [Real-Time Presence Dashboard](25-realtime-presence.md) | Phase 6: materialized `computer_presence`, event projection, admin broadcasting, live Livewire dashboard |
| 26 | [Application Usage](26-application-usage.md) | Phase 7: foreground app tracking, usage projection, admin dashboard (summary, top apps, timeline, breakdowns) |
| 27 | [Screenshot Module](27-screenshot-module.md) | Phase 8: opt-in desktop capture, multipart upload, private storage + signed URLs, admin viewer |
| 28 | [Phase 8 Windows Validation](28-phase8-windows-validation.md) | Phase 8: Windows-side capture/upload validation notes |
| 29 | [Notifications](29-notifications.md) | Phase 9: centralized rule engine, in-app + email channels, preferences, live dashboard, async delivery |
| 30 | [Production Release](30-production-release.md) | Phase 10: final architecture, deployment/upgrade/DR runbook, retention enforcement, v1.0.0 release notes |
| 31 | [Multi-User Computers & Manager Hierarchy](31-multi-user-computer-and-manager-hierarchy.md) | Phase 11: Super Admin→Manager→Employee hierarchy, shared computers, Windows-username resolution, role-scoped dashboards & reports |
| 32 | [File Download Monitoring](32-file-download-monitoring.md) | Phase 12: opt-in download detection (metadata only), projector, role-scoped dashboard/reports/export, configurable alerts |
| 33 | [Windows Desktop Applications](33-windows-desktop-applications.md) | Silent employee-agent improvements, separate WPF admin client, API/security boundaries, packaging, milestones, and acceptance criteria |
| 34 | [SaaS Tenancy Phase A1](34-saas-tenancy-phase-a1.md) | Organization and membership foundation |
| 35 | [SaaS Tenancy Phase A2](35-saas-tenancy-phase-a2.md) | Organization-scoped authorization foundation |
| 36 | [SaaS Tenancy Phase B1](36-saas-tenancy-phase-b1.md) | Core tenant data ownership |
| 37 | [SaaS Tenancy Phase B2](37-saas-tenancy-phase-b2.md) | Monitoring and reporting tenant isolation |
| 38 | [SaaS Tenancy Phase B3](38-saas-tenancy-phase-b3.md) | Tenant-aware agent enrollment and tokens |
| 39 | [SaaS Tenancy Phase B4](39-saas-tenancy-phase-b4.md) | Admin Desktop tenant isolation |

## Deployment artifacts

- [`deploy/nginx.conf.example`](../deploy/nginx.conf.example), [`deploy/supervisor-treck-worker.conf.example`](../deploy/supervisor-treck-worker.conf.example), [`deploy/.env.production.example`](../deploy/.env.production.example)

## Code delivered

- [`database/migrations/`](../database/migrations) — the eight core table migrations.
- [`app/Models/`](../app/Models) — Eloquent models with relationships, casts, scopes, accessors, and helpers.
- [`app/Enums/`](../app/Enums) — status/rating enums cast onto the models.

## Reference artifacts

- [`database/schema.sql`](database/schema.sql) — concrete MySQL DDL for the core schema.

## Conventions used in these documents

- **Agent** = the desktop client installed on an employee's PC (out of scope for
  this Laravel codebase, but its API contract is defined here).
- **Backend / Server** = the Laravel 11 application in this repository.
- **User** = a human who signs into the dashboard (Super Admin, Admin/HR,
  Manager, or Employee).
- Code samples target **Laravel 11 / PHP 8.2+** and are illustrative of the
  intended patterns, not final implementation.
