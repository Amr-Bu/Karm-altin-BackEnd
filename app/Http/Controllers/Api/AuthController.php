<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(private AuthenticationService $authentication) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->authentication->login($request->validated()));
    }
}
