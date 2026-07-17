<?php

namespace App\Services;

use App\Contracts\OtpNotifier;
use App\Enums\OtpRequestResult;
use App\Enums\Platform;
use App\Models\OtpRequestAttempt;
use App\Repositories\OtpRepository;
use App\Repositories\OtpRequestAttemptRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    private const OTP_EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 3;

    // BUSINESS RULE: single, predictable rate limit against email-spam abuse —
    // a short cooldown between requests, plus a flat cap per rolling hour.
    // Replaces a multi-tier exponential ramp (30s -> ... -> 24h) that, stacked
    // on top of a second, separate route-level limiter, made the "try again
    // in N seconds" figure shown to users unpredictable. One rule, one number.
    // Resets on a successful verify (see verifyOtp()).
    private const COOLDOWN_SECONDS = 30;

    private const HOURLY_CAP = 8;

    public function __construct(
        private readonly OtpRepository $otpRepository,
        private readonly OtpRequestAttemptRepository $attemptRepository,
        private readonly UserRepository $userRepository,
        private readonly OtpNotifier $notifier,
    ) {}

    /**
     * Sign-in flow. Only sends an OTP if the user actually exists. The caller
     * gets back ACCOUNT_NOT_FOUND when the email is unknown, but the HTTP
     * layer responds identically to SENT to avoid email enumeration.
     *
     * @return array{result: OtpRequestResult, retry_after?: int}
     */
    public function requestForLogin(string $identifier, string $identifierType = 'email'): array
    {
        if (($limited = $this->checkRateLimit($identifier, $identifierType)) !== null) {
            return $limited;
        }

        $user = $this->userRepository->findByEmail($identifier);

        if ($user === null) {
            // No tracking, no send. We don't count unknown users against the
            // rate limit because that would let an attacker probe existence
            // via the 429 boundary.
            return ['result' => OtpRequestResult::ACCOUNT_NOT_FOUND];
        }

        if ($user->platformAccess(Platform::BIDS)?->isSuspended()) {
            // No point sending a code the verify step will reject anyway
            // (AuthService::completeBidsRegistration blocks suspended access).
            return ['result' => OtpRequestResult::ACCOUNT_SUSPENDED];
        }

        $this->dispatch($identifier, $identifierType, $user->id);

        return ['result' => OtpRequestResult::SENT];
    }

    /**
     * Sign-up flow. Refuses if the identifier already has an account (per the
     * UX decision to surface "you already have an account, please sign in").
     *
     * @return array{result: OtpRequestResult, retry_after?: int}
     */
    public function requestForSignup(string $identifier, string $identifierType = 'email'): array
    {
        $user = $this->userRepository->findByEmail($identifier);

        if ($user !== null) {
            return ['result' => OtpRequestResult::ACCOUNT_ALREADY_EXISTS];
        }

        if (($limited = $this->checkRateLimit($identifier, $identifierType)) !== null) {
            return $limited;
        }

        $this->dispatch($identifier, $identifierType, null);

        return ['result' => OtpRequestResult::SENT];
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

        // BUSINESS RULE: a successful verify clears the rate limit — the user
        // proved they own the inbox.
        $this->attemptRepository->reset($identifier, $identifierType);

        return ['verified' => true, 'user_id' => $otpToken->user_id];
    }

    // BUSINESS RULE: When admin re-approves a previously suspended user, their
    // OTP rate limit must be cleared so they can immediately request a new code.
    public function clearRateLimitFor(string $identifier, string $identifierType = 'email'): void
    {
        $this->attemptRepository->reset($identifier, $identifierType);
    }

    /**
     * @return array{result: OtpRequestResult, retry_after: int}|null
     */
    private function checkRateLimit(string $identifier, string $identifierType): ?array
    {
        $attempt = $this->attemptRepository->findFor($identifier, $identifierType);

        if ($attempt === null) {
            return null;
        }

        if ($attempt->last_request_at !== null) {
            $cooldownEndsAt = $attempt->last_request_at->copy()->addSeconds(self::COOLDOWN_SECONDS);

            if ($cooldownEndsAt->isFuture()) {
                return [
                    'result' => OtpRequestResult::RATE_LIMITED,
                    'retry_after' => max(1, now()->diffInSeconds($cooldownEndsAt, false)),
                ];
            }
        }

        if (! $this->isHourlyWindowExpired($attempt) && $attempt->requests_this_hour >= self::HOURLY_CAP) {
            $windowEndsAt = $attempt->hour_window_started_at->copy()->addHour();

            return [
                'result' => OtpRequestResult::RATE_LIMITED,
                'retry_after' => max(1, now()->diffInSeconds($windowEndsAt, false)),
            ];
        }

        return null;
    }

    private function dispatch(string $identifier, string $identifierType, ?int $userId): void
    {
        $this->otpRepository->invalidateForIdentifier($identifier, $identifierType);

        $code = $this->generateCode();

        $this->otpRepository->create([
            'user_id' => $userId,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
            'created_at' => now(),
        ]);

        $this->bumpAttempt($identifier, $identifierType);

        $this->notifier->send($identifier, $identifierType, $code);
    }

    private function bumpAttempt(string $identifier, string $identifierType): void
    {
        $attempt = $this->attemptRepository->findFor($identifier, $identifierType);

        $windowExpired = $attempt === null || $this->isHourlyWindowExpired($attempt);

        $newWindowStart = $windowExpired ? now() : $attempt->hour_window_started_at;
        $newCount = $windowExpired ? 1 : $attempt->requests_this_hour + 1;

        $this->attemptRepository->recordRequest($identifier, $identifierType, $newCount, $newWindowStart);
    }

    private function isHourlyWindowExpired(OtpRequestAttempt $attempt): bool
    {
        return $attempt->hour_window_started_at === null
            || $attempt->hour_window_started_at->copy()->addHour()->isPast();
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
