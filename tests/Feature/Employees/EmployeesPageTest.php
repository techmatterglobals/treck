<?php

namespace Tests\Feature\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The employees page must render (Laravel 11: authorizeResource() in the
 * constructor threw "Call to undefined method ...::middleware()"; replaced with
 * HasMiddleware). Also verifies the resource authorization still gates access.
 */
class EmployeesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Permission::findOrCreate('manage employees', 'web');
        Permission::findOrCreate('view own data', 'web');
    }

    private function manager(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('manage employees'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/employees')->assertRedirect('/login');
    }

    public function test_authorized_user_can_load_employees_index(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAs($this->manager())->get('/employees')->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create(); // no permissions

        $this->actingAs($user)->get('/employees')->assertForbidden();
    }

    public function test_show_page_loads_for_manager(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->manager())->get(route('employees.show', $employee))->assertOk();
    }
}
