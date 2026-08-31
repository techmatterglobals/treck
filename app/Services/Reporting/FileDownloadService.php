<?php

namespace App\Services\Reporting;

use App\DataObjects\DownloadFilter;
use App\Models\FileDownload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * File-download read model + reports (Phase 12). The dashboard and reports funnel
 * through the single {@see query()} choke point, which applies every filter plus
 * the manager/employee visibility restriction over indexed columns — never a
 * scan of raw agent_events. Metadata only.
 */
class FileDownloadService
{
    /** Base filtered query (all list/report methods build on this). */
    public function query(DownloadFilter $filter): Builder
    {
        return FileDownload::query()
            ->when($filter->organizationId !== null, fn (Builder $q) => $q->where('file_downloads.organization_id', $filter->organizationId))
            ->whereBetween('downloaded_at', [$filter->from, $filter->to])
            ->when($filter->employeeId, fn (Builder $q) => $q->where('file_downloads.employee_id', $filter->employeeId))
            ->when($filter->computerId, fn (Builder $q) => $q->where('file_downloads.computer_id', $filter->computerId))
            ->when($filter->extension, fn (Builder $q) => $q->where('file_extension', $filter->extension))
            ->when($filter->application, fn (Builder $q) => $q->where('application_name', 'like', "%{$filter->application}%"))
            ->when($filter->search, fn (Builder $q) => $q->matching($filter->search))
            ->when($filter->managerUserId, fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('manager_user_id', $filter->managerUserId),
            ))
            // Manager/employee visibility restriction (Phase 11); null = unrestricted.
            ->when($filter->employeeIds !== null, fn (Builder $q) => $q->whereIn('file_downloads.employee_id', $filter->employeeIds ?: [0]));
    }

    /** Paginated, sortable list, newest first by default. */
    public function paginate(DownloadFilter $filter, string $sort = 'downloaded_at', string $direction = 'desc', int $perPage = 25): LengthAwarePaginator
    {
        $sort = in_array($sort, ['downloaded_at', 'file_name', 'file_extension', 'file_size', 'application_name'], true)
            ? $sort
            : 'downloaded_at';
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $this->query($filter)
            ->with(['employee.user', 'employee.manager', 'computer'])
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Headline counts for the dashboard cards. */
    public function summary(DownloadFilter $filter): array
    {
        $base = $this->query($filter);

        return [
            'total' => (clone $base)->count(),
            'executables' => (clone $base)->whereIn('file_extension', (array) config('treck.downloads.executable_extensions', []))->count(),
            'archives' => (clone $base)->whereIn('file_extension', (array) config('treck.downloads.archive_extensions', []))->count(),
        ];
    }

    /**
     * Aggregated report rows grouped by a dimension for the download reports
     * (by employee / manager / computer / file type / application / date). Each
     * row: { group, downloads, bytes }. Respects the same filter + scope.
     *
     * @return Collection<int,object>
     */
    public function report(DownloadFilter $filter, string $dimension): Collection
    {
        $groupExpr = match ($dimension) {
            'extension' => 'file_downloads.file_extension',
            'application' => 'file_downloads.application_name',
            'computer' => 'file_downloads.computer_id',
            'manager' => 'employees.manager_user_id',
            'day' => 'DATE(file_downloads.downloaded_at)',
            default => 'file_downloads.employee_id',
        };

        $query = $this->query($filter)->getQuery();

        if ($dimension === 'manager') {
            $query->leftJoin('employees', 'employees.id', '=', 'file_downloads.employee_id');
        }

        return $query
            ->selectRaw("{$groupExpr} as `group`, COUNT(*) as downloads, COALESCE(SUM(file_downloads.file_size),0) as bytes")
            ->groupBy(DB::raw($groupExpr))
            ->orderByDesc('downloads')
            ->get();
    }
}
