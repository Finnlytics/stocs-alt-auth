<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Repositories\ServiceApiKeyRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IssueServiceApiKey extends Command
{
    protected $signature = 'auth:issue-service-key
                            {name : Human-readable name for the key (e.g. "b2b-backend")}
                            {platform : Platform this key is scoped to (b2b|bids)}
                            {--expires-in-days= : Optional expiry in days}';

    protected $description = 'Issue a service API key for backend-to-backend calls';

    public function handle(ServiceApiKeyRepository $repository): int
    {
        $platform = Platform::tryFrom($this->argument('platform'));

        if (! $platform) {
            $this->error('Invalid platform. Must be one of: b2b, bids');

            return self::FAILURE;
        }

        $prefix = 'sk_'.Str::lower(Str::random(13));
        $secret = Str::random(48);

        $repository->create([
            'name' => $this->argument('name'),
            'key_prefix' => $prefix,
            'key_hash' => Hash::make($secret),
            'platform' => $platform->value,
            'is_active' => true,
            'expires_at' => $this->option('expires-in-days')
                ? now()->addDays((int) $this->option('expires-in-days'))
                : null,
        ]);

        $this->info('Service API key issued. Send this value to the consumer in the X-Service-Key header:');
        $this->newLine();
        $this->line($prefix.'.'.$secret);
        $this->newLine();
        $this->warn('This secret is shown ONCE. Store it securely — it cannot be recovered.');

        return self::SUCCESS;
    }
}
