<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Routes the authenticated user to the correct dashboard for their role.
 * Super Admins see the organization-wide dashboard; Managers see a dashboard
 * scoped to their team (Phase 11); employees see their personal one.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return view('dashboard.admin');
        }

        if ($user->isManager()) {
            return view('dashboard.manager');
        }

        return view('dashboard.employee');
    }
}
