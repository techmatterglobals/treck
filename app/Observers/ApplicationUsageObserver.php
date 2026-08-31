<?php

namespace App\Observers;

use App\Jobs\EvaluateNotificationsJob;
use App\Models\ApplicationUsage;

/**
 * Feeds completed application-usage rows (Phase 7) into the notification engine
 * (Phase 9) via a queued evaluation job — additive, so the Phase 7 projection is
 * untouched and never blocked. Only agent-projected sessions (those carrying a
 * session_id) are considered, to avoid re-notifying on legacy/imported rows.
 */
class ApplicationUsageObserver
{
    public function created(ApplicationUsage $usage): void
    {
        if (blank($usage->session_id)) {
            return;
        }

        EvaluateNotificationsJob::dispatch(
            source: 'app_usage',
            computerId: $usage->computer_id,
            employeeId: $usage->employee_id,
            data: [
                'application_name' => $usage->application_name,
                'executable' => $usage->executable,
                'duration_seconds' => (int) $usage->duration_seconds,
            ],
            organizationId: $usage->organization_id,
        );
    }
}
