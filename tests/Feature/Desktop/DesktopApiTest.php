<?php

namespace Tests\Feature\Desktop;

use App\Enums\PresenceStatus;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\User;
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
        $this->getJson('/api/v1/desktop/bootstrap')->assertUnauthorized();
        $this->getJson('/api/v1/desktop/overview')->assertUnauthorized();
    }

    public function test_employee_role_cannot_use_admin_desktop_api(): void
    {
        $employee = tap(User::factory()->create(), fn (User $user) => $user->assignRole('employee'));

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertForbidden();
    }

    public function test_admin_bootstrap_returns_stable_client_contract(): void
    {
        config(['treck.screenshots.enabled' => true, 'treck.display_timezone' => 'Asia/Karachi']);
        $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole('admin'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.features.screenshots', true)
            ->assertJsonPath('data.server.display_timezone', 'Asia/Karachi')
            ->assertJsonStructure(['data' => [
                'user' => ['id', 'name', 'email'],
                'roles', 'permissions',
                'features' => ['presence', 'attendance', 'reports', 'application_usage', 'screenshots', 'downloads', 'agent_health'],
                'server' => ['version', 'timezone', 'display_timezone', 'time'],
            ]]);
    }

    public function test_manager_login_token_can_bootstrap_but_employee_token_is_forbidden(): void
    {
        $manager = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));
        $managerToken = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password',
            'device_name' => 'Desktop API test',
        ])->assertOk()->json('token');

        $this->withToken($managerToken)
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.user.id', $manager->id);

        $employee = tap(User::factory()->create(), fn (User $user) => $user->assignRole('employee'));
        $employeeToken = $this->postJson('/api/v1/auth/login', [
            'email' => $employee->email,
            'password' => 'password',
            'device_name' => 'Desktop API test',
        ])->assertOk()->json('token');

        $this->withToken($employeeToken)
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertForbidden();
    }

    public function test_manager_overview_is_scoped_to_their_team(): void
    {
        $manager = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));
        $otherManager = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));

        [$ownEmployee, $ownComputer] = $this->teamMember($manager, 'OWN-PC', PresenceStatus::Active, 45, 15);
        $this->teamMember($otherManager, 'OTHER-PC', PresenceStatus::Idle, 10, 50);
        ActivityLog::factory()->create(['employee_id' => $ownEmployee->id, 'computer_id' => $ownComputer->id, 'work_date' => today()]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'team')
            ->assertJsonPath('data.employees.total', 1)
            ->assertJsonPath('data.employees.present', 1)
            ->assertJsonPath('data.presence.total', 1)
            ->assertJsonPath('data.presence.active', 1)
            ->assertJsonPath('data.presence.idle', 0)
            ->assertJsonPath('data.activity.active_seconds', 45)
            ->assertJsonPath('data.activity.idle_seconds', 15)
            ->assertJsonPath('data.activity.active_percent', 75.0);
    }

    public function test_admin_overview_is_organization_wide(): void
    {
        $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole('admin'));
        $managerA = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));
        $managerB = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));
        $this->teamMember($managerA, 'A-PC', PresenceStatus::Active, 30, 0);
        $this->teamMember($managerB, 'B-PC', PresenceStatus::Idle, 0, 30);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/desktop/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.employees.total', 2)
            ->assertJsonPath('data.presence.total', 2)
            ->assertJsonPath('data.presence.active', 1)
            ->assertJsonPath('data.presence.idle', 1)
            ->assertJsonPath('data.activity.tracked_seconds', 60);
    }

    /** @return array{0:Employee,1:Computer} */
    private function teamMember(User $manager, string $hostname, PresenceStatus $status, int $active, int $idle): array
    {
        $employee = Employee::factory()->create(['manager_user_id' => $manager->id]);
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'hostname' => $hostname]);
        ComputerPresence::factory()->for($computer)->status($status)->create();
        AgentEvent::factory()->heartbeat()->for($computer)->create([
            'employee_id' => $employee->id,
            'payload' => ['ActiveSeconds' => $active, 'IdleSeconds' => $idle, 'IsIdle' => $idle > 0],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        return [$employee, $computer];
    }
}
