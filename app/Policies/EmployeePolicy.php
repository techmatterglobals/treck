<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Authorization for the Employee module. Auto-discovered by Laravel 11
 * (Employee → EmployeePolicy). Permissions come from Spatie and are also
 * registered as Gate abilities, so `$user->can('manage employees')` works.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage employees') || $user->can('view own data');
    }

    public function view(User $user, Employee $employee): bool
    {
        // Admins see everyone; a Manager sees their assigned team (Phase 11);
        // an employee may view their own profile.
        return $user->can('manage employees')
            || ($user->isManager() && $employee->manager_user_id === $user->id)
            || $employee->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage employees');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('manage employees');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('manage employees');
    }

    public function assignComputer(User $user, Employee $employee): bool
    {
        return $user->can('manage employees') || $user->can('manage computers');
    }
}
