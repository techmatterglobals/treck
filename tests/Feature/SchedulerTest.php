<?php

namespace Tests\Feature;

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
}
