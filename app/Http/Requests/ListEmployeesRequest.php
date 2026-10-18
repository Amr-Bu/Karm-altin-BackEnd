<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListEmployeesRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'role' => ['sometimes', Rule::in(['ADMIN', 'EMPLOYEE'])],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
