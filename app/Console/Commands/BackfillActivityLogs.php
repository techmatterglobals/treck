<?php

namespace App\Console\Commands;

use App\Enums\AgentEventKind;
use App\Services\Presence\ActivityLogProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `activity_logs` from heartbeats already stored in `agent_events`.
 *
 * The ingestion projector keeps activity_logs current going forward; this
 * command reconstructs history (e.g. for events ingested before the projector
 * existed) so Reports / Attendance / Productivity show the full record. Safe to
 * re-run — the projector recomputes each day rather than incrementing.
 *
 *   php artisan treck:backfill-activity-logs                 # last 30 days
 *   php artisan treck:backfill-activity-logs --from=2026-07-01 --to=2026-08-07
 */
class BackfillActivityLogs extends Command
{
    protected $signature = 'treck:backfill-activity-logs {--from= : Start date (Y-m-d)} {--to= : End date (Y-m-d)}';

    protected $description = 'Rebuild activity_logs from stored heartbeat events (reports/attendance/productivity).';

    public function handle(ActivityLogProjector $projector): int
    {
        $tz = config('app.timezone');

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'), $tz)->startOfDay()
            : today()->subDays(30);
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'), $tz)->startOfDay()
            : today();

        if ($from->gt($to)) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $dayCount = 0;
        $rowCount = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $localDate = $day->toDateString();
            $startUtc = Carbon::parse($localDate.' 00:00:00', $tz)->utc();
            $endUtc = $startUtc->copy()->addDay();

            $pairs = DB::table('agent_events')
                ->where('kind', AgentEventKind::Heartbeat->value)
                ->whereNotNull('employee_id')
                ->where('occurred_at', '>=', $startUtc->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $endUtc->format('Y-m-d H:i:s'))
                ->distinct()
                ->get(['computer_id', 'employee_id']);

            foreach ($pairs as $pair) {
                $projector->rebuildDay((int) $pair->computer_id, (int) $pair->employee_id, $localDate);
                $rowCount++;
            }

            $dayCount++;
        }

        $this->info("Backfilled {$rowCount} activity-log day-row(s) across {$dayCount} day(s) "
            ."[{$from->toDateString()} .. {$to->toDateString()}].");

        return self::SUCCESS;
    }
}
