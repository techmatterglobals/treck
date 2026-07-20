<?php

namespace App\Console\Commands;

use App\Services\Presence\PresenceBroadcaster;
use App\Services\Presence\PresenceService;
use Illuminate\Console\Command;

/**
 * Transitions computers that have gone quiet (no agent contact within the
 * configured timeout) to Offline in the materialized presence table, and
 * broadcasts each change so the dashboard reflects it live (Phase 6).
 *
 * This is the only path that produces the "missing heartbeat -> Offline"
 * transition, since absence of events cannot trigger the event-driven projector.
 * Schedule it every minute (see routes/console.php):
 *
 *   Schedule::command('treck:presence-sweep')->everyMinute();
 */
class SweepPresenceOffline extends Command
{
    protected $signature = 'treck:presence-sweep';

    protected $description = 'Mark quiet computers offline in the presence table and broadcast the change';

    public function handle(PresenceService $presence, PresenceBroadcaster $broadcaster): int
    {
        $changed = $presence->sweepOffline();

        $changed->each(fn ($row) => $broadcaster->changed($row));

        $this->info("Swept {$changed->count()} computer(s) to offline.");

        return self::SUCCESS;
    }
}
