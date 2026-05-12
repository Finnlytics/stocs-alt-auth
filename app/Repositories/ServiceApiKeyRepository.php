<?php

namespace App\Repositories;

use App\Models\ServiceApiKey;

class ServiceApiKeyRepository
{
    public function findActiveByPrefix(string $prefix): ?ServiceApiKey
    {
        return ServiceApiKey::where('key_prefix', $prefix)
            ->where('is_active', true)
            ->first();
    }

    public function create(array $data): ServiceApiKey
    {
        return ServiceApiKey::create($data);
    }

    public function touchLastUsed(ServiceApiKey $key): void
    {
        // Debounced: skip the UPDATE if we already stamped it in the last 5 minutes.
        // Service-to-service calls are high volume and the exact `last_used_at`
        // timestamp is an observability signal, not an auth decision.
        if ($key->last_used_at && $key->last_used_at->gt(now()->subMinutes(5))) {
            return;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
