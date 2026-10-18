<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Validation\Rule;

class ListProductsRequest extends PaginationRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('item_type'))) {
            $this->merge(['item_type' => [$this->input('item_type')]]);
        }
    }

    public function rules(): array
    {
        return parent::rules() + [
            'item_type' => ['sometimes', 'array', 'min:1', 'max:3'],
            'item_type.*' => ['required', 'string', 'distinct', Rule::in([Product::TYPE_RAW_MATERIAL, Product::TYPE_PRODUCED_PRODUCT, Product::TYPE_PURCHASED_PRODUCT])],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
