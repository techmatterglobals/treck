<?php

namespace App\Services\Hierarchy;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\User;

/**
 * Central authority for "which employees / computers may this user see?" under
 * the Phase 11 organization hierarchy. Every scoped dashboard, report and query
 * funnels through here so the rule lives in exactly one place:
 *
 *   - Super Admin → unrestricted (null): existing organization-wide behavior is
 *     preserved byte-for-byte (callers apply no extra WHERE).
 *   - Manager     → only their assigned employees (employees.manager_user_id).
 *   - Employee    → only their own profile.
 *   - anyone else → an empty set (sees nothing).
 *
 * The restriction is a bounded, indexed id list (a manager's team / an
 * employee's own row) — never a scan of historical events. Results are memoized
 * per resolver instance to avoid repeat lookups within a request.
 */
class EmployeeVisibility
{
    /** @var array<int,list<int>|null> */
    private array $employeeCache = [];

    /** @var array<int,list<int>|null> */
    private array $computerCache = [];

    /**
     * Employee ids the user may see, or null when unrestricted (Super Admin).
     *
     * @return list<int>|null
     */
    public function employeeIds(User $user): ?array
    {
        // Only Managers (→ their team) and Employees (→ their own row) are
        // restricted. The Super Admin and any other privileged permission-holder
        // (e.g. a legacy `view reports` account) stay unrestricted, preserving
        // pre-Phase-11 organization-wide behavior.
        if ($user->isManager()) {
            return $this->employeeCache[$user->id] ??= Employee::query()
                ->managedBy($user)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($user->isEmployee() && ! $user->isSuperAdmin()) {
            return $this->employeeCache[$user->id] ??= Employee::query()
                ->where('user_id', $user->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return null;
    }

    /**
     * Computer ids belonging to the user's visible employees, or null when
     * unrestricted (Super Admin).
     *
     * @return list<int>|null
     */
    public function computerIds(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        return $this->computerCache[$user->id] ??= Computer::query()
            ->whereIn('employee_id', $this->employeeIds($user) ?: [0])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Whether the user may see the given employee id. */
    public function canSeeEmployee(User $user, ?int $employeeId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $ids = $this->employeeIds($user);

        return $employeeId !== null && $ids !== null && in_array($employeeId, $ids, true);
    }

    /** Whether the user may see the given computer id. */
    public function canSeeComputer(User $user, ?int $computerId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $ids = $this->computerIds($user);

        return $computerId !== null && $ids !== null && in_array($computerId, $ids, true);
    }
}
