<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollDeviceRequest;
use App\Services\Agent\AgentEnrollmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/agent/enroll
 *
 * Enrolls a computer using a one-time enrollment code (the installer flow). No
 * provisioning key and no employee code are required — the computer is created
 * or linked by its device UUID and issued a Sanctum device token. The owning
 * employee is resolved later, per event, from the Windows username.
 *
 * Coexists with the legacy POST /api/agent/register (provisioning key +
 * employee code), which is unchanged for existing installations.
 */
class EnrollController extends Controller
{
    public function store(EnrollDeviceRequest $request, AgentEnrollmentService $enrollment): JsonResponse
    {
        $data = $request->validated();

        // Throws a 422 ValidationException on a bad/expired/used/revoked code.
        $result = $enrollment->enroll($data['code'], [
            'device_uuid' => $data['device_uuid'],
            'computer_name' => $data['computer_name'] ?? null,
            'os' => $data['os'] ?? null,
            'agent_version' => $data['agent_version'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Device enrolled.',
            'data' => [
                'computer_id' => $result['computer_id'],
                'device_id' => $result['device_id'],
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], Response::HTTP_CREATED);
    }
}
