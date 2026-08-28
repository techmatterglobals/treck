<?php

namespace App\Http\Requests\Agent;

use App\Enums\ComputerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Periodic activity report for an open session: incremental active/idle seconds
 * accumulated since the last report, plus the current status.
 */
class ActivityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer', 'exists:activity_logs,id'],
            'active_seconds' => ['required', 'integer', 'min:0'],
            'idle_seconds' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(ComputerStatus::values())],
        ];
    }
}
