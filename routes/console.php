<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| Driven by a single system cron entry:
|   * * * * * cd /path/to/treck && php artisan schedule:run >> /dev/null 2>&1
*/

// Mark stale computers offline + close abandoned sessions (doc 14).
Schedule::command('treck:reconcile-sessions')->everyMinute()->withoutOverlapping();

// Transition quiet computers to Offline in the real-time presence table (doc 25).
Schedule::command('treck:presence-sweep')->everyMinute()->withoutOverlapping();

// Keep today's attendance/productivity fresh (doc 18).
Schedule::command('treck:daily-rollup')->hourly();

// Finalize yesterday shortly after midnight.
Schedule::command('treck:daily-rollup '.now()->subDay()->toDateString())->dailyAt('00:30');

// Enforce the screenshot retention policy (Phase 8).
Schedule::command('treck:prune-screenshots')->dailyAt('01:00')->withoutOverlapping();

// Enforce the raw-event retention policy (agent_events; doc 30).
Schedule::command('treck:prune-events')->dailyAt('01:15')->withoutOverlapping();
