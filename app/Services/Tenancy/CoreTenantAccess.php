<?php

namespace App\Services\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\OrganizationRole;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CoreTenantAccess
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly OrganizationAuthorization $authorization,
    ) {}

    public function organization(?User $user = null): Organization
    {
        return $this->currentOrganization->resolve($user);
    }

    public function organizationId(?User $user = null): int
    {
        return $this->organization($user)->id;
    }

    /**
     * @return Builder<Department>
     */
    public function departments(?User $user = null): Builder
    {
        return Department::query()->forOrganization($this->organizationId($user));
    }

    /**
     * @return Builder<Employee>
     */
    public function employees(?User $user = null): Builder
    {
        return Employee::query()->forOrganization($this->organizationId($user));
    }

    /**
     * @return Builder<Computer>
     */
    public function computers(?User $user = null): Builder
    {
        return Computer::query()->forOrganization($this->organizationId($user));
    }

    public function department(Department|int|null $department, ?User $user = null): ?Department
    {
        if ($department === null) {
            return null;
        }

        $id = $department instanceof Department ? $department->id : $department;

        return $this->departments($user)->whereKey($id)->firstOrFail();
    }

    public function employee(Employee|int $employee, ?User $user = null): Employee
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return $this->employees($user)->whereKey($id)->firstOrFail();
    }

    public function computer(Computer|int $computer, ?User $user = null): Computer
    {
        $id = $computer instanceof Computer ? $computer->id : $computer;

        return $this->computers($user)->whereKey($id)->firstOrFail();
    }

    /**
     * @return Builder<Computer>
     */
    public function assignableComputers(?User $user = null): Builder
    {
        return $this->computers($user)
            ->whereNull('employee_id')
            ->orderBy('hostname');
    }

    /**
     * @return Builder<Employee>
     */
    public function visibleEmployees(User $user): Builder
    {
        $organization = $this->organization($user);
        $query = Employee::query()->forOrganization($organization);

        if ($this->hasAnyOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin], $organization)) {
            return $query;
        }

        if ($this->hasAnyOrganizationRole($user, [OrganizationRole::Manager], $organization)) {
            return $query->where('manager_user_id', $user->id);
        }

        if ($this->hasAnyOrganizationRole($user, [OrganizationRole::Employee], $organization)) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
    }

    public function canManageCore(User $user): bool
    {
        return $this->hasAnyOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin]);
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        if (! $this->belongsToCurrentOrganization($employee, $user)) {
            return false;
        }

        if ($this->canManageCore($user)) {
            return true;
        }

        if ($this->hasAnyOrganizationRole($user, [OrganizationRole::Manager])) {
            return $employee->manager_user_id === $user->id;
        }

        return $this->hasAnyOrganizationRole($user, [OrganizationRole::Employee])
            && $employee->user_id === $user->id;
    }

    public function belongsToCurrentOrganization(Department|Employee|Computer $model, ?User $user = null): bool
    {
        return $model->organization_id !== null
            && (int) $model->organization_id === $this->organizationId($user);
    }

    /**
     * @param  list<OrganizationRole>  $roles
     */
    public function hasAnyOrganizationRole(User $user, array $roles, Organization|int|null $organization = null): bool
    {
        $organization ??= $this->organization($user);

        return $this->authorization->hasOrganizationRole($user, $roles, $organization);
    }
}
