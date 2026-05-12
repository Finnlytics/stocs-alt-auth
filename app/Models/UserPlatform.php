<?php

namespace App\Models;

use App\Enums\PlatformStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlatform extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'role',
        'status',
        'approved_at',
        'rejection_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApproved(): bool
    {
        return $this->status === PlatformStatus::APPROVED->value;
    }

    public function isPending(): bool
    {
        return $this->status === PlatformStatus::PENDING->value;
    }

    public function isSuspended(): bool
    {
        return $this->status === PlatformStatus::SUSPENDED->value;
    }

    public function approve(): void
    {
        $this->update([
            'status' => PlatformStatus::APPROVED->value,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function reject(?string $reason = null): void
    {
        $this->update([
            'status' => PlatformStatus::REJECTED->value,
            'rejection_reason' => $reason,
        ]);
    }

    public function suspend(): void
    {
        $this->update([
            'status' => PlatformStatus::SUSPENDED->value,
        ]);
    }
}
