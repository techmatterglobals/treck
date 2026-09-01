<?php

namespace Tests\Feature\Presence;

use App\Enums\ComputerStatus;
use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\Computer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end: POST /api/agent/events -> store -> update presence -> broadcast (Phase 6).
 */
class PresenceProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        [, , $computer, $token] = $this->ownedAgentDevice();

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
        Event::fake([PresenceChanged::class]);
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'hb-1')->assertCreated();

        $this->assertDatabaseHas('computer_presence', [
            'computer_id' => $computer->id,
            'status' => PresenceStatus::Active->value,
        ]);
        Event::assertDispatched(PresenceChanged::class, fn ($e) => $e->presence->computer_id === $computer->id);
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
        Event::fake([PresenceChanged::class]);
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'dup')->assertCreated();
        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'dup')->assertOk(); // idempotent duplicate

        Event::assertDispatchedTimes(PresenceChanged::class, 1);
    }

    public function test_event_updates_legacy_computer_liveness_fields(): void
    {
        // A freshly registered computer starts offline with no last_seen_at.
        [$computer, $token] = $this->device();
        $computer->forceFill(['status' => ComputerStatus::Offline, 'last_seen_at' => null])->save();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => false], 'live-1')->assertCreated();

        $computer->refresh();
        $this->assertSame(ComputerStatus::Online, $computer->status, 'active heartbeat -> Online');
        $this->assertNotNull($computer->last_seen_at, 'last_seen_at must update on event ingest');
        $this->assertNotNull($computer->last_activity_at, 'active event advances last_activity_at');
    }

    public function test_idle_and_locked_events_map_to_computer_status(): void
    {
        [$computer, $token] = $this->device();

        $this->sendEvent($token, 'heartbeat', ['IsIdle' => true, 'IdleTimeSeconds' => 120], 'idle-1')->assertCreated();
        $this->assertSame(ComputerStatus::Idle, $computer->refresh()->status);

        $this->sendEvent($token, 'session', ['Type' => 'Lock'], 'lock-1')->assertCreated();
        $this->assertSame(ComputerStatus::Locked, $computer->refresh()->status);
        $this->assertNotNull($computer->last_seen_at);
    }
}
