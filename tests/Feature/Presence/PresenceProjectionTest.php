<?php

namespace Tests\Feature\Presence;

use App\Enums\PresenceStatus;
use App\Events\PresenceUpdated;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end: POST /api/agent/events -> store -> update presence -> broadcast (M7).
 */
class PresenceProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        return [$computer, $token];
    }

    private function sendEvent(string $token, string $kind, array $payload, string $key): TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/events', [
            'kind' => $kind,
            'idempotency_key' => $key,
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode($payload),
        ]);
    }

    public function test_heartbeat_event_updates_presence_and_broadcasts(): void
    {
        Event::fake([PresenceUpdated::class]);
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'hb-1')->assertCreated();

        $this->assertDatabaseHas('computer_presence', [
            'computer_id' => $computer->id,
            'status' => PresenceStatus::Active->value,
        ]);
        Event::assertDispatched(PresenceUpdated::class, fn ($e) => $e->presence->computer_id === $computer->id);
    }

    public function test_idle_heartbeat_projects_idle(): void
    {
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => true, 'IdleTimeSeconds' => 600], 'hb-idle')->assertCreated();

        $this->assertDatabaseHas('computer_presence', [
            'computer_id' => $computer->id,
            'status' => PresenceStatus::Idle->value,
            'idle_seconds' => 600,
        ]);
    }

    public function test_session_lock_projects_locked(): void
    {
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'session', ['Type' => 'Lock'], 'sess-lock')->assertCreated();

        $this->assertDatabaseHas('computer_presence', [
            'computer_id' => $computer->id,
            'status' => PresenceStatus::Locked->value,
        ]);
    }

    public function test_duplicate_event_does_not_rebroadcast(): void
    {
        Event::fake([PresenceUpdated::class]);
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'dup')->assertCreated();
        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'dup')->assertOk(); // idempotent duplicate

        Event::assertDispatchedTimes(PresenceUpdated::class, 1);
    }
}
