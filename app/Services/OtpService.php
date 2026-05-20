<?php

namespace App\Services;

use App\Contracts\OtpNotifier;
use App\Enums\OtpRequestResult;
use App\Repositories\OtpRepository;
use App\Repositories\OtpRequestAttemptRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    private const OTP_EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 3;

    // BUSINESS RULE: exponential backoff against email-spam abuse. The Nth
    // unverified request locks the identifier for $BACKOFF_SECONDS[N] seconds.
    // First request is free (index 0). After 6+ unverified requests in a
    // session we hold them at 24h. Counter resets on a successful verify or
    // after 24h of inactivity (see resetIfStale()).
    private const BACKOFF_SECONDS = [
        0,        // 1st request: no delay until next
        30,       // 2nd request: 30s
        120,      // 3rd: 2 min
        600,      // 4th: 10 min
        3600,     // 5th: 1 hour
        21600,    // 6th: 6 hours
        86400,    // 7th+: 24 hours (cap)
    ];

    private const STALE_INACTIVITY_HOURS = 24;

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
        if (($backoff = $this->checkBackoff($identifier, $identifierType)) !== null) {
            return $backoff;
        }

        $user = $this->userRepository->findByEmail($identifier);

        if ($user === null) {
            // No tracking, no send. We don't increment backoff for non-existent
            // users because that would let an attacker probe existence via the
            // 429 boundary. The in-memory throttle:otp middleware still caps
            // raw request volume per identifier/IP.
            return ['result' => OtpRequestResult::ACCOUNT_NOT_FOUND];
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

        if (($backoff = $this->checkBackoff($identifier, $identifierType)) !== null) {
            return $backoff;
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

        // BUSINESS RULE: a successful verify clears the exponential backoff
        // counter — the user proved they own the inbox.
        $this->attemptRepository->reset($identifier, $identifierType);

        return ['verified' => true, 'user_id' => $otpToken->user_id];
    }

    // BUSINESS RULE: When admin re-approves a previously suspended user, their
    // OTP rate-limit counters (5/hour, 3/min per identifier — set in
    // AppServiceProvider) must be cleared so they can immediately request a new
    // code. Keys here must mirror AppServiceProvider's `throttle:otp` limiter.
    public function clearRateLimitFor(string $identifier, string $identifierType = 'email'): void
    {
        RateLimiter::clear('otp:hour:'.$identifier);
        RateLimiter::clear('otp:minute:'.$identifier);
        $this->attemptRepository->reset($identifier, $identifierType);
    }

    /**
     * @return array{result: OtpRequestResult, retry_after: int}|null
     */
    private function checkBackoff(string $identifier, string $identifierType): ?array
    {
        $attempt = $this->attemptRepository->findFor($identifier, $identifierType);

        if ($attempt === null) {
            return null;
        }

        if ($this->isStale($attempt->last_request_at)) {
            // Inactivity reset happens lazily on the next request (no
            // background job needed): we just treat them as a fresh caller.
            return null;
        }

        if ($attempt->isInBackoff()) {
            return [
                'result' => OtpRequestResult::RATE_LIMITED,
                'retry_after' => $attempt->retryAfterSeconds(),
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

        $currentCount = $attempt !== null && ! $this->isStale($attempt->last_request_at)
            ? $attempt->unverified_count
            : 0;

        $newCount = $currentCount + 1;

        $this->attemptRepository->recordRequest(
            $identifier,
            $identifierType,
            $newCount,
            now()->addSeconds($this->backoffFor($newCount)),
        );
    }

    private function backoffFor(int $count): int
    {
        $index = min($count, count(self::BACKOFF_SECONDS) - 1);

        return self::BACKOFF_SECONDS[$index];
    }

    private function isStale(?Carbon $lastRequestAt): bool
    {
        if ($lastRequestAt === null) {
            return true;
        }

        return $lastRequestAt->lt(now()->subHours(self::STALE_INACTIVITY_HOURS));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
