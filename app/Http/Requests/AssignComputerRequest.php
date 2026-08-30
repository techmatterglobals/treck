<?php

namespace App\Http\Requests;

use App\Contracts\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organization = app(CurrentOrganization::class)->resolve($this->user());

        return [
            'computer_id' => [
                'required',
                'integer',
                // Must exist and not be soft-deleted.
                Rule::exists('computers', 'id')
                    ->whereNull('deleted_at')
                    ->whereNull('employee_id')
                    ->where('organization_id', $organization->id),
            ],
        ];
    }
}
