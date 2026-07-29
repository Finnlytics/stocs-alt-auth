<?php

namespace App\Services;

use App\Contracts\OtpNotifier;
use App\Enums\OtpRequestResult;
use App\Repositories\OtpRepository;
use App\Repositories\OtpRequestAttemptRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    private const OTP_EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 3;

    // BUSINESS RULE: resend policy. A caller may request their first code and
    // then resend it up to 3 more times with no wait (someone who didn't get
    // the email shouldn't be punished). After those 3 free resends we back off
    // exponentially — 5 min, 1 hour, 6 hours — and cap at 24 hours (max one
    // code per day) to stop anyone bashing the endpoint to send loads of email.
    //
    // Indexed by the count of the request that just completed: after the Nth
    // unverified request we lock the identifier for BACKOFF_SECONDS[N] seconds.
    // Index 0 is unused (counts start at 1). The counter resets on a successful
    // verify or after 24h of inactivity (see isStale()).
    private const BACKOFF_SECONDS = [
        0,        // [0] unused — request count starts at 1
        0,        // [1] after the initial send   → 1st resend is free
        0,        // [2] after the 1st resend     → 2nd resend is free
        0,        // [3] after the 2nd resend     → 3rd resend is free
        300,      // [4] after the 3rd resend     → 5 min before the next
        3600,     // [5] after the 4th resend     → 1 hour
        21600,    // [6] after the 5th resend     → 6 hours
        86400,    // [7]+ after the 6th resend    → 24 hours (cap, 1 per day)
    ];

    // BUSINESS RULE: once a caller has burned through all 3 free resends (the
    // 4th send in a session) they've entered the backoff zone — surface that to
    // admins once, via the audit log, so persistent resend abuse (or a user who
    // genuinely can't receive codes) is visible.
    private const RESEND_FLAG_THRESHOLD = 4;

    private const STALE_INACTIVITY_HOURS = 24;

    public function __construct(
        private readonly OtpRepository $otpRepository,
        private readonly OtpRequestAttemptRepository $attemptRepository,
        private readonly UserRepository $userRepository,
        private readonly OtpNotifier $notifier,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Sign-in flow. Only sends an OTP if the user actually exists. The caller
     * gets back ACCOUNT_NOT_FOUND when the email is unknown, but the HTTP
     * layer responds identically to SENT to avoid email enumeration.
     *
     * @return array{result: OtpRequestResult, retry_after?: int}
     */
    public function requestForLogin(string $identifier, string $identifierType = 'email', ?Request $request = null): array
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

        $this->dispatch($identifier, $identifierType, $user->id, $request);

        return ['result' => OtpRequestResult::SENT];
    }

    /**
     * Sign-up flow. Refuses if the identifier already has an account (per the
     * UX decision to surface "you already have an account, please sign in").
     *
     * @return array{result: OtpRequestResult, retry_after?: int}
     */
    public function requestForSignup(string $identifier, string $identifierType = 'email', ?Request $request = null): array
    {
        $user = $this->userRepository->findByEmail($identifier);

        if ($user !== null) {
            return ['result' => OtpRequestResult::ACCOUNT_ALREADY_EXISTS];
        }

        if (($backoff = $this->checkBackoff($identifier, $identifierType)) !== null) {
            return $backoff;
        }

        $this->dispatch($identifier, $identifierType, null, $request);

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

    private function dispatch(string $identifier, string $identifierType, ?int $userId, ?Request $request = null): void
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

        $newCount = $this->bumpAttempt($identifier, $identifierType);

        $this->flagIfExcessiveResends($identifier, $identifierType, $userId, $newCount, $request);

        $this->notifier->send($identifier, $identifierType, $code);
    }

    private function bumpAttempt(string $identifier, string $identifierType): int
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

        return $newCount;
    }

    // BUSINESS RULE: raise a single admin-visible audit entry the moment a
    // caller crosses the free-resend ceiling (see RESEND_FLAG_THRESHOLD). We
    // fire once (on the exact crossing) so a session produces at most one flag;
    // the counter resets on verify / staleness, so a genuinely abusive caller
    // re-flags on their next fresh burst. No PII: the identifier is stored as a
    // truncated SHA-256, never the raw email.
    private function flagIfExcessiveResends(
        string $identifier,
        string $identifierType,
        ?int $userId,
        int $count,
        ?Request $request,
    ): void {
        if ($count !== self::RESEND_FLAG_THRESHOLD) {
            return;
        }

        $this->auditService->log(
            'otp_resend_flagged',
            'Excessive OTP resend requests — free-resend ceiling reached',
            $userId,
            null,
            [
                'identifier_hash' => substr(hash('sha256', $identifier), 0, 12),
                'identifier_type' => $identifierType,
                'resend_count' => $count,
            ],
            $request,
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
