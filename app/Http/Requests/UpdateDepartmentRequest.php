<?php

namespace App\Http\Requests;

use App\Models\Printer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($this->route('department')->id)],
            'printer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('printers', 'id')->where('printer_type', Printer::TYPE_DEPARTMENT)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
