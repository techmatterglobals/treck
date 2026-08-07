<?php

namespace App\Console\Commands;

use App\Services\Device\DeviceStatusService;
use Illuminate\Console\Command;

/**
 * Marks computers whose agent has gone quiet as offline and closes their
 * abandoned sessions. Schedule it to run every minute (see routes/console.php):
 *
 *   Schedule::command('treck:reconcile-sessions')->everyMinute();
 */
class ReconcileStaleSessions extends Command
{
    protected $signature = 'treck:reconcile-sessions';

    protected $description = 'Mark stale computers offline and close abandoned sessions';

    public function handle(DeviceStatusService $status): int
    {
        $count = $status->reconcileStale();

        $this->info("Reconciled {$count} stale computer(s).");

        return self::SUCCESS;
    }
}
