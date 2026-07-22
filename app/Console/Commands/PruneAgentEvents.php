<?php

namespace App\Console\Commands;

use App\Models\AgentEvent;
use Illuminate\Console\Command;

/**
 * Enforces the raw-event retention policy: deletes `agent_events` older than
 * `treck.retention.raw_heartbeat_days`. These rows are the durable receipt of
 * events already projected into presence, activity, application-usage and
 * screenshot tables, so once past the window they are safe to remove. The
 * projected domain tables and aggregate rollups are unaffected.
 *
 * Runs in id-ordered chunks so memory stays flat on very large tables. A
 * retention of 0 disables pruning.
 *
 * Schedule it daily (see routes/console.php):
 *
 *   Schedule::command('treck:prune-events')->dailyAt('01:15');
 */
class PruneAgentEvents extends Command
{
    protected $signature = 'treck:prune-events {--days= : Override the retention window in days}';

    protected $description = 'Delete raw agent events older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('treck.retention.raw_heartbeat_days', 90));

        if ($days <= 0) {
            $this->info('Raw-event retention is disabled (days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        AgentEvent::where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($events) use (&$deleted) {
                $ids = $events->modelKeys();
                $deleted += AgentEvent::whereKey($ids)->delete();
            });

        $this->info("Pruned {$deleted} agent event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
