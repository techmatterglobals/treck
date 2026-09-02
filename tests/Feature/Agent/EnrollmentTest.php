<?php

namespace Tests\Feature\Agent;

use App\Models\AgentEnrollmentCode;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Agent\AgentEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 1 — computer-scoped enrollment via one-time codes (installer flow),
 * alongside the unchanged legacy provisioning-key /register endpoint.
 */
class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function newCode(array $overrides = []): string
    {
        /** @var AgentEnrollmentService $svc */
        $svc = app(AgentEnrollmentService::class);

        return $svc->generate(
            label: $overrides['label'] ?? 'Test PC',
            expiresAt: $overrides['expiresAt'] ?? now()->addDay(),
            maxUses: $overrides['maxUses'] ?? 1,
        )['code'];
    }

    private function enroll(string $code, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/agent/enroll', array_merge([
            'code' => $code,
            'device_uuid' => 'dev-'.uniqid(),
            'computer_name' => 'PC-TEST',
            'os' => 'Windows 11',
            'agent_version' => '1.0.0',
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_valid_code_enrolls_a_computer_without_an_employee_and_returns_a_token(): void
    {
        $code = $this->newCode();

        $response = $this->enroll($code, ['device_uuid' => 'dev-abc']);

        $response->assertCreated()
            ->assertJsonPath('data.device_id', 'dev-abc')
            ->assertJsonStructure(['data' => ['computer_id', 'device_id', 'token', 'token_type']]);

        $computer = Computer::where('device_uuid', 'dev-abc')->firstOrFail();
        $this->assertNull($computer->employee_id, 'enrollment is computer-scoped, not employee-bound');
        $this->assertSame('PC-TEST', $computer->hostname);
        $this->assertSame(1, $computer->tokens()->count());

        // The code is now consumed.
        $this->assertSame(1, AgentEnrollmentCode::first()->uses);
    }

    public function test_code_is_single_use_by_default(): void
    {
        $code = $this->newCode(['maxUses' => 1]);

        $this->enroll($code, ['device_uuid' => 'dev-1'])->assertCreated();
        $this->enroll($code, ['device_uuid' => 'dev-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, Computer::count());
    }

    public function test_multi_use_code_enrolls_up_to_its_limit(): void
    {
        $code = $this->newCode(['maxUses' => 2]);

        $this->enroll($code, ['device_uuid' => 'dev-1'])->assertCreated();
        $this->enroll($code, ['device_uuid' => 'dev-2'])->assertCreated();
        $this->enroll($code, ['device_uuid' => 'dev-3'])->assertStatus(422);

        $this->assertSame(2, Computer::count());
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->newCode(['expiresAt' => now()->subMinute()]);

        $this->enroll($code)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(0, Computer::count());
    }

    public function test_revoked_code_is_rejected(): void
    {
        /** @var AgentEnrollmentService $svc */
        $svc = app(AgentEnrollmentService::class);
        ['code' => $code, 'model' => $model] = $svc->generate(expiresAt: now()->addDay());
        $svc->revoke($model);

        $this->enroll($code)->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->enroll('TRK-ZZZZ-ZZZZ-ZZZZ')->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_plaintext_code_is_never_stored(): void
    {
        $code = $this->newCode();

        $row = AgentEnrollmentCode::first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(hash('sha256', $code), $row->code_hash);
        // No column anywhere holds the plaintext.
        $this->assertNull(AgentEnrollmentCode::where('code_hash', $code)->first());
    }

    public function test_re_enrolling_same_device_preserves_existing_employee_link(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['device_uuid' => 'dev-shared', 'employee_id' => $employee->id]);

        $this->enroll($this->newCode(), ['device_uuid' => 'dev-shared'])->assertCreated();

        $this->assertSame($employee->id, $computer->fresh()->employee_id);
        $this->assertSame(1, Computer::count());
    }

    public function test_legacy_register_endpoint_still_works(): void
    {
        // Backward compatibility: provisioning key + employee_code path unchanged.
        config(['treck.agent.provisioning_key' => 'legacy-key']);
        $employee = Employee::factory()->create();

        $this->postJson('/api/agent/register', [
            'provisioning_key' => 'legacy-key',
            'device_uuid' => 'legacy-dev',
            'employee_code' => $employee->employee_code,
            'computer_name' => 'PC-LEGACY',
            'os' => 'Windows 10',
            'agent_version' => '1.0.0',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['computer_id', 'employee_id', 'token']]);

        $this->assertSame($employee->id, Computer::where('device_uuid', 'legacy-dev')->first()->employee_id);
    }

    public function test_artisan_command_generates_and_revokes(): void
    {
        $this->artisan('treck:enroll-code', ['--label' => 'CLI PC', '--uses' => 1])
            ->assertSuccessful();

        $this->assertSame(1, AgentEnrollmentCode::count());

        $id = AgentEnrollmentCode::first()->id;
        $this->artisan('treck:enroll-code', ['--revoke' => $id])->assertSuccessful();

        $this->assertNotNull(AgentEnrollmentCode::find($id)->revoked_at);
    }
}
