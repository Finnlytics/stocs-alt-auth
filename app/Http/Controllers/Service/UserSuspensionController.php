<?php

namespace App\Http\Controllers\Service;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ReinstateUserRequest;
use App\Http\Requests\Service\SuspendUserRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\PlatformAccessService;
use Illuminate\Http\JsonResponse;

class UserSuspensionController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PlatformAccessService $platformAccessService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Suspend a user's platform access on behalf of a consuming backend.
     *
     * Used by Bids when an auction winner fails to pay within the deadline.
     * Defaults to the `bids` platform because that's the only service that
     * currently suspends; the platform is still explicit in the request so
     * other backends can reuse this endpoint.
     */
    public function suspend(SuspendUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $platform = $request->validated('platform', Platform::BIDS->value);
        $access = $this->platformAccessService->suspend($user, Platform::from($platform));

        if (! $access) {
            return response()->json(['message' => 'User has no access record for this platform.'], 404);
        }

        $this->auditService->log(
            'suspended',
            "User suspended on {$platform} by service",
            $user->id,
            $platform,
            ['reason' => $request->validated('reason')],
            $request,
        );

        return response()->json([
            'data' => new UserResource($user->fresh('platforms')),
            'message' => 'User suspended.',
        ]);
    }

    /**
     * Lift a suspension on behalf of a consuming backend's admin (e.g. the Bids
     * "All users" page reinstating a bidder who was suspended for non-payment).
     * Only acts on a currently-suspended account so it can't quietly promote a
     * pending or rejected user.
     */
    public function reinstate(ReinstateUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $platform = $request->validated('platform', Platform::BIDS->value);
        $access = $this->platformAccessService->getPlatformAccess($user, Platform::from($platform));

        if (! $access) {
            return response()->json(['message' => 'User has no access record for this platform.'], 404);
        }

        if (! $access->isSuspended()) {
            return response()->json(['message' => 'User is not suspended.'], 422);
        }

        $this->platformAccessService->reinstate($user, Platform::from($platform));

        $this->auditService->log(
            'reinstated',
            "User reinstated on {$platform} by service",
            $user->id,
            $platform,
            null,
            $request,
        );

        return response()->json([
            'data' => new UserResource($user->fresh('platforms')),
            'message' => 'User reinstated.',
        ]);
    }
}
