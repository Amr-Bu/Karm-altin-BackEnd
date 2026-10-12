<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthenticationService $authentication) {}

    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logout($request->user());

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->authentication->login($request->validated()));
    }
}
