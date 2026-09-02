<?php

namespace App\Http\Requests\Agent;

use App\Services\Agent\AgentEnrollmentService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Enrolls a computer via a one-time enrollment code (installer flow). The code
 * itself is the credential — there is no provisioning key and no employee code
 * here (computer-scoped enrollment). Code validity (existence, expiry, uses,
 * revocation) is checked in {@see AgentEnrollmentService},
 * which returns a 422 for a bad code.
 */
class EnrollDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'device_uuid' => ['required', 'string', 'max:64'],
            'computer_name' => ['nullable', 'string', 'max:191'],
            'os' => ['nullable', 'string', 'max:60'],
            'agent_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
