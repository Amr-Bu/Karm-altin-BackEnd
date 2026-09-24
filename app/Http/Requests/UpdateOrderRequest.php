<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chairs_count' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'items' => ['sometimes', 'array', 'max:200'],
            'items.*' => ['required', 'array:product_id,quantity,note'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/D', 'min:0', 'max:99999999.99'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['prohibited'], 'chair_price' => ['prohibited'], 'unit_price' => ['prohibited'],
        ];
    }
}
