<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reports the end of a PC session. Optionally carries a final active/idle
 * delta captured between the last activity report and logout.
 */
class AgentLogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer', 'exists:activity_logs,id'],
            'logout_time' => ['nullable', 'date'],
            'active_seconds' => ['nullable', 'integer', 'min:0'],
            'idle_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
