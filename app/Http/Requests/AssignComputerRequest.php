<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignComputerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage employees') ?? false)
            || ($this->user()?->can('manage computers') ?? false);
    }

    public function rules(): array
    {
        return [
            'computer_id' => [
                'required',
                'integer',
                // Must exist and not be soft-deleted.
                Rule::exists('computers', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
