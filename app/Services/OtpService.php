<?php

namespace App\Services;

use App\Contracts\OtpNotifier;
use App\Repositories\OtpRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    private const OTP_EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly OtpRepository $otpRepository,
        private readonly UserRepository $userRepository,
        private readonly OtpNotifier $notifier,
    ) {}

    // Rate limiting (5/hour per identifier) is enforced by the `throttle:otp` middleware
    // in AppServiceProvider. Do not re-check here; services should not emit HTTP responses.
    public function sendOtp(string $identifier, string $identifierType = 'email'): void
    {
        $this->otpRepository->invalidateForIdentifier($identifier, $identifierType);

        $code = $this->generateCode();

        $user = $this->userRepository->findByEmail($identifier);

        $this->otpRepository->create([
            'user_id' => $user?->id,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
            'created_at' => now(),
        ]);

        $this->notifier->send($identifier, $identifierType, $code);
    }

    /**
     * Verify an OTP code.
     *
     * Returns ['verified' => true/false, 'user_id' => int|null]
     * user_id is null when OTP was created for a new (not-yet-registered) user.
     */
    public function verifyOtp(string $identifier, string $code, string $identifierType = 'email'): array
    {
        $otpToken = $this->otpRepository->findLatestValid($identifier, $identifierType);

        if (! $otpToken) {
            return ['verified' => false, 'user_id' => null];
        }

        if ($otpToken->hasExceededAttempts(self::MAX_ATTEMPTS)) {
            return ['verified' => false, 'user_id' => null];
        }

        if (! Hash::check($code, $otpToken->code_hash)) {
            $otpToken->increment('attempts');

            return ['verified' => false, 'user_id' => null];
        }

        $otpToken->update(['verified_at' => now()]);

        return ['verified' => true, 'user_id' => $otpToken->user_id];
    }

    // BUSINESS RULE: When admin re-approves a previously suspended user, their
    // OTP rate-limit counters (5/hour, 3/min per identifier — set in
    // AppServiceProvider) must be cleared so they can immediately request a new
    // code. Keys here must mirror AppServiceProvider's `throttle:otp` limiter.
    public function clearRateLimitFor(string $identifier): void
    {
        RateLimiter::clear('otp:hour:'.$identifier);
        RateLimiter::clear('otp:minute:'.$identifier);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
