<?php

namespace App\Policies;

use App\Models\FileDownload;
use App\Models\User;
use App\Services\Tenancy\MonitoringTenantAccess;
use Throwable;

/**
 * Authorization for File Download Monitoring (Phase 12). Auto-discovered
 * (FileDownload → FileDownloadPolicy). Download records are sensitive: the Super
 * Admin sees all; a Manager sees only their own employees' downloads; everyone
 * else is denied.
 */
class FileDownloadPolicy
{
    public function viewAny(User $user): bool
    {
        try {
            return app(MonitoringTenantAccess::class)->canViewMonitoring($user);
        } catch (Throwable) {
            return false;
        }
    }

    public function view(User $user, FileDownload $download): bool
    {
        try {
            $access = app(MonitoringTenantAccess::class);

            if ($download->organization_id === null
                || (int) $download->organization_id !== $access->organizationId($user)) {
                return false;
            }

            if ($access->canManageMonitoring($user)) {
                return true;
            }

            return $download->employee_id !== null
                && $access->canSeeEmployee($user, (int) $download->employee_id);
        } catch (Throwable) {
            return false;
        }
    }
}
