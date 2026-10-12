<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_number' => ['required', 'integer', 'min:-2147483648', 'max:2147483647', Rule::unique('restaurant_tables', 'table_number')],
            'default_chairs' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['missing'],
            'is_active' => ['missing'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
