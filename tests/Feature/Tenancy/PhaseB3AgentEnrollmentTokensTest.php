<?php

namespace Tests\Feature\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\PlatformRole;
use App\Models\AgentEnrollmentCredential;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Agent\AgentEnrollmentCredentialService;
use App\Services\Agent\AgentSecurityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhaseB3AgentEnrollmentTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_b3_schema_adds_credentials_and_token_organization_ownership(): void
    {
        $this->assertTrue(Schema::hasTable('agent_enrollment_credentials'));
        $this->assertTrue(Schema::hasColumn('agent_enrollment_credentials', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('agent_enrollment_credentials', 'secret_hash'));
        $this->assertTrue(Schema::hasColumn('agent_enrollment_credentials', 'revoked_at'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'organization_id'));
    }

    public function test_enrollment_credential_is_hashed_and_plaintext_is_shown_once(): void
    {
        $organization = Organization::factory()->create();
        $created = $this->createCredential($organization);
        $credential = $created['credential']->refresh();
        $secret = $created['secret'];

        $this->assertStringStartsWith(AgentEnrollmentCredentialService::PREFIX.'_', $secret);
        $this->assertNotSame($secret, $credential->secret_hash);
        $parsedSecret = app(AgentEnrollmentCredentialService::class)->parse($secret);
        $this->assertNotNull($parsedSecret);
        [, $secretValue] = $parsedSecret;
        $this->assertTrue(Hash::check($secretValue, $credential->secret_hash));
        $this->assertArrayNotHasKey('secret_hash', $credential->toArray());

        $this->artisan('treck:agent-enrollment-list', ['--organization' => (string) $organization->id])
            ->doesntExpectOutputToContain($secret)
            ->doesntExpectOutputToContain($credential->secret_hash)
            ->assertSuccessful();
    }

    public function test_registration_is_scoped_to_enrollment_credential_organization_and_mints_owned_token(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['employee_code' => 'B3-EMP-1']);
        $secret = $this->createCredential($organization)['secret'];

        $response = $this->postJson('/api/agent/register', [
            'enrollment_secret' => $secret,
            'organization_id' => Organization::factory()->create()->id,
            'device_uuid' => 'b3-device-1',
            'employee_code' => $employee->employee_code,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_id');

        $response = $this->postJson('/api/agent/register', [
            'enrollment_secret' => $secret,
            'device_uuid' => 'b3-device-1',
            'employee_code' => $employee->employee_code,
            'computer_name' => 'B3-PC',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonStructure(['data' => ['computer_id', 'employee_id', 'token', 'token_type']]);

        $computer = Computer::where('device_uuid', 'b3-device-1')->firstOrFail();
        $token = PersonalAccessToken::whereMorphedTo('tokenable', $computer)->firstOrFail();

        $this->assertSame($organization->id, $computer->organization_id);
        $this->assertSame($organization->id, $token->organization_id);
        $this->assertSame(1, AgentEnrollmentCredential::firstOrFail()->uses_count);
        $this->assertFalse(Role::query()->where('name', PlatformRole::SuperAdmin->value)->exists());
    }

    public function test_one_use_credentials_cannot_be_reused(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['employee_code' => 'B3-EMP-2']);
        $secret = $this->createCredential($organization, maxUses: 1)['secret'];
        $payload = [
            'enrollment_secret' => $secret,
            'device_uuid' => 'b3-device-reuse',
            'employee_code' => $employee->employee_code,
        ];

        $this->postJson('/api/agent/register', $payload)->assertCreated();
        $this->postJson('/api/agent/register', $payload)->assertForbidden();
    }

    public function test_foreign_employee_and_foreign_device_uuid_are_rejected_without_consuming_credential(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $foreignEmployee = Employee::factory()->forOrganization($second)->create(['employee_code' => 'B3-FOREIGN']);
        Computer::factory()->forEmployee($foreignEmployee)->create(['device_uuid' => 'b3-foreign-device']);
        $created = $this->createCredential($first, maxUses: 2);

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => $created['secret'],
            'device_uuid' => 'b3-new-device',
            'employee_code' => $foreignEmployee->employee_code,
        ])->assertStatus(422)->assertJsonValidationErrors('employee_code');

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => $created['secret'],
            'device_uuid' => 'b3-foreign-device',
            'employee_code' => Employee::factory()->forOrganization($first)->create(['employee_code' => 'B3-OWN'])->employee_code,
        ])->assertStatus(422)->assertJsonValidationErrors('device_uuid');

        $this->assertSame(0, $created['credential']->refresh()->uses_count);
    }

    public function test_revoked_and_expired_credentials_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['employee_code' => 'B3-EMP-3']);
        $service = app(AgentEnrollmentCredentialService::class);
        $revoked = $this->createCredential($organization);
        $expired = $this->createCredential($organization, expiresAt: now()->subMinute());

        $service->revoke($revoked['credential']);

        foreach ([$revoked['secret'], $expired['secret']] as $secret) {
            $this->postJson('/api/agent/register', [
                'enrollment_secret' => $secret,
                'device_uuid' => 'b3-'.uniqid(),
                'employee_code' => $employee->employee_code,
            ])->assertForbidden();
        }
    }

    public function test_agent_token_middleware_enforces_token_and_computer_organization_match(): void
    {
        [$first, , $computer] = $this->ownedAgentDevice();
        $second = Organization::factory()->create();
        $token = $computer->createToken('agent', ['agent:report']);
        $token->accessToken->forceFill(['organization_id' => $second->id])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/agent/config')->assertUnauthorized();

        $legacyToken = $computer->createToken('agent-legacy', ['agent:report']);
        $legacyToken->accessToken->forceFill(['organization_id' => null])->save();

        config(['treck.agent.legacy_token_compatibility' => true]);
        $this->withToken($legacyToken->plainTextToken)->getJson('/api/agent/config')
            ->assertOk()
            ->assertJsonPath('data.policy.organization_id', (string) $first->id);

        config(['treck.agent.legacy_token_compatibility' => false]);
        $this->withToken($legacyToken->plainTextToken)->getJson('/api/agent/config')->assertUnauthorized();
    }

    public function test_agent_employee_mapping_ignores_cross_organization_employee_ids(): void
    {
        [$first, , $computer, $token] = $this->ownedAgentDevice();
        $foreignEmployee = Employee::factory()->forOrganization(Organization::factory()->create())->create();
        $computer->computerUsers()->create([
            'windows_username' => 'foreign.user',
            'employee_id' => $foreignEmployee->id,
            'is_active' => true,
        ]);

        $this->withToken($token)->postJson('/api/agent/events', [
            'kind' => 'heartbeat',
            'idempotency_key' => 'b3-cross-map',
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode(['SourceUser' => 'foreign.user', 'ActiveSeconds' => 30], JSON_THROW_ON_ERROR),
        ])->assertCreated();

        $this->assertDatabaseHas('agent_events', [
            'idempotency_key' => 'b3-cross-map',
            'organization_id' => $first->id,
            'employee_id' => null,
        ]);
    }

    public function test_agent_token_backfill_supports_dry_run_real_execution_and_verify(): void
    {
        [$organization, , $computer] = $this->ownedAgentDevice();
        $token = $computer->createToken('agent-backfill', ['agent:report']);
        $token->accessToken->forceFill(['organization_id' => null])->save();

        $this->artisan('treck:backfill-agent-token-ownership', [
            '--organization' => (string) $organization->id,
            '--dry-run' => true,
        ])->expectsOutput('platform_super_admin_assignments=0')->assertSuccessful();

        $this->assertNull($token->accessToken->refresh()->organization_id);

        $this->artisan('treck:backfill-agent-token-ownership', [
            '--organization' => (string) $organization->id,
        ])->assertSuccessful();
        $this->assertSame($organization->id, $token->accessToken->refresh()->organization_id);

        $this->artisan('treck:backfill-agent-token-ownership', [
            '--organization' => (string) $organization->id,
            '--verify' => true,
        ])->assertSuccessful();
    }

    public function test_agent_token_backfill_reports_conflicts_without_overwriting_existing_ownership(): void
    {
        [$first, , $computer] = $this->ownedAgentDevice();
        $second = Organization::factory()->create();
        $token = $computer->createToken('agent-conflict', ['agent:report']);
        $token->accessToken->forceFill(['organization_id' => $second->id])->save();

        $this->artisan('treck:backfill-agent-token-ownership', [
            '--organization' => (string) $first->id,
            '--verify' => true,
        ])->expectsOutput('agent_tokens_conflicts=1')->assertFailed();

        $this->artisan('treck:backfill-agent-token-ownership', [
            '--organization' => (string) $first->id,
        ])->assertSuccessful();

        $this->assertSame($second->id, $token->accessToken->refresh()->organization_id);
    }

    public function test_enrollment_management_is_organization_scoped_and_fails_closed_without_scoped_role(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $admin = $this->actingAsOrganizationRole($first, OrganizationRole::Admin);
        $firstCredential = $this->createCredential($first)['credential'];
        $secondCredential = $this->createCredential($second)['credential'];

        $this->actingAs($admin)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get(route('agent-enrollment-credentials.index'))
            ->assertOk()
            ->assertSee($firstCredential->public_id)
            ->assertDontSee($secondCredential->public_id)
            ->assertDontSee($firstCredential->secret_hash);

        $this->post(route('agent-enrollment-credentials.revoke', $secondCredential))->assertNotFound();

        $legacyAdmin = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $legacyRole = Role::query()->firstOrCreate(['organization_id' => null, 'name' => 'admin', 'guard_name' => 'web']);
        DB::table(config('permission.table_names.model_has_roles'))->insert([
            'role_id' => $legacyRole->id,
            'model_type' => $legacyAdmin->getMorphClass(),
            'model_id' => $legacyAdmin->id,
            'organization_id' => null,
        ]);
        OrganizationMembership::create([
            'organization_id' => $first->id,
            'user_id' => $legacyAdmin->id,
            'status' => MembershipStatus::Active,
            'role' => 'legacy-admin-without-scoped-role',
            'is_owner' => false,
            'joined_at' => now(),
        ]);

        $this->actingAs($legacyAdmin)
            ->withSession([CurrentOrganization::SESSION_KEY => $first->id])
            ->get(route('agent-enrollment-credentials.index'))
            ->assertForbidden();
    }

    public function test_agent_security_logging_redacts_sensitive_context(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'agent_security_event'
                && $context['secret_value'] === '[redacted]'
                && $context['authorization_header'] === '[redacted]'
                && $context['safe_context'] === 'visible';
        });

        app(AgentSecurityLogger::class)->event('test_event', context: [
            'secret_value' => 'treck_enroll_abc',
            'authorization_header' => 'Bearer token',
            'safe_context' => 'visible',
        ]);
    }

    /**
     * @return array{credential:AgentEnrollmentCredential,secret:string}
     */
    private function createCredential(
        Organization $organization,
        int $maxUses = 1,
        ?Carbon $expiresAt = null,
    ): array {
        return app(AgentEnrollmentCredentialService::class)->create(
            organization: $organization,
            name: 'Test enrollment',
            expiresAt: $expiresAt,
            maxUses: $maxUses,
        );
    }
}
