<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\InAppChannel;
use App\Services\Notifications\Channels\NotificationChannel;

/**
 * Routes a NotificationLog to the channel named by its `channel` column
 * (Phase 9). New channels (Teams, Slack, SMS, push, webhooks) are added by
 * registering them here — callers never change.
 */
class NotificationDeliveryService
{
    /** @var array<string,NotificationChannel> */
    private array $channels;

    public function __construct(InAppChannel $inApp, EmailChannel $email)
    {
        $this->channels = [
            $inApp->key() => $inApp,
            $email->key() => $email,
        ];
    }

    /** @return list<string> */
    public function availableChannels(): array
    {
        return array_keys($this->channels);
    }

    public function deliver(NotificationLog $log): void
    {
        $channel = $this->channels[$log->channel] ?? null;

        if ($channel === null) {
            $log->forceFill(['status' => 'failed'])->save();

            return;
        }

        $channel->deliver($log);
    }
}
