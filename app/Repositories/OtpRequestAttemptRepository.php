<?php

namespace App\Repositories;

use App\Models\OtpRequestAttempt;
use Illuminate\Support\Carbon;

class OtpRequestAttemptRepository
{
    public function findFor(string $identifier, string $identifierType = 'email'): ?OtpRequestAttempt
    {
        return OtpRequestAttempt::where('identifier', $identifier)
            ->where('identifier_type', $identifierType)
            ->first();
    }

    public function recordRequest(
        string $identifier,
        string $identifierType,
        int $unverifiedCount,
        Carbon $nextAllowedAt,
    ): OtpRequestAttempt {
        $attempt = $this->findFor($identifier, $identifierType);

        if ($attempt === null) {
            return OtpRequestAttempt::create([
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'unverified_count' => $unverifiedCount,
                'last_request_at' => now(),
                'next_allowed_at' => $nextAllowedAt,
            ]);
        }

        $attempt->update([
            'unverified_count' => $unverifiedCount,
            'last_request_at' => now(),
            'next_allowed_at' => $nextAllowedAt,
        ]);

        return $attempt;
    }

    public function reset(string $identifier, string $identifierType = 'email'): void
    {
        $attempt = $this->findFor($identifier, $identifierType);

        $attempt?->update([
            'unverified_count' => 0,
            'next_allowed_at' => null,
        ]);
    }
}
