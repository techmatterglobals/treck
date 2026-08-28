<?php

namespace App\Http\Controllers;

use App\DataObjects\ReportFilter;
use App\Enums\ReportPeriod;
use App\Enums\UserRole;
use App\Exports\ProductivityReportExport;
use App\Http\Requests\ReportFilterRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hierarchy\EmployeeVisibility;
use App\Services\Reporting\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reporting: daily / weekly / monthly productivity reports filtered by
 * employee, department, and date range, with Excel and PDF export.
 * Query logic lives in ReportService; this controller wires filters → service
 * → view/export.
 */
class ReportController extends Controller
{
    public function index(ReportFilterRequest $request, ReportService $service, EmployeeVisibility $visibility): View
    {
        $filter = $this->scopedFilter($request, $visibility);
        $rows = $service->paginate($filter, 50);

        return view('reports.index', [
            'filter' => $filter,
            'rows' => $rows,
            'totals' => $service->totalsFor($filter, $rows->total()),
            'periods' => ReportPeriod::cases(),
            'employees' => $this->visibleEmployees($visibility),
            'departments' => Department::orderBy('name')->get(),
            // Managers filter is only meaningful for the Super Admin.
            'managers' => $request->user()->isSuperAdmin()
                ? User::query()->withRole(UserRole::Manager)->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function exportExcel(ReportFilterRequest $request, ReportService $service, EmployeeVisibility $visibility): BinaryFileResponse
    {
        $filter = $this->scopedFilter($request, $visibility);
        $rows = $service->build($filter);

        return Excel::download(
            new ProductivityReportExport($rows, $filter->period),
            $filter->fileSlug().'.xlsx',
        );
    }

    public function exportPdf(ReportFilterRequest $request, ReportService $service, EmployeeVisibility $visibility): Response
    {
        $filter = $this->scopedFilter($request, $visibility);
        $rows = $service->build($filter);

        $pdf = Pdf::loadView('reports.pdf', [
            'filter' => $filter,
            'rows' => $rows,
            'totals' => $service->totals($rows),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filter->fileSlug().'.pdf');
    }

    /** Computer Usage History (Phase 11): who used each computer, and when. */
    public function computerUsage(Request $request, ReportService $service, EmployeeVisibility $visibility): View
    {
        $filter = ReportFilter::fromArray($request->all())
            ->restrictToEmployees($visibility->employeeIds($request->user()));

        return view('reports.computer-usage', [
            'filter' => $filter,
            'sessions' => $service->computerUsageHistory($filter),
        ]);
    }

    /**
     * Build the report filter from the request and clamp it to what the viewer
     * may see (Phase 11): Super Admin → unrestricted; Manager → their team only.
     * Applied to the index AND exports so a scoped user can never export
     * out-of-scope data.
     */
    private function scopedFilter(ReportFilterRequest $request, EmployeeVisibility $visibility): ReportFilter
    {
        return ReportFilter::fromArray($request->validated())
            ->restrictToEmployees($visibility->employeeIds($request->user()));
    }

    private function visibleEmployees(EmployeeVisibility $visibility): Collection
    {
        $ids = $visibility->employeeIds(request()->user());

        return Employee::query()
            ->with('user')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->get();
    }
}
