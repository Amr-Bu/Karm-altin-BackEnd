<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => ['sometimes', Rule::in(['RAW_MATERIAL', 'PURCHASED_PRODUCT'])],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'low_stock_only' => ['sometimes', 'boolean'],
        ];
    }
}
