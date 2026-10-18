<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListInventoryRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'item_type' => ['sometimes', Rule::in(['RAW_MATERIAL', 'PURCHASED_PRODUCT'])],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'low_stock_only' => ['sometimes', 'boolean'],
        ];
    }
}
