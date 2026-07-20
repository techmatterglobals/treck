<?php

namespace App\Services\Agent;

use App\Enums\AgentEventKind;
use App\Enums\ComputerStatus;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Services\Presence\ApplicationUsageProjector;
use App\Services\Presence\PresenceBroadcaster;
use App\Services\Presence\PresenceProjector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists one event drained from a desktop agent's offline queue (M6).
 *
 * Design goals (from the milestone contract):
 *   - Store the event only after it is durably committed, so the HTTP success
 *     the agent sees truly means "safe to delete locally".
 *   - Idempotent per device: the same (computer, idempotency_key) is stored
 *     exactly once; a re-submission is a safe no-op that still returns success.
 *   - The owning employee is taken from the resolved Computer, never the body.
 *
 * This ingest stores the raw event and, for a newly-stored event, projects it
 * onto the materialized presence state (Phase 6) inside the same transaction, then
 * broadcasts the change after commit:
 *
 *   store event -> update presence -> mirror liveness onto the computer
 *                -> broadcast PresenceChanged
 *
 * The liveness mirror keeps the legacy `computers.status` / `last_seen_at` /
 * `last_activity_at` fields in sync with the presence projection, so the
 * device-status layer (DeviceStatusService, reconcile sweep, M6 dashboard) stays
 * accurate now that the agent only calls /register and /events (it no longer
 * hits the old login/activity/logout endpoints that used to call markSeen).
 *
 * `app_usage` events (Phase 7) are routed to the ApplicationUsageProjector
 * instead of the presence projector (they do not change presence and are not
 * broadcast). No screenshots or attendance aggregates are produced here.
 */
class AgentEventIngestionService
{
    public function __construct(
        private readonly PresenceProjector $projector,
        private readonly PresenceBroadcaster $broadcaster,
        private readonly ApplicationUsageProjector $appUsageProjector,
    ) {}

    /**
     * Ingest one queued event for the given device.
     *
     * @param  array{kind:string,idempotency_key:string,created_at:string,payload:string}  $data
     */
    public function ingest(Computer $computer, array $data): AgentEvent
    {
        $attributes = [
            'computer_id' => $computer->id,
            'idempotency_key' => $data['idempotency_key'],
        ];

        $values = [
            'employee_id' => $computer->employee_id,
            'kind' => AgentEventKind::from($data['kind']),
            // `payload` is validated JSON; decode so it lands as a queryable
            // JSON document (the model casts it back to an array).
            'payload' => json_decode($data['payload'], true),
            'occurred_at' => Carbon::parse($data['created_at']),
            'received_at' => now(),
        ];

        try {
            // Atomic: store the event and, if it is new, advance presence and
            // mirror liveness onto the computer in the same transaction so state
            // and history never diverge.
            [$event, $presence] = DB::transaction(function () use ($attributes, $values, $computer) {
                $event = AgentEvent::firstOrCreate($attributes, $values);
                $presence = null;

                if ($event->wasRecentlyCreated) {
                    if ($event->kind === AgentEventKind::AppUsage) {
                        // Application usage does not affect presence; project it
                        // into application_usage only (Phase 7).
                        $this->appUsageProjector->project($event);
                    } else {
                        $presence = $this->projector->project($event);

                        if ($presence !== null) {
                            $this->mirrorLiveness($computer, $presence);
                        }
                    }
                }

                return [$event, $presence];
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate: another request for the same
            // (computer, idempotency_key) won the race. Return the stored row so
            // the caller reports success and the agent clears its local copy.
            return AgentEvent::where($attributes)->firstOrFail();
        }

        // Broadcast only after the transaction has committed, so subscribers
        // never observe a state that could still roll back.
        if ($presence !== null) {
            $this->broadcaster->changed($presence);
        }

        return $event;
    }

    /**
     * Mirror the projected presence onto the computer's legacy liveness fields.
     * `last_seen_at` reflects that we just heard from the device (server clock);
     * `status` is the presence status mapped to the ComputerStatus vocabulary;
     * `last_activity_at` advances only on genuine activity.
     */
    private function mirrorLiveness(Computer $computer, ComputerPresence $presence): void
    {
        $attributes = [
            'status' => $this->toComputerStatus($presence->status),
            'last_seen_at' => $presence->last_synced_at ?? now(),
        ];

        if ($presence->last_activity_at !== null) {
            $attributes['last_activity_at'] = $presence->last_activity_at;
        }

        $computer->forceFill($attributes)->save();
    }

    /** Map the presence vocabulary onto the legacy ComputerStatus enum. */
    private function toComputerStatus(PresenceStatus $status): ComputerStatus
    {
        return match ($status) {
            PresenceStatus::Active => ComputerStatus::Online,
            PresenceStatus::Idle => ComputerStatus::Idle,
            PresenceStatus::Locked => ComputerStatus::Locked,
            PresenceStatus::LoggedOut, PresenceStatus::Offline => ComputerStatus::Offline,
        };
    }
}
