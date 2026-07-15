<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ComputerStatus;
use App\Enums\SessionEndReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentLoginRequest;
use App\Http\Requests\Agent\AgentLogoutRequest;
use App\Models\ActivityLog;
use App\Models\Computer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * PC session lifecycle for the desktop agent.
 *
 *   POST /api/agent/login   → open a work session (records login time)
 *   POST /api/agent/logout  → close it (records logout time + final totals)
 *
 * The authenticated user is the Computer (Sanctum tokenable).
 */
class WorkSessionController extends Controller
{
    /** POST /api/agent/login */
    public function login(AgentLoginRequest $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();
        $data = $request->validated();

        $loginAt = isset($data['login_time']) ? Carbon::parse($data['login_time']) : now();

        // Keep the reported hostname current.
        if (! empty($data['computer_name'])) {
            $computer->forceFill(['hostname' => $data['computer_name']])->save();
        }

        $computer->markSeen(ComputerStatus::Online);

        // Idempotent: reuse an already-open session instead of duplicating.
        $session = $computer->openSession() ?? ActivityLog::create([
            'employee_id' => (int) $data['employee_id'],
            'computer_id' => $computer->id,
            'login_at' => $loginAt,
            'work_date' => $loginAt->toDateString(),
            'status' => ComputerStatus::Online,
            'active_seconds' => 0,
            'idle_seconds' => 0,
        ]);

        return response()->json([
            'message' => 'Session started.',
            'data' => [
                'session_id' => $session->id,
                'login_time' => $session->login_at->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    /** POST /api/agent/logout */
    public function logout(AgentLogoutRequest $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();
        $data = $request->validated();

        $session = ActivityLog::findOrFail($data['session_id']);

        // A device may only act on its own sessions.
        abort_unless($session->computer_id === $computer->id, Response::HTTP_FORBIDDEN);

        // Idempotent: closing an already-closed session is a no-op success.
        if (! $session->is_open) {
            return response()->json([
                'message' => 'Session already closed.',
                'data' => ['session_id' => $session->id],
            ]);
        }

        if (isset($data['active_seconds'])) {
            $session->increment('active_seconds', (int) $data['active_seconds']);
        }
        if (isset($data['idle_seconds'])) {
            $session->increment('idle_seconds', (int) $data['idle_seconds']);
        }

        $logoutAt = isset($data['logout_time']) ? Carbon::parse($data['logout_time']) : now();
        $session->close(SessionEndReason::Logout, $logoutAt);

        $computer->markSeen(ComputerStatus::Offline);

        return response()->json([
            'message' => 'Session ended.',
            'data' => [
                'session_id' => $session->id,
                'login_time' => $session->login_at->toIso8601String(),
                'logout_time' => $session->logout_at->toIso8601String(),
                'active_seconds' => $session->active_seconds,
                'idle_seconds' => $session->idle_seconds,
            ],
        ]);
    }
}
