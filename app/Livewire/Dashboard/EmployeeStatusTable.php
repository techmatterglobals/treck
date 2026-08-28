<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Live table of every employee's current status with today's active and idle
 * time. Polls so status/time stay current.
 */
class EmployeeStatusTable extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view attendance'), 403);
    }

    public function render(DashboardMetricsService $metrics): View
    {
        return view('livewire.dashboard.employee-status-table', [
            'rows' => $metrics->employeeStatusRows(),
        ]);
    }
}
