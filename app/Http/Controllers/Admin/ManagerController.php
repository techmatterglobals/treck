<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Livewire\Hierarchy\ManagerManagement;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Super-Admin Manager Management (Phase 11). Thin: it only renders the page and
 * enforces access; all behaviour (create/promote/demote managers, assign /
 * remove / transfer employees, team size + activity summary) lives in the
 * {@see ManagerManagement} Livewire component and the
 * hierarchy services. Gated by `manage users` (Super Admin only).
 */
class ManagerController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('manage users');

        return view('admin.managers.index');
    }
}
