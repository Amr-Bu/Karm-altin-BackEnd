<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Validation\Rule;

class AddRecipeIngredientRequest extends UpdateRecipeIngredientRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->whereIn('item_type', [Product::TYPE_RAW_MATERIAL, Product::TYPE_PURCHASED_PRODUCT]),
                Rule::unique('product_ingredients', 'raw_material_id')->where('product_id', $this->route('product')->id),
            ],
        ]);
    }
}
