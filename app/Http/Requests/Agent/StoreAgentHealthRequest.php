<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof \App\Models\Computer;
    }

    public function rules(): array
    {
        return [
            'agent_version' => ['required', 'string', 'max:20'],
            'config_revision' => ['required', 'string', 'max:64'],
            'pending_event_count' => ['required', 'integer', 'min:0'],
            'helper_running' => ['required', 'boolean'],
            'helper_session_id' => ['nullable', 'integer', 'min:0'],
            'service_started_at' => ['nullable', 'date'],
            'last_capture_at' => ['nullable', 'date'],
            'last_successful_sync_at' => ['nullable', 'date'],
            'last_error_category' => ['nullable', 'string', 'max:80'],
            'report_time' => ['required', 'date'],
        ];
    }
}
