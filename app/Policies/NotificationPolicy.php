<?php

namespace App\Policies;

use App\Models\NotificationLog;
use App\Models\User;

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
        return $user->isAdministrator();
    }

    public function view(User $user, NotificationLog $log): bool
    {
        return $user->isAdministrator() && $log->recipient_id === $user->id;
    }

    /** Read/act on a specific notification (own inbox only). */
    public function update(User $user, NotificationLog $log): bool
    {
        return $this->view($user, $log);
    }

    /** Manage global notification settings/rules. */
    public function manage(User $user): bool
    {
        return $user->isAdministrator();
    }
}
