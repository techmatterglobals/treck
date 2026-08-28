<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Department performance: average active ratio per department for the day.
 */
class DepartmentPerformanceChart extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view reports'), 403);
    }

    public function render(DashboardMetricsService $metrics): View
    {
        return view('livewire.dashboard.department-performance-chart', [
            'departments' => $metrics->departmentPerformance(),
        ]);
    }
}
