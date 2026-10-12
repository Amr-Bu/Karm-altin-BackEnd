<?php

namespace App\Http\Requests;

class UpdateProductRequest extends CreateProductRequest
{
    public function rules(): array
    {
        return $this->productRules($this->route('product')->item_type, false) + ['item_type' => ['missing']];
    }
}
