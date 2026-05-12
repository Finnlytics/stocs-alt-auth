<?php

namespace App\Services;

use App\Mail\PasswordResetEmail;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES = 60;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokens,
    ) {}

    public function sendResetLink(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        // Don't reveal if user exists — always show success
        if (! $user) {
            return;
        }

        $token = Str::random(64);

        $this->passwordResetTokens->upsertForEmail($email, Hash::make($token));

        Mail::to($email)->queue(new PasswordResetEmail($user, $token));
    }

    public function reset(string $email, string $token, string $newPassword): bool
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

        return true;
    }
}
