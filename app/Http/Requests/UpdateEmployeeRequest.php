<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends CreateEmployeeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['name', 'username', 'password', 'role'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['username'] = ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($this->route('employee')->id)];

        return $rules;
    }
}
