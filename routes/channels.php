<?php

use App\Enums\OrganizationRole;
use App\Models\Computer;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Support\Facades\Broadcast;

$tenantBroadcastAuthorization = fn (): OrganizationAuthorization => app(OrganizationAuthorization::class);
$canViewTenantPresence = function (User $user, int $organizationId) use ($tenantBroadcastAuthorization): bool {
    if (! $user->is_active || ! Organization::query()->active()->whereKey($organizationId)->exists()) {
        return false;
    }

    return $tenantBroadcastAuthorization()->hasOrganizationRole($user, [
        OrganizationRole::Owner,
        OrganizationRole::Admin,
        OrganizationRole::Manager,
    ], $organizationId);
};

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Presence channels (M7) are private and admin-only. Only an authenticated
| administrator may subscribe, so no computer/employee telemetry leaks to
| ordinary users. Device tokens and credentials are never broadcast (see
| PresenceChanged::broadcastWith()).
*/

// Legacy single-tenant channels remain only for null-owned records during
// staged rollout. Organization-owned traffic uses the tenant-bearing channels
// below.
Broadcast::channel('presence', function (User $user) {
    return $user->is_active && $user->isAdministrator();
});

// One computer's presence (details page).
Broadcast::channel('presence.computer.{computerId}', function (User $user, int $computerId) {
    return $user->is_active && $user->isAdministrator();
});

// A recipient's own notifications (Phase 9): live bell/badge updates. Admin-only,
// and a user may only subscribe to their own channel.
Broadcast::channel('notifications.user.{userId}', function (User $user, int $userId) {
    return $user->is_active && $user->isAdministrator() && $user->id === $userId;
});

Broadcast::channel('organization.{organizationId}.presence', function (User $user, int $organizationId) use ($canViewTenantPresence) {
    return $canViewTenantPresence($user, $organizationId);
});

Broadcast::channel('organization.{organizationId}.presence.computer.{computerId}', function (User $user, int $organizationId, int $computerId) use ($canViewTenantPresence, $tenantBroadcastAuthorization) {
    if (! $canViewTenantPresence($user, $organizationId)) {
        return false;
    }

    $computer = Computer::query()
        ->whereKey($computerId)
        ->where('organization_id', $organizationId)
        ->with('employee')
        ->first();

    if ($computer === null) {
        return false;
    }

    if ($tenantBroadcastAuthorization()->hasOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin], $organizationId)) {
        return true;
    }

    return $computer->employee?->manager_user_id === $user->id;
});

Broadcast::channel('organization.{organizationId}.notifications.user.{userId}', function (User $user, int $organizationId, int $userId) use ($canViewTenantPresence, $tenantBroadcastAuthorization) {
    if ($user->id !== $userId || ! $canViewTenantPresence($user, $organizationId)) {
        return false;
    }

    return NotificationLog::query()
        ->where('organization_id', $organizationId)
        ->where('recipient_id', $userId)
        ->exists()
        || $tenantBroadcastAuthorization()->hasOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin], $organizationId);
});
