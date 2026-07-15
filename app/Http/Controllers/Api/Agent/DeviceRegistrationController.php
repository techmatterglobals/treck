<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\RegisterDeviceRequest;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/agent/register
 *
 * Bootstraps a workstation: finds/creates the Computer by its hardware
 * fingerprint, links it to an employee, and issues a Sanctum device token with
 * the `agent:report` ability. The agent stores this token and sends it as a
 * Bearer token on every subsequent request.
 */
class DeviceRegistrationController extends Controller
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $employee = Employee::where('employee_code', $data['employee_code'])->firstOrFail();

        $computer = Computer::updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            [
                'employee_id' => $employee->id,
                'hostname' => $data['computer_name'] ?? null,
                'os' => $data['os'] ?? null,
                'agent_version' => $data['agent_version'] ?? null,
                'paired_at' => now(),
                'status' => ComputerStatus::Offline,
            ],
        );

        // One live token per device: revoke any previous, then mint a new one.
        $computer->tokens()->delete();
        $token = $computer->createToken('agent-'.$computer->device_uuid, ['agent:report']);

        return response()->json([
            'message' => 'Device registered.',
            'data' => [
                'computer_id' => $computer->id,
                'employee_id' => $employee->id,
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], Response::HTTP_CREATED);
    }
}
