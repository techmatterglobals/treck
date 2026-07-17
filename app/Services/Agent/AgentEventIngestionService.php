<?php

namespace App\Services\Agent;

use App\Enums\AgentEventKind;
use App\Events\PresenceUpdated;
use App\Models\AgentEvent;
use App\Models\Computer;
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
 * onto the materialized presence state (M7) inside the same transaction, then
 * broadcasts the change after commit:
 *
 *   store event -> update presence -> broadcast PresenceUpdated
 *
 * It still adds no screenshots, application tracking, or attendance aggregates.
 */
class AgentEventIngestionService
{
    public function __construct(private readonly PresenceProjector $projector) {}

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
            // Atomic: store the event and, if it is new, advance presence in the
            // same transaction so state and history never diverge.
            [$event, $presence] = DB::transaction(function () use ($attributes, $values) {
                $event = AgentEvent::firstOrCreate($attributes, $values);
                $presence = $event->wasRecentlyCreated ? $this->projector->project($event) : null;

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
            event(new PresenceUpdated($presence));
        }

        return $event;
    }
}
