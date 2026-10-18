<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ListStockMovementsRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'transaction_type' => ['sometimes', Rule::in(['PURCHASE', 'WASTE', 'ADJUSTMENT', 'ORDER_CONSUMPTION', 'ORDER_RETURN'])],
            'direction' => ['sometimes', Rule::in(['IN', 'OUT'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
