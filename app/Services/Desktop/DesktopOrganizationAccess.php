<?php

namespace App\Services\Desktop;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Support\Collection;

class DesktopOrganizationAccess
{
    public const HEADER = 'X-Treck-Organization-Id';

    /** @var list<OrganizationRole> */
    private const DESKTOP_ROLES = [
        OrganizationRole::Owner,
        OrganizationRole::Admin,
        OrganizationRole::Manager,
    ];

    public function __construct(private readonly OrganizationAuthorization $authorization) {}

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function authorizedOrganizations(User $user): Collection
    {
        return $user->memberships()
            ->with('organization')
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (OrganizationMembership $membership) => $membership->organization?->isActive()
                && ! $membership->organization->isSuspended())
            ->map(function (OrganizationMembership $membership) use ($user) {
                $organization = $membership->organization;
                $role = $organization instanceof Organization ? $this->effectiveRole($user, $organization) : null;

                return $role === null ? null : $this->organizationPayload($organization, $role);
            })
            ->filter()
            ->values();
    }

    public function effectiveRole(User $user, Organization $organization): ?OrganizationRole
    {
        foreach (self::DESKTOP_ROLES as $role) {
            if ($this->authorization->hasOrganizationRole($user, $role, $organization)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @return array<string,bool>
     */
    public function capabilities(OrganizationRole $role): array
    {
        $canAdminister = in_array($role, [OrganizationRole::Owner, OrganizationRole::Admin], true);

        return [
            'presence' => true,
            'attendance' => $canAdminister,
            'reports' => $canAdminister,
            'application_usage' => true,
            'screenshots' => (bool) config('treck.screenshots.enabled'),
            'downloads' => true,
            'agent_health' => true,
            'employee_detail' => true,
            'organization_wide' => $canAdminister,
            'manager_limited' => $role === OrganizationRole::Manager,
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(OrganizationRole $role): array
    {
        $permissions = ['view dashboard'];

        if (in_array($role, [OrganizationRole::Owner, OrganizationRole::Admin], true)) {
            $permissions[] = 'view attendance';
            $permissions[] = 'view reports';
        }

        return $permissions;
    }

    /**
     * @return array<string,mixed>
     */
    private function organizationPayload(Organization $organization, OrganizationRole $role): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
            'role' => $role->value,
            'permissions' => $this->permissions($role),
            'features' => $this->capabilities($role),
        ];
    }
}
