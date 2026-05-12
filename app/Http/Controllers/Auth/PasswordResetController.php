<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Services\AuditService;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly AuditService $auditService
    ) {}

    public function forgot(PasswordForgotRequest $request): JsonResponse
    {
        $this->passwordResetService->sendResetLink($request->validated('email'));

        $this->auditService->log(
            'password_reset_requested',
            'Password reset requested',
            metadata: ['email' => $request->validated('email')],
            request: $request
        );

        // Always return success to prevent email enumeration
        return response()->json([
            'message' => 'If this email exists, a reset link has been sent.',
        ]);
    }

    public function reset(PasswordResetRequest $request): JsonResponse
    {
        $success = $this->passwordResetService->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password')
        );

        if (! $success) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
            ], 422);
        }

        $this->auditService->log(
            'password_reset_completed',
            'Password reset completed',
            metadata: ['email' => $request->validated('email')],
            request: $request
        );

        return response()->json([
            'message' => 'Password reset successfully. Please log in.',
        ]);
    }
}
