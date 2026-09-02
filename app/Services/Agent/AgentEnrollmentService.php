<?php

namespace App\Services\Agent;

use App\Enums\ComputerStatus;
use App\Http\Controllers\Api\Agent\DeviceRegistrationController;
use App\Models\AgentEnrollmentCode;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues and redeems one-time agent enrollment codes (installer flow).
 *
 * Enrollment binds a COMPUTER to the organization — never an employee. The
 * employee behind each event is still resolved at runtime from the Windows
 * username via {@see EmployeeResolver} and computer_users,
 * so shared PCs work without re-enrollment. This coexists with the legacy
 * provisioning-key {@see DeviceRegistrationController}.
 */
class AgentEnrollmentService
{
    /**
     * Create a new code. Returns the PLAINTEXT (shown once, never stored) plus
     * the persisted model (which holds only the hash).
     *
     * @return array{code:string, model:AgentEnrollmentCode}
     */
    public function generate(
        ?User $creator = null,
        ?string $label = null,
        ?Carbon $expiresAt = null,
        int $maxUses = 1,
    ): array {
        $plaintext = AgentEnrollmentCode::generatePlaintext();

        $model = AgentEnrollmentCode::create([
            'code_hash' => AgentEnrollmentCode::hashOf($plaintext),
            'code_last_four' => AgentEnrollmentCode::lastFourOf($plaintext),
            'label' => $label,
            'max_uses' => max(1, $maxUses),
            'expires_at' => $expiresAt,
            'created_by' => $creator?->id,
        ]);

        return ['code' => $plaintext, 'model' => $model];
    }

    /** Revoke a code so it can no longer be redeemed. */
    public function revoke(AgentEnrollmentCode $code): void
    {
        if ($code->revoked_at === null) {
            $code->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Redeem a code to enroll a computer. Creates or links the Computer by its
     * device UUID (no employee required), mints a fresh Sanctum device token,
     * and consumes one use — all atomically, with the code row locked so a code
     * cannot be redeemed twice concurrently.
     *
     * @param  array{device_uuid:string, computer_name?:?string, os?:?string, agent_version?:?string, ip?:?string}  $device
     * @return array{computer_id:int, device_id:string, token:string}
     *
     * @throws ValidationException when the code is invalid/expired/used/revoked.
     */
    public function enroll(string $code, array $device): array
    {
        return DB::transaction(function () use ($code, $device) {
            $row = AgentEnrollmentCode::query()
                ->where('code_hash', AgentEnrollmentCode::hashOf($code))
                ->lockForUpdate()
                ->first();

            if ($row === null || ! $row->isUsable()) {
                throw ValidationException::withMessages([
                    'code' => 'Invalid, expired, already-used, or revoked enrollment code.',
                ]);
            }

            // Create or link the computer by hardware fingerprint. Note: no
            // employee_id is set — enrollment is computer-scoped. Re-enrolling an
            // existing device preserves any employee link already present.
            $computer = Computer::updateOrCreate(
                ['device_uuid' => $device['device_uuid']],
                [
                    'hostname' => $device['computer_name'] ?? null,
                    'os' => $device['os'] ?? null,
                    'agent_version' => $device['agent_version'] ?? null,
                    'paired_at' => now(),
                    'status' => ComputerStatus::Offline,
                ],
            );

            // One live token per device: revoke previous, mint fresh.
            $computer->tokens()->delete();
            $token = $computer->createToken('agent-'.$computer->device_uuid, ['agent:report']);

            $row->forceFill([
                'uses' => $row->uses + 1,
                'last_used_at' => now(),
                'last_used_ip' => $device['ip'] ?? null,
                'last_computer_id' => $computer->id,
            ])->save();

            return [
                'computer_id' => $computer->id,
                'device_id' => $computer->device_uuid,
                'token' => $token->plainTextToken,
            ];
        });
    }
}
