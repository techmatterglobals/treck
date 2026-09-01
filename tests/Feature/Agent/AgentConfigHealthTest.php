<?php

namespace Tests\Feature\Agent;

use App\Models\AgentHealthReport;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\Agent\AgentEnrollmentCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AgentConfigHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_uses_enrollment_secret_payload(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['employee_code' => 'EMP-SEC']);
        $secret = $this->enrollmentSecretFor($organization);

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => 'wrong',
            'device_uuid' => 'device-1',
            'employee_code' => $employee->employee_code,
        ])->assertForbidden();

        $this->postJson('/api/agent/register', [
            'enrollment_secret' => $secret,
            'device_uuid' => 'device-1',
            'employee_code' => $employee->employee_code,
            'computer_name' => 'OPS-PC',
            'agent_version' => '1.0.0',
        ])->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_registration_upsert_keeps_one_live_device_token(): void
    {
        $organization = Organization::factory()->create();
        $employee = Employee::factory()->forOrganization($organization)->create(['employee_code' => 'EMP-SEC']);
        $secret = $this->enrollmentSecretFor($organization, maxUses: 2);
        $payload = [
            'enrollment_secret' => $secret,
            'device_uuid' => 'device-1',
            'employee_code' => $employee->employee_code,
        ];

        $this->postJson('/api/agent/register', $payload)->assertCreated();
        $this->postJson('/api/agent/register', $payload)->assertCreated();

        $computer = Computer::where('device_uuid', 'device-1')->firstOrFail();
        $this->assertSame(1, PersonalAccessToken::whereMorphedTo('tokenable', $computer)->count());
    }

    public function test_agent_config_requires_agent_token_and_returns_revisioned_policy(): void
    {
        config(['treck.agent.policy.revision' => 'rev-2026-08-29']);
        [, , $computer, $token] = $this->ownedAgentDevice();

        $this->getJson('/api/agent/config')->assertUnauthorized();

        $this->withToken($token)->getJson('/api/agent/config')
            ->assertOk()
            ->assertJsonPath('data.computer_id', $computer->id)
            ->assertJsonPath('data.revision', 'rev-2026-08-29')
            ->assertJsonStructure(['data' => ['policy' => ['activity', 'screenshots', 'downloads']]]);
    }

    public function test_agent_health_upserts_for_authenticated_computer(): void
    {
        [, , $computer, $token] = $this->ownedAgentDevice();
        $computer->forceFill(['agent_version' => '0.9.0'])->save();

        $this->postJson('/api/agent/health', $this->healthPayload())->assertUnauthorized();

        $wrongAbility = $computer->createToken('agent', ['something:else'])->plainTextToken;
        $this->withToken($wrongAbility)->postJson('/api/agent/health', $this->healthPayload())->assertForbidden();

        $payload = $this->healthPayload(['pending_event_count' => 5]);
        $this->withToken($token)->postJson('/api/agent/health', $payload)
            ->assertOk()
            ->assertJsonPath('data.computer_id', $computer->id);

        $this->withToken($token)->postJson('/api/agent/health', $this->healthPayload(['pending_event_count' => 2]))
            ->assertOk();

        $this->assertDatabaseCount('agent_health_reports', 1);
        $this->assertSame(2, AgentHealthReport::firstOrFail()->pending_event_count);
        $this->assertSame('1.0.0', $computer->refresh()->agent_version);
    }

    private function enrollmentSecretFor(Organization $organization, int $maxUses = 1): string
    {
        return app(AgentEnrollmentCredentialService::class)->create(
            organization: $organization,
            name: 'Test enrollment',
            maxUses: $maxUses,
        )['secret'];
    }

    /** @param array<string,mixed> $overrides */
    private function healthPayload(array $overrides = []): array
    {
        return array_merge([
            'agent_version' => '1.0.0',
            'config_revision' => 'rev-a',
            'pending_event_count' => 0,
            'helper_running' => true,
            'helper_session_id' => 1,
            'service_started_at' => now()->subHour()->toIso8601String(),
            'last_capture_at' => now()->subMinute()->toIso8601String(),
            'last_successful_sync_at' => now()->subMinute()->toIso8601String(),
            'last_error_category' => null,
            'report_time' => now()->toIso8601String(),
        ], $overrides);
    }
}
