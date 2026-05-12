<?php

namespace App\Repositories;

use App\Models\OtpToken;

class OtpRepository
{
    public function create(array $data): OtpToken
    {
        return OtpToken::create($data);
    }

    public function findLatestValid(string $identifier, string $identifierType = 'email'): ?OtpToken
    {
        return OtpToken::where('identifier', $identifier)
            ->where('identifier_type', $identifierType)
            ->where('expires_at', '>', now())
            ->whereNull('verified_at')
            ->where('attempts', '<', 3)
            ->latest('created_at')
            ->first();
    }

    public function invalidateForIdentifier(string $identifier, string $identifierType = 'email'): void
    {
        OtpToken::where('identifier', $identifier)
            ->where('identifier_type', $identifierType)
            ->whereNull('verified_at')
            ->delete();
    }

    public function deleteExpired(): int
    {
        return OtpToken::where('expires_at', '<', now()->subDay())->delete();
    }
}
