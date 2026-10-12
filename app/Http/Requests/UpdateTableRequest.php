<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_number' => ['sometimes', 'required', 'integer', 'min:-2147483648', 'max:2147483647', Rule::unique('restaurant_tables', 'table_number')->ignore($this->route('table')->id)],
            'default_chairs' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['missing'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
