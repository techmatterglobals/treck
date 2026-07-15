<?php

namespace App\Services\Activity;

use App\Enums\ComputerStatus;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Write-side of activity tracking. Applies an agent activity report to the open
 * session and refreshes the owning computer's live status, last-seen, and
 * last-activity timestamps — all atomically. Used by the agent API's
 * ActivityController so the accumulation rules live in one place.
 */
class ActivityTrackingService
{
    /**
     * Accumulate the active/idle deltas onto a session and update the computer.
     *
     * @param  int  $activeDelta  Seconds of real input since the last report.
     * @param  int  $idleDelta    Seconds idle since the last report.
     */
    public function record(
        ActivityLog $session,
        int $activeDelta,
        int $idleDelta,
        ComputerStatus $status = ComputerStatus::Online,
        ?Carbon $at = null,
    ): ActivityLog {
        $at ??= now();
        $activeDelta = max(0, $activeDelta);
        $idleDelta = max(0, $idleDelta);

        return DB::transaction(function () use ($session, $activeDelta, $idleDelta, $status, $at) {
            // Atomic counter accumulation; also refresh the session's status.
            $session->increment('active_seconds', $activeDelta, ['status' => $status->value]);
            if ($idleDelta > 0) {
                $session->increment('idle_seconds', $idleDelta);
            }

            // Update the owning computer's live state.
            $attributes = [
                'status' => $status,
                'last_seen_at' => $at,
            ];

            // Only bump last-activity when there was genuine input.
            if ($activeDelta > 0 && $status === ComputerStatus::Online) {
                $attributes['last_activity_at'] = $at;
            }

            $session->computer?->forceFill($attributes)->save();

            return $session->refresh();
        });
    }
}
