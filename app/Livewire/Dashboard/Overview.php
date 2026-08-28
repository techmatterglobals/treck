<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * KPI cards: total employees, online employees, today's attendance, and
 * average productivity. Polls periodically so the live numbers stay fresh.
 */
class Overview extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view attendance'), 403);
    }

    public function render(DashboardMetricsService $metrics): View
    {
        return view('livewire.dashboard.overview', [
            'totalEmployees' => $metrics->totalEmployees(),
            'onlineEmployees' => $metrics->onlineEmployees(),
            'attendance' => $metrics->todaysAttendance(),
            'avgProductivity' => $metrics->averageProductivity(),
        ]);
    }
}
