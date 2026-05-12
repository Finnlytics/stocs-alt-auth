<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dev-only endpoint that mints N Bids-scoped Sanctum tokens in one shot.
 *
 * Used by the bids:simulate-http load-test harness so it can fire
 * authenticated requests without an OTP email round-trip. Disabled in
 * production via the env guard; still also sits behind `service-key`
 * middleware so it's never reachable anonymously.
 */
class TestUsersController extends Controller
{
    private const MAX_COUNT = 200;

    public function __construct(
        private readonly PlatformAccessService $platformAccessService,
    ) {}

    public function mint(Request $request): JsonResponse
    {
        if (app()->isProduction()) {
            return response()->json(['message' => 'Disabled in production.'], 403);
        }

        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
            'prefix' => ['sometimes', 'string', 'max:32'],
        ]);

        $prefix = $data['prefix'] ?? 'loadtest';
        $users = [];

        for ($i = 0; $i < $data['count']; $i++) {
            $suffix = Str::lower(Str::random(10));
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => "Loadtest User {$i}",
                'email' => "{$prefix}-{$suffix}@stocs-test.local",
                'email_verified_at' => now(),
            ]);

            $this->platformAccessService->grantBidsAccess($user);

            $token = $user->createToken("{$prefix}-{$i}", ['platform:bids'])->plainTextToken;

            $users[] = [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'token' => $token,
            ];
        }

        return response()->json(['data' => $users], 201);
    }
}
