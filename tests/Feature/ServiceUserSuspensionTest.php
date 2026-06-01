<?php

namespace Tests\Feature;

use App\Enums\PlatformStatus;
use App\Models\ServiceApiKey;
use App\Models\User;
use App\Models\UserPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceUserSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function serviceKey(): array
    {
        ServiceApiKey::create([
            'name' => 'bids-backend',
            'key_prefix' => 'sk_bids001',
            'key_hash' => Hash::make('correct-secret'),
            'platform' => 'bids',
            'is_active' => true,
        ]);

        return ['X-Service-Key' => 'sk_bids001.correct-secret'];
    }

    private function bidsUser(): User
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Bidder',
            'email' => 'bidder@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'bids',
            'role' => 'consumer',
            'status' => PlatformStatus::APPROVED->value,
            'approved_at' => now(),
        ]);

        return $user;
    }

    public function test_requires_service_key(): void
    {
        $user = $this->bidsUser();

        $this->postJson("/api/v1/service/users/{$user->uuid}/suspend")
            ->assertStatus(401);
    }

    public function test_suspends_bids_access_and_revokes_tokens(): void
    {
        $headers = $this->serviceKey();
        $user = $this->bidsUser();
        $user->createToken('bids-web', ['platform:bids']);

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeaders($headers)
            ->postJson("/api/v1/service/users/{$user->uuid}/suspend", [
                'platform' => 'bids',
                'reason' => 'non_payment_order_ORD-2026-ABCDE',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User suspended.');

        $access = UserPlatform::where('user_id', $user->id)->where('platform', 'bids')->first();
        $this->assertSame(PlatformStatus::SUSPENDED->value, $access->status);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_defaults_to_bids_platform_when_omitted(): void
    {
        $headers = $this->serviceKey();
        $user = $this->bidsUser();

        $this->withHeaders($headers)
            ->postJson("/api/v1/service/users/{$user->uuid}/suspend")
            ->assertOk();

        $access = UserPlatform::where('user_id', $user->id)->where('platform', 'bids')->first();
        $this->assertSame(PlatformStatus::SUSPENDED->value, $access->status);
    }

    public function test_returns_404_for_unknown_user(): void
    {
        $headers = $this->serviceKey();

        $this->withHeaders($headers)
            ->postJson('/api/v1/service/users/'.Str::uuid()->toString().'/suspend')
            ->assertStatus(404);
    }

    public function test_returns_404_when_no_platform_access_record(): void
    {
        $headers = $this->serviceKey();
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'NoBids',
            'email' => 'nobids@example.com',
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/service/users/{$user->uuid}/suspend", ['platform' => 'bids'])
            ->assertStatus(404);
    }

    public function test_reinstate_lifts_a_suspension_to_approved(): void
    {
        $headers = $this->serviceKey();
        $user = $this->bidsUser();
        UserPlatform::where('user_id', $user->id)->where('platform', 'bids')
            ->update(['status' => PlatformStatus::SUSPENDED->value]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/service/users/{$user->uuid}/reinstate", ['platform' => 'bids'])
            ->assertOk()
            ->assertJsonPath('message', 'User reinstated.');

        $access = UserPlatform::where('user_id', $user->id)->where('platform', 'bids')->first();
        $this->assertSame(PlatformStatus::APPROVED->value, $access->status);
        $this->assertNotNull($access->approved_at);
    }

    public function test_reinstate_rejects_a_user_that_is_not_suspended(): void
    {
        $headers = $this->serviceKey();
        $user = $this->bidsUser(); // approved

        $this->withHeaders($headers)
            ->postJson("/api/v1/service/users/{$user->uuid}/reinstate", ['platform' => 'bids'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is not suspended.');
    }

    public function test_reinstate_requires_service_key(): void
    {
        $user = $this->bidsUser();

        $this->postJson("/api/v1/service/users/{$user->uuid}/reinstate")
            ->assertStatus(401);
    }
}
