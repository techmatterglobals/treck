<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\RegisterDeviceRequest;
use App\Models\AgentEnrollmentCredential;
use App\Models\Computer;
use App\Models\Employee;
use App\Services\Agent\AgentEnrollmentCredentialService;
use App\Services\Agent\AgentSecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
    public function store(
        RegisterDeviceRequest $request,
        AgentEnrollmentCredentialService $credentials,
        AgentSecurityLogger $security,
    ): JsonResponse {
        $data = $request->validated();

        try {
            [$computer, $token] = $credentials->consumeForRegistration(
                $data['enrollment_secret'],
                function (AgentEnrollmentCredential $credential) use ($data, $security) {
                    $organization = $credential->organization;

                    if ($organization === null || $organization->isSuspended()) {
                        $security->event('agent_registration_inactive_organization', $organization);

                        throw ValidationException::withMessages([
                            'enrollment_secret' => ['The registration credential is invalid.'],
                        ]);
                    }

                    $employee = Employee::query()
                        ->forOrganization($organization)
                        ->where('employee_code', $data['employee_code'])
                        ->first();

                    if ($employee === null) {
                        $security->event('agent_registration_employee_not_in_organization', $organization, context: [
                            'credential_id' => $credential->id,
                        ]);

                        throw ValidationException::withMessages([
                            'employee_code' => ['The provided registration details are invalid.'],
                        ]);
                    }

                    $computer = Computer::query()
                        ->forOrganization($organization)
                        ->where('device_uuid', $data['device_uuid'])
                        ->lockForUpdate()
                        ->first();

                    if ($computer === null) {
                        $foreignComputer = Computer::query()
                            ->where('device_uuid', $data['device_uuid'])
                            ->where(function ($query) use ($organization) {
                                $query->whereNull('organization_id')
                                    ->orWhere('organization_id', '!=', $organization->id);
                            })
                            ->lockForUpdate()
                            ->first();

                        if ($foreignComputer !== null) {
                            $security->event('agent_registration_foreign_computer_conflict', $organization, $foreignComputer, context: [
                                'credential_id' => $credential->id,
                            ]);

                            throw ValidationException::withMessages([
                                'device_uuid' => ['The provided registration details are invalid.'],
                            ]);
                        }

                        $computer = Computer::create([
                            'organization_id' => $organization->id,
                            'device_uuid' => $data['device_uuid'],
                        ]);
                    }

                    $computer->forceFill([
                        'organization_id' => $organization->id,
                        'employee_id' => $employee->id,
                        'hostname' => $data['computer_name'] ?? null,
                        'os' => $data['os'] ?? null,
                        'agent_version' => $data['agent_version'] ?? null,
                        'paired_at' => now(),
                        'status' => ComputerStatus::Offline,
                    ])->save();

                    // One live token per device: revoke any previous, then mint
                    // an organization-bound token. The credential row lock
                    // serializes competing one-use registrations.
                    $computer->tokens()->delete();
                    $token = $computer->createToken('agent-'.$computer->device_uuid, ['agent:report']);
                    $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

                    return [$computer, $token];
                },
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The registration credential is invalid.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'message' => 'Device registered.',
            'data' => [
                'computer_id' => $computer->id,
                'employee_id' => $computer->employee_id,
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ], Response::HTTP_CREATED);
    }
}
