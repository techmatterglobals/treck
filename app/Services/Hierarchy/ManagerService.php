<?php

namespace App\Services\Hierarchy;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Organization-hierarchy operations (Phase 11). All Manager/Employee mutations
 * live here so the controller and Livewire component stay thin and every change
 * is a single, auditable call. A user holds exactly one role at a time (Spatie
 * syncRoles), matching the rest of the system.
 */
class ManagerService
{
    /** Create a brand-new Manager login account. */
    public function createManager(string $name, string $email, string $password): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $user->syncRoles([UserRole::Manager->value]);

        return $user;
    }

    /** Promote an existing user (typically an employee) to Manager. */
    public function promote(User $user): void
    {
        $user->syncRoles([UserRole::Manager->value]);
    }

    /**
     * Demote a Manager back to Employee. Their team is unassigned (each
     * employee's manager_user_id cleared) so no employee is left pointing at a
     * non-manager.
     */
    public function demote(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->managedEmployees()->update(['manager_user_id' => null]);
            $user->syncRoles([UserRole::Employee->value]);
        });
    }

    /** Assign (or reassign/transfer) an employee to a manager. */
    public function assignEmployee(Employee $employee, User $manager): void
    {
        $employee->update(['manager_user_id' => $manager->id]);
    }

    /** Move an employee from their current manager to another. */
    public function transferEmployee(Employee $employee, User $toManager): void
    {
        $this->assignEmployee($employee, $toManager);
    }

    /** Remove an employee from their manager (leave unassigned). */
    public function removeEmployee(Employee $employee): void
    {
        $employee->update(['manager_user_id' => null]);
    }

    /**
     * Lightweight activity summary for a manager's team, for the management UI.
     *
     * @return array{team_size:int,online:int}
     */
    public function teamSummary(User $manager): array
    {
        $employeeIds = $manager->managedEmployees()->pluck('id');

        $online = $employeeIds->isEmpty() ? 0 : Employee::query()
            ->whereIn('id', $employeeIds)
            ->whereHas('computers.presence', fn ($q) => $q->online())
            ->count();

        return [
            'team_size' => $employeeIds->count(),
            'online' => $online,
        ];
    }
}
