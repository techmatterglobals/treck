<?php

namespace Tests\Feature\Presence;

use App\Enums\AgentEventKind;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Presence\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Live Presence board's "Idle time" reflects today's accumulated idle
 * (the same metric the dashboard shows), not the current idle streak — which
 * is 0 whenever a machine is Active or Offline.
 */
class PresenceIdleTimeTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(Computer $computer, int $active, int $idle): void
    {
        AgentEvent::create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'kind' => AgentEventKind::Heartbeat,
            'idempotency_key' => 'hb-'.uniqid(),
            'payload' => ['ActiveSeconds' => $active, 'IdleSeconds' => $idle, 'IsIdle' => $idle > 0],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    public function test_presence_rows_show_todays_accumulated_idle_and_active(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]);

        // Two heartbeats today: 60+60 active, 40+50 idle → 120s active, 90s idle.
        $this->heartbeat($computer, 60, 40);
        $this->heartbeat($computer, 60, 50);

        $row = app(PresenceService::class)->rows()->firstWhere('computer_id', $computer->id);

        $this->assertSame(120, $row['active_seconds']);
        $this->assertSame(90, $row['idle_seconds']);
        $this->assertSame('0h 01m', $row['idle_label']);   // 90s → 1m
        $this->assertSame('0h 02m', $row['active_label']);  // 120s → 2m
    }

    public function test_idle_time_is_zero_without_heartbeats_today(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]);

        $row = app(PresenceService::class)->rows()->firstWhere('computer_id', $computer->id);

        $this->assertSame(0, $row['idle_seconds']);
        $this->assertSame('0h 00m', $row['idle_label']);
    }
}
