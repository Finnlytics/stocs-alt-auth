<?php

namespace App\Services;

use App\Mail\PasswordResetEmail;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES = 60;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokens,
        private readonly AuditService $auditService,
    ) {}

    public function sendResetLink(string $email, ?Request $request = null): void
    {
        $user = $this->userRepository->findByEmail($email);

        $this->auditService->log(
            'password_reset_requested',
            'Password reset requested',
            $user?->id,
            metadata: ['user_found' => $user !== null],
            request: $request
        );

        // Don't reveal if user exists — always show success
        if (! $user) {
            return;
        }

        $token = Str::random(64);

        $this->passwordResetTokens->upsertForEmail($email, Hash::make($token));

        Mail::to($email)->queue(new PasswordResetEmail($user, $token));
    }

    public function reset(string $email, string $token, string $newPassword, ?Request $request = null): bool
    {
        $record = $this->passwordResetTokens->findByEmail($email);

        if (! $record) {
            return false;
        }

        if (! Hash::check($token, $record->token)) {
            return false;
        }

        if ($record->created_at->addMinutes(self::TOKEN_EXPIRY_MINUTES)->isPast()) {
            return false;
        }

        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            return false;
        }

        $this->userRepository->update($user, ['password' => $newPassword]);

        $user->tokens()->delete();

        $this->passwordResetTokens->deleteByEmail($email);

        $this->auditService->log(
            'password_reset_completed',
            'Password reset completed',
            $user->id,
            request: $request
        );

        return true;
    }
}
