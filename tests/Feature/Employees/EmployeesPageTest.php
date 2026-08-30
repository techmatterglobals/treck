<?php

namespace Tests\Feature\Employees;

use App\Enums\OrganizationRole;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
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
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Admin->value,
        ]);

        app(OrganizationAuthorization::class)->assignOrganizationRole($user, $organization, OrganizationRole::Admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/employees')->assertRedirect('/login');
    }

    public function test_authorized_user_can_load_employees_index(): void
    {
        $user = $this->manager();
        $organization = $user->memberships()->firstOrFail()->organization;

        Employee::factory()->count(3)->forOrganization($organization)->create();

        $this->actingAs($user)->get('/employees')->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(); // no permissions
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->get('/employees')->assertForbidden();
    }

    public function test_show_page_loads_for_manager(): void
    {
        $user = $this->manager();
        $organization = $user->memberships()->firstOrFail()->organization;
        $employee = Employee::factory()->forOrganization($organization)->create();

        $this->actingAs($user)->get(route('employees.show', $employee))->assertOk();
    }
}
