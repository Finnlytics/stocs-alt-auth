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

class ServiceUserListTest extends TestCase
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

    private function userOn(string $platform, string $name, string $email, string $status = PlatformStatus::APPROVED->value): User
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'email' => $email,
            'password' => 'Xk9$mQ2vR7nP',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'role' => 'consumer',
            'status' => $status,
            'approved_at' => $status === PlatformStatus::APPROVED->value ? now() : null,
        ]);

        return $user;
    }

    public function test_requires_service_key(): void
    {
        $this->getJson('/api/v1/service/users')->assertStatus(401);
    }

    public function test_lists_users_filtered_by_platform(): void
    {
        $headers = $this->serviceKey();
        $this->userOn('bids', 'Bidder One', 'one@example.com');
        $this->userOn('bids', 'Bidder Two', 'two@example.com');
        $this->userOn('b2b', 'Wholesaler', 'wholesale@example.com');

        $this->withHeaders($headers)
            ->getJson('/api/v1/service/users?platform=bids')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure([
                'data' => [['uuid', 'name', 'email', 'platforms']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_search_matches_name_or_email(): void
    {
        $headers = $this->serviceKey();
        $this->userOn('bids', 'Alice Smith', 'alice@example.com');
        $this->userOn('bids', 'Bob Jones', 'bob@example.com');

        $this->withHeaders($headers)
            ->getJson('/api/v1/service/users?platform=bids&search=alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alice@example.com');
    }

    public function test_status_filter_narrows_results(): void
    {
        $headers = $this->serviceKey();
        $this->userOn('bids', 'Active', 'active@example.com', PlatformStatus::APPROVED->value);
        $this->userOn('bids', 'Banned', 'banned@example.com', PlatformStatus::SUSPENDED->value);

        $this->withHeaders($headers)
            ->getJson('/api/v1/service/users?platform=bids&status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'banned@example.com');
    }

    public function test_respects_per_page(): void
    {
        $headers = $this->serviceKey();
        for ($i = 0; $i < 5; $i++) {
            $this->userOn('bids', "Bidder {$i}", "bidder{$i}@example.com");
        }

        $this->withHeaders($headers)
            ->getJson('/api/v1/service/users?platform=bids&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }
}
