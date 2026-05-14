<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Enums\PlatformStatus;
use App\Models\User;
use App\Repositories\UserPlatformRepository;
use App\Services\PlatformAccessService;
use Illuminate\Database\Seeder;

/**
 * Seeds real-looking demo accounts for local development.
 *
 * Stable UUIDs let Bids/B2B seeders bind profile data (addresses, category
 * preferences, bid history) to these users reliably across fresh installs.
 *
 *   admin@stocs.test      / testing1!   — admin on both platforms
 *   wholesale@stocs.test  / testing1!   — B2B wholesaler (approved) + Bids access
 *   bidder@stocs.test     — OTP only    — primary Bids consumer (DEMO_USER_UUID)
 *   bidder2@stocs.test    — OTP only    — secondary Bids consumer for bid-vs-bid testing
 *
 * Idempotent — safe to re-run.
 */
class DemoUsersSeeder extends Seeder
{
    // Stable UUIDs — keep in sync with stocs-bids DEMO_USER_UUID default.
    private const ADMIN_UUID = '00000000-0000-4000-8000-0000000000a0';

    private const WHOLESALE_UUID = '00000000-0000-4000-8000-0000000000b0';

    private const BIDDER_UUID = '00000000-0000-4000-8000-000000000001';

    private const BIDDER_2_UUID = '00000000-0000-4000-8000-000000000002';

    public function run(PlatformAccessService $platformAccess, UserPlatformRepository $platforms): void
    {
        // Admin — password auth, full access to both platforms.
        $admin = User::updateOrCreate(
            ['email' => 'admin@stocs.test'],
            [
                'uuid' => self::ADMIN_UUID,
                'name' => 'Admin User',
                'password' => 'testing1!',
                'email_verified_at' => now(),
            ],
        );
        // is_super_admin is guarded (not mass-assignable) — set explicitly.
        $admin->is_super_admin = true;
        $admin->save();
        $platformAccess->grantAdminAccess($admin);

        // Wholesaler — password auth, B2B approved (not pending) so they can actually log in,
        // plus Bids so they can participate on the consumer side too.
        $wholesaler = User::updateOrCreate(
            ['email' => 'wholesale@stocs.test'],
            [
                'uuid' => self::WHOLESALE_UUID,
                'name' => 'Demo Wholesaler',
                'password' => 'testing1!',
                'email_verified_at' => now(),
            ],
        );
        $platforms->grantAccess(
            $wholesaler,
            Platform::B2B->value,
            'wholesaler',
            PlatformStatus::APPROVED->value,
        );
        $platformAccess->grantBidsAccess($wholesaler);

        // Primary bidder — OTP-only (no password), Bids consumer.
        $bidder = User::updateOrCreate(
            ['email' => 'bidder@stocs.test'],
            [
                'uuid' => self::BIDDER_UUID,
                'name' => 'Demo Bidder',
                'email_verified_at' => now(),
            ],
        );
        $platformAccess->grantBidsAccess($bidder);

        // Secondary bidder — so bidding wars / outbid flows can be tested with two accounts.
        $bidder2 = User::updateOrCreate(
            ['email' => 'bidder2@stocs.test'],
            [
                'uuid' => self::BIDDER_2_UUID,
                'name' => 'Rival Bidder',
                'email_verified_at' => now(),
            ],
        );
        $platformAccess->grantBidsAccess($bidder2);
    }
}
