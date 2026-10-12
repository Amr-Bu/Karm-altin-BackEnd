<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->productRules($this->input('item_type'), true) + [
            'item_type' => ['required', 'string', Rule::in([Product::TYPE_RAW_MATERIAL, Product::TYPE_PRODUCED_PRODUCT, Product::TYPE_PURCHASED_PRODUCT])],
        ];
    }

    protected function productRules(mixed $type, bool $creating): array
    {
        $raw = $type === Product::TYPE_RAW_MATERIAL;
        $produced = $type === Product::TYPE_PRODUCED_PRODUCT;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:150'],
            'unit' => [$creating ? 'required' : 'sometimes', 'required', Rule::in([Product::UNIT_KG, Product::UNIT_G, Product::UNIT_LITER, Product::UNIT_ML, Product::UNIT_PIECE])],
            'price' => $raw ? ['exclude'] : [$creating ? 'required' : 'sometimes', 'required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/D', 'min:0', 'max:99999999.99'],
            'min_stock' => $produced ? ['exclude'] : ['sometimes', 'nullable', 'numeric', 'regex:/^\d+(?:\.\d{1,3})?$/D', 'min:0', 'max:999999999.999'],
            'category_id' => $raw ? ['exclude'] : ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'department_id' => $raw ? ['exclude'] : ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'is_stock_tracked' => ['sometimes', 'required', 'boolean'],
            'is_active' => ['missing'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
            'ingredients' => ['missing'], 'current_quantity' => ['missing'],
        ];
    }
}
