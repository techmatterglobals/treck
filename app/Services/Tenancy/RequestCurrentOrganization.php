<?php

namespace App\Services\Tenancy;

use App\Contracts\CurrentOrganization as CurrentOrganizationContract;
use App\Enums\MembershipStatus;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class RequestCurrentOrganization implements CurrentOrganizationContract
{
    private ?Organization $resolved = null;

    private ?OrganizationMembership $resolvedMembership = null;

    public function __construct(private readonly Request $request) {}

    public function resolve(?User $user = null, int|string|null $selectedOrganizationId = null): Organization
    {
        return $this->membership($user, $selectedOrganizationId)->organization;
    }

    public function membership(?User $user = null, int|string|null $selectedOrganizationId = null): OrganizationMembership
    {
        $user ??= $this->request->user();

        if (! $user instanceof User) {
            throw CurrentOrganizationException::unauthenticated();
        }

        $fromSession = $selectedOrganizationId === null;
        $selectedOrganizationId ??= $this->selectedOrganizationId();
        $cacheKey = $selectedOrganizationId !== null ? (int) $selectedOrganizationId : null;

        if ($this->resolvedMembership !== null
            && $this->resolved !== null
            && ($cacheKey === null || $this->resolved->id === $cacheKey)) {
            return $this->resolvedMembership;
        }

        if ($selectedOrganizationId !== null) {
            try {
                return $this->resolveSelected($user, (int) $selectedOrganizationId);
            } catch (CurrentOrganizationException $exception) {
                if ($fromSession) {
                    $this->clear();
                }

                throw $exception;
            }
        }

        return $this->resolveSingleActive($user);
    }

    public function select(User $user, Organization|int $organization): Organization
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;
        $membership = $this->resolveSelected($user, (int) $organizationId);
        $resolved = $membership->organization;

        if ($this->request->hasSession()) {
            $this->request->session()->put(self::SESSION_KEY, $resolved->id);
        }

        return $resolved;
    }

    public function clear(): void
    {
        $this->resolved = null;
        $this->resolvedMembership = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        if ($this->request->hasSession()) {
            $this->request->session()->forget(self::SESSION_KEY);
        }
    }

    private function selectedOrganizationId(): ?int
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $selected = $this->request->session()->get(self::SESSION_KEY);

        if ($selected === null || $selected === '') {
            return null;
        }

        return filter_var($selected, FILTER_VALIDATE_INT) !== false ? (int) $selected : null;
    }

    private function resolveSelected(User $user, int $organizationId): OrganizationMembership
    {
        $membership = $user->memberships()
            ->with('organization')
            ->where('organization_id', $organizationId)
            ->first();

        return $this->validate($membership);
    }

    private function resolveSingleActive(User $user): OrganizationMembership
    {
        $memberships = $user->memberships()
            ->with('organization')
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (OrganizationMembership $membership) => ! $membership->organization?->isSuspended())
            ->values();

        if ($memberships->isEmpty()) {
            throw CurrentOrganizationException::noMembership();
        }

        if ($memberships->count() > 1) {
            throw CurrentOrganizationException::ambiguous();
        }

        $membership = $this->remember($memberships->first());

        if ($this->request->hasSession()) {
            $this->request->session()->put(self::SESSION_KEY, $membership->organization_id);
        }

        return $membership;
    }

    private function validate(?OrganizationMembership $membership): OrganizationMembership
    {
        if ($membership === null) {
            throw CurrentOrganizationException::noMembership();
        }

        if (! $membership->isActive()) {
            throw CurrentOrganizationException::inactiveMembership();
        }

        if ($membership->organization?->isSuspended()) {
            throw CurrentOrganizationException::suspendedOrganization();
        }

        return $this->remember($membership);
    }

    private function remember(OrganizationMembership $membership): OrganizationMembership
    {
        $this->resolvedMembership = $membership;
        $this->resolved = $membership->organization;
        app(PermissionRegistrar::class)->setPermissionsTeamId($membership->organization_id);

        return $membership;
    }
}
