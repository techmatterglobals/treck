<?php

namespace App\Http\Controllers\Api\V1\Desktop\Concerns;

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

trait AuthorizesDesktopAccess
{
    private function authorizeDesktopAccess(User $user): void
    {
        abort_unless(
            ($user->isAdministrator() || $user->isManager())
                && $user->can('view dashboard'),
            Response::HTTP_FORBIDDEN,
        );
    }
}
