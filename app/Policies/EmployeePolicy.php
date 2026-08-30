<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\Tenancy\CoreTenantAccess;
use Throwable;

/**
 * Authorization for the Employee module. Auto-discovered by Laravel 11
 * (Employee → EmployeePolicy). Permissions come from Spatie and are also
 * registered as Gate abilities, so `$user->can('manage employees')` works.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        if ($this->hasOrganizationContext($user)) {
            return $this->canManageEmployees($user) || $this->canManageTeam($user);
        }

        return $user->can('manage employees') || $user->can('view own data');
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($this->hasOrganizationContext($user)) {
            return app(CoreTenantAccess::class)->canViewEmployee($user, $employee);
        }

        // Admins see everyone; a Manager sees their assigned team (Phase 11);
        // an employee may view their own profile.
        return $user->can('manage employees')
            || ($user->isManager() && $employee->manager_user_id === $user->id)
            || $employee->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationContext($user)
            ? $this->canManageEmployees($user)
            : $user->can('manage employees');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->hasOrganizationContext($user)
            ? $this->belongsToCurrentOrganization($user, $employee) && $this->canManageEmployees($user)
            : $user->can('manage employees');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->hasOrganizationContext($user)
            ? $this->belongsToCurrentOrganization($user, $employee) && $this->canManageEmployees($user)
            : $user->can('manage employees');
    }

    public function assignComputer(User $user, Employee $employee): bool
    {
        return $this->hasOrganizationContext($user)
            ? $this->belongsToCurrentOrganization($user, $employee) && $this->canManageEmployees($user)
            : $user->can('manage employees') || $user->can('manage computers');
    }

    private function hasOrganizationContext(User $user): bool
    {
        try {
            app(CoreTenantAccess::class)->organization($user);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function belongsToCurrentOrganization(User $user, Employee $employee): bool
    {
        try {
            return app(CoreTenantAccess::class)->belongsToCurrentOrganization($employee, $user);
        } catch (Throwable) {
            return false;
        }
    }

    private function canManageEmployees(User $user): bool
    {
        return app(CoreTenantAccess::class)->canManageCore($user);
    }

    private function canManageTeam(User $user): bool
    {
        return app(CoreTenantAccess::class)->hasAnyOrganizationRole($user, [OrganizationRole::Manager]);
    }
}
