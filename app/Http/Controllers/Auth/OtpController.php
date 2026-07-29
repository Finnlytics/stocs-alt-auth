<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Enums\OtpRequestResult;
use App\Enums\Platform;
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

    public function requestForLogin(OtpRequestRequest $request): JsonResponse
    {
        $result = $this->otpService->requestForLogin(
            $request->validated('identifier'),
            $request->validated('type', 'email'),
            $request
        );

        // BUSINESS RULE: respond identically for SENT and ACCOUNT_NOT_FOUND so
        // the endpoint can't be used to enumerate registered emails.
        return match ($result['result']) {
            OtpRequestResult::SENT,
            OtpRequestResult::ACCOUNT_NOT_FOUND => response()->json([
                'message' => 'If an account exists for that email, we\'ve sent a sign-in code.',
                'expires_in' => 600,
            ], 202),
            OtpRequestResult::RATE_LIMITED => $this->rateLimitedResponse($result['retry_after'] ?? 60),
            default => response()->json(['message' => 'Unable to process request.'], 422),
        };
    }

    public function requestForSignup(OtpRequestRequest $request): JsonResponse
    {
        $result = $this->otpService->requestForSignup(
            $request->validated('identifier'),
            $request->validated('type', 'email'),
            $request
        );

        return match ($result['result']) {
            OtpRequestResult::SENT => response()->json([
                'message' => 'OTP sent.',
                'expires_in' => 600,
            ], 202),
            OtpRequestResult::ACCOUNT_ALREADY_EXISTS => response()->json([
                'message' => 'An account already exists for that email. Please sign in instead.',
                'code' => 'account_exists',
            ], 409),
            OtpRequestResult::RATE_LIMITED => $this->rateLimitedResponse($result['retry_after'] ?? 60),
            default => response()->json(['message' => 'Unable to process request.'], 422),
        };
    }

    public function verify(OtpVerifyRequest $request): JsonResponse
    {
        $platform = Platform::from($request->validated('platform', Platform::BIDS->value));

        $result = $this->authService->completeConsumerOtpRegistration(
            $platform,
            $request->validated('identifier'),
            $request->validated('code'),
            $request,
            $request->validated('name')
        );

        return match ($result['result']) {
            LoginResult::SUCCESS => response()->json([
                'data' => new UserResource($result['user']),
                'token' => $result['token'],
                'is_new_user' => $result['is_new_user'],
                'message' => $result['is_new_user'] ? 'Account created.' : 'Login successful.',
            ]),
            LoginResult::SUSPENDED => response()->json([
                'message' => 'Your account is suspended. Please contact support.',
                'status' => 'suspended',
            ], 403),
            LoginResult::REJECTED => response()->json([
                'message' => 'Your account has been rejected. Please contact support.',
                'status' => 'rejected',
            ], 403),
            default => response()->json([
                'message' => 'Invalid or expired OTP code.',
            ], 422),
        };
    }

    private function rateLimitedResponse(int $retryAfter): JsonResponse
    {
        return response()->json([
            'message' => 'Too many sign-in code requests for this email. Try again later.',
            'code' => 'rate_limited',
            'retry_after' => $retryAfter,
        ], 429)->header('Retry-After', (string) $retryAfter);
    }
}
