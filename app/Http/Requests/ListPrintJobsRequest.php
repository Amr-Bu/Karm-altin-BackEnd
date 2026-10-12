<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPrintJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['PENDING', 'PRINTED', 'FAILED'])],
            'job_type' => ['sometimes', Rule::in(['NEW_ORDER', 'ADDITION', 'MODIFICATION', 'CANCELLATION', 'RECEIPT', 'REPRINT'])],
            'printer_id' => ['sometimes', 'integer', 'exists:printers,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'order_id' => ['sometimes', 'integer', 'exists:orders,id'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
