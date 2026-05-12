<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserPlatform;

class UserPlatformRepository
{
    public function findByUserAndPlatform(int $userId, string $platform): ?UserPlatform
    {
        return UserPlatform::where('user_id', $userId)
            ->where('platform', $platform)
            ->first();
    }

    public function create(array $data): UserPlatform
    {
        return UserPlatform::create($data);
    }

    public function grantAccess(User $user, string $platform, string $role, string $status = 'pending'): UserPlatform
    {
        return UserPlatform::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform],
            ['role' => $role, 'status' => $status, 'approved_at' => $status === 'approved' ? now() : null]
        );
    }
}
