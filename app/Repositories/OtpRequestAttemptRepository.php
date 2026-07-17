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
        int $requestsThisHour,
        Carbon $hourWindowStartedAt,
    ): OtpRequestAttempt {
        $attempt = $this->findFor($identifier, $identifierType);

        $data = [
            'requests_this_hour' => $requestsThisHour,
            'last_request_at' => now(),
            'hour_window_started_at' => $hourWindowStartedAt,
        ];

        if ($attempt === null) {
            return OtpRequestAttempt::create([
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                ...$data,
            ]);
        }

        $attempt->update($data);

        return $attempt;
    }

    public function reset(string $identifier, string $identifierType = 'email'): void
    {
        $attempt = $this->findFor($identifier, $identifierType);

        $attempt?->update([
            'requests_this_hour' => 0,
            'hour_window_started_at' => null,
            'last_request_at' => null,
        ]);
    }
}
