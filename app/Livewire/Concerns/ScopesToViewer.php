<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\Tenancy\MonitoringTenantAccess;
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

        abort_unless(
            $user instanceof User && app(MonitoringTenantAccess::class)->canViewMonitoring($user),
            403,
        );

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
        return app(MonitoringTenantAccess::class)->visibleEmployeeIds(auth()->user());
    }

    protected function monitoringOrganizationId(): int
    {
        return app(MonitoringTenantAccess::class)->organizationId(auth()->user());
    }

    /** Employees the viewer may pick in a filter dropdown (scoped). */
    protected function visibleEmployees(): Collection
    {
        $ids = $this->visibleEmployeeIds();

        return app(MonitoringTenantAccess::class)->employees(auth()->user());
    }

    /** Computers the viewer may pick in a filter dropdown (scoped). */
    protected function visibleComputers(): Collection
    {
        return app(MonitoringTenantAccess::class)->computers(auth()->user());
    }
}
