<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Services\Tenancy\CoreTenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Routes the authenticated user to the correct dashboard for their role.
 * Super Admins see the organization-wide dashboard; Managers see a dashboard
 * scoped to their team (Phase 11); employees see their personal one.
 */
class DashboardController extends Controller
{
    public function index(Request $request, CoreTenantAccess $tenant): View
    {
        $user = $request->user();

        if ($tenant->hasAnyOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin])) {
            return view('dashboard.admin');
        }

        if ($tenant->hasAnyOrganizationRole($user, [OrganizationRole::Manager])) {
            return view('dashboard.manager');
        }

        if ($tenant->hasAnyOrganizationRole($user, [OrganizationRole::Employee])) {
            return view('dashboard.employee');
        }

        abort(403);
    }
}
