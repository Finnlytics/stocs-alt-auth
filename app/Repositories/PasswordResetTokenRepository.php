<?php

namespace App\Repositories;

use App\Models\PasswordResetToken;

class PasswordResetTokenRepository
{
    public function findByEmail(string $email): ?PasswordResetToken
    {
        return PasswordResetToken::where('email', $email)->first();
    }

    public function upsertForEmail(string $email, string $tokenHash): PasswordResetToken
    {
        PasswordResetToken::where('email', $email)->delete();

        return PasswordResetToken::create([
            'email' => $email,
            'token' => $tokenHash,
            'created_at' => now(),
        ]);
    }

    public function deleteByEmail(string $email): void
    {
        PasswordResetToken::where('email', $email)->delete();
    }
}
