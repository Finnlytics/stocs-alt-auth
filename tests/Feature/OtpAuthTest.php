<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\OtpRequestAttempt;
use App\Models\OtpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    private function createBidsUser(string $email, string $name = 'Existing User'): User
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'email' => $email,
            'password' => null,
        ]);

        $user->platforms()->create([
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $user;
    }

    // ──────────────────────────────────────────────
    // Sign-up request endpoint
    // ──────────────────────────────────────────────

    public function test_signup_request_sends_otp_for_new_email(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/request/signup', [
            'identifier' => 'newuser@example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'OTP sent.');
        $response->assertJsonPath('expires_in', 600);

        $this->assertDatabaseCount('otp_tokens', 1);
        $this->assertDatabaseHas('otp_request_attempts', [
            'identifier' => 'newuser@example.com',
            'requests_this_hour' => 1,
        ]);
    }

    public function test_signup_request_refuses_when_account_already_exists(): void
    {
        $this->createBidsUser('already@example.com');

        $response = $this->postJson('/api/v1/auth/otp/request/signup', [
            'identifier' => 'already@example.com',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'account_exists');

        $this->assertDatabaseCount('otp_tokens', 0);
    }

    public function test_signup_request_refuses_when_account_already_exists_with_different_case(): void
    {
        $this->createBidsUser('already@example.com');

        $response = $this->postJson('/api/v1/auth/otp/request/signup', [
            'identifier' => 'Already@Example.com',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'account_exists');
    }

    // ──────────────────────────────────────────────
    // Sign-in request endpoint
    // ──────────────────────────────────────────────

    public function test_login_request_sends_otp_when_user_exists(): void
    {
        $user = $this->createBidsUser('member@example.com');

        $response = $this->postJson('/api/v1/auth/otp/request/login', [
            'identifier' => 'member@example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('expires_in', 600);

        $this->assertDatabaseCount('otp_tokens', 1);
        $this->assertDatabaseHas('otp_tokens', [
            'user_id' => $user->id,
            'identifier' => 'member@example.com',
        ]);
    }

    public function test_login_request_does_not_send_otp_for_unknown_email_but_returns_generic_202(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/request/login', [
            'identifier' => 'ghost@example.com',
        ]);

        $response->assertStatus(202);
        // BUSINESS RULE: response must not differ from the SENT case so the
        // endpoint can't be used for email enumeration.
        $response->assertJsonStructure(['message', 'expires_in']);

        $this->assertDatabaseCount('otp_tokens', 0);
        $this->assertDatabaseCount('otp_request_attempts', 0);
    }

    public function test_login_request_blocks_suspended_user_and_does_not_send_otp(): void
    {
        $user = $this->createBidsUser('suspended@example.com');
        $user->platformAccess(Platform::BIDS)->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/auth/otp/request/login', [
            'identifier' => 'suspended@example.com',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'suspended');

        $this->assertDatabaseCount('otp_tokens', 0);
    }

    // ──────────────────────────────────────────────
    // Route-level `throttle:auth` limiter (AppServiceProvider, wraps the whole
    // /v1/auth group — 5/min per IP) — regression coverage for a bug where
    // named-limiter routes had no registered exception handler: hitting one
    // returned Laravel's raw debug response (full stack trace, no
    // retry_after) instead of the app's usual clean rate-limit JSON. OTP's
    // own per-identifier limiter (below) is a separate, additional layer.
    // ──────────────────────────────────────────────

    public function test_route_level_auth_throttle_returns_clean_json_not_a_debug_trace(): void
    {
        $payload = ['email' => 'nobody@example.com', 'password' => 'wrong'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login/b2b', $payload);
        }

        $response = $this->postJson('/api/v1/auth/login/b2b', $payload);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'route_throttled');
        $response->assertJsonStructure(['message', 'code', 'retry_after']);
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $response->assertHeader('Retry-After');
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    // ──────────────────────────────────────────────
    // Rate limiting — single rule per identifier: a 30s cooldown between
    // requests, plus a flat cap of 8 per rolling hour. Resets on verify.
    // ──────────────────────────────────────────────

    public function test_second_signup_request_within_cooldown_is_rate_limited(): void
    {
        $payload = ['identifier' => 'spam-target@example.com'];

        $this->postJson('/api/v1/auth/otp/request/signup', $payload)->assertStatus(202);

        $response = $this->postJson('/api/v1/auth/otp/request/signup', $payload);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'otp_backoff');
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertLessThanOrEqual(30, $response->json('retry_after'));
        $response->assertHeader('Retry-After');

        $this->assertDatabaseCount('otp_tokens', 1);
    }

    public function test_login_request_within_cooldown_is_rate_limited(): void
    {
        $this->createBidsUser('member-bk@example.com');

        $payload = ['identifier' => 'member-bk@example.com'];

        $this->postJson('/api/v1/auth/otp/request/login', $payload)->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/request/login', $payload)->assertStatus(429);
    }

    public function test_login_request_does_not_track_attempts_for_unknown_emails(): void
    {
        $payload = ['identifier' => 'unknown@example.com'];

        // Even after many calls, an unknown email must not accumulate rate-limit
        // state — otherwise the 429 boundary becomes an enumeration oracle.
        $this->postJson('/api/v1/auth/otp/request/login', $payload)->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/request/login', $payload)->assertStatus(202);

        $this->assertDatabaseCount('otp_request_attempts', 0);
    }

    public function test_successful_verify_resets_the_rate_limit(): void
    {
        $code = '111222';
        OtpToken::create([
            'identifier' => 'verifier@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
        OtpRequestAttempt::create([
            'identifier' => 'verifier@example.com',
            'identifier_type' => 'email',
            'requests_this_hour' => 4,
            'last_request_at' => now(),
            'hour_window_started_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'verifier@example.com',
            'code' => $code,
        ])->assertOk();

        $this->assertDatabaseHas('otp_request_attempts', [
            'identifier' => 'verifier@example.com',
            'requests_this_hour' => 0,
            'hour_window_started_at' => null,
            'last_request_at' => null,
        ]);
    }

    public function test_hourly_window_resets_after_an_hour_of_inactivity(): void
    {
        OtpRequestAttempt::create([
            'identifier' => 'stale@example.com',
            'identifier_type' => 'email',
            'requests_this_hour' => 8,
            'last_request_at' => now()->subHours(2),
            'hour_window_started_at' => now()->subHours(2),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/request/signup', [
            'identifier' => 'stale@example.com',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('otp_request_attempts', [
            'identifier' => 'stale@example.com',
            'requests_this_hour' => 1,
        ]);
    }

    public function test_cooldown_blocks_an_immediate_repeat_but_allows_it_once_elapsed(): void
    {
        $payload = ['identifier' => 'cooldown@example.com'];

        $this->postJson('/api/v1/auth/otp/request/signup', $payload)->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/request/signup', $payload)->assertStatus(429);

        // Jump the cooldown so the next request is allowed again.
        $attempt = OtpRequestAttempt::firstWhere('identifier', 'cooldown@example.com');
        $attempt->update(['last_request_at' => now()->subSeconds(31)]);

        $this->postJson('/api/v1/auth/otp/request/signup', $payload)->assertStatus(202);

        $attempt->refresh();
        $this->assertSame(2, $attempt->requests_this_hour);
    }

    public function test_hourly_cap_blocks_the_ninth_request_within_the_hour(): void
    {
        // Seed 8 requests already made in the current window (at the cap),
        // with the cooldown already elapsed so only the hourly cap is at play.
        OtpRequestAttempt::create([
            'identifier' => 'capped@example.com',
            'identifier_type' => 'email',
            'requests_this_hour' => 8,
            'last_request_at' => now()->subSeconds(31),
            'hour_window_started_at' => now()->subMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/request/signup', [
            'identifier' => 'capped@example.com',
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'otp_backoff');
        // Window started 10 minutes ago, so ~50 minutes remain until it expires.
        $this->assertEqualsWithDelta(50 * 60, $response->json('retry_after'), 5);
    }

    // ──────────────────────────────────────────────
    // Verify (existing behaviour, retained)
    // ──────────────────────────────────────────────

    public function test_otp_verify_creates_new_user_on_first_login(): void
    {
        $code = '123456';

        OtpToken::create([
            'identifier' => 'newuser@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'newuser@example.com',
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'token', 'is_new_user']);
        $response->assertJsonPath('is_new_user', true);

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
        $this->assertDatabaseHas('user_platforms', [
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'approved',
        ]);
    }

    public function test_otp_verify_uses_supplied_name_when_creating_new_user(): void
    {
        $code = '123456';

        OtpToken::create([
            'identifier' => 'joiner@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'joiner@example.com',
            'code' => $code,
            'name' => 'Sam Joiner',
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_new_user', true);
        $this->assertDatabaseHas('users', [
            'email' => 'joiner@example.com',
            'name' => 'Sam Joiner',
        ]);
    }

    public function test_otp_verify_ignores_supplied_name_for_existing_user(): void
    {
        $user = $this->createBidsUser('existing-named@example.com', 'Original Name');

        $code = '654321';

        OtpToken::create([
            'user_id' => $user->id,
            'identifier' => 'existing-named@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'existing-named@example.com',
            'code' => $code,
            'name' => 'Imposter Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_new_user', false);
        $this->assertDatabaseHas('users', [
            'email' => 'existing-named@example.com',
            'name' => 'Original Name',
        ]);
    }

    public function test_otp_verify_falls_back_to_email_local_part_when_name_omitted(): void
    {
        $code = '123456';

        OtpToken::create([
            'identifier' => 'fallback-user@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'fallback-user@example.com',
            'code' => $code,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => 'fallback-user@example.com',
            'name' => 'fallback-user',
        ]);
    }

    public function test_otp_verify_logs_in_existing_user(): void
    {
        $user = $this->createBidsUser('existing@example.com');

        $code = '654321';

        OtpToken::create([
            'user_id' => $user->id,
            'identifier' => 'existing@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'existing@example.com',
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_new_user', false);
    }

    public function test_otp_verify_fails_with_wrong_code(): void
    {
        OtpToken::create([
            'identifier' => 'test@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'test@example.com',
            'code' => '999999',
        ]);

        $response->assertStatus(422);
    }

    public function test_otp_verify_blocks_suspended_user_and_does_not_re_approve(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Suspended User',
            'email' => 'suspended-bids@example.com',
            'password' => null,
        ]);

        $user->platforms()->create([
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'suspended',
            'approved_at' => now()->subDay(),
        ]);

        $code = '424242';

        OtpToken::create([
            'user_id' => $user->id,
            'identifier' => 'suspended-bids@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'suspended-bids@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'suspended');
        $response->assertJsonMissingPath('token');

        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $user->id,
            'platform' => 'bids',
            'status' => 'suspended',
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_otp_verify_fails_with_expired_code(): void
    {
        OtpToken::create([
            'identifier' => 'test@example.com',
            'identifier_type' => 'email',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(11),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'test@example.com',
            'code' => '123456',
        ]);

        $response->assertStatus(422);
    }
}
