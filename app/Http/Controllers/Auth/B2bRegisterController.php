<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\B2bRegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class B2bRegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function __invoke(B2bRegisterRequest $request): JsonResponse
    {
        $result = $this->authService->registerB2b($request->validated(), $request);

        return response()->json([
            'data' => new UserResource($result['user']),
            'token' => $result['token'],
            'message' => 'Registration successful.',
        ], 201);
    }
}
