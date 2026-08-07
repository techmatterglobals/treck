<?php

namespace App\Http\Controllers;

use App\Livewire\ApplicationUsage\ApplicationUsageDashboard;
use App\Services\Reporting\ApplicationUsageService;
use Illuminate\Contracts\View\View;

/**
 * Admin-only Application Usage dashboard (Phase 7). Thin: it only renders the
 * view; all state, filtering and reporting live in the Livewire component
 * ({@see ApplicationUsageDashboard}) and the
 * {@see ApplicationUsageService}. Access is restricted
 * to authenticated administrators by the route middleware.
 */
class ApplicationUsageController extends Controller
{
    /** The application-usage dashboard. */
    public function index(): View
    {
        return view('application-usage.index');
    }
}
