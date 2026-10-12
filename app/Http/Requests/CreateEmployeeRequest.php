<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:72', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('The password must not exceed 72 bytes or contain null characters.');
                }
            }],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_EMPLOYEE])],
            'id' => ['missing'],
            'is_active' => ['missing'],
            'password_hash' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'remember_token' => ['missing'],
            'token' => ['missing'],
            'tokens' => ['missing'],
        ];
    }
}
