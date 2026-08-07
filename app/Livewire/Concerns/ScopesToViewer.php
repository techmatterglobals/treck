<?php

namespace App\Livewire\Concerns;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hierarchy\EmployeeVisibility;
use Illuminate\Support\Collection;

/**
 * Shared role-scoping for admin/manager Livewire dashboards (Phase 11).
 *
 * A Super Admin sees everything (restriction = null → no extra WHERE, so the
 * existing organization-wide behavior is byte-for-byte unchanged). A Manager is
 * admitted to the same screens but every list, filter and dropdown is restricted
 * to their assigned employees. Anyone else is refused (403).
 */
trait ScopesToViewer
{
    /** Authorize the current viewer as Super Admin or Manager; abort otherwise. */
    protected function authorizeViewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && ($user->isSuperAdmin() || $user->isManager()), 403);

        return $user;
    }

    /**
     * Visible employee-id restriction for the current viewer, or null when
     * unrestricted (Super Admin).
     *
     * @return list<int>|null
     */
    protected function visibleEmployeeIds(): ?array
    {
        return app(EmployeeVisibility::class)->employeeIds(auth()->user());
    }

    /** Employees the viewer may pick in a filter dropdown (scoped). */
    protected function visibleEmployees(): Collection
    {
        $ids = $this->visibleEmployeeIds();

        return Employee::query()
            ->with('user')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->get()
            ->sortBy('name')
            ->values();
    }

    /** Computers the viewer may pick in a filter dropdown (scoped). */
    protected function visibleComputers(): Collection
    {
        $ids = app(EmployeeVisibility::class)->computerIds(auth()->user());

        return Computer::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->orderBy('hostname')
            ->get();
    }
}
