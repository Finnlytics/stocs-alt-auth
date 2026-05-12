<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceApiKey extends Model
{
    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'platform',
        'is_active',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
