<?php

namespace App\Services\Notifications\Channels;

use App\Events\NotificationCreated;
use App\Models\NotificationLog;

/**
 * In-app channel (Phase 9): the NotificationLog row itself is the inbox item, so
 * "delivery" marks it delivered and broadcasts NotificationCreated on the
 * recipient's private channel to update the bell/badge live (no polling).
 */
class InAppChannel implements NotificationChannel
{
    public const KEY = 'in_app';

    public function key(): string
    {
        return self::KEY;
    }

    public function deliver(NotificationLog $log): void
    {
        $log->forceFill([
            'status' => 'delivered',
            'delivered_at' => now(),
        ])->save();

        if ($log->recipient_id !== null) {
            NotificationCreated::dispatch($log);
        }
    }
}
