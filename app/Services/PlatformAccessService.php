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
        return $this->grantConsumerAccess($user, Platform::BIDS, $role);
    }

    public function grantBuyAccess(User $user, string $role = 'consumer'): UserPlatform
    {
        return $this->grantConsumerAccess($user, Platform::BUY, $role);
    }

    /**
     * Grant auto-approved access to a consumer platform (Bids, Buy). Consumer
     * platforms authenticate via OTP and need no screening. B2B is the
     * exception (admin approval) and has its own pending-status grant.
     */
    public function grantConsumerAccess(User $user, Platform $platform, string $role = 'consumer'): UserPlatform
    {
        // BUSINESS RULE: consumer platform access is auto-approved, no screening required
        return $this->platformRepository->grantAccess(
            $user,
            $platform->value,
            $role,
            PlatformStatus::APPROVED->value
        );
    }

    /**
     * Admins are shared across every platform — one admin account works on B2B,
     * Bids and Buy. Granting admin approves the account on all of them so a
     * consuming app's admin panel (which validates the token/role against this
     * service) recognises the admin regardless of which site they log in on.
     */
    public function grantAdminAccess(User $user): void
    {
        foreach ([Platform::B2B, Platform::BIDS, Platform::BUY] as $platform) {
            $this->platformRepository->grantAccess(
                $user,
                $platform->value,
                PlatformRole::ADMIN->value,
                PlatformStatus::APPROVED->value
            );
        }
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
