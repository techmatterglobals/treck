<?php

namespace App\Events;

use App\Models\NotificationLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an in-app notification is delivered (Phase 9), so the admin's
 * bell/badge and list update live without polling. Sent on the recipient's
 * private channel `notifications.user.{id}` (admin-authorized in
 * routes/channels.php). Carries a compact, secret-free payload.
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly NotificationLog $log) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.user.'.$this->log->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'event_type' => $this->log->event_type,
            'severity' => $this->log->severity,
            'title' => $this->log->title,
            'message' => $this->log->message,
            'created_at' => $this->log->created_at?->toIso8601String(),
        ];
    }
}
