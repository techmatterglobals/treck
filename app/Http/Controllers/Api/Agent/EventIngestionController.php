<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAgentEventRequest;
use App\Models\Computer;
use App\Services\Agent\AgentEventIngestionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/agent/events
 *
 * Drains one event from the desktop agent's offline queue. The device
 * authenticates with its Sanctum token (ability `agent:report`); the owning
 * employee is resolved from the Computer, never the request body (SEC-1).
 *
 * The response is a success only once the event is committed:
 *   - 201 Created  → newly stored
 *   - 200 OK       → already stored (idempotent re-submission)
 *
 * Both are 2xx so the agent treats them identically and clears the event from
 * its local SQLite queue. Anything else keeps the event queued for retry.
 */
class EventIngestionController extends Controller
{
    public function store(
        StoreAgentEventRequest $request,
        AgentEventIngestionService $ingestion,
    ): JsonResponse {
        /** @var Computer $computer */
        $computer = $request->user();

        $event = $ingestion->ingest($computer, $request->validated());

        return response()->json([
            'message' => $event->wasRecentlyCreated ? 'Event stored.' : 'Event already recorded.',
            'data' => [
                'id' => $event->id,
                'idempotency_key' => $event->idempotency_key,
                'duplicate' => ! $event->wasRecentlyCreated,
            ],
        ], $event->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
