<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Daily productivity (company-wide active ratio) over a configurable window.
 */
class ProductivityChart extends Component
{
    #[Url]
    public int $days = 14;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view reports'), 403);
    }

    public function render(DashboardMetricsService $metrics): View
    {
        return view('livewire.dashboard.productivity-chart', [
            'series' => $metrics->dailyProductivity($this->days),
        ]);
    }
}
