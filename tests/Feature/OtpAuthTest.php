<?php

namespace Tests\Feature;

use App\Models\OtpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_request_returns_success(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'newuser@example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'OTP sent.');
        $response->assertJsonPath('expires_in', 600);

        $this->assertDatabaseCount('otp_tokens', 1);
    }

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
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Original Name',
            'email' => 'existing-named@example.com',
            'password' => null,
        ]);

        $user->platforms()->create([
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

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
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => null,
        ]);

        $user->platforms()->create([
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

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
