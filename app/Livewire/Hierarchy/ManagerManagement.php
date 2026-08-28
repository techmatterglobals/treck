<?php

namespace App\Livewire\Hierarchy;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hierarchy\ManagerService;
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

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
    }

    public function createManager(ManagerService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $service->createManager($data['name'], $data['email'], $data['password']);

        $this->reset(['name', 'email', 'password']);
        session()->flash('status', 'Manager created.');
    }

    public function promote(int $userId, ManagerService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $service->promote(User::findOrFail($userId));
        session()->flash('status', 'User promoted to Manager.');
    }

    public function demote(int $userId, ManagerService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $service->demote(User::findOrFail($userId));
        session()->flash('status', 'Manager demoted to Employee; their team was unassigned.');
    }

    public function assignEmployee(ManagerService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $data = $this->validate([
            'assignEmployeeId' => ['required', 'integer', Rule::exists('employees', 'id')],
            'assignManagerId' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $service->assignEmployee(
            Employee::findOrFail($data['assignEmployeeId']),
            User::findOrFail($data['assignManagerId']),
        );

        $this->reset(['assignEmployeeId', 'assignManagerId']);
        session()->flash('status', 'Employee assigned to manager.');
    }

    public function removeEmployee(int $employeeId, ManagerService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $service->removeEmployee(Employee::findOrFail($employeeId));
        session()->flash('status', 'Employee removed from their manager.');
    }

    public function render(ManagerService $service): View
    {
        $managers = User::query()
            ->withRole(UserRole::Manager)
            ->withCount('managedEmployees')
            ->orderBy('name')
            ->get()
            ->map(fn (User $m) => [
                'user' => $m,
                'summary' => $service->teamSummary($m),
            ]);

        return view('livewire.hierarchy.manager-management', [
            'managers' => $managers,
            'managerOptions' => User::query()->withRole(UserRole::Manager)->orderBy('name')->get(),
            'employees' => Employee::query()->with(['user', 'manager'])->orderBy('id')->get(),
            'promotable' => User::query()
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', UserRole::Manager->value))
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', UserRole::Admin->value))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
