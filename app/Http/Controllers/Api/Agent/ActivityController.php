<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ActivityReportRequest;
use App\Models\ActivityLog;
use App\Models\Computer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/activity
 *
 * Periodic activity report: accumulates the active/idle seconds the agent
 * measured since its last report onto the open session, and refreshes the
 * computer's live status.
 */
class ActivityController extends Controller
{
    public function store(ActivityReportRequest $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();
        $data = $request->validated();

        $session = ActivityLog::findOrFail($data['session_id']);

        // A device may only report on its own sessions.
        abort_unless($session->computer_id === $computer->id, Response::HTTP_FORBIDDEN);
        abort_if(! $session->is_open, Response::HTTP_CONFLICT, 'Session is already closed.');

        // Accumulate the deltas.
        $session->increment('active_seconds', (int) $data['active_seconds']);
        $session->increment('idle_seconds', (int) $data['idle_seconds']);

        $status = isset($data['status'])
            ? ComputerStatus::from($data['status'])
            : ComputerStatus::Online;

        $session->update(['status' => $status]);
        $computer->markSeen($status);

        return response()->json([
            'message' => 'Activity recorded.',
            'data' => [
                'session_id' => $session->id,
                'active_seconds' => $session->active_seconds,
                'idle_seconds' => $session->idle_seconds,
                'status' => $status->value,
            ],
        ]);
    }
}
