<?php

namespace Tests\Unit\Presence;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Presence\PresenceProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for the presence projection rules (Phase 6). Exercises the projector
 * directly against stored agent events.
 */
class PresenceProjectorTest extends TestCase
{
    use RefreshDatabase;

    private PresenceProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(PresenceProjector::class);
    }

    private function computer(): Computer
    {
        return Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);
    }

    private function event(Computer $computer, AgentEventKind $kind, array $payload, ?string $at = null): AgentEvent
    {
        $when = $at ? Carbon::parse($at) : now();

        return AgentEvent::create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'kind' => $kind,
            'idempotency_key' => uniqid('k', true),
            'payload' => $payload,
            'occurred_at' => $when,
            'received_at' => $when,
        ]);
    }

    public function test_active_heartbeat_projects_active(): void
    {
        $computer = $this->computer();
        $presence = $this->projector->project(
            $this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => false, 'IdleTimeSeconds' => 0]),
        );

        $this->assertSame(PresenceStatus::Active, $presence->status);
        $this->assertSame(0, $presence->idle_seconds);
        $this->assertNotNull($presence->last_heartbeat_at);
        $this->assertNotNull($presence->last_activity_at);
    }

    public function test_idle_heartbeat_projects_idle_and_records_idle_seconds(): void
    {
        $computer = $this->computer();
        $presence = $this->projector->project(
            $this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => true, 'IdleTimeSeconds' => 420]),
        );

        $this->assertSame(PresenceStatus::Idle, $presence->status);
        $this->assertSame(420, $presence->idle_seconds);
    }

    public function test_idle_then_active_transitions_back_to_active(): void
    {
        $computer = $this->computer();
        $this->projector->project($this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => true, 'IdleTimeSeconds' => 300], '-2 minutes'));
        $presence = $this->projector->project($this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => false], '-1 minute'));

        $this->assertSame(PresenceStatus::Active, $presence->status);
        $this->assertSame(0, $presence->idle_seconds);
    }

    public function test_lock_and_unlock_transitions(): void
    {
        $computer = $this->computer();

        $locked = $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 'Lock'], '-2 minutes'));
        $this->assertSame(PresenceStatus::Locked, $locked->status);

        $unlocked = $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 'Unlock'], '-1 minute'));
        $this->assertSame(PresenceStatus::Active, $unlocked->status);
    }

    public function test_logon_sets_session_start_and_logoff_clears_it(): void
    {
        $computer = $this->computer();

        $on = $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 'Logon'], '-1 hour'));
        $this->assertSame(PresenceStatus::Active, $on->status);
        $this->assertNotNull($on->session_started_at);

        $off = $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 'Logoff'], '-1 minute'));
        $this->assertSame(PresenceStatus::LoggedOut, $off->status);
        $this->assertNull($off->session_started_at);
    }

    public function test_numeric_session_type_from_agent_enum_is_understood(): void
    {
        $computer = $this->computer();

        // SessionEventType ordinal 3 = Lock (default C# enum serialization).
        $presence = $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 3]));

        $this->assertSame(PresenceStatus::Locked, $presence->status);
    }

    public function test_out_of_order_event_does_not_overwrite_newer_state(): void
    {
        $computer = $this->computer();

        // Newer event first (Locked at -1 min), then an older heartbeat (-5 min).
        $this->projector->project($this->event($computer, AgentEventKind::Session, ['Type' => 'Lock'], '-1 minute'));
        $result = $this->projector->project($this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => false], '-5 minutes'));

        $this->assertNull($result, 'stale event should be ignored');
        $this->assertSame(PresenceStatus::Locked, $computer->presence()->first()->status);
    }

    public function test_presence_row_is_created_once_per_computer(): void
    {
        $computer = $this->computer();
        $this->projector->project($this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => false], '-2 minutes'));
        $this->projector->project($this->event($computer, AgentEventKind::Heartbeat, ['IsIdle' => true], '-1 minute'));

        $this->assertSame(1, $computer->presence()->count());
    }
}
