<?php

namespace App\Observers;

use App\Jobs\EvaluateNotificationsJob;
use App\Models\FileDownload;

/**
 * Feeds newly-recorded file downloads (Phase 12) into the notification engine
 * (Phase 9) via a queued evaluation job — additive, so projection is never
 * blocked and the alert rules (executable / archive / large / restricted) run
 * off the ingest path. Metadata only; no file contents are ever passed.
 */
class FileDownloadObserver
{
    public function created(FileDownload $download): void
    {
        EvaluateNotificationsJob::dispatch(
            source: 'download',
            computerId: $download->computer_id,
            employeeId: $download->employee_id,
            data: [
                'file_name' => $download->file_name,
                'file_extension' => $download->file_extension,
                'file_size' => (int) $download->file_size,
                'application_name' => $download->application_name,
            ],
            organizationId: $download->organization_id,
        );
    }
}
