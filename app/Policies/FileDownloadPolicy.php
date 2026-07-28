<?php

namespace App\Policies;

use App\Models\FileDownload;
use App\Models\User;

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
        return $user->isSuperAdmin() || $user->isManager();
    }

    public function view(User $user, FileDownload $download): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isManager()
            && $download->employee_id !== null
            && $download->employee?->manager_user_id === $user->id;
    }
}
