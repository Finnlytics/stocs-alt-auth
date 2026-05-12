<?php

namespace Tests\Feature;

use App\Models\ServiceApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_missing_header(): void
    {
        $response = $this->postJson('/api/v1/service/validate-token', ['token' => 'x']);

        $response->assertStatus(401);
    }

    public function test_rejects_malformed_key(): void
    {
        $response = $this->withHeaders(['X-Service-Key' => 'no_dot_here'])
            ->postJson('/api/v1/service/validate-token', ['token' => 'x']);

        $response->assertStatus(401);
    }

    public function test_rejects_unknown_prefix(): void
    {
        $response = $this->withHeaders(['X-Service-Key' => 'sk_unknown.secretvalue'])
            ->postJson('/api/v1/service/validate-token', ['token' => 'x']);

        $response->assertStatus(401);
    }

    public function test_rejects_wrong_secret(): void
    {
        ServiceApiKey::create([
            'name' => 'b2b-backend',
            'key_prefix' => 'sk_test123',
            'key_hash' => Hash::make('correct-secret'),
            'platform' => 'b2b',
            'is_active' => true,
        ]);

        $response = $this->withHeaders(['X-Service-Key' => 'sk_test123.wrong-secret'])
            ->postJson('/api/v1/service/validate-token', ['token' => 'x']);

        $response->assertStatus(401);
    }

    public function test_rejects_inactive_key(): void
    {
        ServiceApiKey::create([
            'name' => 'b2b-backend',
            'key_prefix' => 'sk_test123',
            'key_hash' => Hash::make('correct-secret'),
            'platform' => 'b2b',
            'is_active' => false,
        ]);

        $response = $this->withHeaders(['X-Service-Key' => 'sk_test123.correct-secret'])
            ->postJson('/api/v1/service/validate-token', ['token' => 'x']);

        $response->assertStatus(401);
    }

    public function test_accepts_valid_key_and_validates_user_token(): void
    {
        ServiceApiKey::create([
            'name' => 'b2b-backend',
            'key_prefix' => 'sk_test123',
            'key_hash' => Hash::make('correct-secret'),
            'platform' => 'b2b',
            'is_active' => true,
        ]);

        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $userToken = $user->createToken('b2b-web', ['platform:b2b'])->plainTextToken;

        $response = $this->withHeaders(['X-Service-Key' => 'sk_test123.correct-secret'])
            ->postJson('/api/v1/service/validate-token', ['token' => $userToken]);

        $response->assertOk();
        $response->assertJsonPath('valid', true);
    }

    public function test_touch_last_used_is_debounced(): void
    {
        $key = ServiceApiKey::create([
            'name' => 'b2b-backend',
            'key_prefix' => 'sk_test123',
            'key_hash' => Hash::make('correct-secret'),
            'platform' => 'b2b',
            'is_active' => true,
            'last_used_at' => now()->subMinute(),
        ]);

        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);
        $userToken = $user->createToken('b2b-web', ['platform:b2b'])->plainTextToken;

        $originalLastUsed = $key->last_used_at;

        $this->withHeaders(['X-Service-Key' => 'sk_test123.correct-secret'])
            ->postJson('/api/v1/service/validate-token', ['token' => $userToken]);

        $key->refresh();

        // Stayed the same — within 5-minute debounce window
        $this->assertEquals($originalLastUsed->timestamp, $key->last_used_at->timestamp);
    }
}
