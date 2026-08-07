<?php

namespace Tests\Feature\Reports;

use App\DataObjects\ReportFilter;
use App\Enums\AgentEventKind;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Presence\ActivityLogProjector;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reporting layer reads from activity_logs, which the current
 * heartbeat-only agent no longer writes directly. These lock the behavior that
 * heartbeats ingested via /api/agent/events (and the backfill command)
 * materialize the per-day activity_log the reports aggregate.
 */
class ActivityLogProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function device(): array
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        return [$employee, $computer, $token];
    }

    public function test_ingested_heartbeats_populate_activity_logs_and_reports(): void
    {
        [$employee, $computer, $token] = $this->device();

        foreach ([[60, 0], [50, 10]] as [$active, $idle]) {
            $this->withToken($token)->postJson('/api/agent/events', [
                'kind' => 'heartbeat',
                'idempotency_key' => 'hb-'.uniqid(),
                'created_at' => now()->toIso8601String(),
                'payload' => json_encode(['IsIdle' => $idle > 0, 'ActiveSeconds' => $active, 'IdleSeconds' => $idle]),
            ])->assertCreated();
        }

        // One accumulated day-row with the summed active/idle.
        $log = ActivityLog::where('computer_id', $computer->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(110, (int) $log->active_seconds);
        $this->assertSame(10, (int) $log->idle_seconds);
        $this->assertSame(today()->toDateString(), $log->work_date->toDateString());
        $this->assertSame(1, ActivityLog::count(), 'heartbeats of one day accumulate into a single row');

        // And the Reports read model now returns a row for the range.
        $filter = ReportFilter::fromArray([
            'period' => 'daily',
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]);
        $rows = app(ReportService::class)->build($filter);

        $this->assertCount(1, $rows);
        $this->assertSame(110, $rows->first()['active_seconds']);
    }

    public function test_duplicate_heartbeat_does_not_double_count(): void
    {
        [, $computer, $token] = $this->device();

        $payload = [
            'kind' => 'heartbeat',
            'idempotency_key' => 'hb-dup',
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode(['IsIdle' => false, 'ActiveSeconds' => 60, 'IdleSeconds' => 0]),
        ];

        $this->withToken($token)->postJson('/api/agent/events', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/agent/events', $payload)->assertOk(); // duplicate

        $this->assertSame(60, (int) ActivityLog::firstOrFail()->active_seconds);
    }

    public function test_backfill_rebuilds_activity_logs_from_existing_events(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]);

        // Pre-existing heartbeats written straight to agent_events (no projector),
        // as if ingested before this feature existed → no activity_logs yet.
        foreach ([90, 30] as $active) {
            AgentEvent::create([
                'computer_id' => $computer->id,
                'employee_id' => $employee->id,
                'kind' => AgentEventKind::Heartbeat,
                'idempotency_key' => 'seed-'.uniqid(),
                'payload' => ['ActiveSeconds' => $active, 'IdleSeconds' => 5, 'IsIdle' => false],
                'occurred_at' => now(),
                'received_at' => now(),
            ]);
        }

        $this->assertSame(0, ActivityLog::count());

        $this->artisan('treck:backfill-activity-logs', ['--from' => today()->toDateString(), '--to' => today()->toDateString()])
            ->assertSuccessful();

        $log = ActivityLog::firstOrFail();
        $this->assertSame(120, (int) $log->active_seconds);
        $this->assertSame(10, (int) $log->idle_seconds);
    }

    public function test_backfill_is_idempotent(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id, 'paired_at' => now()]);

        AgentEvent::create([
            'computer_id' => $computer->id,
            'employee_id' => $employee->id,
            'kind' => AgentEventKind::Heartbeat,
            'idempotency_key' => 'seed-1',
            'payload' => ['ActiveSeconds' => 60, 'IdleSeconds' => 0, 'IsIdle' => false],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $projector = app(ActivityLogProjector::class);
        $projector->rebuildDay($computer->id, $employee->id, today()->toDateString());
        $projector->rebuildDay($computer->id, $employee->id, today()->toDateString());

        $this->assertSame(1, ActivityLog::count());
        $this->assertSame(60, (int) ActivityLog::firstOrFail()->active_seconds);
    }
}
