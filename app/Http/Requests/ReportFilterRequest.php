<?php

namespace App\Http\Requests;

use App\Enums\ReportPeriod;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(MonitoringTenantAccess::class)->canViewMonitoring($user);
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(ReportPeriod::values())],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
