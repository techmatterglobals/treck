<?php

namespace App\Livewire\Hierarchy;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Employee;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Hierarchy\ManagerService;
use App\Services\Tenancy\CoreTenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Super-Admin Manager Management (Phase 11). Create managers, promote/demote,
 * and assign / transfer / remove employees. Thin: all mutations delegate to
 * {@see ManagerService}. Access is Super-Admin-only (manage users).
 */
class ManagerManagement extends Component
{
    // Create-manager form.
    public string $name = '';

    public string $email = '';

    public string $password = '';

    // Assign-employee form.
    public ?int $assignEmployeeId = null;

    public ?int $assignManagerId = null;

    public function mount(CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);
    }

    public function createManager(ManagerService $service, CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $service->createManager($data['name'], $data['email'], $data['password']);

        $this->reset(['name', 'email', 'password']);
        session()->flash('status', 'Manager created.');
    }

    public function promote(int $userId, ManagerService $service, CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);

        $service->promote($this->memberUser($tenant, $userId));
        session()->flash('status', 'User promoted to Manager.');
    }

    public function demote(int $userId, ManagerService $service, CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);

        $service->demote($this->memberUser($tenant, $userId));
        session()->flash('status', 'Manager demoted to Employee; their team was unassigned.');
    }

    public function assignEmployee(ManagerService $service, CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);

        $organization = $tenant->organization(auth()->user());
        $data = $this->validate([
            'assignEmployeeId' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('organization_id', $organization->id)),
            ],
            'assignManagerId' => [
                'required',
                'integer',
                Rule::exists('organization_user', 'user_id')->where(fn ($query) => $query->where('organization_id', $organization->id)),
            ],
        ]);

        $service->assignEmployee(
            $tenant->employee($data['assignEmployeeId'], auth()->user()),
            $this->memberUser($tenant, $data['assignManagerId']),
        );

        $this->reset(['assignEmployeeId', 'assignManagerId']);
        session()->flash('status', 'Employee assigned to manager.');
    }

    public function removeEmployee(int $employeeId, ManagerService $service, CoreTenantAccess $tenant): void
    {
        $this->authorizeOrganizationAdmin($tenant);

        $service->removeEmployee($tenant->employee($employeeId, auth()->user()));
        session()->flash('status', 'Employee removed from their manager.');
    }

    public function render(ManagerService $service, CoreTenantAccess $tenant): View
    {
        $organization = $tenant->organization(auth()->user());
        $managerIds = $this->organizationRoleUserIds($organization->id, OrganizationRole::Manager);
        $adminIds = $this->organizationRoleUserIds($organization->id, OrganizationRole::Admin);

        $managers = User::query()
            ->whereIn('id', $managerIds ?: [0])
            ->withCount('managedEmployees')
            ->orderBy('name')
            ->get()
            ->map(fn (User $m) => [
                'user' => $m,
                'summary' => $service->teamSummary($m),
            ]);

        return view('livewire.hierarchy.manager-management', [
            'managers' => $managers,
            'managerOptions' => User::query()->whereIn('id', $managerIds ?: [0])->orderBy('name')->get(),
            'employees' => $tenant->employees(auth()->user())->with(['user', 'manager'])->orderBy('id')->get(),
            'promotable' => User::query()
                ->whereIn('id', OrganizationMembership::query()
                    ->where('organization_id', $organization->id)
                    ->select('user_id'))
                ->whereNotIn('id', array_unique([...$managerIds, ...$adminIds]))
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function memberUser(CoreTenantAccess $tenant, int $userId): User
    {
        $organization = $tenant->organization(auth()->user());

        return User::query()
            ->whereKey($userId)
            ->whereHas('memberships', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('status', MembershipStatus::Active->value))
            ->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function organizationRoleUserIds(int $organizationId, OrganizationRole $role): array
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.name', $role->value)
                ->where('roles.organization_id', $organizationId))
            ->whereHas('memberships', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('status', MembershipStatus::Active->value))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function authorizeOrganizationAdmin(CoreTenantAccess $tenant): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User
            && $tenant->hasAnyOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin]), 403);
    }
}
