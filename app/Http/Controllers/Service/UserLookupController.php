<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;

class UserLookupController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository
    ) {}

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
