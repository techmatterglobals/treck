<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\PlatformRole;
use App\Enums\PresenceStatus;
use App\Events\NotificationCreated;
use App\Events\PresenceChanged;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Department;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Agent\AgentEnrollmentCredentialService;
use App\Services\Desktop\DesktopOrganizationAccess;
use App\Services\Tenancy\OrganizationContext;
use App\Support\Tenancy\TenantCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseB6SaasSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_organization_web_routes_and_signed_storage_fail_closed(): void
    {
        Storage::fake('local');

        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->actingAsOrganizationRole($first, OrganizationRole::Admin);
        $foreignEmployee = Employee::factory()->forOrganization($second)->create();
        $foreignComputer = Computer::factory()->forEmployee($foreignEmployee)->create(['hostname' => 'FOREIGN-B6']);
        $foreignShot = Screenshot::factory()->forComputer($foreignComputer)->create([
            'captured_at' => Carbon::parse('2026-09-03 10:00:00', 'UTC'),
            'path' => 'organizations/'.$second->id.'/screenshots/'.$foreignComputer->id.'/2026-09-03/foreign.jpg',
            'filename' => 'foreign.jpg',
        ]);
        Storage::disk('local')->put($foreignShot->path, 'foreign bytes');

        $this->actingAs($admin)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get('/presence/computers/'.$foreignComputer->id.'?organization_id='.$second->id)
            ->assertNotFound();

        $signedUrl = URL::temporarySignedRoute('screenshots.image', now()->addMinute(), ['screenshot' => $foreignShot->id]);
        $this->actingAs($admin)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get($signedUrl)
            ->assertNotFound();
    }

    public function test_desktop_header_tampering_and_token_confusion_are_rejected(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->grantOrganizationRole(User::factory()->create(), $first, OrganizationRole::Admin);
        $humanToken = $admin->createToken('desktop', ['*'])->plainTextToken;
        [, , , $agentToken] = $this->ownedAgentDevice($first);

        $this->withToken($humanToken)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $second->id)
            ->getJson('/api/v1/desktop/overview?organization_id='.$first->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'organization_forbidden');

        $this->withToken($agentToken)
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $first->id)
            ->getJson('/api/v1/desktop/bootstrap')
            ->assertUnauthorized();

        $this->withToken($humanToken)
            ->getJson('/api/agent/config')
            ->assertUnauthorized();
    }

    public function test_membership_legacy_role_inactive_state_and_manager_scope_fail_closed(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $membershipOnly = User::factory()->create();
        OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $membershipOnly->id,
            'status' => MembershipStatus::Active,
            'role' => 'member-only',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($membershipOnly)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/presence')
            ->assertForbidden();

        $legacyAdmin = $this->legacyGlobalAdminWithMembership($organization);
        $this->actingAs($legacyAdmin)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/presence')
            ->assertForbidden();

        $inactive = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Admin);
        $inactive->membershipFor($organization)?->forceFill(['status' => MembershipStatus::Inactive])->save();
        $this->actingAs($inactive)
            ->withSession([CurrentOrganization::SESSION_KEY => $organization->id])
            ->get('/presence')
            ->assertForbidden();

        $manager = $this->grantOrganizationRole(User::factory()->create(), $organization, OrganizationRole::Manager);
        $visible = Employee::factory()->forOrganization($organization)->create(['manager_user_id' => $manager->id]);
        $hidden = Employee::factory()->forOrganization($organization)->create();
        $foreignEmployee = Employee::factory()->forOrganization($foreign)->create(['manager_user_id' => $manager->id]);
        $visibleComputer = Computer::factory()->forEmployee($visible)->create(['hostname' => 'VISIBLE-B6']);
        $hiddenComputer = Computer::factory()->forEmployee($hidden)->create(['hostname' => 'HIDDEN-B6']);
        $foreignComputer = Computer::factory()->forEmployee($foreignEmployee)->create(['hostname' => 'FOREIGN-B6']);
        ComputerPresence::factory()->forComputer($visibleComputer)->status(PresenceStatus::Active)->create();
        ComputerPresence::factory()->forComputer($hiddenComputer)->status(PresenceStatus::Active)->create();
        ComputerPresence::factory()->forComputer($foreignComputer)->status(PresenceStatus::Active)->create();

        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader(DesktopOrganizationAccess::HEADER, (string) $organization->id)
            ->getJson('/api/v1/desktop/presence')
            ->assertOk();

        $this->assertDatabaseCount('employees', 3);
        $this->assertSame([$visible->id], $response->json('data.items.*.employee_id'));
        $this->assertNotContains($hidden->id, $response->json('data.items.*.employee_id') ?? []);
    }

    public function test_agent_registration_and_token_tenant_tampering_are_rejected_without_consuming_credentials(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $foreignEmployee = Employee::factory()->forOrganization($second)->create(['employee_code' => 'B6-FOREIGN']);
        $credential = app(AgentEnrollmentCredentialService::class)
            ->create($first, 'B6 enrollment', maxUses: 2);

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => $credential['secret'],
            'organization_id' => $first->id,
            'device_uuid' => 'b6-device-foreign',
            'employee_code' => $foreignEmployee->employee_code,
        ])->assertStatus(422)->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => $credential['secret'],
            'device_uuid' => 'b6-device-foreign',
            'employee_code' => $foreignEmployee->employee_code,
        ])->assertStatus(422)->assertJsonValidationErrors('employee_code');

        $this->assertSame(0, $credential['credential']->refresh()->uses_count);

        [$organization, , $computer] = $this->ownedAgentDevice($first);
        $token = $computer->createToken('mismatch', ['agent:report']);
        $token->accessToken->forceFill(['organization_id' => $second->id])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/agent/config')->assertUnauthorized();
        $this->assertSame($organization->id, $computer->refresh()->organization_id);
    }

    public function test_cache_broadcast_and_queue_context_are_tenant_safe(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $this->assertSame("org:{$first->id}:presence:summary", TenantCacheKey::forOrganization($first, 'presence summary'));
        $this->assertSame("org:{$second->id}:presence:summary", TenantCacheKey::forOrganization($second, 'presence summary'));
        $this->expectException(\InvalidArgumentException::class);
        TenantCacheKey::forOrganization(0, 'presence summary');
    }

    public function test_null_owned_records_are_not_broadcast_and_context_clears_after_failures(): void
    {
        $organization = Organization::factory()->create();
        $computer = Computer::factory()->forOrganization($organization)->create();
        $presence = ComputerPresence::factory()->for($computer)->create(['organization_id' => null]);
        $log = NotificationLog::factory()->create(['organization_id' => null]);

        $this->assertSame([], array_map('strval', (new PresenceChanged($presence))->broadcastOn()));
        $this->assertSame([], array_map('strval', (new NotificationCreated($log))->broadcastOn()));

        $context = app(OrganizationContext::class);
        $this->expectException(\RuntimeException::class);

        try {
            $context->run(Organization::factory()->suspended()->create()->id, fn () => null);
        } finally {
            $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
        }
    }

    public function test_employee_mass_assignment_cannot_override_current_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $department = Department::factory()->forOrganization($first)->create();
        $admin = $this->actingAsOrganizationRole($first, OrganizationRole::Admin);

        $this->actingAs($admin)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->post(route('employees.store'), [
                'name' => 'B6 Employee',
                'email' => 'b6.employee@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => OrganizationRole::Employee->value,
                'employee_code' => 'B6-EMP',
                'department_id' => $department->id,
                'organization_id' => $second->id,
            ])
            ->assertRedirect();

        $employee = Employee::where('employee_code', 'B6-EMP')->firstOrFail();
        $this->assertSame($first->id, $employee->organization_id);
    }

    public function test_readiness_reports_blockers_without_mutating_or_assigning_platform_admins(): void
    {
        Storage::fake('local');
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($first)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        $token = $computer->createToken('agent', ['agent:report']);
        $token->accessToken->forceFill(['organization_id' => $second->id])->save();
        $conflicted = Screenshot::factory()->forComputer($computer)->create([
            'organization_id' => $first->id,
            'captured_at' => Carbon::parse('2026-09-03 12:00:00', 'UTC'),
            'path' => 'screenshots/'.$computer->id.'/2026-09-03/conflict.jpg',
            'filename' => 'conflict.jpg',
        ]);
        $nullOwned = Employee::factory()->create(['organization_id' => null]);
        $rolesBefore = Role::count();

        $this->artisan('treck:verify-saas-readiness')
            ->expectsOutput('FAIL tenant ownership backfills are complete')
            ->expectsOutput('FAIL agent tokens match owned computers')
            ->expectsOutput('FAIL tenant screenshot storage paths match ownership')
            ->expectsOutput('platform_super_admin_assignments=0')
            ->assertFailed();

        $this->assertSame($second->id, $token->accessToken->refresh()->organization_id);
        $this->assertSame($conflicted->path, $conflicted->fresh()->path);
        $this->assertNull($nullOwned->fresh()->organization_id);
        $this->assertSame($rolesBefore, Role::count());
        $this->assertFalse(Role::query()->where('name', PlatformRole::SuperAdmin->value)->exists());
    }

    public function test_storage_migration_rejects_same_size_different_bytes_before_row_update(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        $legacyPath = 'screenshots/'.$computer->id.'/2026-09-03/b6.jpg';
        $tenantPath = 'organizations/'.$organization->id.'/screenshots/'.$computer->id.'/2026-09-03/b6.jpg';
        Storage::disk('local')->put($legacyPath, 'legacy');
        Storage::disk('local')->put($tenantPath, 'tamper');

        $shot = Screenshot::factory()->forComputer($computer)->create([
            'captured_at' => Carbon::parse('2026-09-03 10:00:00', 'UTC'),
            'filename' => 'b6.jpg',
            'path' => $legacyPath,
        ]);

        $this->artisan('treck:migrate-tenant-storage', [
            '--organization' => (string) $organization->id,
        ])->expectsOutput('target_exists=1')
            ->expectsOutput('verification_failures=1')
            ->assertSuccessful();

        $this->assertSame($legacyPath, $shot->fresh()->path);
        $this->assertSame('tamper', Storage::disk('local')->get($tenantPath));
    }

    private function legacyGlobalAdminWithMembership(Organization $organization): User
    {
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
            'role' => 'legacy-admin-without-scoped-role',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        return $user;
    }
}
