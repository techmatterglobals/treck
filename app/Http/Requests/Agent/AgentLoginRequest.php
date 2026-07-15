<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reports the start of a PC session (employee logged into the workstation).
 * Authentication/authorization is handled by the route middleware
 * (auth:sanctum + ability:agent:report), so authorize() just returns true.
 */
class AgentLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'computer_name' => ['nullable', 'string', 'max:191'],
            'login_time' => ['nullable', 'date'],
        ];
    }
}
