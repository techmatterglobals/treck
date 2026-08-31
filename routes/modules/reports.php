<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting routes
|--------------------------------------------------------------------------
| Include from routes/web.php:
|
|   require __DIR__.'/modules/reports.php';
|
| Gated by the `view reports` permission.
*/

Route::middleware(['auth', 'verified', 'active', 'organization', 'organization.role:owner|admin|manager'])->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');
    Route::get('reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');
    // Computer Usage History — who used each computer, and when (Phase 11).
    Route::get('reports/computer-usage', [ReportController::class, 'computerUsage'])->name('reports.computer-usage');
});
