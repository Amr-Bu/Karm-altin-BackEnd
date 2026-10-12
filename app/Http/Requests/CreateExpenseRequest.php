<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'amount' => ['bail', 'required', 'numeric', 'regex:/^\\d{1,8}(\\.\\d{1,2})?$/D', 'gt:0'],
            'note' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The note must not exceed 65535 bytes.');
                }
            }],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'id' => ['missing'],
            'created_by' => ['missing'],
            'status' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
