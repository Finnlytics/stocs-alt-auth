<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ValidateTokenRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class TokenValidationController extends Controller
{
    public function validate(ValidateTokenRequest $request): JsonResponse
    {
        $token = PersonalAccessToken::findToken($request->validated('token'));

        if (! $token) {
            return response()->json(['valid' => false, 'message' => 'Token not found.'], 401);
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return response()->json(['valid' => false, 'message' => 'Token expired.'], 401);
        }

        $user = $token->tokenable;

        if (! $user || $user->trashed()) {
            return response()->json(['valid' => false, 'message' => 'User not found.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'valid' => true,
            'data' => new UserResource($user->load('platforms')),
            'abilities' => $token->abilities,
        ]);
    }
}
