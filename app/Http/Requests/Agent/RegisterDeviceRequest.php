<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bootstraps a desktop agent. Protected by a shared provisioning key (not a
 * user token) since this is how the agent obtains its Sanctum token in the
 * first place. Set TRECK_AGENT_PROVISIONING_KEY and expose it via
 * config('treck.agent.provisioning_key').
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('treck.agent.provisioning_key');

        return $expected !== ''
            && hash_equals($expected, (string) $this->input('provisioning_key'));
    }

    public function rules(): array
    {
        return [
            'provisioning_key' => ['required', 'string'],
            'device_uuid' => ['required', 'string', 'max:64'],
            'employee_code' => ['required', 'string', 'exists:employees,employee_code'],
            'computer_name' => ['nullable', 'string', 'max:191'],
            'os' => ['nullable', 'string', 'max:60'],
            'agent_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
