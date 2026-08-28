<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ActivityReportRequest;
use App\Models\ActivityLog;
use App\Models\Computer;
use App\Services\Activity\ActivityTrackingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/activity
 *
 * Periodic activity report. Validation lives in the FormRequest; the
 * accumulation rules live in ActivityTrackingService — this controller only
 * resolves + guards the session and shapes the response.
 */
class ActivityController extends Controller
{
    public function store(ActivityReportRequest $request, ActivityTrackingService $tracker): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();
        $data = $request->validated();

        $session = ActivityLog::findOrFail($data['session_id']);

        // A device may only report on its own sessions.
        abort_unless($session->computer_id === $computer->id, Response::HTTP_FORBIDDEN);
        abort_if(! $session->is_open, Response::HTTP_CONFLICT, 'Session is already closed.');

        $status = isset($data['status'])
            ? ComputerStatus::from($data['status'])
            : ComputerStatus::Online;

        $session = $tracker->record(
            $session,
            (int) $data['active_seconds'],
            (int) $data['idle_seconds'],
            $status,
        );

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
