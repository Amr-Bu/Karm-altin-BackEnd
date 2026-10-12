<?php

namespace App\Http\Requests;

use App\Models\Printer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'printer_type' => ['required', 'string', Rule::in([Printer::TYPE_DEPARTMENT, Printer::TYPE_RECEIPT])],
            'connection_type' => ['required', 'string', Rule::in(['NETWORK', 'USB', 'LOCAL_AGENT'])],
            'connection_config' => ['bail', 'sometimes', 'nullable', 'string', 'max:65535', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 65535) {
                    $fail('The connection config must not exceed 65535 bytes.');
                }
            }],
            'is_active' => ['missing'],
            'id' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
