<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Admin',
            'email' => 'admin@stocs.com',
            'password' => 'AdminPass1234',
        ]);
        $this->admin->is_super_admin = true;
        $this->admin->save();

        UserPlatform::create([
            'user_id' => $this->admin->id,
            'platform' => 'b2b',
            'role' => 'admin',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->adminToken = $this->admin->createToken('admin', ['platform:b2b'])->plainTextToken;
    }

    public function test_admin_can_list_users(): void
    {
        $response = $this->getJson('/api/v1/admin/users', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta']);
    }

    public function test_admin_can_approve_user(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'Password123',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/v1/admin/users/{$user->uuid}/approve", [
            'platform' => 'b2b',
        ], [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'User approved.');

        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $user->id,
            'platform' => 'b2b',
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_reject_user(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'Password123',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'pending',
        ]);

        $response = $this->postJson("/api/v1/admin/users/{$user->uuid}/reject", [
            'platform' => 'b2b',
            'reason' => 'Incomplete profile',
        ], [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('user_platforms', [
            'user_id' => $user->id,
            'platform' => 'b2b',
            'status' => 'rejected',
            'rejection_reason' => 'Incomplete profile',
        ]);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $user = User::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => 'Password123',
        ]);

        UserPlatform::create([
            'user_id' => $user->id,
            'platform' => 'b2b',
            'role' => 'wholesaler',
            'status' => 'approved',
        ]);

        $token = $user->createToken('test', ['platform:b2b'])->plainTextToken;

        $response = $this->getJson('/api/v1/admin/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }
}
