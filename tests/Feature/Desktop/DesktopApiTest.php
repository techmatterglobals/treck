<?php

namespace Tests\Feature\Desktop;

use App\Enums\OrganizationRole;
use App\Enums\PresenceStatus;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\AgentHealthReport;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Desktop\DesktopOrganizationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DesktopApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view dashboard', 'view attendance', 'view reports', 'view own data'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $admin->givePermissionTo(Permission::all());
        $manager = Role::findOrCreate('manager', 'web');
        $manager->givePermissionTo(['view dashboard', 'view attendance', 'view reports', 'view own data']);
        $employee = Role::findOrCreate('employee', 'web');
        $employee->givePermissionTo(['view dashboard', 'view own data']);
    }

    public function test_desktop_endpoints_require_authentication(): void
    {
        $employee = Employee::factory()->create();
        $this->getJson('/api/v1/desktop/bootstrap')->assertUnauthorized();
        $this->getJson('/api/v1/desktop/agent-health')->assertUnauthorized();
        $this->getJson('/api/v1/desktop/overview')->assertUnauthorized();
        $this->getJson('/api/v1/desktop/presence')->assertUnauthorized();
        $this->getJson("/api/v1/desktop/employees/{$employee->id}")->assertUnauthorized();
    }

    public function test_employee_role_cannot_use_admin_desktop_api(): void
    {
        $organization = Organization::factory()->create();
        $employee = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Employee);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.organizations');
    }

    public function test_admin_bootstrap_returns_stable_client_contract(): void
    {
        config(['treck.screenshots.enabled' => true, 'treck.display_timezone' => 'Asia/Karachi']);
        $organization = Organization::factory()->create(['name' => 'Desktop Org']);
        $admin = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.contract_version', 'desktop-v2')
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.organizations.0.id', $organization->id)
            ->assertJsonPath('data.organizations.0.role', 'admin')
            ->assertJsonPath('data.recommended_organization.id', $organization->id)
            ->assertJsonPath('data.organization_selection_required', false)
            ->assertJsonPath('data.features.screenshots', true)
            ->assertJsonPath('data.features.agent_health', true)
            ->assertJsonPath('data.server.display_timezone', 'Asia/Karachi')
            ->assertJsonStructure(['data' => [
                'contract_version',
                'user' => ['id', 'name', 'email'],
                'roles', 'permissions', 'organizations', 'organization_selection_required', 'recommended_organization',
                'features' => ['presence', 'attendance', 'reports', 'application_usage', 'screenshots', 'downloads', 'agent_health'],
                'server' => ['version', 'timezone', 'display_timezone', 'time'],
            ]]);
    }

    public function test_manager_login_token_can_bootstrap_but_employee_token_is_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $managerToken = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password',
            'device_name' => 'Desktop API test',
        ])->assertOk()->json('token');

        $this->withToken($managerToken)
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.user.id', $manager->id);

        $employee = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Employee);
        $employeeToken = $this->postJson('/api/v1/auth/login', [
            'email' => $employee->email,
            'password' => 'password',
            'device_name' => 'Desktop API test',
        ])->assertOk()->json('token');

        $this->withToken($employeeToken)
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'data.organizations');
    }

    public function test_manager_overview_is_scoped_to_their_team(): void
    {
        $organization = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $otherManager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);

        [$ownEmployee, $ownComputer] = $this->teamMember($manager, $organization, 'OWN-PC', PresenceStatus::Active, 45, 15);
        $this->teamMember($otherManager, $organization, 'OTHER-PC', PresenceStatus::Idle, 10, 50);
        ActivityLog::factory()->forOrganization($organization)->create(['employee_id' => $ownEmployee->id, 'computer_id' => $ownComputer->id, 'work_date' => today()]);

        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'team')
            ->assertJsonPath('data.employees.total', 1)
            ->assertJsonPath('data.employees.present', 1)
            ->assertJsonPath('data.presence.total', 1)
            ->assertJsonPath('data.presence.active', 1)
            ->assertJsonPath('data.presence.idle', 0)
            ->assertJsonPath('data.activity.active_seconds', 45)
            ->assertJsonPath('data.activity.idle_seconds', 15);

        $this->assertEquals(75.0, $response->json('data.activity.active_percent'));
    }

    public function test_admin_overview_is_organization_wide(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Admin);
        $managerA = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $managerB = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $this->teamMember($managerA, $organization, 'A-PC', PresenceStatus::Active, 30, 0);
        $this->teamMember($managerB, $organization, 'B-PC', PresenceStatus::Idle, 0, 30);

        $this->actingAs($admin, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.employees.total', 2)
            ->assertJsonPath('data.presence.total', 2)
            ->assertJsonPath('data.presence.active', 1)
            ->assertJsonPath('data.presence.idle', 1)
            ->assertJsonPath('data.activity.tracked_seconds', 60);
    }

    public function test_manager_presence_contains_only_team_computers(): void
    {
        $organization = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $otherManager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $this->teamMember($manager, $organization, 'VISIBLE-PC', PresenceStatus::Active, 30, 0);
        $this->teamMember($otherManager, $organization, 'HIDDEN-PC', PresenceStatus::Idle, 0, 30);

        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.refresh_after_seconds', 30);

        $this->assertSame(['VISIBLE-PC'], $response->json('data.items.*.computer_name'));
    }

    public function test_manager_can_open_team_employee_but_not_another_team(): void
    {
        $organization = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $otherManager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        [$visible] = $this->teamMember($manager, $organization, 'VISIBLE-PC', PresenceStatus::Active, 30, 0);
        [$hidden] = $this->teamMember($otherManager, $organization, 'HIDDEN-PC', PresenceStatus::Idle, 0, 30);

        $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson("/api/v1/desktop/employees/{$visible->id}")
            ->assertOk()
            ->assertJsonPath('data.employee.id', $visible->id)
            ->assertJsonPath('data.computers.0.computer_name', 'VISIBLE-PC');

        $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson("/api/v1/desktop/employees/{$hidden->id}")
            ->assertNotFound();
    }

    public function test_manager_agent_health_contains_only_team_computers_and_uses_server_receipt_time(): void
    {
        config([
            'treck.agent.minimum_version' => '1.0.0',
            'treck.agent.health_stale_seconds' => 180,
        ]);
        $organization = Organization::factory()->create();
        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $otherManager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        [$ownEmployee, $ownComputer] = $this->teamMember($manager, $organization, 'VISIBLE-PC', PresenceStatus::Active, 30, 0);
        [$otherEmployee, $otherComputer] = $this->teamMember($otherManager, $organization, 'HIDDEN-PC', PresenceStatus::Idle, 0, 30);

        AgentHealthReport::factory()->for($ownComputer)->forOrganization($organization)->create([
            'agent_version' => '1.0.0',
            'pending_event_count' => 3,
            'reported_at' => now()->subDay(),
            'received_at' => now(),
        ]);
        AgentHealthReport::factory()->for($otherComputer)->forOrganization($organization)->create([
            'agent_version' => '0.9.0',
            'pending_event_count' => 99,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/agent-health')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.healthy', 1)
            ->assertJsonPath('data.summary.pending_events', 3)
            ->assertJsonPath('data.refresh_after_seconds', 60);

        $this->assertSame(['VISIBLE-PC'], $response->json('data.items.*.computer_name'));
        $this->assertSame('healthy', $response->json('data.items.0.status'));
        $this->assertNotSame($otherEmployee->id, $ownEmployee->id);
    }

    public function test_agent_health_marks_stale_and_never_reported(): void
    {
        config(['treck.agent.health_stale_seconds' => 180]);
        $organization = Organization::factory()->create();
        $admin = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Admin);
        $stale = Computer::factory()->forOrganization($organization)->create(['hostname' => 'STALE-PC']);
        Computer::factory()->forOrganization($organization)->create(['hostname' => 'NEVER-PC']);
        AgentHealthReport::factory()->for($stale)->forOrganization($organization)->create(['received_at' => now()->subMinutes(10)]);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/agent-health')
            ->assertOk()
            ->assertJsonPath('data.summary.stale', 1)
            ->assertJsonPath('data.summary.never_reported', 1);

        $statuses = collect($response->json('data.items'))->pluck('status', 'computer_name');
        $this->assertSame('stale', $statuses['STALE-PC']);
        $this->assertSame('never_reported', $statuses['NEVER-PC']);
    }

    /** @return array{0:Employee,1:Computer} */
    private function teamMember(User $manager, Organization $organization, string $hostname, PresenceStatus $status, int $active, int $idle): array
    {
        $employee = Employee::factory()->forOrganization($organization)->create(['manager_user_id' => $manager->id]);
        $computer = Computer::factory()->forOrganization($organization)->create(['employee_id' => $employee->id, 'hostname' => $hostname]);
        ComputerPresence::factory()->for($computer)->forOrganization($organization)->status($status)->create();
        AgentEvent::factory()->heartbeat()->for($computer)->forOrganization($organization)->create([
            'employee_id' => $employee->id,
            'payload' => ['ActiveSeconds' => $active, 'IdleSeconds' => $idle, 'IsIdle' => $idle > 0],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        return [$employee, $computer];
    }
}
