<?php

namespace Tests\Feature\Agent;

use App\Models\ActivityLog;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function pairedComputer(Employee $employee): Computer
    {
        return Computer::factory()->create([
            'employee_id' => $employee->id,
            'paired_at' => now(),
        ]);
    }

    /** SEC-1: a device token cannot open a session for a different employee. */
    public function test_agent_cannot_open_session_for_another_employee(): void
    {
        $owner = Employee::factory()->create();
        $other = Employee::factory()->create();

        $computer = $this->pairedComputer($owner);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/agent/login', [
            'employee_id' => $other->id,        // spoof attempt — must be ignored
            'computer_name' => 'ATTACKER-PC',
        ]);

        $response->assertCreated();
        $sessionId = $response->json('data.session_id');

        // The session belongs to the computer's real owner, not the spoofed id.
        $this->assertSame($owner->id, ActivityLog::find($sessionId)->employee_id);
        $this->assertDatabaseMissing('activity_logs', [
            'id' => $sessionId,
            'employee_id' => $other->id,
        ]);
    }

    public function test_unpaired_computer_cannot_open_session(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null, 'paired_at' => null]);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        $this->withToken($token)->postJson('/api/agent/login', [])
            ->assertStatus(409);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')->postJson('/api/agent/login', [])
            ->assertUnauthorized();
    }

    public function test_token_without_agent_ability_is_forbidden(): void
    {
        $computer = $this->pairedComputer(Employee::factory()->create());
        $token = $computer->createToken('agent', ['something:else'])->plainTextToken;

        $this->withToken($token)->postJson('/api/agent/login', [])
            ->assertForbidden();
    }
}
