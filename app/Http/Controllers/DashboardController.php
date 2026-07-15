<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Routes the authenticated user to the correct dashboard for their role.
 * Admins see the organization-wide dashboard; employees see their personal one.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return $user->isAdministrator()
            ? view('dashboard.admin')
            : view('dashboard.employee');
    }
}
