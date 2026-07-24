<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\OtpToken;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function seedOtp(string $identifier, string $code = '123456'): void
    {
        OtpToken::create([
            'identifier' => $identifier,
            'identifier_type' => 'email',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    public function test_platform_enum_includes_buy(): void
    {
        $this->assertSame('buy', Platform::BUY->value);
        $this->assertNotNull(Platform::tryFrom('buy'));
    }

    public function test_otp_verify_with_buy_platform_grants_buy_access_and_stamps_origin(): void
    {
        $this->seedOtp('buyer@example.com');

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'buyer@example.com',
            'code' => '123456',
            'platform' => 'buy',
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_new_user', true);
        $response->assertJsonPath('data.signup_platform', 'buy');

        $this->assertDatabaseHas('users', [
            'email' => 'buyer@example.com',
            'signup_platform' => 'buy',
        ]);
        $this->assertDatabaseHas('user_platforms', [
            'platform' => 'buy',
            'role' => 'consumer',
            'status' => 'approved',
        ]);

        $user = User::where('email', 'buyer@example.com')->firstOrFail();
        $this->assertTrue($user->tokens()->first()->can('platform:buy'));
        $this->assertFalse($user->tokens()->first()->can('platform:bids'));
    }

    public function test_otp_verify_defaults_to_bids_and_stamps_bids_origin(): void
    {
        $this->seedOtp('defaultsite@example.com');

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'defaultsite@example.com',
            'code' => '123456',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.signup_platform', 'bids');
        $this->assertDatabaseHas('user_platforms', ['platform' => 'bids', 'status' => 'approved']);
        $this->assertDatabaseMissing('user_platforms', ['platform' => 'buy']);
    }

    public function test_otp_verify_rejects_non_consumer_platform(): void
    {
        $this->seedOtp('badplatform@example.com');

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'badplatform@example.com',
            'code' => '123456',
            'platform' => 'b2b',
        ])->assertStatus(422);
    }

    public function test_existing_bids_user_buying_keeps_original_signup_platform(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Crossover User',
            'email' => 'crossover@example.com',
            'password' => null,
            'signup_platform' => 'bids',
        ]);
        $user->platforms()->create([
            'platform' => 'bids', 'role' => 'consumer', 'status' => 'approved', 'approved_at' => now(),
        ]);

        $this->seedOtp('crossover@example.com');

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => 'crossover@example.com',
            'code' => '123456',
            'platform' => 'buy',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'crossover@example.com', 'signup_platform' => 'bids']);
        $this->assertDatabaseHas('user_platforms', ['platform' => 'buy', 'status' => 'approved']);
    }

    public function test_grant_admin_access_spans_buy(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Admin User',
            'email' => 'admin-span@example.com',
            'password' => Hash::make('password-1234'),
        ]);

        app(PlatformAccessService::class)->grantAdminAccess($user);

        foreach (['b2b', 'bids', 'buy'] as $platform) {
            $this->assertDatabaseHas('user_platforms', [
                'user_id' => $user->id,
                'platform' => $platform,
                'role' => 'admin',
                'status' => 'approved',
            ]);
        }

        $this->assertTrue($user->isAdminOn(Platform::BUY));
    }

    public function test_created_admin_token_carries_buy_ability(): void
    {
        $creator = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Super',
            'email' => 'super@example.com',
            'password' => Hash::make('password-1234'),
        ]);
        $creator->is_super_admin = true;
        $creator->save();

        $result = app(AuthService::class)->createAdmin([
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'password-1234',
        ], $creator);

        $newAdmin = $result['user'];
        $this->assertTrue($newAdmin->tokens()->first()->can('platform:buy'));
        $this->assertTrue($newAdmin->tokens()->first()->can('platform:bids'));
        $this->assertTrue($newAdmin->tokens()->first()->can('platform:b2b'));
    }
}
