<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity_required' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,3})?$/D', 'gt:0', 'max:999999999.999'],
            'product_id' => ['missing'], 'raw_material_id' => ['missing'], 'unit' => ['missing'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
