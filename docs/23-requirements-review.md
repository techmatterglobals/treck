# 23. Requirements Review

Implementation vs. original requirements, as of branch
`claude/employee-monitoring-laravel-arch-mbjgyp`. Grounded in the actual repo
(controllers, routes, models, services present). **No code was changed for this
review.**

Legend — **Complete**: usable end-to-end; **Partial**: core exists, gaps remain;
**Not Started**: schema/design only or absent.

| Module | Status | Missing features | Priority |
| ------ | ------ | ---------------- | -------- |
| **Authentication** | Partial | Only a minimal session login + Sanctum token auth are wired. No registration, password reset, email verification, profile, or MFA (Breeze installed but its installer not run). | High |
| **Roles & Permissions** | Complete | RBAC works (Spatie tables, seeder, middleware, `UserRoleController`, `UserRole` enum). No role/permission **management UI** (assignment endpoint only). | Low |
| **Employee Management** | Complete | Full CRUD + department/computer assignment (controller, requests, policy, Livewire table). No bulk import/export or employee self-service profile. | Low |
| **Department Management** | Partial | Model, migration, factory; assignable in employee form; feeds the dashboard chart. **No Department CRUD controller/UI**, no manager-assignment UI. | Medium |
| **Computer Registration** | Partial | Agent `register` mints a device token; pair/unpair via the employee profile. No admin **device console** (list, live status, token revocation UI), no pairing-code flow. | Medium |
| **Attendance** | Partial | `attendances` table + `AttendanceService` daily rollup + status classification + scheduled command. **No attendance board UI and no correction action** (`is_corrected` exists but nothing sets it); no leave/holiday handling. | High |
| **Activity Tracking** | Complete | Tracking/summary/status services, agent `activity` endpoint, stale-session reconciler, read API (`live`, `summary`). Live status uses the DB `status` column (Redis TTL model from doc 01 not implemented); no per-employee timeline UI. | Medium |
| **Productivity Reports** | Partial | `productivity_reports` table + daily rollup + `ReportService` (daily/weekly/monthly) + Excel/PDF export + reports UI. Score is the **active-ratio proxy** (app-usage-based scoring needs ingestion, below); only **daily** rollup rows generated (weekly/monthly computed on the fly). | Medium |
| **Dashboard** | Complete | KPI cards, employee status table, productivity + department charts, admin/employee pages. No dedicated **Live Monitor** page, employee personal widgets are a placeholder, no websockets. | Low |
| **API** | Complete | Agent API (register/login/activity/logout) + user API (auth, activity live/summary) with Sanctum, abilities, and rate limiting. No versioned resource endpoints for employees/attendance/reports, **no app-usage or screenshot ingestion endpoints**, no OpenAPI spec. | Medium |
| **Windows Agent** | Partial | .NET 8 reference project (service, API client, idle detector, session monitor, DPAPI token store). Reference only: **not built/tested**, no session-0 helper + named-pipe IPC, no offline persistence, no installer/MSI, no app-usage/screenshot capture. | Medium |
| **Notifications** | Not Started | No `Notification` classes and no alert engine (idle/offline/lateness/low-productivity rules from the architecture). | Medium |
| **Screenshot Module** | Not Started | Schema + `Screenshot` model + config flag only. No capture (agent), no upload endpoint, no storage/retention handling, no viewer UI. | Low |
| **Application Usage Tracking** | Partial | Table, model, factory; consumed by the productivity rollup + demo seeder. **No ingestion endpoint** (`/agent/app-usage`), no agent capture, no application catalog / productivity-category classification UI, no per-app reports. | Medium |
| **Deployment** | Complete (docs) | Production guide + nginx/supervisor/.env references + bootable P0 skeleton. No CI/CD pipeline, no Dockerfile, `spatie/laravel-backup` not yet required/configured, no real-environment verification. | Medium |

## Summary

- **Complete (6):** Roles & Permissions, Employee Management, Activity Tracking,
  Dashboard, API, Deployment (docs).
- **Partial (6):** Authentication, Department Management, Computer Registration,
  Attendance, Productivity Reports, Windows Agent, Application Usage Tracking.
- **Not Started (2):** Notifications, Screenshot Module.

### Recommended next build order (by priority)
1. **Attendance UI + correction** (High) — data exists but is invisible/uneditable.
2. **Authentication suite** (High) — run `breeze:install` for register/reset/verify.
3. **Application-usage ingestion** (Medium) → unlocks real productivity scoring.
4. **Department & Computer admin UIs** (Medium).
5. **Notifications/alerts** (Medium).
6. **Windows agent hardening** (Medium) and **Screenshot module** (Low).

This review does not include the audit's P0/P1 remediation status — those are
tracked in [doc 20](20-audit-report.md), [doc 21](21-p0-remediation.md), and
[doc 22](22-p1-security-remediation.md). P2/P3 items remain open.
