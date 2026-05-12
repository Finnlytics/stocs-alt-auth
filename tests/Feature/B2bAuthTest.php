<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class B2bAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_register_creates_user_with_both_platform_records(): void
    {
        $response = $this->postJson('/api/v1/auth/register/b2b', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
            'password_confirmation' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['uuid', 'name', 'email', 'platforms'],
            'token',
            'message',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

        // B2B access should be pending
        $this->assertDatabaseHas('user_platforms', [
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'pending',
        ]);

        // Bids access should be auto-approved
        $this->assertDatabaseHas('user_platforms', [
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => 'approved',
        ]);
    }

    public function test_b2b_register_rejects_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register/b2b', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_b2b_register_rejects_duplicate_email(): void
    {
        User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Existing',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $response = $this->postJson('/api/v1/auth/register/b2b', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
            'password_confirmation' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_b2b_login_returns_token(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login/b2b', [
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'token', 'message']);
    }

    public function test_b2b_login_fails_with_wrong_password(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'approved',
        ]);

        $response = $this->postJson('/api/v1/auth/login/b2b', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword1',
        ]);

        $response->assertStatus(401);
    }

    public function test_b2b_login_fails_for_suspended_user(): void
    {
        $this->createB2bUser('suspended');

        $response = $this->postJson('/api/v1/auth/login/b2b', [
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'suspended');
    }

    public function test_b2b_login_fails_for_pending_user(): void
    {
        $this->createB2bUser('pending');

        $response = $this->postJson('/api/v1/auth/login/b2b', [
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'pending');
    }

    public function test_b2b_login_fails_for_rejected_user(): void
    {
        $this->createB2bUser('rejected');

        $response = $this->postJson('/api/v1/auth/login/b2b', [
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('status', 'rejected');
    }

    private function createB2bUser(string $status): User
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => $status,
        ]);

        return $user;
    }

    public function test_me_endpoint_returns_user_with_platforms(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'approved',
        ]);

        $token = $user->createToken('test', ['platform:b2b'])->plainTextToken;

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_me_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $token = $user->createToken('test', ['platform:b2b'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/logout');

        $response->assertOk();

        // Verify token was deleted from DB
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
