<?php

namespace App\Livewire\ApplicationUsage;

use App\DataObjects\AppUsageFilter;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Reporting\ApplicationUsageService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin-only Application Usage dashboard (Phase 7).
 *
 * Summary cards, top applications, per-employee / per-department breakdowns, a
 * daily timeline and a paginated recent-sessions table — all driven by the same
 * filter (employee / computer / department / application search / date range).
 *
 * All reads go through {@see ApplicationUsageService}, which queries the
 * materialized `application_usage` rows (one per completed session) via indexed
 * scopes — never raw agent_events. Only usage metadata is shown; no keystrokes,
 * clipboard, screen or file contents are ever collected or displayed.
 */
class ApplicationUsageDashboard extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $employeeId = null;

    #[Url]
    public ?int $computerId = null;

    #[Url]
    public ?int $departmentId = null;

    #[Url(as: 'q')]
    public string $application = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator() ?? false, 403);

        $this->from = $this->from ?: today()->subDays(6)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    /** Reset pagination whenever any filter changes. */
    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['employeeId', 'computerId', 'departmentId', 'application']);
        $this->from = today()->subDays(6)->toDateString();
        $this->to = today()->toDateString();
        $this->resetPage();
    }

    private function filter(): AppUsageFilter
    {
        return AppUsageFilter::fromArray([
            'from' => $this->from,
            'to' => $this->to,
            'employee_id' => $this->employeeId,
            'computer_id' => $this->computerId,
            'department_id' => $this->departmentId,
            'application' => $this->application,
        ]);
    }

    public function render(ApplicationUsageService $usage): View
    {
        $filter = $this->filter();

        return view('livewire.application-usage.application-usage-dashboard', [
            'summary' => $usage->summary($filter),
            'topApplications' => $usage->topApplications($filter),
            'dailyUsage' => $usage->dailyUsage($filter),
            'perEmployee' => $usage->perEmployee($filter),
            'perDepartment' => $usage->perDepartment($filter),
            'sessions' => $usage->recent($filter),
            'employees' => Employee::query()->with('user')->get()
                ->sortBy('name')->values(),
            'computers' => Computer::query()->orderBy('hostname')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }
}
