<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    public function login(array $credentials): array
    {
        $user = User::where('username', $credentials['username'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['username' => ['بيانات الدخول غير صحيحة أو الحساب غير نشط']]);
        }

        return ['token_type' => 'Bearer', 'token' => $user->createToken('postman')->plainTextToken,
            'user' => $user->only(['id', 'name', 'username', 'role'])];
    }
}
