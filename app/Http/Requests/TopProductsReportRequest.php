<?php

namespace App\Http\Requests;

class TopProductsReportRequest extends FinancialReportRequest
{
    public function rules(): array
    {
        return parent::rules() + (new PaginationRequest)->rules() + ['limit' => ['sometimes', 'integer', 'between:1,100']];
    }
}
