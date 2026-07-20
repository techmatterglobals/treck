<?php

namespace App\Services\Presence;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\ComputerPresence;
use Illuminate\Support\Facades\DB;

/**
 * Projects a single agent event onto the materialized {@see ComputerPresence}
 * row for its computer (Phase 6). This is the only writer of presence state on the
 * ingestion path; it never scans history - it advances the current state.
 *
 * Status rules (from the milestone contract):
 *   Lock                         -> Locked
 *   Unlock                       -> Active
 *   heartbeat IsIdle = true      -> Idle
 *   heartbeat IsIdle = false     -> Active
 *   Logon                        -> Active (session start)
 *   Logoff / Shutdown / Restart  -> Logged Out
 *   (missing heartbeat -> Offline is handled by the sweep, not here.)
 *
 * The agent serializes payloads with PascalCase keys, and session `Type` may be
 * a string ("Lock") or the numeric ordinal of the C# SessionEventType enum
 * (Unknown=0, Logon=1, Logoff=2, Lock=3, Unlock=4, Shutdown=5, Restart=6). Both
 * forms are accepted so the Windows agent needs no change.
 */
class PresenceProjector
{
    /** Numeric SessionEventType ordinals -> canonical lowercase names. */
    private const SESSION_ORDINALS = [
        0 => 'unknown',
        1 => 'logon',
        2 => 'logoff',
        3 => 'lock',
        4 => 'unlock',
        5 => 'shutdown',
        6 => 'restart',
    ];

    /**
     * Advance the computer's presence for this event.
     *
     * Returns the updated presence, or null when the event was ignored (an
     * unknown session type, or an out-of-order event older than the current
     * state). A null return means callers should not broadcast.
     */
    public function project(AgentEvent $event): ?ComputerPresence
    {
        return DB::transaction(function () use ($event) {
            $presence = ComputerPresence::query()
                ->where('computer_id', $event->computer_id)
                ->lockForUpdate()
                ->first()
                ?? new ComputerPresence(['computer_id' => $event->computer_id]);

            // Reject stale/out-of-order events: never let an older event overwrite
            // a newer materialized state.
            if ($presence->last_event_at !== null
                && $event->occurred_at !== null
                && $event->occurred_at->lt($presence->last_event_at)) {
                return null;
            }

            $applied = match ($event->kind) {
                AgentEventKind::Heartbeat => $this->applyHeartbeat($presence, $event),
                AgentEventKind::Session => $this->applySession($presence, $event),
            };

            if (! $applied) {
                return null;
            }

            $presence->last_event_at = $event->occurred_at;
            $presence->last_synced_at = $event->received_at;
            $presence->save();

            return $presence;
        });
    }

    /** Apply a heartbeat: Active or Idle, refreshing heartbeat/idle fields. */
    private function applyHeartbeat(ComputerPresence $presence, AgentEvent $event): bool
    {
        $payload = $event->payload ?? [];
        $isIdle = (bool) $this->value($payload, ['IsIdle', 'isIdle', 'is_idle'], false);

        $presence->last_heartbeat_at = $event->occurred_at;

        if ($isIdle) {
            $presence->status = PresenceStatus::Idle;
            $presence->idle_seconds = (int) $this->value(
                $payload,
                ['IdleTimeSeconds', 'idleTimeSeconds', 'idle_time_seconds', 'IdleSeconds', 'idle_seconds'],
                0,
            );
        } else {
            $presence->status = PresenceStatus::Active;
            $presence->idle_seconds = 0;
            $presence->last_activity_at = $event->occurred_at;
        }

        return true;
    }

    /** Apply a session transition (Lock/Unlock/Logon/Logoff/Shutdown/...). */
    private function applySession(ComputerPresence $presence, AgentEvent $event): bool
    {
        $type = $this->sessionType($event->payload ?? []);

        switch ($type) {
            case 'lock':
                $presence->status = PresenceStatus::Locked;

                return true;

            case 'unlock':
                $presence->status = PresenceStatus::Active;
                $presence->last_activity_at = $event->occurred_at;

                return true;

            case 'logon':
                $presence->status = PresenceStatus::Active;
                $presence->last_activity_at = $event->occurred_at;
                $presence->session_started_at = $event->occurred_at;

                return true;

            case 'logoff':
            case 'shutdown':
            case 'restart':
                $presence->status = PresenceStatus::LoggedOut;
                $presence->session_started_at = null;

                return true;

            default:
                // Unknown/unhandled session type: leave state untouched.
                return false;
        }
    }

    /**
     * Canonical lowercase session type from a payload, accepting either the
     * string name or the numeric SessionEventType ordinal.
     *
     * @param  array<string,mixed>  $payload
     */
    private function sessionType(array $payload): string
    {
        $raw = $this->value($payload, ['Type', 'type'], null);

        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return self::SESSION_ORDINALS[(int) $raw] ?? 'unknown';
        }

        return is_string($raw) ? strtolower($raw) : 'unknown';
    }

    /**
     * Read the first present key from a payload (case-tolerant), else a default.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function value(array $payload, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }
}
