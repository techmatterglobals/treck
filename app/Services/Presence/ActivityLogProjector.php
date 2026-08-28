<?php

namespace App\Services\Presence;

use App\Console\Commands\BackfillActivityLogs;
use App\Enums\AgentEventKind;
use App\Enums\ComputerStatus;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Materializes the per-day {@see ActivityLog} row (active/idle seconds,
 * login/logout span) that the reporting, attendance and productivity layers
 * read from.
 *
 * The current agent reports everything through /api/agent/events (heartbeats),
 * and no longer calls the legacy /login,/activity,/logout endpoints that used
 * to create activity_logs — so without this projector the whole reporting layer
 * is empty. Here each heartbeat rebuilds its (computer, employee, work_date)
 * row by summing that local day's heartbeats from `agent_events`.
 *
 * Recompute (not increment) is deliberate: it is idempotent, drift-free, and
 * lets the ingestion hook and the {@see BackfillActivityLogs}
 * command share the exact same logic (so a backfill and live traffic can never
 * double-count).
 */
class ActivityLogProjector
{
    /** Project one ingested event into its day's activity_log (heartbeat-driven). */
    public function project(AgentEvent $event): void
    {
        if ($event->employee_id === null || $event->occurred_at === null) {
            return;
        }

        // work_date is the LOCAL calendar day of the event (occurred_at is UTC).
        $localDate = $event->occurred_at->copy()
            ->timezone(config('app.timezone'))
            ->toDateString();

        $this->rebuildDay($event->computer_id, $event->employee_id, $localDate);
    }

    /**
     * Recompute the activity_log row for one (computer, employee, local day)
     * from the heartbeats stored in `agent_events`. Idempotent — safe to call
     * repeatedly (live ingestion) or in bulk (backfill).
     */
    public function rebuildDay(int $computerId, ?int $employeeId, string $localDate): void
    {
        if ($employeeId === null) {
            return;
        }

        $tz = config('app.timezone');

        // Convert the local day to a UTC [start, end) window; occurred_at is
        // stored as UTC wall-clock digits, so we compare against UTC bounds.
        $startUtc = Carbon::parse($localDate.' 00:00:00', $tz)->utc();
        $endUtc = $startUtc->copy()->addDay();

        $agg = DB::table('agent_events')
            ->where('computer_id', $computerId)
            ->where('employee_id', $employeeId)
            ->where('kind', AgentEventKind::Heartbeat->value)
            ->where('occurred_at', '>=', $startUtc->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->selectRaw("
                COALESCE(SUM(json_extract(payload, '$.ActiveSeconds')), 0) as active,
                COALESCE(SUM(json_extract(payload, '$.IdleSeconds')), 0) as idle,
                MIN(occurred_at) as first_at,
                MAX(occurred_at) as last_at,
                COUNT(*) as beats
            ")
            ->first();

        // No heartbeats for this pair on this day → nothing to record.
        if (! $agg || (int) $agg->beats === 0) {
            return;
        }

        // login/logout are stored in the app timezone (the 'datetime' cast), so
        // convert the UTC extremes back to local before persisting.
        $login = Carbon::parse($agg->first_at, 'UTC')->timezone($tz);
        $logout = Carbon::parse($agg->last_at, 'UTC')->timezone($tz);

        $values = [
            'login_at' => $login,
            'logout_at' => $logout,
            'active_seconds' => (int) $agg->active,
            'idle_seconds' => (int) $agg->idle,
            'status' => ComputerStatus::Online,
        ];

        // Match on the date part (the 'date' cast stores "Y-m-d 00:00:00", so a
        // raw string equality in updateOrCreate would miss and duplicate rows).
        $existing = ActivityLog::query()
            ->where('computer_id', $computerId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $localDate)
            ->first();

        if ($existing !== null) {
            $existing->update($values);

            return;
        }

        ActivityLog::create($values + [
            'computer_id' => $computerId,
            'employee_id' => $employeeId,
            'work_date' => $localDate,
        ]);
    }
}
