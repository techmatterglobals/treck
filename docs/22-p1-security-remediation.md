# 22. P1 Security Remediation

Executes the **P1** security items from the [audit](20-audit-report.md).
No new features; only hardening of existing behavior.

## 22.1 SEC-1 — Secure agent identity binding

**Before:** `POST /api/agent/login` accepted `employee_id` from the request body
(validated only as `exists`), so a valid device token could attribute a session
to any employee.

**After:**
- The agent authenticates as its **Computer** (Sanctum device token).
- The employee is resolved **server-side** from `computer->employee_id`.
- `employee_id` is **no longer accepted** (removed from `AgentLoginRequest`) and
  is ignored if sent.
- A device not assigned to an employee gets **409 Conflict**.
- `activity` and `logout` already verify `session->computer_id === computer->id`,
  so every record stays bound to the authenticated computer's owner.

Files: `WorkSessionController@login`, `AgentLoginRequest`; client aligned
(`agent/` `ApiModels`, `ApiClient`, `Worker`); doc 13 updated.

## 22.2 SEC-2 — Rate limiting

Named limiters defined in `bootstrap/app.php` and applied via `throttle:*`:

| Limiter | Scope | Applied to |
| ------- | ----- | ---------- |
| `login` | 5/min per email+IP | `POST /api/v1/auth/login`, web `POST /login` |
| `agent-register` | 10/min per IP | `POST /api/agent/register` |
| `agent` | 120/min per device token | agent `login`/`activity`/`logout` |
| `user` | 60/min per user | authenticated user API (`auth/me`, `logout`, `activity/*`) |

Files: `bootstrap/app.php`, `routes/api.php`, `routes/auth.php`,
`routes/modules/agent.php`, `routes/modules/activity.php`.

## 22.3 Authentication hardening

- **Abilities/scopes:** device tokens carry `agent:report`; agent routes gate on
  `ability:agent:report`. User tokens carry role-derived abilities (`*` for
  admins, `employee:self` otherwise).
- **Token revocation:** user logout revokes the current token
  (`currentAccessToken()->delete()`); device re-registration revokes prior
  device tokens (`tokens()->delete()`); admins can revoke a device's tokens
  server-side. (No new endpoints added.)
- **Middleware protection:** every non-public route is behind `auth:sanctum`
  (API) / `auth` (web) + `active`, plus `ability`/`role`/`permission` as
  appropriate. Added the `sanctum` guard to `config/auth.php`.

## 22.4 Scheduler verification

`treck:reconcile-sessions` and `treck:daily-rollup` are registered
(`routes/console.php`) and covered by `SchedulerTest` (asserts they appear in
`schedule:list` and execute with exit code 0).

## 22.5 Security tests

`tests/` bootstrap added (`phpunit.xml`, `tests/TestCase.php`, `UserFactory`):

- `AgentIdentityTest` — spoofed `employee_id` is ignored (session bound to the
  real owner); unpaired device → 409; invalid token → 401; missing ability → 403.
- `ApiAuthTest` — unauthenticated → 401; invalid bearer → 401; authenticated
  without permission → 403.
- `RateLimitTest` — login throttled (6th attempt → 429).
- `SchedulerTest` — commands scheduled + executable.

## 22.6 Remaining risks (not in P1)

- Provisioning-key strength/rotation is operational (P1 enforces it is set and
  registration is throttled, but the key must be strong and rotated per deploy).
- No brute-force lockout beyond rate limiting on login (P2 could add account
  lockout / MFA).
- Dashboard/report tables remain unpaginated (PERF-1, P2).
- Web email verification not enforced (`verified` is a no-op; BP-4, P3).
- Tests run against SQLite in CI; MySQL-only SQL (report `DATE_FORMAT`) isn't
  exercised there — cover with a MySQL CI job (P2).
