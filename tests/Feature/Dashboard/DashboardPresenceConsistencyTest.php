<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Presence\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard KPIs and the presence board must agree - both read the single
 * presence source (computer_presence). Also covers today's active/idle time
 * aggregation from heartbeat events.
 */
class DashboardPresenceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function computerFor(PresenceStatus $status): Computer
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);
        ComputerPresence::factory()->status($status)->for($computer)->create();

        return $computer;
    }

    public function test_dashboard_online_count_matches_presence_board(): void
    {
        $this->computerFor(PresenceStatus::Active);
        $this->computerFor(PresenceStatus::Idle);
        $this->computerFor(PresenceStatus::Locked);
        $this->computerFor(PresenceStatus::LoggedOut);
        $this->computerFor(PresenceStatus::Offline);

        $presence = app(PresenceService::class);
        $metrics = app(DashboardMetricsService::class);

        // Presence board: Active + Idle + Locked = 3 online computers.
        $this->assertSame(3, $presence->summary()['online']);
        // Dashboard: the 3 owning employees are online - same source, same number.
        $this->assertSame(3, $metrics->onlineEmployees());
    }

    public function test_online_counts_idle_and_locked_not_just_active(): void
    {
        $this->computerFor(PresenceStatus::Idle);
        $this->computerFor(PresenceStatus::Locked);

        $this->assertSame(2, app(DashboardMetricsService::class)->onlineEmployees());
    }

    public function test_employee_status_row_uses_presence_status_and_last_activity(): void
    {
        $computer = $this->computerFor(PresenceStatus::Idle);
        $employeeId = $computer->employee_id;

        $rows = app(DashboardMetricsService::class)->employeeStatusRows();
        $row = $rows->firstWhere('id', $employeeId);

        $this->assertSame(PresenceStatus::Idle, $row['status']);
        $this->assertNotNull($row['last_activity_at']);
    }

    public function test_active_and_idle_time_summed_from_todays_heartbeats(): void
    {
        $computer = $this->computerFor(PresenceStatus::Active);
        $employeeId = $computer->employee_id;

        // Two heartbeats today: 50+10 and 40+20 -> active 90, idle 30.
        foreach ([[50, 10], [40, 20]] as [$active, $idle]) {
            AgentEvent::create([
                'computer_id' => $computer->id,
                'employee_id' => $employeeId,
                'kind' => AgentEventKind::Heartbeat,
                'idempotency_key' => uniqid('k', true),
                'payload' => ['IsIdle' => false, 'ActiveSeconds' => $active, 'IdleSeconds' => $idle],
                'occurred_at' => now(),
                'received_at' => now(),
            ]);
        }
        // A heartbeat from yesterday must NOT count.
        AgentEvent::create([
            'computer_id' => $computer->id,
            'employee_id' => $employeeId,
            'kind' => AgentEventKind::Heartbeat,
            'idempotency_key' => uniqid('k', true),
            'payload' => ['IsIdle' => false, 'ActiveSeconds' => 999, 'IdleSeconds' => 999],
            'occurred_at' => now()->subDay(),
            'received_at' => now()->subDay(),
        ]);

        $row = app(DashboardMetricsService::class)->employeeStatusRows()->firstWhere('id', $employeeId);

        $this->assertSame(90, $row['active_seconds']);
        $this->assertSame(30, $row['idle_seconds']);
    }

    public function test_employee_with_no_presence_is_offline(): void
    {
        $employee = Employee::factory()->create();
        Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]); // no presence row

        $row = app(DashboardMetricsService::class)->employeeStatusRows()->firstWhere('id', $employee->id);

        $this->assertSame(PresenceStatus::Offline, $row['status']);
    }
}
