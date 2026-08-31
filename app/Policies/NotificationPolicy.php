<?php

namespace App\Policies;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Tenancy\MonitoringTenantAccess;
use Throwable;

/**
 * Authorization for the Notifications module (Phase 9). Notifications may contain
 * sensitive presence/activity detail, so viewing, managing settings and reading
 * are restricted to administrators — and a recipient may only act on their own
 * notifications.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        try {
            return app(MonitoringTenantAccess::class)->canManageMonitoring($user);
        } catch (Throwable) {
            return false;
        }
    }

    public function view(User $user, NotificationLog $log): bool
    {
        try {
            $access = app(MonitoringTenantAccess::class);

            return $access->canManageMonitoring($user)
                && $log->organization_id !== null
                && (int) $log->organization_id === $access->organizationId($user)
                && $log->recipient_id === $user->id;
        } catch (Throwable) {
            return false;
        }
    }

    /** Read/act on a specific notification (own inbox only). */
    public function update(User $user, NotificationLog $log): bool
    {
        return $this->view($user, $log);
    }

    /** Manage global notification settings/rules. */
    public function manage(User $user): bool
    {
        return $this->viewAny($user);
    }
}
