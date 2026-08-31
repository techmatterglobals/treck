<?php

namespace App\Http\Controllers;

use App\DataObjects\DownloadFilter;
use App\Exports\FileDownloadExport;
use App\Models\FileDownload;
use App\Services\Reporting\FileDownloadService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * File Download Monitoring (Phase 12). Thin: listing/filtering lives in the
 * Livewire dashboard + FileDownloadService; the detail page shows metadata only
 * (never file contents, never a file fetch from the monitored computer).
 * Access is gated by route middleware + FileDownloadPolicy (Super Admin all;
 * Manager their team only).
 */
class FileDownloadController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', FileDownload::class);

        return view('downloads.index');
    }

    public function show(FileDownload $download, MonitoringTenantAccess $tenant): View
    {
        $download = $tenant->fileDownload($download);
        $this->authorize('view', $download);

        $download->load(['employee.user', 'employee.manager', 'computer']);

        return view('downloads.show', ['download' => $download]);
    }

    /** Download reports grouped by employee/manager/computer/type/app/date. */
    public function reports(Request $request, FileDownloadService $service, MonitoringTenantAccess $tenant): View
    {
        $this->authorize('viewAny', FileDownload::class);

        $dimension = (string) $request->query('dimension', 'employee');
        $filter = $this->scopedFilter($request, $tenant);

        return view('downloads.reports', [
            'filter' => $filter,
            'dimension' => $dimension,
            'rows' => $service->report($filter, $dimension),
        ]);
    }

    /** Export the (scoped) download list to Excel/CSV via the shared infra. */
    public function export(Request $request, FileDownloadService $service, MonitoringTenantAccess $tenant): BinaryFileResponse
    {
        $this->authorize('viewAny', FileDownload::class);

        $filter = $this->scopedFilter($request, $tenant);
        $rows = $service->query($filter)
            ->with(['employee.user', 'employee.manager', 'computer'])
            ->orderByDesc('downloaded_at')
            ->get();

        $format = strtolower((string) $request->query('format', 'xlsx')) === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(new FileDownloadExport($rows), 'treck-downloads.'.$format);
    }

    /** Build a request filter clamped to the viewer's visible employees. */
    private function scopedFilter(Request $request, MonitoringTenantAccess $tenant): DownloadFilter
    {
        return DownloadFilter::fromArray($request->all())
            ->restrictToEmployees($tenant->visibleEmployeeIds($request->user()))
            ->forOrganization($tenant->organizationId($request->user()));
    }
}
