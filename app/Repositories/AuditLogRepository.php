<?php

namespace App\Repositories;

use App\Models\AuthAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogRepository
{
    public function create(array $data): AuthAuditLog
    {
        return AuthAuditLog::create(array_merge($data, [
            'created_at' => now(),
        ]));
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = AuthAuditLog::query();

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
