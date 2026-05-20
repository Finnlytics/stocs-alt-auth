<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpRequestAttempt extends Model
{
    protected $fillable = [
        'identifier',
        'identifier_type',
        'unverified_count',
        'last_request_at',
        'next_allowed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_request_at' => 'datetime',
            'next_allowed_at' => 'datetime',
            'unverified_count' => 'integer',
        ];
    }

    public function isInBackoff(): bool
    {
        return $this->next_allowed_at !== null && $this->next_allowed_at->isFuture();
    }

    public function retryAfterSeconds(): int
    {
        if (! $this->isInBackoff()) {
            return 0;
        }

        return max(1, now()->diffInSeconds($this->next_allowed_at, false));
    }
}
