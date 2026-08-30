<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseA1TenancyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_creation_and_status_helpers(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($organization->isActive());
        $this->assertFalse($organization->isSuspended());

        $organization->suspend();

        $this->assertTrue($organization->fresh()->isSuspended());
        $this->assertSame(1, Organization::suspended()->count());

        $organization->activate();

        $this->assertTrue($organization->fresh()->isActive());
        $this->assertSame(1, Organization::active()->count());
    }

    public function test_organization_user_membership_is_unique(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_membership_relationships(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $membership = OrganizationMembership::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($user->fresh()->organizations->contains($organization));
        $this->assertTrue($organization->fresh()->users->contains($user));
        $this->assertTrue($user->fresh()->memberships->contains($membership));
        $this->assertTrue($user->fresh()->hasActiveMembership($organization));
    }

    public function test_inactive_membership_fails_closed(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->inactive()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $this->expectResolverFailure(CurrentOrganizationException::inactiveMembership(), function () use ($user, $organization) {
            app(CurrentOrganization::class)->resolve($user, $organization->id);
        });
    }

    public function test_suspended_organization_is_rejected_before_role_checks(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        Route::middleware(['web', 'auth', 'organization'])->get('/phase-a1-org-check', fn () => 'ok');

        $this->actingAs($user)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/phase-a1-org-check')
            ->assertForbidden();
    }

    public function test_resolver_automatically_selects_exactly_one_active_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $resolved = app(CurrentOrganization::class)->resolve($user);

        $this->assertTrue($organization->is($resolved));
    }

    public function test_resolver_honors_valid_session_selected_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();

        foreach ([$first, $second] as $organization) {
            OrganizationMembership::factory()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);
        }

        $resolved = app(CurrentOrganization::class)->resolve($user, $second->id);

        $this->assertTrue($second->is($resolved));
    }

    public function test_selection_contract_stores_current_organization_in_session(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();

        foreach ([$first, $second] as $organization) {
            OrganizationMembership::factory()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);
        }

        Route::middleware(['web', 'auth'])->post('/phase-a1-select-org/{organization}', function (Organization $organization, CurrentOrganization $currentOrganization) {
            $currentOrganization->select(request()->user(), $organization);

            return response('selected');
        });

        $this->actingAs($user)
            ->post('/phase-a1-select-org/'.$second->id)
            ->assertOk()
            ->assertSessionHas(CurrentOrganization::SESSION_KEY, $second->id);
    }

    public function test_selected_organization_without_membership_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $this->expectResolverFailure(CurrentOrganizationException::noMembership(), function () use ($user, $organization) {
            app(CurrentOrganization::class)->resolve($user, $organization->id);
        });
    }

    public function test_multiple_active_organizations_without_selection_fails_closed(): void
    {
        $user = User::factory()->create();

        OrganizationMembership::factory()->count(2)->create(['user_id' => $user->id]);

        $this->expectResolverFailure(CurrentOrganizationException::ambiguous(), function () use ($user) {
            app(CurrentOrganization::class)->resolve($user);
        });
    }

    public function test_platform_only_user_without_membership_fails_closed(): void
    {
        $user = User::factory()->create();

        $this->expectResolverFailure(CurrentOrganizationException::noMembership(), function () use ($user) {
            app(CurrentOrganization::class)->resolve($user);
        });
    }

    public function test_default_organization_backfill_is_idempotent_and_marks_existing_admin_owner(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('manager', 'web');
        Role::findOrCreate('employee', 'web');

        $admin = tap(User::factory()->create(), fn (User $user) => $user->assignRole('admin'));
        $manager = tap(User::factory()->create(), fn (User $user) => $user->assignRole('manager'));
        $employee = tap(User::factory()->create(), fn (User $user) => $user->assignRole('employee'));

        $this->artisan('treck:backfill-default-organization', [
            '--name' => 'Acme Default',
            '--slug' => 'acme-default',
        ])->assertSuccessful();

        $organization = Organization::where('slug', 'acme-default')->firstOrFail();

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => MembershipStatus::Active->value,
            'role' => 'organization-owner',
            'is_owner' => true,
        ]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_owner' => false,
        ]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $employee->id,
            'role' => 'employee',
            'is_owner' => false,
        ]);

        $this->artisan('treck:backfill-default-organization', [
            '--name' => 'Acme Default',
            '--slug' => 'acme-default',
        ])->assertSuccessful();

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_user', 3);
        $this->assertDatabaseMissing('roles', ['name' => 'platform-super-admin']);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_backfill_dry_run_does_not_create_records(): void
    {
        User::factory()->count(2)->create();

        $this->artisan('treck:backfill-default-organization', [
            '--name' => 'Dry Run Org',
            '--slug' => 'dry-run-org',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_user', 0);
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
