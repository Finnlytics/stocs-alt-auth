<?php

namespace App\Services;

use App\Enums\Platform;
use App\Enums\PlatformRole;
use App\Enums\PlatformStatus;
use App\Models\User;
use App\Models\UserPlatform;
use App\Repositories\UserPlatformRepository;

class PlatformAccessService
{
    public function __construct(
        private readonly UserPlatformRepository $platformRepository
    ) {}

    public function grantB2bAccess(User $user, string $role = 'wholesaler'): UserPlatform
    {
        return $this->platformRepository->grantAccess(
            $user,
            Platform::B2B->value,
            $role,
            PlatformStatus::PENDING->value
        );
    }

    public function grantBidsAccess(User $user, string $role = 'consumer'): UserPlatform
    {
        // BUSINESS RULE: Bids access is auto-approved, no screening required
        return $this->platformRepository->grantAccess(
            $user,
            Platform::BIDS->value,
            $role,
            PlatformStatus::APPROVED->value
        );
    }

    public function grantAdminAccess(User $user): void
    {
        $this->platformRepository->grantAccess(
            $user,
            Platform::B2B->value,
            PlatformRole::ADMIN->value,
            PlatformStatus::APPROVED->value
        );

        $this->platformRepository->grantAccess(
            $user,
            Platform::BIDS->value,
            PlatformRole::ADMIN->value,
            PlatformStatus::APPROVED->value
        );
    }

    public function canAccessPlatform(User $user, Platform $platform): bool
    {
        return $user->hasPlatformAccess($platform);
    }

    public function getPlatformAccess(User $user, Platform $platform): ?UserPlatform
    {
        return $this->platformRepository->findByUserAndPlatform(
            $user->id,
            $platform->value
        );
    }
}
