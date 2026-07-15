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
