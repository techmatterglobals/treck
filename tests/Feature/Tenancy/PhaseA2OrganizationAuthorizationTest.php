<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PlatformRole;
use App\Enums\UserRole;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseA2OrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_spatie_teams_are_enabled_with_organization_id_key(): void
    {
        $this->assertTrue(config('permission.teams'));
        $this->assertSame('organization_id', config('permission.column_names.team_foreign_key'));
        $this->assertTrue(app(PermissionRegistrar::class)->teams);
        $this->assertSame('organization_id', app(PermissionRegistrar::class)->teamsKey);
    }

    public function test_global_and_organization_roles_can_coexist_with_repeated_names(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $global = Role::query()->create(['name' => PlatformRole::SuperAdmin->value, 'guard_name' => 'web']);
        $firstAdmin = Role::query()->create(['organization_id' => $first->id, 'name' => OrganizationRole::Admin->value, 'guard_name' => 'web']);
        $secondAdmin = Role::query()->create(['organization_id' => $second->id, 'name' => OrganizationRole::Admin->value, 'guard_name' => 'web']);

        $this->assertNull($global->organization_id);
        $this->assertNotSame($firstAdmin->id, $secondAdmin->id);
        $this->assertDatabaseHas('roles', ['organization_id' => $first->id, 'name' => 'admin']);
        $this->assertDatabaseHas('roles', ['organization_id' => $second->id, 'name' => 'admin']);
    }

    public function test_single_active_membership_is_selected_automatically_and_sets_team_context(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-a2-context', fn () => response((string) getPermissionsTeamId()));

        $this->actingAs($user)
            ->get('/phase-a2-context')
            ->assertOk()
            ->assertSee((string) $organization->id)
            ->assertSessionHas(CurrentOrganization::SESSION_KEY, $organization->id);
    }

    public function test_multiple_memberships_require_explicit_selection(): void
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->count(2)->create(['user_id' => $user->id]);

        $this->expectResolverFailure(CurrentOrganizationException::ambiguous(), fn () => app(CurrentOrganization::class)->resolve($user));
    }

    public function test_selected_active_membership_resolves_successfully(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $first->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $second->id, 'user_id' => $user->id]);

        $resolved = app(CurrentOrganization::class)->resolve($user, $second->id);

        $this->assertTrue($second->is($resolved));
        $this->assertSame($second->id, getPermissionsTeamId());
    }

    public function test_another_users_organization_is_rejected_and_stale_session_is_cleared(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $other->id]);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-a2-stale', fn () => 'ok');

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/phase-a2-stale')
            ->assertRedirect(route('organizations.select'));

        $this->assertFalse(session()->has(CurrentOrganization::SESSION_KEY));
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_inactive_organization_and_membership_are_rejected(): void
    {
        $suspended = Organization::factory()->create([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
        $inactive = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $suspended->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->inactive()->create(['organization_id' => $inactive->id, 'user_id' => $user->id]);

        $this->expectResolverFailure(CurrentOrganizationException::suspendedOrganization(), fn () => app(CurrentOrganization::class)->resolve($user, $suspended->id));
        $this->expectResolverFailure(CurrentOrganizationException::inactiveMembership(), fn () => app(CurrentOrganization::class)->resolve($user, $inactive->id));
    }

    public function test_unauthenticated_and_json_organization_requests_do_not_redirect_to_html(): void
    {
        Route::middleware(['web', 'auth', 'organization'])->get('/phase-a2-web-context', fn () => 'ok');
        Route::middleware(['web', 'auth', 'organization'])->get('/phase-a2-json-context', fn () => 'ok');

        $this->get('/phase-a2-web-context')->assertRedirect(route('login'));

        $user = User::factory()->create();
        OrganizationMembership::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/phase-a2-json-context')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'ambiguous');
    }

    public function test_organization_roles_are_isolated_by_current_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        $authorization = app(OrganizationAuthorization::class);

        foreach ([$first, $second] as $organization) {
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        }

        $authorization->assignOrganizationRole($user, $first, OrganizationRole::Admin);
        $authorization->assignOrganizationRole($user, $second, OrganizationRole::Manager);

        $this->assertTrue($authorization->hasOrganizationRole($user, OrganizationRole::Admin, $first));
        $this->assertFalse($authorization->hasOrganizationRole($user, OrganizationRole::Admin, $second));
        $this->assertTrue($authorization->hasOrganizationRole($user, OrganizationRole::Manager, $second));
        $this->assertFalse($authorization->hasOrganizationRole($user, OrganizationRole::Admin, $second));
    }

    public function test_managers_and_employees_do_not_inherit_higher_organization_roles(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $manager->id]);
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $employee->id]);

        $authorization = app(OrganizationAuthorization::class);
        $authorization->assignOrganizationRole($manager, $organization, OrganizationRole::Manager);
        $authorization->assignOrganizationRole($employee, $organization, OrganizationRole::Employee);

        $this->assertFalse($authorization->hasOrganizationRole($manager, OrganizationRole::Admin, $organization));
        $this->assertFalse($authorization->hasOrganizationRole($employee, OrganizationRole::Manager, $organization));
    }

    public function test_platform_super_admin_is_global_and_organization_admin_is_not_platform_admin(): void
    {
        $organization = Organization::factory()->create();
        $platform = User::factory()->create();
        $admin = User::factory()->create();
        $authorization = app(OrganizationAuthorization::class);

        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $admin->id]);
        $authorization->assignOrganizationRole($admin, $organization, OrganizationRole::Admin);

        $platformRole = Role::query()->create(['name' => PlatformRole::SuperAdmin->value, 'guard_name' => 'web']);
        DB::table(config('permission.table_names.model_has_roles'))->insert([
            'role_id' => $platformRole->id,
            'model_type' => $platform->getMorphClass(),
            'model_id' => $platform->id,
            'organization_id' => null,
        ]);

        $this->assertTrue($authorization->isPlatformSuperAdmin($platform));
        $this->assertFalse($authorization->isPlatformSuperAdmin($admin));
    }

    public function test_changing_current_organization_changes_effective_roles_without_stale_team_leak(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        $authorization = app(OrganizationAuthorization::class);

        foreach ([$first, $second] as $organization) {
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        }

        $authorization->assignOrganizationRole($user, $first, OrganizationRole::Admin);
        $authorization->assignOrganizationRole($user, $second, OrganizationRole::Employee);

        app(CurrentOrganization::class)->resolve($user, $first->id);
        $this->assertSame($first->id, getPermissionsTeamId());
        $this->assertTrue($authorization->hasOrganizationRole($user, OrganizationRole::Admin));

        app(CurrentOrganization::class)->resolve($user, $second->id);
        $this->assertSame($second->id, getPermissionsTeamId());
        $this->assertFalse($authorization->hasOrganizationRole($user, OrganizationRole::Admin));
        $this->assertTrue($authorization->hasOrganizationRole($user, OrganizationRole::Employee));
    }

    public function test_selection_page_lists_only_active_organizations_and_switches_safely(): void
    {
        $active = Organization::factory()->create(['name' => 'Active Co']);
        $inactiveOrganization = Organization::factory()->suspended()->create(['name' => 'Suspended Co']);
        $inactiveMembership = Organization::factory()->create(['name' => 'Inactive Membership Co']);
        $other = Organization::factory()->create(['name' => 'Other Co']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        OrganizationMembership::factory()->create(['organization_id' => $active->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $inactiveOrganization->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->inactive()->create(['organization_id' => $inactiveMembership->id, 'user_id' => $user->id]);
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $otherUser->id]);

        $this->actingAs($user)
            ->get(route('organizations.select'))
            ->assertOk()
            ->assertSee('Active Co')
            ->assertDontSee('Suspended Co')
            ->assertDontSee('Inactive Membership Co')
            ->assertDontSee('Other Co');

        $this->actingAs($user)
            ->post(route('organizations.switch'), ['organization_id' => $active->id])
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas(CurrentOrganization::SESSION_KEY, $active->id);

        $this->actingAs($user)
            ->post(route('organizations.switch'), ['organization_id' => $other->id])
            ->assertForbidden();
    }

    public function test_selection_post_requires_csrf_and_authentication(): void
    {
        $organization = Organization::factory()->create();

        $this->post(route('organizations.switch'), ['organization_id' => $organization->id])
            ->assertRedirect(route('login'));

        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(route('organizations.switch'), ['organization_id' => $organization->id])
            ->assertRedirect(route('login'));
    }

    public function test_backfill_organization_roles_is_dry_run_safe_and_idempotent(): void
    {
        $organization = Organization::factory()->create(['slug' => 'default']);
        Role::findOrCreate(UserRole::Admin->value, 'web');
        $legacyAdmin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(UserRole::Admin->value));

        $this->artisan('treck:backfill-organization-roles', ['--slug' => 'default', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $legacyAdmin->id]);

        $this->artisan('treck:backfill-organization-roles', ['--slug' => 'default'])
            ->assertSuccessful();
        $this->artisan('treck:backfill-organization-roles', ['--slug' => 'default'])
            ->assertSuccessful();

        $this->assertDatabaseCount('organization_user', 1);
        $this->assertTrue(app(OrganizationAuthorization::class)->hasOrganizationRole($legacyAdmin, OrganizationRole::Admin, $organization));
        $this->assertDatabaseMissing('roles', ['name' => PlatformRole::SuperAdmin->value]);
    }

    public function test_backfill_preserves_existing_membership_and_fails_for_invalid_target(): void
    {
        $organization = Organization::factory()->create(['slug' => 'default']);
        Role::findOrCreate(UserRole::Admin->value, 'web');
        $legacyAdmin = tap(User::factory()->create(), fn (User $user) => $user->assignRole(UserRole::Admin->value));
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $legacyAdmin->id,
            'role' => 'owner',
            'is_owner' => true,
        ]);

        $this->artisan('treck:backfill-organization-roles', ['--slug' => 'default'])
            ->assertSuccessful();

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $legacyAdmin->id,
            'role' => 'owner',
            'is_owner' => true,
        ]);

        $this->artisan('treck:backfill-organization-roles', ['--slug' => 'missing'])
            ->assertFailed();
    }

    private function expectResolverFailure(CurrentOrganizationException $expected, \Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Resolver did not fail closed.');
        } catch (CurrentOrganizationException $exception) {
            $this->assertSame($expected->reason, $exception->reason);
        }
    }
}
