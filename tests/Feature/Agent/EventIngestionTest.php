<?php

namespace Tests\Feature\Agent;

use App\Enums\AgentEventKind;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M6 — server-side event ingestion (POST /api/agent/events).
 *
 * Covers device authentication, identity binding, idempotency/duplicate safety,
 * validation, and that both heartbeat and session events land verbatim.
 */
class EventIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function agentToken(Computer $computer, array $abilities = ['agent:report']): string
    {
        return $computer->createToken('agent', $abilities)->plainTextToken;
    }

    private function pairedComputer(?Employee $employee = null): Computer
    {
        $employee ??= Employee::factory()->create();

        return Computer::factory()->create([
            'employee_id' => $employee->id,
            'paired_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function heartbeatPayload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'heartbeat',
            'idempotency_key' => 'idem-'.uniqid(),
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode([
                'TimestampUtc' => now()->toIso8601String(),
                'ElapsedSeconds' => 60,
                'ActiveSeconds' => 50,
                'IdleSeconds' => 10,
                'IsIdle' => false,
            ]),
        ], $overrides);
    }

    public function test_stores_a_heartbeat_event_and_binds_it_to_the_device_owner(): void
    {
        $employee = Employee::factory()->create();
        $computer = $this->pairedComputer($employee);
        $body = $this->heartbeatPayload(['idempotency_key' => 'hb-1']);

        $response = $this->withToken($this->agentToken($computer))
            ->postJson('/api/agent/events', $body);

        $response->assertCreated()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.idempotency_key', 'hb-1');

        $this->assertDatabaseHas('agent_events', [
            'computer_id' => $computer->id,
            'employee_id' => $employee->id,
            'kind' => AgentEventKind::Heartbeat->value,
            'idempotency_key' => 'hb-1',
        ]);

        // Payload is decoded and stored as a queryable JSON document.
        $event = AgentEvent::where('idempotency_key', 'hb-1')->firstOrFail();
        $this->assertSame(60, $event->payload['ElapsedSeconds']);
        $this->assertNotNull($event->occurred_at);
        $this->assertNotNull($event->received_at);
    }

    public function test_stores_a_session_event(): void
    {
        $computer = $this->pairedComputer();
        $body = $this->heartbeatPayload([
            'kind' => 'session',
            'idempotency_key' => 'sess-1',
            'payload' => json_encode(['Type' => 'Logon', 'TimestampUtc' => now()->toIso8601String()]),
        ]);

        $this->withToken($this->agentToken($computer))
            ->postJson('/api/agent/events', $body)
            ->assertCreated();

        $this->assertDatabaseHas('agent_events', [
            'idempotency_key' => 'sess-1',
            'kind' => AgentEventKind::Session->value,
        ]);
    }

    public function test_duplicate_idempotency_key_is_stored_once_and_returns_success(): void
    {
        $computer = $this->pairedComputer();
        $body = $this->heartbeatPayload(['idempotency_key' => 'dup-1']);
        $token = $this->agentToken($computer);

        $first = $this->withToken($token)->postJson('/api/agent/events', $body);
        $first->assertCreated()->assertJsonPath('data.duplicate', false);

        // Re-submit the exact same event (agent retry after a lost ack).
        $second = $this->withToken($token)->postJson('/api/agent/events', $body);
        $second->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, AgentEvent::where('idempotency_key', 'dup-1')->count());
    }

    public function test_same_idempotency_key_on_different_devices_is_not_a_collision(): void
    {
        $a = $this->pairedComputer();
        $b = $this->pairedComputer();

        $this->withToken($this->agentToken($a))
            ->postJson('/api/agent/events', $this->heartbeatPayload(['idempotency_key' => 'shared']))
            ->assertCreated();

        // Different device, same key → distinct row (uniqueness is per device).
        $this->withToken($this->agentToken($b))
            ->postJson('/api/agent/events', $this->heartbeatPayload(['idempotency_key' => 'shared']))
            ->assertCreated();

        $this->assertSame(2, AgentEvent::where('idempotency_key', 'shared')->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/agent/events', $this->heartbeatPayload())
            ->assertUnauthorized();
    }

    public function test_token_without_agent_ability_is_forbidden(): void
    {
        $computer = $this->pairedComputer();

        $this->withToken($this->agentToken($computer, ['something:else']))
            ->postJson('/api/agent/events', $this->heartbeatPayload())
            ->assertForbidden();
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $computer = $this->pairedComputer();

        $this->withToken($this->agentToken($computer))
            ->postJson('/api/agent/events', $this->heartbeatPayload(['kind' => 'screenshot']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('kind');
    }

    public function test_missing_fields_are_rejected(): void
    {
        $computer = $this->pairedComputer();

        $this->withToken($this->agentToken($computer))
            ->postJson('/api/agent/events', ['kind' => 'heartbeat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key', 'created_at', 'payload']);
    }

    public function test_non_json_payload_is_rejected(): void
    {
        $computer = $this->pairedComputer();

        $this->withToken($this->agentToken($computer))
            ->postJson('/api/agent/events', $this->heartbeatPayload(['payload' => 'not-json']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payload');
    }
}
