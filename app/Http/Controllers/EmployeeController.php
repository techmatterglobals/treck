<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AssignComputerRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
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
            new Middleware('can:view,employee', only: ['show']),
            new Middleware('can:create,'.Employee::class, only: ['create', 'store']),
            new Middleware('can:update,employee', only: ['edit', 'update']),
            new Middleware('can:delete,employee', only: ['destroy']),
        ];
    }

    /** Listing is rendered by the Livewire table (search + pagination). */
    public function index(): View
    {
        return view('employees.index');
    }

    public function create(): View
    {
        return view('employees.create', [
            'employee' => new Employee,
            'departments' => Department::orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'isCreate' => true,
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $employee = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            $user->assignRole($data['role']);

            return Employee::create([
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

    public function show(Employee $employee): View
    {
        $employee->load(['user', 'department', 'computers']);

        return view('employees.show', [
            'employee' => $employee,
            // Unassigned, non-deleted computers available to attach.
            'assignableComputers' => Computer::whereNull('employee_id')
                ->orderBy('hostname')
                ->get(),
        ]);
    }

    public function edit(Employee $employee): View
    {
        return view('employees.edit', [
            'employee' => $employee->load('user'),
            'departments' => Department::orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'isCreate' => false,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $employee) {
            $employee->user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            if (! empty($data['password'])) {
                $employee->user->update(['password' => Hash::make($data['password'])]);
            }

            if (! empty($data['role'])) {
                $employee->user->syncRoles([$data['role']]);
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

    public function destroy(Employee $employee): RedirectResponse
    {
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
    public function assignComputer(AssignComputerRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('assignComputer', $employee);

        $computer = Computer::findOrFail($request->validated()['computer_id']);

        $computer->update([
            'employee_id' => $employee->id,
            'paired_at' => $computer->paired_at ?? now(),
        ]);

        return back()->with('status', "Computer “{$computer->hostname}” assigned.");
    }

    /** Release a computer from this employee. */
    public function unassignComputer(Employee $employee, Computer $computer): RedirectResponse
    {
        $this->authorize('assignComputer', $employee);

        abort_unless($computer->employee_id === $employee->id, 404);

        $computer->update(['employee_id' => null]);

        return back()->with('status', 'Computer unassigned.');
    }
}
