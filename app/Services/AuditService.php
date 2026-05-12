<?php

namespace App\Services;

use App\Repositories\AuditLogRepository;
use Illuminate\Http\Request;

class AuditService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository
    ) {}

    public function log(
        string $action,
        string $description,
        ?int $userId = null,
        ?string $platform = null,
        ?array $metadata = null,
        ?Request $request = null
    ): void {
        $this->auditLogRepository->create([
            'user_id' => $userId,
            'action' => $action,
            'platform' => $platform,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
