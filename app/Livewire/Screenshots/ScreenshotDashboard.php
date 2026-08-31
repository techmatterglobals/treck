<?php

namespace App\Livewire\Screenshots;

use App\DataObjects\ScreenshotFilter;
use App\Livewire\Concerns\ScopesToViewer;
use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotService;
use App\Services\Screenshots\ScreenshotStorageService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin-only Screenshot Management dashboard (Phase 8): a paginated grid of the
 * latest captures with employee / computer / department / date / search filters
 * and a headline capture-status strip.
 *
 * Thumbnails load through short-lived signed URLs (never a filesystem path); the
 * grid links into the dedicated viewer. All reads go through
 * {@see ScreenshotService} over the indexed `screenshots` rows.
 */
class ScreenshotDashboard extends Component
{
    use ScopesToViewer, WithPagination;

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
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeViewer();

        $this->from = $this->from ?: today()->subDays(6)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['employeeId', 'computerId', 'departmentId', 'search']);
        $this->from = today()->subDays(6)->toDateString();
        $this->to = today()->toDateString();
        $this->resetPage();
    }

    private function filter(): ScreenshotFilter
    {
        return ScreenshotFilter::fromArray([
            'from' => $this->from,
            'to' => $this->to,
            'employee_id' => $this->employeeId,
            'computer_id' => $this->computerId,
            'department_id' => $this->departmentId,
            'search' => $this->search,
        ])
            ->restrictToEmployees($this->visibleEmployeeIds())
            ->forOrganization($this->monitoringOrganizationId());
    }

    public function render(ScreenshotService $service, ScreenshotStorageService $storage, MonitoringTenantAccess $tenant): View
    {
        $filter = $this->filter();
        $screenshots = $service->latest($filter);

        // Mint a signed thumbnail URL per row (kept out of the model to avoid
        // coupling it to the HTTP/URL layer).
        $urls = $screenshots->getCollection()
            ->mapWithKeys(fn (Screenshot $s) => [$s->id => $storage->signedUrl($s)]);

        return view('livewire.screenshots.screenshot-dashboard', [
            'screenshots' => $screenshots,
            'urls' => $urls,
            'status' => $service->status($filter),
            'employees' => $this->visibleEmployees(),
            'computers' => $this->visibleComputers(),
            'departments' => $tenant->departments(auth()->user()),
        ]);
    }
}
