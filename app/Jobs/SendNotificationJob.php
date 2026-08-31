<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one persisted notification through its channel, on the queue
 * (Phase 9). Keeping delivery async means notification work never blocks agent
 * sync, presence updates, application tracking or screenshot uploads. Retries
 * are handled by the queue worker; a permanently failed job marks the log failed.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $notificationLogId,
        public readonly ?int $organizationId = null,
    ) {}

    public function handle(NotificationDeliveryService $delivery): void
    {
        $log = NotificationLog::query()
            ->whereKey($this->notificationLogId)
            ->when($this->organizationId !== null, fn ($query) => $query->where('organization_id', $this->organizationId))
            ->first();

        if ($log === null || $log->status === 'delivered') {
            return; // deleted, or already delivered by a prior attempt
        }

        $delivery->deliver($log);
    }

    public function failed(\Throwable $e): void
    {
        NotificationLog::where('id', $this->notificationLogId)
            ->when($this->organizationId !== null, fn ($query) => $query->where('organization_id', $this->organizationId))
            ->where('status', '!=', 'delivered')
            ->update(['status' => 'failed']);
    }
}
