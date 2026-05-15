<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuthService $authService
    ) {}

    public function request(OtpRequestRequest $request): JsonResponse
    {
        $this->otpService->sendOtp(
            $request->validated('identifier'),
            $request->validated('type', 'email')
        );

        return response()->json([
            'message' => 'OTP sent.',
            'expires_in' => 600,
        ], 202);
    }

    public function verify(OtpVerifyRequest $request): JsonResponse
    {
        $result = $this->authService->completeBidsRegistration(
            $request->validated('identifier'),
            $request->validated('code'),
            $request,
            $request->validated('name')
        );

        if (! $result) {
            return response()->json([
                'message' => 'Invalid or expired OTP code.',
            ], 422);
        }

        return response()->json([
            'data' => new UserResource($result['user']),
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
            'message' => $result['is_new_user'] ? 'Account created.' : 'Login successful.',
        ]);
    }
}
