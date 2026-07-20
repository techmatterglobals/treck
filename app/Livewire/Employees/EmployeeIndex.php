<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Services\Presence\PresenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Live, searchable, paginated employee table for the dashboard. Mutations
 * (create/edit) happen via EmployeeController + Blade forms; this component
 * owns listing and inline delete.
 */
class EmployeeIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $department = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingDepartment(): void
    {
        $this->resetPage();
    }

    public function delete(Employee $employee): void
    {
        $this->authorize('delete', $employee);

        $employee->user?->update(['is_active' => false]);
        $employee->computers()->update(['employee_id' => null, 'paired_at' => null]);
        $employee->delete();

        session()->flash('status', 'Employee deleted.');
    }

    public function render(PresenceService $presence): View
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->with(['user', 'department', 'computers.presence'])
            ->search($this->search)
            ->when($this->department, fn ($q) => $q->inDepartment($this->department))
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.employees.employee-index', [
            'employees' => $employees,
            // Shared presence source, so the badge matches the presence board.
            'statuses' => $presence->employeeStatusMap($employees->getCollection()),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }
}
