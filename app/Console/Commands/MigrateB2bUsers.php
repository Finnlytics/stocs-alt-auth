<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Enums\PlatformStatus;
use App\Models\User;
use App\Models\UserPlatform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateB2bUsers extends Command
{
    protected $signature = 'auth:migrate-b2b-users
                            {--dry-run : Show what would be migrated without making changes}
                            {--force : Run without confirmation prompt}';

    protected $description = 'Migrate existing B2B users into the auth service';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $b2bUsers = DB::connection('b2b')->table('users')->get();

        if ($b2bUsers->isEmpty()) {
            $this->info('No users found in B2B database.');

            return self::SUCCESS;
        }

        $this->info("Found {$b2bUsers->count()} users in B2B database.");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Status', 'Super Admin'],
            $b2bUsers->map(fn ($u) => [
                $u->id, $u->name, $u->email, $u->role, $u->status,
                $u->is_super_admin ? 'Yes' : 'No',
            ])
        );

        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('Proceed with migration?')) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($b2bUsers as $b2bUser) {
            $existing = User::where('email', $b2bUser->email)->first();

            if ($existing) {
                $this->warn("Skipping {$b2bUser->email} — already exists in auth (UUID: {$existing->uuid})");
                $skipped++;

                continue;
            }

            $uuid = Str::uuid()->toString();

            DB::transaction(function () use ($b2bUser, $uuid) {
                $user = User::create([
                    'uuid' => $uuid,
                    'name' => $b2bUser->name,
                    'email' => $b2bUser->email,
                    'password' => $b2bUser->password, // Already hashed
                    'email_verified_at' => $b2bUser->email_verified_at,
                    'created_at' => $b2bUser->created_at,
                    'updated_at' => $b2bUser->updated_at,
                ]);

                // is_super_admin is guarded (not mass-assignable) — set explicitly.
                if ($b2bUser->is_super_admin) {
                    $user->is_super_admin = true;
                    $user->save();
                }

                // Prevent double-hashing — password is already hashed from B2B
                DB::table('users')->where('id', $user->id)->update([
                    'password' => $b2bUser->password,
                ]);

                $isAdmin = $b2bUser->role === 'admin';

                // B2B platform access
                UserPlatform::create([
                    'user_id' => $user->id,
                    'platform' => 'b2b',
                    'role' => $isAdmin ? PlatformRole::ADMIN->value : PlatformRole::WHOLESALER->value,
                    'status' => $b2bUser->status ?? PlatformStatus::PENDING->value,
                    'approved_at' => $b2bUser->approved_at,
                    'metadata' => $b2bUser->admin_email_preferences
                        ? json_decode($b2bUser->admin_email_preferences, true)
                        : null,
                ]);

                // BUSINESS RULE: All B2B users also get Bids access (auto-approved)
                UserPlatform::create([
                    'user_id' => $user->id,
                    'platform' => 'bids',
                    'role' => $isAdmin ? PlatformRole::ADMIN->value : PlatformRole::CONSUMER->value,
                    'status' => PlatformStatus::APPROVED->value,
                    'approved_at' => now(),
                ]);

                // Write UUID back to B2B database
                $this->writeUuidToB2b($b2bUser->id, $uuid);
            });

            $this->info("Migrated: {$b2bUser->email} → {$uuid}");
            $migrated++;
        }

        $this->newLine();
        $this->info("Migration complete. Migrated: {$migrated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function writeUuidToB2b(int $userId, string $uuid): void
    {
        $hasColumn = DB::connection('b2b')
            ->getSchemaBuilder()
            ->hasColumn('users', 'auth_user_uuid');

        if (! $hasColumn) {
            $this->warn('B2B users table missing auth_user_uuid column — run the B2B migration first.');

            return;
        }

        DB::connection('b2b')
            ->table('users')
            ->where('id', $userId)
            ->update(['auth_user_uuid' => $uuid]);
    }
}
