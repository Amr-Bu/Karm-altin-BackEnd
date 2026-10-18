<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListExpensesRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'CANCELLED'])],
            'created_by' => ['sometimes', 'integer', 'exists:users,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
