<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'identifier',
        'identifier_type',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasExceededAttempts(int $maxAttempts = 3): bool
    {
        return $this->attempts >= $maxAttempts;
    }
}
