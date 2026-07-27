<?php

namespace Tests\Feature\Hierarchy;

use App\Livewire\Hierarchy\ManagerManagement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 — Super-Admin Manager Management: create/promote/demote managers and
 * assign / transfer / remove employees, with Super-Admin-only authorization.
 */
class ManagerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permission::findOrCreate('manage users', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $admin->givePermissionTo('manage users');
        Role::findOrCreate('manager', 'web');
        Role::findOrCreate('employee', 'web');
    }

    private function superAdmin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    public function test_super_admin_can_open_manager_management(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/managers')->assertOk();
    }

    public function test_non_admin_cannot_open_manager_management(): void
    {
        $manager = tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));
        $this->actingAs($manager)->get('/admin/managers')->assertForbidden();
    }

    public function test_super_admin_can_create_a_manager(): void
    {
        Livewire::actingAs($this->superAdmin())->test(ManagerManagement::class)
            ->set('name', 'Ali Manager')
            ->set('email', 'ali@treck.test')
            ->set('password', 'password123')
            ->call('createManager')
            ->assertHasNoErrors();

        $user = User::where('email', 'ali@treck.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isManager());
    }

    public function test_promote_and_demote(): void
    {
        $employeeUser = tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));
        $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

        $component = Livewire::actingAs($this->superAdmin())->test(ManagerManagement::class);

        $component->call('promote', $employeeUser->id);
        $this->assertTrue($employeeUser->fresh()->isManager());

        // Give the new manager a team member, then demote → team is unassigned.
        $report = Employee::factory()->create(['manager_user_id' => $employeeUser->id]);
        $component->call('demote', $employeeUser->id);

        $this->assertFalse($employeeUser->fresh()->isManager());
        $this->assertNull($report->fresh()->manager_user_id);
    }

    public function test_assign_and_transfer_and_remove_employee(): void
    {
        $m1 = tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));
        $m2 = tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));
        $employee = Employee::factory()->create(['manager_user_id' => null]);

        $component = Livewire::actingAs($this->superAdmin())->test(ManagerManagement::class);

        // Assign to m1.
        $component->set('assignEmployeeId', $employee->id)->set('assignManagerId', $m1->id)->call('assignEmployee')->assertHasNoErrors();
        $this->assertSame($m1->id, $employee->fresh()->manager_user_id);

        // Transfer to m2 (same action, different manager).
        $component->set('assignEmployeeId', $employee->id)->set('assignManagerId', $m2->id)->call('assignEmployee');
        $this->assertSame($m2->id, $employee->fresh()->manager_user_id);

        // Remove.
        $component->call('removeEmployee', $employee->id);
        $this->assertNull($employee->fresh()->manager_user_id);
    }

    public function test_manager_cannot_mutate_hierarchy(): void
    {
        $manager = tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));

        Livewire::actingAs($manager)->test(ManagerManagement::class)->assertForbidden();
    }

    /**
     * Regression: promoting/creating a manager must work on a deployment that
     * migrated but never seeded the `manager` role (the service creates it on
     * demand instead of throwing RoleDoesNotExist).
     */
    public function test_promote_works_when_manager_role_was_not_seeded(): void
    {
        Role::whereName('manager')->delete();
        $employeeUser = tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));

        Livewire::actingAs($this->superAdmin())->test(ManagerManagement::class)
            ->call('promote', $employeeUser->id)
            ->assertHasNoErrors();

        $this->assertTrue($employeeUser->fresh()->isManager());
    }
}
