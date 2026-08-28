<?php

namespace Tests\Feature;

use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    /** The rollup/reconcile commands are registered on the scheduler. */
    public function test_treck_commands_are_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('treck:reconcile-sessions')
            ->expectsOutputToContain('treck:daily-rollup')
            ->expectsOutputToContain('treck:presence-sweep')
            ->expectsOutputToContain('treck:prune-screenshots')
            ->expectsOutputToContain('treck:prune-events')
            ->assertSuccessful();
    }

    /** The commands exist and execute without error. */
    public function test_reconcile_sessions_command_runs(): void
    {
        $this->artisan('treck:reconcile-sessions')->assertSuccessful();
    }

    public function test_presence_sweep_command_runs(): void
    {
        $this->artisan('treck:presence-sweep')->assertSuccessful();
    }

    public function test_daily_rollup_command_runs(): void
    {
        $this->artisan('treck:daily-rollup')->assertSuccessful();
    }

    public function test_prune_screenshots_command_runs(): void
    {
        $this->artisan('treck:prune-screenshots')->assertSuccessful();
    }

    public function test_prune_events_command_runs(): void
    {
        $this->artisan('treck:prune-events')->assertSuccessful();
    }

    /** Raw agent events past the retention window are removed; recent ones kept. */
    public function test_prune_events_deletes_only_events_past_the_window(): void
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
        ]);

        $old = AgentEvent::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'occurred_at' => now()->subDays(120),
        ]);
        $recent = AgentEvent::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'occurred_at' => now()->subDays(10),
        ]);

        $this->artisan('treck:prune-events', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('agent_events', ['id' => $old->id]);
        $this->assertDatabaseHas('agent_events', ['id' => $recent->id]);
    }

    public function test_prune_events_respects_disabled_retention(): void
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
        ]);
        $event = AgentEvent::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'occurred_at' => now()->subDays(400),
        ]);

        $this->artisan('treck:prune-events', ['--days' => 0])->assertSuccessful();

        $this->assertDatabaseHas('agent_events', ['id' => $event->id]);
    }
}
