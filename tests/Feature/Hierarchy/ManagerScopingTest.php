<?php

namespace Tests\Feature\Hierarchy;

use App\Enums\OrganizationRole;
use App\Livewire\ApplicationUsage\ApplicationUsageDashboard;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Presence\PresenceBoard;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Hierarchy\EmployeeVisibility;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 11 — role-scoped visibility: a Manager sees only their own team across
 * employees, presence and application usage and can never reach another
 * Manager's data; the Super Admin sees everything.
 */
class ManagerScopingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->organization = Organization::factory()->create();
        foreach (['view dashboard', 'view reports', 'view attendance', 'manage employees', 'view own data'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $admin = Role::findOrCreate('admin', 'web');
        $admin->givePermissionTo(Permission::all());
        $manager = Role::findOrCreate('manager', 'web');
        $manager->givePermissionTo(['view dashboard', 'view reports', 'view attendance', 'view own data']);
        Role::findOrCreate('employee', 'web');
    }

    private function manager(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));
        $this->addMembership($user, 'manager');
        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $this->organization, OrganizationRole::Manager);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $user->givePermissionTo('view own data');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
        $this->addMembership($user, 'admin');
        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $this->organization, OrganizationRole::Admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $user;
    }

    /** @return array{0:User,1:Employee,2:Computer} manager, their employee, their computer */
    private function team(User $manager, string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $this->addMembership($user, 'employee');

        $employee = Employee::factory()->forOrganization($this->organization)->create(['user_id' => $user->id, 'manager_user_id' => $manager->id]);
        $computer = Computer::factory()->forEmployee($employee)->create(['hostname' => "PC-{$name}"]);

        return [$manager, $employee, $computer];
    }

    private function addMembership(User $user, string $role): void
    {
        OrganizationMembership::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    public function test_visibility_service_scopes_ids_by_role(): void
    {
        $visibility = app(EmployeeVisibility::class);
        $m1 = $this->manager();
        $m2 = $this->manager();
        [, $e1] = $this->team($m1, 'Hassan');
        [, $e2] = $this->team($m2, 'Zain');

        $this->assertSame([$e1->id], $visibility->employeeIds($m1));
        $this->assertSame([$e2->id], $visibility->employeeIds($m2));
        $this->assertNull($visibility->employeeIds($this->superAdmin()));
    }

    public function test_manager_employee_list_shows_only_their_team(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $this->team($m1, 'Hassan');
        $this->team($m2, 'Zain');

        Livewire::actingAs($m1)->test(EmployeeIndex::class)
            ->assertSee('Hassan')
            ->assertDontSee('Zain');
    }

    public function test_manager_presence_board_shows_only_their_computers(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $this->team($m1, 'Hassan');
        $this->team($m2, 'Zain');

        Livewire::actingAs($m1)->test(PresenceBoard::class)
            ->assertSee('PC-Hassan')
            ->assertDontSee('PC-Zain');
    }

    public function test_manager_application_usage_is_scoped(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        [, $e1, $c1] = $this->team($m1, 'Hassan');
        [, $e2, $c2] = $this->team($m2, 'Zain');

        ApplicationUsage::factory()->create([
            'organization_id' => $this->organization->id,
            'employee_id' => $e1->id, 'computer_id' => $c1->id,
            'application_name' => 'AlphaApp', 'used_at' => today()->setTime(9, 0),
            'duration_seconds' => 600, 'session_id' => 's1',
        ]);
        ApplicationUsage::factory()->create([
            'organization_id' => $this->organization->id,
            'employee_id' => $e2->id, 'computer_id' => $c2->id,
            'application_name' => 'BetaApp', 'used_at' => today()->setTime(9, 0),
            'duration_seconds' => 600, 'session_id' => 's2',
        ]);

        Livewire::actingAs($m1)->test(ApplicationUsageDashboard::class)
            ->assertSee('AlphaApp')
            ->assertDontSee('BetaApp');
    }

    public function test_super_admin_sees_all_teams(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $this->team($m1, 'Hassan');
        $this->team($m2, 'Zain');

        Livewire::actingAs($this->superAdmin())->test(EmployeeIndex::class)
            ->assertSee('Hassan')
            ->assertSee('Zain');
    }

    public function test_employee_without_role_is_denied_dashboards(): void
    {
        $employee = tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));
        $this->grantOrganizationRole($employee, $this->organization, OrganizationRole::Employee);

        Livewire::actingAs($employee)->test(PresenceBoard::class)->assertForbidden();
        Livewire::actingAs($employee)->test(ApplicationUsageDashboard::class)->assertForbidden();
    }
}
