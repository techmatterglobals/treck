<?php

namespace App\Services\Agent;

use App\Enums\AgentEventKind;
use App\Models\AgentEvent;
use App\Models\Computer;
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
 * This is intentionally a thin, single-responsibility ingest: it lands the raw
 * event and does not (yet) project it into attendance/activity aggregates —
 * that belongs to later milestones. It adds no screenshots, application
 * tracking, notifications, or UI.
 */
class AgentEventIngestionService
{
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
            // firstOrCreate runs the lookup + insert; the surrounding transaction
            // makes the "return success only after persistence" guarantee explicit.
            return DB::transaction(
                fn () => AgentEvent::firstOrCreate($attributes, $values),
            );
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate: another request for the same
            // (computer, idempotency_key) won the race. Return the stored row so
            // the caller reports success and the agent clears its local copy.
            return AgentEvent::where($attributes)->firstOrFail();
        }
    }
}
