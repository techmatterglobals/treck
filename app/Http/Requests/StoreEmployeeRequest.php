<?php

namespace App\Http\Requests;

use App\Contracts\CurrentOrganization;
use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    /**
     * Creating an employee also provisions the underlying user account, so this
     * request validates both the user fields (name/email/password/role) and the
     * employee profile fields.
     */
    public function rules(): array
    {
        $organization = app(CurrentOrganization::class)->resolve($this->user());

        return [
            // User account
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in(UserRole::values())],

            // Employee profile
            'employee_code' => ['required', 'string', 'max:40', 'unique:employees,employee_code'],
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
