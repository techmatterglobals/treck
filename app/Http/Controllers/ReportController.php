<?php

namespace App\Http\Controllers;

use App\DataObjects\ReportFilter;
use App\Enums\ReportPeriod;
use App\Exports\ProductivityReportExport;
use App\Http\Requests\ReportFilterRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Reporting\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
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
    public function index(ReportFilterRequest $request, ReportService $service): View
    {
        $filter = ReportFilter::fromArray($request->validated());
        $rows = $service->build($filter);

        return view('reports.index', [
            'filter' => $filter,
            'rows' => $rows,
            'totals' => $service->totals($rows),
            'periods' => ReportPeriod::cases(),
            'employees' => Employee::with('user')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function exportExcel(ReportFilterRequest $request, ReportService $service): BinaryFileResponse
    {
        $filter = ReportFilter::fromArray($request->validated());
        $rows = $service->build($filter);

        return Excel::download(
            new ProductivityReportExport($rows, $filter->period),
            $filter->fileSlug().'.xlsx',
        );
    }

    public function exportPdf(ReportFilterRequest $request, ReportService $service): Response
    {
        $filter = ReportFilter::fromArray($request->validated());
        $rows = $service->build($filter);

        $pdf = Pdf::loadView('reports.pdf', [
            'filter' => $filter,
            'rows' => $rows,
            'totals' => $service->totals($rows),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filter->fileSlug().'.pdf');
    }
}
