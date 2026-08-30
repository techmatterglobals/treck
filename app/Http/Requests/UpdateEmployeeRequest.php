<?php

namespace App\Http\Requests;

use App\Contracts\CurrentOrganization;
use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');
        $organization = app(CurrentOrganization::class)->resolve($this->user());

        return [
            // User account — password optional (blank = keep current).
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($employee->user_id),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['nullable', 'string', Rule::in(UserRole::values())],

            // Employee profile
            'employee_code' => [
                'required', 'string', 'max:40',
                Rule::unique('employees', 'employee_code')->ignore($employee->id),
            ],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('organization_id', $organization->id)),
            ],
            'joined_on' => ['nullable', 'date'],
        ];
    }
}
