<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bootstraps a desktop agent. The opaque enrollment credential is validated by
 * the controller so tenant resolution happens inside one locked transaction.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enrollment_secret' => ['required', 'string', 'max:255'],
            'device_uuid' => ['required', 'string', 'max:64'],
            'employee_code' => ['required', 'string', 'max:40'],
            'computer_name' => ['nullable', 'string', 'max:191'],
            'os' => ['nullable', 'string', 'max:60'],
            'agent_version' => ['nullable', 'string', 'max:20'],
            'organization_id' => ['prohibited'],
        ];
    }
}
