<?php

namespace App\Policies;

use App\Models\Screenshot;
use App\Models\User;

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
        return $user->isSuperAdmin() || $user->isManager();
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
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isManager()
            && $screenshot->employee_id !== null
            && $screenshot->employee?->manager_user_id === $user->id;
    }
}
