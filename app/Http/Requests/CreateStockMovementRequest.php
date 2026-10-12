<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $directions = match ($this->input('transaction_type')) {
            'PURCHASE' => ['IN'],
            'WASTE' => ['OUT'],
            default => ['IN', 'OUT'],
        };

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereIn('item_type', ['RAW_MATERIAL', 'PURCHASED_PRODUCT']), 'exists:inventory_stock,product_id'],
            'transaction_type' => ['required', Rule::in(['PURCHASE', 'WASTE', 'ADJUSTMENT'])],
            'direction' => ['required', Rule::in($directions)],
            'quantity' => ['bail', 'required', 'numeric', 'regex:/^\\d{1,9}(\\.\\d{1,3})?$/D', 'gt:0'],
            'note' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The note must not exceed 65535 bytes.');
                }
            }],
            'id' => ['missing'],
            'reference_order_id' => ['missing'],
            'created_by' => ['missing'],
            'current_quantity' => ['missing'],
            'actual_quantity' => ['missing'],
            'balance_after' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
