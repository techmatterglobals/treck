<?php

namespace App\Listeners;

use App\Events\PresenceChanged;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Bridges the existing PresenceChanged event (Phase 6) into the notification
 * engine (Phase 9) — WITHOUT touching the presence pipeline. Queued
 * (ShouldQueue) so presence broadcasting/ingestion is never blocked; throttling
 * in the engine keeps per-heartbeat presence broadcasts from flooding.
 */
class EvaluatePresenceNotifications implements ShouldQueue
{
    public function __construct(private readonly NotificationEngine $engine) {}

    public function handle(PresenceChanged $event): void
    {
        $presence = $event->presence->loadMissing('computer.employee');
        $computer = $presence->computer;

        $this->engine->dispatch(new NotificationContext(
            source: 'presence',
            data: [
                'status' => $presence->status,
                'idle_seconds' => (int) ($presence->idle_seconds ?? 0),
            ],
            computer: $computer,
            employee: $computer?->employee,
            occurredAt: $presence->last_synced_at,
            organizationId: $presence->organization_id,
        ));
    }
}
