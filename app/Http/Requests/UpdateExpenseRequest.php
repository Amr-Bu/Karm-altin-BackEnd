<?php

namespace App\Http\Requests;

class UpdateExpenseRequest extends CreateExpenseRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['name', 'amount', 'note', 'expense_date'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
