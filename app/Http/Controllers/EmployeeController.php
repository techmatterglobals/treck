<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserRole;
use App\Http\Requests\AssignComputerRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\CoreTenantAccess;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class EmployeeController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    /**
     * Resource authorization via EmployeePolicy. Laravel 11's base controller no
     * longer exposes an instance middleware() method (so authorizeResource() in
     * the constructor throws "Call to undefined method ...::middleware()"); the
     * idiomatic replacement is HasMiddleware, which registers the same per-method
     * `can:` gates declaratively (index→viewAny, show→view, create/store→create,
     * edit/update→update, destroy→delete). assignComputer/unassignComputer
     * authorize in-method.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Employee::class, only: ['index']),
            new Middleware('can:create,'.Employee::class, only: ['create', 'store']),
        ];
    }

    /** Listing is rendered by the Livewire table (search + pagination). */
    public function index(): View
    {
        return view('employees.index');
    }

    public function create(CoreTenantAccess $tenant): View
    {
        return view('employees.create', [
            'employee' => new Employee,
            'departments' => $tenant->departments()->orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'isCreate' => true,
        ]);
    }

    public function store(
        StoreEmployeeRequest $request,
        CoreTenantAccess $tenant,
        OrganizationAuthorization $authorization,
    ): RedirectResponse {
        $data = $request->validated();
        $organization = $tenant->organization($request->user());

        $employee = DB::transaction(function () use ($data, $organization, $authorization) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            $this->ensureMembershipAndOrganizationRole($user, $organization, $data['role'], $authorization);

            return Employee::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'department_id' => $data['department_id'] ?? null,
                'employee_code' => $data['employee_code'],
                'designation' => $data['designation'] ?? null,
                'phone' => $data['phone'] ?? null,
                'joined_on' => $data['joined_on'] ?? null,
            ]);
        });

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee created successfully.');
    }

    public function show(Employee $employee, CoreTenantAccess $tenant): View
    {
        $employee = $tenant->employee($employee);
        $this->authorize('view', $employee);

        $employee->load(['user', 'department', 'computers']);

        return view('employees.show', [
            'employee' => $employee,
            // Unassigned, non-deleted computers available to attach.
            'assignableComputers' => $tenant->assignableComputers()->get(),
        ]);
    }

    public function edit(Employee $employee, CoreTenantAccess $tenant): View
    {
        $employee = $tenant->employee($employee);
        $this->authorize('update', $employee);

        return view('employees.edit', [
            'employee' => $employee->load('user'),
            'departments' => $tenant->departments()->orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'isCreate' => false,
        ]);
    }

    public function update(
        UpdateEmployeeRequest $request,
        Employee $employee,
        CoreTenantAccess $tenant,
        OrganizationAuthorization $authorization,
    ): RedirectResponse {
        $employee = $tenant->employee($employee);
        $this->authorize('update', $employee);

        $data = $request->validated();
        $organization = $tenant->organization($request->user());

        DB::transaction(function () use ($data, $employee, $organization, $authorization) {
            $employee->user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            if (! empty($data['password'])) {
                $employee->user->update(['password' => Hash::make($data['password'])]);
            }

            if (! empty($data['role'])) {
                $this->ensureMembershipAndOrganizationRole($employee->user, $organization, $data['role'], $authorization);
            }

            $employee->update([
                'department_id' => $data['department_id'] ?? null,
                'employee_code' => $data['employee_code'],
                'designation' => $data['designation'] ?? null,
                'phone' => $data['phone'] ?? null,
                'joined_on' => $data['joined_on'] ?? null,
            ]);
        });

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee, CoreTenantAccess $tenant): RedirectResponse
    {
        $employee = $tenant->employee($employee);
        $this->authorize('delete', $employee);

        DB::transaction(function () use ($employee) {
            // Disable the login, release assigned computers, then soft-delete.
            $employee->user?->update(['is_active' => false]);
            $employee->computers()->update(['employee_id' => null, 'paired_at' => null]);
            $employee->delete();
        });

        return redirect()
            ->route('employees.index')
            ->with('status', 'Employee deleted.');
    }

    /** Assign an unassigned computer to this employee. */
    public function assignComputer(AssignComputerRequest $request, Employee $employee, CoreTenantAccess $tenant): RedirectResponse
    {
        $employee = $tenant->employee($employee);
        $this->authorize('assignComputer', $employee);

        $computer = $tenant->assignableComputers()
            ->whereKey($request->validated()['computer_id'])
            ->firstOrFail();

        $computer->update([
            'employee_id' => $employee->id,
            'paired_at' => $computer->paired_at ?? now(),
        ]);

        return back()->with('status', "Computer “{$computer->hostname}” assigned.");
    }

    /** Release a computer from this employee. */
    public function unassignComputer(Employee $employee, Computer $computer, CoreTenantAccess $tenant): RedirectResponse
    {
        $employee = $tenant->employee($employee);
        $computer = $tenant->computer($computer);
        $this->authorize('assignComputer', $employee);

        abort_unless($computer->employee_id === $employee->id, 404);

        $computer->update(['employee_id' => null]);

        return back()->with('status', 'Computer unassigned.');
    }

    private function ensureMembershipAndOrganizationRole(
        User $user,
        Organization $organization,
        string $role,
        OrganizationAuthorization $authorization,
    ): void {
        OrganizationMembership::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'status' => MembershipStatus::Active,
                'role' => $role,
                'is_owner' => false,
                'joined_at' => now(),
            ],
        );

        $authorization->syncOrganizationRole($user, $organization, OrganizationRole::from($role));
    }
}
