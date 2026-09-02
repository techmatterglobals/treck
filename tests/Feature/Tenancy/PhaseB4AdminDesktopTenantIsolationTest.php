<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PlatformRole;
use App\Enums\PresenceStatus;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\AgentHealthReport;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Desktop\DesktopOrganizationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseB4AdminDesktopTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_lists_only_active_authorized_desktop_organizations(): void
    {
        $user = User::factory()->create();
        $admin = Organization::factory()->create(['name' => 'Admin Org']);
        $manager = Organization::factory()->create(['name' => 'Manager Org']);
        $employeeOnly = Organization::factory()->create(['name' => 'Employee Org']);
        $membershipOnly = Organization::factory()->create(['name' => 'Membership Only']);
        $inactiveMembership = Organization::factory()->create(['name' => 'Inactive Membership']);
        $inactiveOrganization = Organization::factory()->create([
            'name' => 'Inactive Org',
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);

        $this->grantOrganizationRole($user, $admin, OrganizationRole::Admin);
        $this->grantOrganizationRole($user, $manager, OrganizationRole::Manager);
        $this->grantOrganizationRole($user, $employeeOnly, OrganizationRole::Employee);
        OrganizationMembership::create([
            'organization_id' => $membershipOnly->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'role' => 'legacy',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        $this->grantOrganizationRole($user, $inactiveMembership, OrganizationRole::Admin)
            ->membershipFor($inactiveMembership)
            ?->forceFill(['status' => MembershipStatus::Inactive])
            ?->save();
        $this->grantOrganizationRole($user, $inactiveOrganization, OrganizationRole::Admin);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.contract_version', 'desktop-v2')
            ->assertJsonPath('data.organization_selection_required', true)
            ->assertJsonPath('data.recommended_organization', null);

        $this->assertSame(['Admin Org', 'Manager Org'], $response->json('data.organizations.*.name'));
        $this->assertSame('admin', $response->json('data.organizations.0.role'));
        $this->assertTrue($response->json('data.organizations.0.features.organization_wide'));
        $this->assertTrue($response->json('data.organizations.1.features.manager_limited'));
        $this->assertFalse($response->json('data.organizations.1.features.reports'));
        $this->assertFalse(Role::query()->where('name', PlatformRole::SuperAdmin->value)->exists());
    }

    public function test_single_desktop_organization_is_recommended(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Owner);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.organization_selection_required', false)
            ->assertJsonPath('data.recommended_organization.id', $organization->id)
            ->assertJsonPath('data.recommended_organization.role', 'owner');
    }

    public function test_legacy_global_role_does_not_create_desktop_organization_access(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $role = Role::query()->firstOrCreate(['organization_id' => null, 'name' => 'admin', 'guard_name' => 'web']);
        DB::table(config('permission.table_names.model_has_roles'))->insert([
            'role_id' => $role->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
            'organization_id' => null,
        ]);
        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => MembershipStatus::Active,
            'role' => 'legacy-admin',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.organizations');

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_forbidden');
    }

    public function test_desktop_organization_header_is_required_and_validated_without_web_session_fallback(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Admin);

        $this->actingAs($user, 'sanctum')
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->getJson('/api/v1/desktop/overview?organization_id='.$first->id)
            ->assertConflict()
            ->assertJsonPath('code', 'organization_required');

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, 'not-an-id')
            ->getJson('/api/v1/desktop/overview')
            ->assertConflict()
            ->assertJsonPath('code', 'organization_required');

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $second->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_forbidden');
    }

    public function test_inactive_organization_and_membership_fail_closed(): void
    {
        $inactiveOrganization = Organization::factory()->create([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
        $inactiveMembershipOrganization = Organization::factory()->create();
        $user = $this->grantOrganizationRole(User::factory()->create(), $inactiveOrganization, OrganizationRole::Admin);
        $this->grantOrganizationRole($user, $inactiveMembershipOrganization, OrganizationRole::Admin)
            ->membershipFor($inactiveMembershipOrganization)
            ?->forceFill(['status' => MembershipStatus::Inactive])
            ?->save();

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $inactiveOrganization->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertConflict()
            ->assertJsonPath('code', 'organization_inactive');

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $inactiveMembershipOrganization->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_forbidden');
    }

    public function test_agent_token_is_rejected_from_desktop_api_and_human_token_does_not_enter_agent_api(): void
    {
        [, , , $agentToken] = $this->ownedAgentDevice();
        $organization = Organization::factory()->create();
        $user = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Admin);
        $humanToken = $user->createToken('desktop', ['*'])->plainTextToken;

        $this->withToken($agentToken)
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertUnauthorized();

        $this->withToken($humanToken)
            ->getJson('/api/agent/config')
            ->assertUnauthorized();
    }

    public function test_desktop_endpoints_are_isolated_by_selected_organization_and_reset_context(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        $this->grantOrganizationRole($user, $first, OrganizationRole::Admin);
        $this->grantOrganizationRole($user, $second, OrganizationRole::Admin);
        $this->desktopComputer($first, 'FIRST-PC', active: 40, idle: 10);
        $this->desktopComputer($second, 'SECOND-PC', active: 5, idle: 15);
        $this->desktopComputer(null, 'NULL-PC', active: 99, idle: 99);

        $firstResponse = $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $first->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.employees.total', 1)
            ->assertJsonPath('data.presence.total', 1)
            ->assertJsonPath('data.activity.active_seconds', 40);

        $this->assertSame('organization', $firstResponse->json('data.scope'));
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());

        $secondResponse = $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $second->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1);

        $this->assertSame(['SECOND-PC'], $secondResponse->json('data.items.*.computer_name'));
    }

    public function test_employee_detail_and_agent_health_do_not_disclose_foreign_records(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Admin);
        [$visibleEmployee] = $this->desktopComputer($first, 'VISIBLE-PC');
        [$foreignEmployee, $foreignComputer] = $this->desktopComputer($second, 'FOREIGN-PC');
        AgentHealthReport::factory()->for($foreignComputer)->forOrganization($second)->create(['pending_event_count' => 77]);

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $first->id)
            ->getJson('/api/v1/desktop/employees/'.$visibleEmployee->id)
            ->assertOk()
            ->assertJsonPath('data.employee.id', $visibleEmployee->id);

        $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $first->id)
            ->getJson('/api/v1/desktop/employees/'.$foreignEmployee->id)
            ->assertNotFound();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $first->id)
            ->getJson('/api/v1/desktop/agent-health')
            ->assertOk();

        $this->assertNotContains('FOREIGN-PC', $response->json('data.items.*.computer_name'));
        $this->assertSame(0, $response->json('data.summary.pending_events'));
    }

    public function test_manager_desktop_results_are_limited_to_assigned_employees_inside_selected_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $this->desktopComputer($organization, 'VISIBLE-PC', manager: $manager, active: 20, idle: 5);
        $this->desktopComputer($organization, 'HIDDEN-PC', active: 40, idle: 10);
        $this->desktopComputer($foreign, 'FOREIGN-PC', manager: $manager, active: 80, idle: 20);

        $overview = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'team')
            ->assertJsonPath('data.employees.total', 1)
            ->assertJsonPath('data.activity.active_seconds', 20);

        $this->assertEquals(80.0, $overview->json('data.activity.active_percent'));

        $presence = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertOk();

        $this->assertSame(['VISIBLE-PC'], $presence->json('data.items.*.computer_name'));
    }

    /**
     * @return array{0:Employee,1:Computer}
     */
    private function desktopComputer(
        ?Organization $organization,
        string $hostname,
        ?User $manager = null,
        int $active = 1,
        int $idle = 0,
    ): array {
        $employee = Employee::factory()
            ->when($organization, fn ($factory) => $factory->forOrganization($organization))
            ->create(['manager_user_id' => $manager?->id]);
        $computer = Computer::factory()
            ->when($organization, fn ($factory) => $factory->forOrganization($organization))
            ->create(['employee_id' => $employee->id, 'hostname' => $hostname]);
        ComputerPresence::factory()
            ->for($computer)
            ->when($organization, fn ($factory) => $factory->forOrganization($organization))
            ->status(PresenceStatus::Active)
            ->create();
        ActivityLog::factory()
            ->when($organization, fn ($factory) => $factory->forOrganization($organization))
            ->create(['employee_id' => $employee->id, 'computer_id' => $computer->id, 'work_date' => today()]);
        AgentEvent::factory()
            ->heartbeat()
            ->for($computer)
            ->when($organization, fn ($factory) => $factory->forOrganization($organization))
            ->create([
                'employee_id' => $employee->id,
                'payload' => ['ActiveSeconds' => $active, 'IdleSeconds' => $idle, 'IsIdle' => $idle > 0],
                'occurred_at' => now(),
                'received_at' => now(),
            ]);

        return [$employee, $computer];
    }
}
