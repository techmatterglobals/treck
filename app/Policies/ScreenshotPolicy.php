<?php

namespace App\Policies;

use App\Models\Screenshot;
use App\Models\User;
use App\Services\Tenancy\MonitoringTenantAccess;
use Throwable;

/**
 * Authorization for the Screenshot module (Phase 8). Auto-discovered by Laravel
 * 11 (Screenshot → ScreenshotPolicy). Screenshots are sensitive: the Super Admin
 * sees all; a Manager may only view/download captures of their own employees
 * (Phase 11); everyone else is denied.
 */
class ScreenshotPolicy
{
    public function viewAny(User $user): bool
    {
        try {
            return app(MonitoringTenantAccess::class)->canViewMonitoring($user);
        } catch (Throwable) {
            return false;
        }
    }

    public function view(User $user, Screenshot $screenshot): bool
    {
        return $this->owns($user, $screenshot);
    }

    public function download(User $user, Screenshot $screenshot): bool
    {
        return $this->owns($user, $screenshot);
    }

    /** Super Admin sees all; a Manager only their team's captures. */
    private function owns(User $user, Screenshot $screenshot): bool
    {
        try {
            $access = app(MonitoringTenantAccess::class);

            if ($screenshot->organization_id === null
                || (int) $screenshot->organization_id !== $access->organizationId($user)) {
                return false;
            }

            if ($access->canManageMonitoring($user)) {
                return true;
            }

            return $screenshot->employee_id !== null
                && $access->canSeeEmployee($user, (int) $screenshot->employee_id);
        } catch (Throwable) {
            return false;
        }
    }
}
