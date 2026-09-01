<?php

namespace Tests;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function grantOrganizationRole(
        User $user,
        Organization $organization,
        OrganizationRole $role = OrganizationRole::Admin,
    ): User {
        OrganizationMembership::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'status' => MembershipStatus::Active,
                'role' => $role->value,
                'is_owner' => $role === OrganizationRole::Owner,
                'joined_at' => now(),
            ],
        );

        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $organization, $role);

        return $user;
    }

    protected function actingAsOrganizationRole(
        Organization $organization,
        OrganizationRole $role = OrganizationRole::Admin,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();
        $this->grantOrganizationRole($user, $organization, $role);
        $this->actingAs($user);
        session([CurrentOrganization::SESSION_KEY => $organization->id]);

        return $user;
    }

    /**
     * @return array{0:Organization,1:Employee,2:Computer,3:string}
     */
    protected function ownedAgentDevice(
        ?Organization $organization = null,
        ?Employee $employee = null,
        array $abilities = ['agent:report'],
    ): array {
        $organization ??= $employee?->organization ?? Organization::factory()->create();
        $employee ??= Employee::factory()->forOrganization($organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);
        $token = $computer->createToken('agent', $abilities)->plainTextToken;

        return [$organization, $employee, $computer, $token];
    }

    protected function tearDown(): void
    {
        if ($this->app?->bound(PermissionRegistrar::class)) {
            $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }

        parent::tearDown();
    }
}
