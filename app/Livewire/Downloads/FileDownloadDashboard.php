<?php

namespace App\Livewire\Downloads;

use App\DataObjects\DownloadFilter;
use App\Enums\UserRole;
use App\Livewire\Concerns\ScopesToViewer;
use App\Models\User;
use App\Services\Reporting\FileDownloadService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * File Downloads dashboard (Phase 12): a searchable, sortable, paginated list of
 * observed downloads with employee / manager / computer / file-type / application
 * / date filters. Metadata only. Reads go through {@see FileDownloadService} over
 * the indexed `file_downloads` rows and are role-scoped (Super Admin = all,
 * Manager = their team).
 */
class FileDownloadDashboard extends Component
{
    use ScopesToViewer, WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $employeeId = null;

    #[Url]
    public ?int $managerUserId = null;

    #[Url]
    public ?int $computerId = null;

    #[Url]
    public string $extension = '';

    #[Url]
    public string $application = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'downloaded_at';

    #[Url]
    public string $direction = 'desc';

    public function mount(): void
    {
        $this->authorizeViewer();

        $this->from = $this->from ?: today()->subDays(29)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['employeeId', 'managerUserId', 'computerId', 'extension', 'application', 'search']);
        $this->from = today()->subDays(29)->toDateString();
        $this->to = today()->toDateString();
        $this->resetPage();
    }

    private function filter(): DownloadFilter
    {
        return DownloadFilter::fromArray([
            'from' => $this->from,
            'to' => $this->to,
            'employee_id' => $this->employeeId,
            'manager_user_id' => $this->managerUserId,
            'computer_id' => $this->computerId,
            'extension' => $this->extension,
            'application' => $this->application,
            'search' => $this->search,
        ])->restrictToEmployees($this->visibleEmployeeIds());
    }

    public function render(FileDownloadService $service): View
    {
        $filter = $this->filter();

        return view('livewire.downloads.file-download-dashboard', [
            'downloads' => $service->paginate($filter, $this->sort, $this->direction),
            'summary' => $service->summary($filter),
            'employees' => $this->visibleEmployees(),
            'computers' => $this->visibleComputers(),
            // Manager filter is only meaningful for the Super Admin.
            'managers' => auth()->user()->isSuperAdmin()
                ? User::query()->withRole(UserRole::Manager)->orderBy('name')->get()
                : collect(),
        ]);
    }
}
