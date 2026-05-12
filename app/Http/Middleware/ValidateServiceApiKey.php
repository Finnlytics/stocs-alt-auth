<?php

namespace App\Http\Middleware;

use App\Repositories\ServiceApiKeyRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ValidateServiceApiKey
{
    public function __construct(
        private readonly ServiceApiKeyRepository $repository
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Service-Key');

        if (! $apiKey || ! str_contains($apiKey, '.')) {
            return $this->unauthorized();
        }

        [$prefix, $secret] = explode('.', $apiKey, 2);

        if ($prefix === '' || $secret === '') {
            return $this->unauthorized();
        }

        $key = $this->repository->findActiveByPrefix($prefix);

        if (! $key || ! Hash::check($secret, $key->key_hash) || ! $key->isValid()) {
            return $this->unauthorized();
        }

        $this->repository->touchLastUsed($key);

        $request->attributes->set('service_platform', $key->platform);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Invalid or expired service API key.'], 401);
    }
}
