<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationLog;

/**
 * A delivery channel for a notification (Phase 9). Implementations are keyed by
 * {@see key()} (matching NotificationLog::$channel) and are independently
 * configurable. Future channels (Teams, Slack, SMS, push, webhooks) implement
 * this same contract.
 */
interface NotificationChannel
{
    public function key(): string;

    public function deliver(NotificationLog $log): void;
}
