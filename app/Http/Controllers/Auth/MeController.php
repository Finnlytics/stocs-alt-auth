<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class MeController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuthService $authService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('platforms')),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->userRepository->update($request->user(), $request->validated());

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Profile updated.',
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'confirmed',
                Password::min(10)->mixedCase()->numbers()->uncompromised(),
            ],
        ]);

        $this->userRepository->update($request->user(), [
            'password' => $request->input('password'),
        ]);

        // Revoke all other tokens, keep current one
        $request->user()->tokens()
            ->where('id', '!=', $request->user()->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    public function updateMarketing(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => ['required', 'array'],
        ]);

        $this->userRepository->update($request->user(), [
            'marketing_preferences' => $request->input('preferences'),
        ]);

        return response()->json(['message' => 'Marketing preferences updated.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // GDPR: anonymize personal data
        $this->userRepository->update($user, [
            'name' => 'Deleted User',
            'email' => "deleted_{$user->id}@deleted.stocs.com",
            'phone' => null,
            'marketing_preferences' => null,
            'gdpr_data_deleted_at' => now(),
        ]);

        $user->tokens()->delete();
        $this->userRepository->softDelete($user);

        return response()->json(['message' => 'Account deleted.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user(), $request->user()->currentAccessToken()->id);

        return response()->json(['message' => 'Logged out.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return response()->json(['message' => 'Logged out from all devices.']);
    }
}
