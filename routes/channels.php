<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Presence channels (M7) are private and admin-only. Only an authenticated
| administrator may subscribe, so no computer/employee telemetry leaks to
| ordinary users. Device tokens and credentials are never broadcast (see
| PresenceUpdated::broadcastWith()).
*/

// The board: every computer's presence.
Broadcast::channel('presence', function (User $user) {
    return $user->is_active && $user->isAdministrator();
});

// One computer's presence (details page).
Broadcast::channel('presence.computer.{computerId}', function (User $user, int $computerId) {
    return $user->is_active && $user->isAdministrator();
});
