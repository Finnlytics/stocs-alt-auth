<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveUserRequest;
use App\Http\Requests\Admin\CreateAdminRequest;
use App\Http\Requests\Admin\RejectUserRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Mail\AccountApprovedEmail;
use App\Mail\AccountRejectedEmail;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Services\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminUsersController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuthService $authService,
        private readonly PlatformAccessService $platformAccessService,
        private readonly AuditService $auditService,
        private readonly OtpService $otpService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->userRepository->list(
            $request->only(['platform', 'status', 'role', 'search']),
            $request->integer('per_page', 20)
        );

        return response()->json([
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user = $this->userRepository->update($user, $request->validated());

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'User updated.',
        ]);
    }

    public function approve(ApproveUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $platform = $request->validated('platform', 'b2b');
        $access = $this->platformAccessService->getPlatformAccess($user, Platform::from($platform));

        if (! $access) {
            return response()->json(['message' => 'User has no access record for this platform.'], 404);
        }

        $access->approve();

        // BUSINESS RULE: Approving (especially after suspension) must clear the
        // OTP rate limit so the user can immediately request a fresh code.
        $this->otpService->clearRateLimitFor($user->email);

        Mail::to($user->email)->queue(new AccountApprovedEmail($user, $platform));

        $this->auditService->log(
            'approved',
            "User approved for {$platform}",
            $user->id,
            $platform,
            ['approved_by' => $request->user()->uuid],
            $request
        );

        return response()->json([
            'data' => new UserResource($user->fresh('platforms')),
            'message' => 'User approved.',
        ]);
    }

    public function reject(RejectUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $platform = $request->input('platform', 'b2b');
        $access = $this->platformAccessService->getPlatformAccess($user, Platform::from($platform));

        if (! $access) {
            return response()->json(['message' => 'User has no access record for this platform.'], 404);
        }

        $access->reject($request->input('reason'));

        Mail::to($user->email)->queue(new AccountRejectedEmail($user, $platform, $request->input('reason')));

        $this->auditService->log(
            'rejected',
            "User rejected for {$platform}",
            $user->id,
            $platform,
            ['reason' => $request->input('reason')],
            $request
        );

        return response()->json([
            'data' => new UserResource($user->fresh('platforms')),
            'message' => 'User rejected.',
        ]);
    }

    public function suspend(SuspendUserRequest $request, string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $platform = $request->validated('platform', 'b2b');
        $access = $this->platformAccessService->getPlatformAccess($user, Platform::from($platform));

        if (! $access) {
            return response()->json(['message' => 'User has no access record for this platform.'], 404);
        }

        $access->suspend();

        // Revoke all tokens for this user
        $user->tokens()->delete();

        $this->auditService->log(
            'suspended',
            "User suspended on {$platform}",
            $user->id,
            $platform,
            request: $request
        );

        return response()->json([
            'data' => new UserResource($user->fresh('platforms')),
            'message' => 'User suspended.',
        ]);
    }

    public function store(CreateAdminRequest $request): JsonResponse
    {
        $result = $this->authService->createAdmin($request->validated(), $request->user());

        $this->auditService->log(
            'admin_created',
            'Admin user created',
            $result['user']->id,
            metadata: ['created_by' => $request->user()->uuid],
            request: $request
        );

        return response()->json([
            'data' => new UserResource($result['user']),
            'message' => 'Admin user created.',
        ], 201);
    }
}
