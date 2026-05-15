<?php

namespace Database\Seeders;

use App\Models\User;
use App\Repositories\UserPlatformRepository;
use App\Services\PlatformAccessService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds operator admin accounts from config/admins.php.
 *
 * Each configured slot becomes a super-admin user with approved access on
 * every platform. Idempotent — matches on email and preserves the existing
 * UUID across re-seeds so issued Sanctum tokens stay valid.
 *
 * Unlike DemoUsersSeeder, this runs in production too: the real admin list
 * is the source of truth, not demo accounts. Add a new admin by appending
 * an entry to config/admins.php and setting the matching env vars.
 */
class AdminUsersSeeder extends Seeder
{
    public function run(PlatformAccessService $platformAccess, UserPlatformRepository $platforms): void
    {
        foreach ((array) config('admins', []) as $admin) {
            $email = $admin['email'] ?? null;
            $password = $admin['password'] ?? null;

            if (! $email || ! $password) {
                continue;
            }

            $user = User::firstOrNew(['email' => $email]);
            if (! $user->uuid) {
                $user->uuid = (string) Str::uuid();
            }
            $user->name = $admin['name'] ?? 'Admin';
            $user->password = $password; // hashed via cast
            $user->email_verified_at = $user->email_verified_at ?? now();
            $user->save();

            // is_super_admin is guarded (not mass-assignable) — set explicitly.
            $user->is_super_admin = true;
            $user->save();

            $platformAccess->grantAdminAccess($user);
        }
    }
}
