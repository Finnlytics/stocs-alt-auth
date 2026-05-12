<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\B2bLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class B2bLoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function __invoke(B2bLoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginB2b(
            $request->validated('email'),
            $request->validated('password'),
            $request
        );

        return match ($result['result']) {
            LoginResult::SUCCESS => response()->json([
                'data' => new UserResource($result['user']),
                'token' => $result['token'],
                'message' => 'Login successful.',
            ]),
            LoginResult::INVALID_CREDENTIALS,
            LoginResult::NO_ACCESS => response()->json([
                'message' => 'Invalid credentials.',
            ], 401),
            LoginResult::PENDING => response()->json([
                'message' => 'Your account is pending admin approval.',
                'status' => 'pending',
            ], 403),
            LoginResult::REJECTED => response()->json([
                'message' => 'Your account has been rejected. Please contact support.',
                'status' => 'rejected',
            ], 403),
            LoginResult::SUSPENDED => response()->json([
                'message' => 'Your account is suspended. Please contact support.',
                'status' => 'suspended',
            ], 403),
        };
    }
}
