<?php

namespace App\Services\Hierarchy;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserRole;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Organization-hierarchy operations (Phase 11). All Manager/Employee mutations
 * live here so the controller and Livewire component stay thin and every change
 * is a single, auditable call. A user holds exactly one role at a time (Spatie
 * syncRoles), matching the rest of the system.
 */
class ManagerService
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly OrganizationAuthorization $authorization,
    ) {}

    /** Create a brand-new Manager login account. */
    public function createManager(string $name, string $email, string $password): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        if ($organization = $this->currentOrganizationOrNull()) {
            $this->ensureMembershipAndOrganizationRole($user, $organization, OrganizationRole::Manager);

            return $user;
        }

        $user->syncRoles([$this->role(UserRole::Manager)->name]);

        return $user;
    }

    /** Promote an existing user (typically an employee) to Manager. */
    public function promote(User $user): void
    {
        if ($organization = $this->currentOrganizationOrNull()) {
            $this->ensureMembershipAndOrganizationRole($user, $organization, OrganizationRole::Manager);

            return;
        }

        $user->syncRoles([$this->role(UserRole::Manager)->name]);
    }

    /**
     * Demote a Manager back to Employee. Their team is unassigned (each
     * employee's manager_user_id cleared) so no employee is left pointing at a
     * non-manager.
     */
    public function demote(User $user): void
    {
        if ($organization = $this->currentOrganizationOrNull()) {
            DB::transaction(function () use ($user, $organization) {
                $user->managedEmployees()
                    ->where('organization_id', $organization->id)
                    ->update(['manager_user_id' => null]);

                $this->ensureMembershipAndOrganizationRole($user, $organization, OrganizationRole::Employee);
            });

            return;
        }

        DB::transaction(function () use ($user) {
            $user->managedEmployees()->update(['manager_user_id' => null]);
            $user->syncRoles([$this->role(UserRole::Employee)->name]);
        });
    }

    /**
     * Resolve a role, creating it on demand so hierarchy actions never fail with
     * RoleDoesNotExist on a deployment that migrated but hasn't re-seeded.
     */
    private function role(UserRole $role): Role
    {
        return Role::findOrCreate($role->value, 'web');
    }

    /** Assign (or reassign/transfer) an employee to a manager. */
    public function assignEmployee(Employee $employee, User $manager): void
    {
        if ($organization = $this->currentOrganizationOrNull()) {
            $this->ensureSameOrganization($employee, $organization);
            $this->ensureOrganizationManager($manager, $organization);
        }

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
        if ($organization = $this->currentOrganizationOrNull()) {
            $this->ensureSameOrganization($employee, $organization);
        }

        $employee->update(['manager_user_id' => null]);
    }

    /**
     * Lightweight activity summary for a manager's team, for the management UI.
     *
     * @return array{team_size:int,online:int}
     */
    public function teamSummary(User $manager): array
    {
        $query = $manager->managedEmployees();

        if ($organization = $this->currentOrganizationOrNull()) {
            $query->where('organization_id', $organization->id);
        }

        $employeeIds = $query->pluck('id');

        $online = $employeeIds->isEmpty() ? 0 : Employee::query()
            ->whereIn('id', $employeeIds)
            ->whereHas('computers.presence', fn ($q) => $q->online())
            ->count();

        return [
            'team_size' => $employeeIds->count(),
            'online' => $online,
        ];
    }

    private function currentOrganizationOrNull(): ?Organization
    {
        try {
            return $this->currentOrganization->resolve(auth()->user());
        } catch (CurrentOrganizationException) {
            return null;
        }
    }

    private function ensureMembershipAndOrganizationRole(User $user, Organization $organization, OrganizationRole $role): void
    {
        OrganizationMembership::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'status' => MembershipStatus::Active,
                'role' => $role->value,
                'is_owner' => false,
                'joined_at' => now(),
            ],
        );

        $this->authorization->syncOrganizationRole($user, $organization, $role);
    }

    private function ensureSameOrganization(Employee $employee, Organization $organization): void
    {
        abort_unless((int) $employee->organization_id === $organization->id, 404);
    }

    private function ensureOrganizationManager(User $manager, Organization $organization): void
    {
        abort_unless(
            $this->authorization->hasOrganizationRole($manager, OrganizationRole::Manager, $organization),
            422,
            'The selected manager does not belong to this organization.',
        );
    }
}
