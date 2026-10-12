<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'id' => ['missing'],
            'name' => ['missing'],
            'username' => ['missing'],
            'role' => ['missing'],
            'password' => ['missing'],
            'password_hash' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'remember_token' => ['missing'],
            'token' => ['missing'],
            'tokens' => ['missing'],
        ];
    }
}
