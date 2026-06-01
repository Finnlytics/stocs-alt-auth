<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLookupController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository
    ) {}

    /**
     * Paginated list of users, filterable by platform/status/role/search.
     * Service-to-service counterpart of the admin list endpoint — lets a
     * consumer (e.g. the Bids admin) enumerate its platform's users without
     * an admin token. Same response envelope as the admin endpoint.
     */
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
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function showByUuid(string $uuid): JsonResponse
    {
        $user = $this->userRepository->findByUuid($uuid);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    public function showByEmail(string $email): JsonResponse
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
