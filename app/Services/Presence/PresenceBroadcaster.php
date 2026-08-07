<?php

namespace App\Services\Presence;

use App\Events\PresenceChanged;
use App\Models\ComputerPresence;

/**
 * The single seam for broadcasting presence changes (Phase 6). Wrapping the
 * event dispatch here keeps the broadcast concern in one named place (SRP) and
 * out of the ingestion service and the offline sweep, which just call
 * {@see changed()} after they have committed a new presence state.
 */
class PresenceBroadcaster
{
    /**
     * Broadcast that a computer's presence changed, so the admin dashboard
     * updates live. Safe to call after the presence write has committed.
     */
    public function changed(ComputerPresence $presence): void
    {
        PresenceChanged::dispatch($presence);
    }
}
