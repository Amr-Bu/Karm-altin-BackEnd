<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeCreationService
{
    public function create(array $input): User
    {
        try {
            return User::create([
                'name' => $input['name'],
                'username' => $input['username'],
                'password_hash' => Hash::make($input['password']),
                'role' => $input['role'],
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // The unique index also protects concurrent requests that pass validation together.
            throw ValidationException::withMessages(['username' => [__('validation.unique', ['attribute' => 'username'])]]);
        }
    }
}
