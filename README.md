# Treck — Employee Productivity & PC Activity Monitoring System

Treck is an employee productivity and workstation-activity monitoring platform.
A lightweight **desktop agent** installed on employee PCs captures workstation
activity (login/logout, active/idle time, computer status, foreground
application usage) and streams it to a **Laravel backend**, which stores,
aggregates, and exposes the data through a REST API and a real-time admin
dashboard for HR, team leads, and management.

> This repository currently contains the **architecture and technical design**
> for the platform. Implementation follows the phased
> [development roadmap](docs/07-development-roadmap.md).

---

## What the system tracks

| Capability            | Description                                                            |
| --------------------- | ---------------------------------------------------------------------- |
| Attendance            | Daily clock-in / clock-out, late / early / absent status, work hours   |
| PC login / logout     | Workstation session start and end times per device                     |
| Active time           | Time the employee was actively using the keyboard / mouse              |
| Idle time             | Time the workstation was idle beyond the configured threshold          |
| Computer status       | Live online / idle / locked / offline state per device                 |
| Productivity reports  | Per-employee / team / department productivity scores and trends        |

---

## Technology stack

| Layer            | Technology                                              |
| ---------------- | ------------------------------------------------------- |
| Framework        | Laravel 11 (PHP 8.2+)                                   |
| Database         | MySQL 8                                                 |
| API auth         | Laravel Sanctum (token-based, agent + user tokens)      |
| Web auth         | Laravel Breeze (session-based, Blade + Livewire stack)  |
| Dashboard UI     | Livewire 3 + Alpine.js + Tailwind CSS                   |
| Async processing | Laravel Queues (Redis / database driver)                |
| Caching / realtime | Redis, Laravel Reverb / Echo (optional websockets)    |
| Authorization    | Spatie Laravel-Permission (roles & permissions)         |

---

## Documentation index

The full technical design lives in [`docs/`](docs/):

1. [System Architecture](docs/01-system-architecture.md)
2. [Laravel Folder Structure](docs/02-folder-structure.md)
3. [Database Design](docs/03-database-design.md)
4. [Modules List](docs/04-modules.md)
5. [API Structure](docs/05-api-structure.md)
6. [Admin Dashboard Structure](docs/06-admin-dashboard.md)
7. [Development Roadmap](docs/07-development-roadmap.md)
8. [Getting Started (project setup)](docs/08-getting-started.md)
9. [Database Migrations & Model Relationships](docs/09-database-migrations.md)
10. [Eloquent Models Reference](docs/10-models.md)
11. [Authentication & Authorization](docs/11-authentication-authorization.md)
12. [Employee Management Module](docs/12-employee-management.md)
13. [Desktop PC Agent API](docs/13-agent-api.md)
14. [Employee Activity Tracking System](docs/14-activity-tracking.md)
15. [Admin Dashboard (Livewire)](docs/15-admin-dashboard.md)
16. [Reporting Module](docs/16-reporting.md)
17. [Windows PC Agent (C#)](docs/17-windows-agent.md) — client in [`agent/`](agent/)
18. [Aggregation Rollups & Demo Seeding](docs/18-rollups-and-seeding.md)
19. [Production Deployment](docs/19-production-deployment.md) — configs in [`deploy/`](deploy/)
20. [Codebase Audit Report](docs/20-audit-report.md)
21. [P0 Remediation — Application Skeleton](docs/21-p0-remediation.md)
22. [P1 Security Remediation](docs/22-p1-security-remediation.md)
23. [Requirements Review](docs/23-requirements-review.md)

## Setup

New here? Start with [SETUP.md](SETUP.md).

Concrete reference DDL: [`docs/database/schema.sql`](docs/database/schema.sql)

---

## High-level data flow

```
Desktop Agent (per PC)                Laravel Backend                      Users
────────────────────                 ───────────────                     ───────
  capture events                     ┌───────────────┐
  ├─ session start/end   ── HTTPS ──▶ │  Sanctum API  │
  ├─ activity heartbeats             │  (agent token)│
  └─ app usage / status              └──────┬────────┘
                                            │ dispatch jobs
                                     ┌──────▼────────┐
                                     │ Queue workers │  aggregate → attendance,
                                     │  + Services   │  active/idle, productivity
                                     └──────┬────────┘
                                            │
                                     ┌──────▼────────┐   Livewire      ┌─────────┐
                                     │    MySQL      │ ◀────────────▶  │ Admin / │
                                     │  + Redis      │   dashboard     │ HR / TL │
                                     └───────────────┘                 └─────────┘
```

See [System Architecture](docs/01-system-architecture.md) for the full diagram
and component responsibilities.
