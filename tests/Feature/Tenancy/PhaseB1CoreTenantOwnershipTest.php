<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\OrganizationRole;
use App\Enums\PlatformRole;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Livewire\Hierarchy\ManagerManagement;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseB1CoreTenantOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permission::findOrCreate('manage employees', 'web');
        Permission::findOrCreate('manage computers', 'web');
        Permission::findOrCreate('manage users', 'web');

        Role::findOrCreate('admin', 'web')->givePermissionTo('manage users');
    }

    public function test_core_tables_have_nullable_organization_ownership(): void
    {
        foreach (['departments', 'employees', 'computers'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'organization_id'));
        }

        $departmentIndexes = collect(DB::select('pragma index_list(departments)'))->pluck('name');
        $employeeIndexes = collect(DB::select('pragma index_list(employees)'))->pluck('name');
        $computerIndexes = collect(DB::select('pragma index_list(computers)'))->pluck('name');

        $this->assertContains('departments_organization_id_name_idx', $departmentIndexes);
        $this->assertContains('employees_organization_id_employee_code_idx', $employeeIndexes);
        $this->assertContains('computers_organization_id_device_uuid_idx', $computerIndexes);
        $this->assertContains('employees_employee_code_unique', $employeeIndexes);
        $this->assertContains('computers_device_uuid_unique', $computerIndexes);
        $this->assertFalse(Schema::hasColumn('computer_users', 'organization_id'));
    }

    public function test_core_models_belong_to_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->forOrganization($organization)->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['department_id' => $department->id]);
        $computer = Computer::factory()->forEmployee($employee)->create();

        $this->assertTrue($department->organization->is($organization));
        $this->assertTrue($employee->organization->is($organization));
        $this->assertTrue($computer->organization->is($organization));
        $this->assertTrue($organization->departments->contains($department));
        $this->assertTrue($organization->employees->contains($employee));
        $this->assertTrue($organization->computers->contains($computer));
    }

    public function test_employee_index_lists_only_current_organization_records(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->organizationUser($first, OrganizationRole::Admin, ['manage employees']);

        $visibleDepartment = Department::factory()->forOrganization($first)->create(['name' => 'Support']);
        $hiddenDepartment = Department::factory()->forOrganization($second)->create(['name' => 'Finance']);
        Employee::factory()->forOrganization($first)->create([
            'department_id' => $visibleDepartment->id,
            'employee_code' => 'B1-VISIBLE',
        ]);
        Employee::factory()->forOrganization($second)->create([
            'department_id' => $hiddenDepartment->id,
            'employee_code' => 'B1-HIDDEN',
        ]);

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('B1-VISIBLE')
            ->assertSee('Support')
            ->assertDontSee('B1-HIDDEN')
            ->assertDontSee('Finance');
    }

    public function test_active_membership_without_organization_role_is_denied(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOnly($organization);

        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        $user->givePermissionTo('manage employees');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_legacy_global_manager_with_membership_but_no_scoped_role_is_denied(): void
    {
        $organization = Organization::factory()->create();
        Role::findOrCreate('manager', 'web')->givePermissionTo('view own data');
        $manager = $this->memberOnly($organization);
        $manager->assignRole('manager');

        Employee::factory()->forOrganization($organization)->create(['manager_user_id' => $manager->id]);

        $this->actingAs($manager)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_legacy_global_admin_with_membership_but_no_scoped_role_is_denied(): void
    {
        $organization = Organization::factory()->create();
        Role::findOrCreate('admin', 'web')->givePermissionTo('manage employees');
        $admin = $this->memberOnly($organization);
        $admin->assignRole('admin');

        Employee::factory()->forOrganization($organization)->create();

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_organization_scoped_manager_sees_assigned_employees_only(): void
    {
        $organization = Organization::factory()->create();
        $manager = $this->organizationUser($organization, OrganizationRole::Manager);
        $visible = Employee::factory()->forOrganization($organization)->create([
            'manager_user_id' => $manager->id,
            'employee_code' => 'B1-MANAGED',
        ]);
        Employee::factory()->forOrganization($organization)->create([
            'manager_user_id' => null,
            'employee_code' => 'B1-UNASSIGNED',
        ]);

        $other = Organization::factory()->create();
        Employee::factory()->forOrganization($other)->create([
            'manager_user_id' => $manager->id,
            'employee_code' => 'B1-FOREIGN-MANAGED',
        ]);

        $this->actingAs($manager)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee($visible->employee_code)
            ->assertDontSee('B1-UNASSIGNED')
            ->assertDontSee('B1-FOREIGN-MANAGED');
    }

    public function test_organization_scoped_admin_sees_only_current_organization_employees(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->organizationUser($first, OrganizationRole::Admin);

        Employee::factory()->forOrganization($first)->create(['employee_code' => 'B1-ORG-ADMIN-VISIBLE']);
        Employee::factory()->forOrganization($second)->create(['employee_code' => 'B1-ORG-ADMIN-HIDDEN']);

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('B1-ORG-ADMIN-VISIBLE')
            ->assertDontSee('B1-ORG-ADMIN-HIDDEN');
    }

    public function test_organization_scoped_employee_and_unknown_roles_cannot_access_core_administration(): void
    {
        $organization = Organization::factory()->create();
        $employee = $this->organizationUser($organization, OrganizationRole::Employee);
        $unknown = $this->memberOnly($organization, 'auditor');

        $this->actingAs($employee)
            ->get(route('employees.index'))
            ->assertForbidden();

        $this->actingAs($unknown)
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_switching_organizations_does_not_retain_previous_effective_role(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $first->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $second->id, 'user_id' => $user->id]);
        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $first, OrganizationRole::Admin);
        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $second, OrganizationRole::Employee);

        Employee::factory()->forOrganization($first)->create(['employee_code' => 'B1-FIRST-ORG']);
        Employee::factory()->forOrganization($second)->create(['employee_code' => 'B1-SECOND-ORG']);

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('B1-FIRST-ORG')
            ->assertDontSee('B1-SECOND-ORG');

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $second->id])
            ->get(route('employees.index'))
            ->assertForbidden();
    }

    public function test_pre_resolved_resolver_uses_active_http_request_identity(): void
    {
        $resolver = app(CurrentOrganization::class);
        $organization = Organization::factory()->create();
        $user = $this->organizationUser($organization, OrganizationRole::Admin);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-b1-lazy-resolver', function () use ($resolver) {
            return response((string) $resolver->resolve()->id);
        });

        $this->actingAs($user)
            ->get('/phase-b1-lazy-resolver')
            ->assertOk()
            ->assertSee((string) $organization->id);
    }

    public function test_resolver_does_not_leak_selection_between_consecutive_requests(): void
    {
        $resolver = app(CurrentOrganization::class);
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $first->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $second->id, 'user_id' => $user->id]);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-b1-selected-resolver', function () use ($resolver) {
            return response((string) getPermissionsTeamId().':'.$resolver->resolve()->id);
        });

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get('/phase-b1-selected-resolver')
            ->assertOk()
            ->assertSee($first->id.':'.$first->id);

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $second->id])
            ->get('/phase-b1-selected-resolver')
            ->assertOk()
            ->assertSee($second->id.':'.$second->id);
    }

    public function test_switching_users_clears_stale_previous_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $firstUser = $this->organizationUser($first, OrganizationRole::Admin);
        $secondUser = $this->organizationUser($second, OrganizationRole::Admin);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-b1-user-switch', fn () => 'ok');

        $this->actingAs($firstUser)
            ->get('/phase-b1-user-switch')
            ->assertOk()
            ->assertSessionHas(CurrentOrganization::SESSION_KEY, $first->id);

        $this->actingAs($secondUser)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get('/phase-b1-user-switch')
            ->assertRedirect(route('organizations.select'));

        $this->assertFalse(session()->has(CurrentOrganization::SESSION_KEY));
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_failed_resolution_clears_spatie_team_context(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-b1-no-org', fn () => 'ok');

        $this->actingAs($user)
            ->getJson('/phase-b1-no-org')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'no_membership');

        $this->assertNull(getPermissionsTeamId());
    }

    public function test_console_resolution_does_not_inherit_web_context(): void
    {
        $organization = Organization::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        Auth::logout();
        app()->instance('request', Request::create('/artisan'));

        try {
            app(CurrentOrganization::class)->resolve();
            $this->fail('Console-style resolution unexpectedly found a web organization.');
        } catch (CurrentOrganizationException $exception) {
            $this->assertSame('unauthenticated', $exception->reason);
        }

        $this->assertNull(getPermissionsTeamId());
    }

    public function test_worker_style_resolver_reuse_does_not_leak_user_or_team_context(): void
    {
        $resolver = app(CurrentOrganization::class);
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $firstUser = $this->organizationUser($first, OrganizationRole::Admin);
        $secondUser = $this->organizationUser($second, OrganizationRole::Admin);

        $this->assertTrue($first->is($resolver->resolve($firstUser)));
        $this->assertSame($first->id, getPermissionsTeamId());

        $this->assertTrue($second->is($resolver->resolve($secondUser)));
        $this->assertSame($second->id, getPermissionsTeamId());
    }

    public function test_foreign_employee_route_model_is_not_disclosed(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->organizationUser($first, OrganizationRole::Admin, ['manage employees']);
        $employee = Employee::factory()->forOrganization($second)->create();

        $this->actingAs($admin)
            ->get(route('employees.show', $employee))
            ->assertNotFound();
    }

    public function test_employee_creation_assigns_current_organization_and_rejects_foreign_departments(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->organizationUser($first, OrganizationRole::Admin, ['manage employees']);
        $department = Department::factory()->forOrganization($first)->create();
        $foreignDepartment = Department::factory()->forOrganization($second)->create();

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload([
                'organization_id' => $second->id,
                'department_id' => $department->id,
                'employee_code' => 'B1-CREATED',
                'role' => 'admin',
            ]))
            ->assertRedirect();

        $created = Employee::where('employee_code', 'B1-CREATED')->firstOrFail();
        $this->assertSame($first->id, $created->organization_id);
        $this->assertTrue($created->user->hasActiveMembership($first));
        $this->assertDatabaseMissing('roles', ['name' => PlatformRole::SuperAdmin->value]);

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload([
                'department_id' => $foreignDepartment->id,
                'employee_code' => 'B1-REJECTED',
                'email' => 'rejected-b1@example.test',
            ]))
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('employees', ['employee_code' => 'B1-REJECTED']);
    }

    public function test_computer_assignment_is_limited_to_current_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->organizationUser($first, OrganizationRole::Admin, ['manage employees', 'manage computers']);
        $employee = Employee::factory()->forOrganization($first)->create();
        $computer = Computer::factory()->forOrganization($first)->create(['employee_id' => null, 'paired_at' => null]);
        $foreignComputer = Computer::factory()->forOrganization($second)->create(['employee_id' => null, 'paired_at' => null]);

        $this->actingAs($admin)
            ->post(route('employees.computers.assign', $employee), ['computer_id' => $foreignComputer->id])
            ->assertSessionHasErrors('computer_id');

        $this->actingAs($admin)
            ->post(route('employees.computers.assign', $employee), ['computer_id' => $computer->id])
            ->assertRedirect();

        $this->assertSame($employee->id, $computer->fresh()->employee_id);
        $this->assertSame($first->id, $computer->fresh()->organization_id);
    }

    public function test_manager_assignment_cannot_cross_organizations(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->legacySuperAdminForOrganization($first);
        $manager = $this->organizationUser($second, OrganizationRole::Manager);
        $employee = Employee::factory()->forOrganization($first)->create();

        Livewire::actingAs($admin)
            ->test(ManagerManagement::class)
            ->set('assignEmployeeId', $employee->id)
            ->set('assignManagerId', $manager->id)
            ->call('assignEmployee')
            ->assertHasErrors('assignManagerId');

        $this->assertNull($employee->fresh()->manager_user_id);
    }

    public function test_core_backfill_dry_run_real_run_and_verify_are_idempotent(): void
    {
        $organization = Organization::factory()->create(['slug' => 'default']);
        $department = Department::factory()->create(['organization_id' => null]);
        $employee = Employee::factory()->create([
            'organization_id' => null,
            'department_id' => $department->id,
        ]);
        $computer = Computer::factory()->create([
            'organization_id' => null,
            'employee_id' => $employee->id,
        ]);

        $this->artisan('treck:backfill-core-organization-ownership', [
            '--organization' => 'default',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertNull($department->fresh()->organization_id);
        $this->assertNull($employee->fresh()->organization_id);
        $this->assertNull($computer->fresh()->organization_id);

        $this->artisan('treck:backfill-core-organization-ownership', ['--organization' => (string) $organization->id])
            ->assertSuccessful();
        $this->artisan('treck:backfill-core-organization-ownership', ['--organization' => 'default'])
            ->assertSuccessful();
        $this->artisan('treck:backfill-core-organization-ownership', [
            '--organization' => 'default',
            '--verify' => true,
        ])->assertSuccessful();

        $this->assertSame($organization->id, $department->fresh()->organization_id);
        $this->assertSame($organization->id, $employee->fresh()->organization_id);
        $this->assertSame($organization->id, $computer->fresh()->organization_id);
        $this->assertDatabaseMissing('roles', ['name' => PlatformRole::SuperAdmin->value]);
    }

    public function test_core_backfill_reports_conflicts_without_moving_existing_ownership(): void
    {
        $first = Organization::factory()->create(['slug' => 'first']);
        $second = Organization::factory()->create(['slug' => 'second']);
        $foreignDepartment = Department::factory()->forOrganization($second)->create();
        $employee = Employee::factory()->forOrganization($first)->create(['department_id' => $foreignDepartment->id]);
        $computer = Computer::factory()->forOrganization($second)->create(['employee_id' => $employee->id]);

        $this->artisan('treck:backfill-core-organization-ownership', [
            '--organization' => 'first',
            '--verify' => true,
        ])
            ->expectsOutput('employee_department_conflicts=1')
            ->expectsOutput('computer_employee_conflicts=1')
            ->assertFailed();

        $this->artisan('treck:backfill-core-organization-ownership', ['--organization' => 'first'])
            ->assertSuccessful();

        $this->assertSame($first->id, $employee->fresh()->organization_id);
        $this->assertSame($second->id, $computer->fresh()->organization_id);
    }

    private function organizationUser(
        Organization $organization,
        OrganizationRole $role,
        array $permissions = [],
    ): User {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $organization, $role);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $user;
    }

    private function memberOnly(Organization $organization, string $metadataRole = 'employee'): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $metadataRole,
        ]);

        return $user;
    }

    private function legacySuperAdminForOrganization(Organization $organization): User
    {
        $user = $this->organizationUser($organization, OrganizationRole::Admin, ['manage users']);
        $user->assignRole('admin');
        session([CurrentOrganization::SESSION_KEY => $organization->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Phase B1 Employee',
            'email' => 'phase-b1@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'employee',
            'employee_code' => 'B1-EMPLOYEE',
            'designation' => 'Analyst',
            'phone' => '+1-555-0100',
            'department_id' => null,
            'joined_on' => '2026-08-30',
        ], $overrides);
    }
}
