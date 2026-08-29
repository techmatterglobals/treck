<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bootstraps a desktop agent. Protected by a one-time enrollment secret
 * supplied to the workstation at install/run time, since this is how the agent
 * obtains its Sanctum token in the first place.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('treck.agent.enrollment_secret');

        return $expected !== ''
            && hash_equals($expected, (string) $this->input('enrollment_secret'));
    }

    public function rules(): array
    {
        return [
            'enrollment_secret' => ['required', 'string'],
            'device_uuid' => ['required', 'string', 'max:64'],
            'employee_code' => ['required', 'string', 'exists:employees,employee_code'],
            'computer_name' => ['nullable', 'string', 'max:191'],
            'os' => ['nullable', 'string', 'max:60'],
            'agent_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
