<?php

namespace App\Services\Notifications\Channels;

use App\Mail\NotificationMail;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Mail;

/**
 * Email channel (Phase 9): queues an HTML NotificationMail to the recipient.
 * The mailable is ShouldQueue, so delivery + retries happen on the queue and
 * never block the caller. Marks the log delivered once queued.
 */
class EmailChannel implements NotificationChannel
{
    public const KEY = 'email';

    public function key(): string
    {
        return self::KEY;
    }

    public function deliver(NotificationLog $log): void
    {
        $email = $log->recipient?->email;

        if (blank($email)) {
            $log->forceFill(['status' => 'failed'])->save();

            return;
        }

        Mail::to($email)->queue(new NotificationMail($log));

        $log->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
        ])->save();
    }
}
