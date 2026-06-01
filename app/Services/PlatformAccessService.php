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

    /**
     * Suspend a user's access to a platform and revoke every active token so
     * existing sessions can't continue. Returns null if the user has no access
     * record for the platform. Used by both the admin panel and the
     * service-to-service suspend endpoint (e.g. Bids suspending a non-paying
     * auction winner).
     */
    public function suspend(User $user, Platform $platform): ?UserPlatform
    {
        $access = $this->getPlatformAccess($user, $platform);

        if (! $access) {
            return null;
        }

        $access->suspend();

        // BUSINESS RULE: suspension must take effect immediately. Revoke all
        // tokens — the suspended-status check on OTP login then prevents the
        // user issuing a fresh one until an admin lifts the suspension.
        $user->tokens()->delete();

        return $access;
    }

    /**
     * Lift a suspension — return the platform access to the approved state.
     * Bids access is auto-approved, so "approved" is the correct lifted state.
     * Returns null if the user has no access record for the platform. The user
     * simply logs in again via OTP (their tokens were revoked on suspend).
     */
    public function reinstate(User $user, Platform $platform): ?UserPlatform
    {
        $access = $this->getPlatformAccess($user, $platform);

        if (! $access) {
            return null;
        }

        $access->approve();

        return $access;
    }
}
