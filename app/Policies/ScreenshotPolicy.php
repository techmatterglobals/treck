<?php

namespace App\Policies;

use App\Models\Screenshot;
use App\Models\User;

/**
 * Authorization for the Screenshot module (Phase 8). Auto-discovered by Laravel
 * 11 (Screenshot → ScreenshotPolicy). Screenshots are sensitive, so viewing and
 * downloading are restricted to administrators — the same bar as the presence
 * and application-usage dashboards.
 */
class ScreenshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, Screenshot $screenshot): bool
    {
        return $user->isAdministrator();
    }

    public function download(User $user, Screenshot $screenshot): bool
    {
        return $user->isAdministrator();
    }
}
