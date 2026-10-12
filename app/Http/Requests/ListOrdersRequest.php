<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_status' => ['sometimes', Rule::in(['PENDING_APPROVAL', 'APPROVED', 'PREPARING', 'READY', 'CLOSED', 'CANCELLED'])],
            'payment_status' => ['sometimes', Rule::in(['PAID', 'UNPAID'])],
            'employee_id' => ['sometimes', 'integer', 'exists:users,id'],
            'table_id' => ['sometimes', 'integer', 'exists:restaurant_tables,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
