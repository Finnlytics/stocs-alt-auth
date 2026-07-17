<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpRequestAttempt extends Model
{
    protected $fillable = [
        'identifier',
        'identifier_type',
        'requests_this_hour',
        'last_request_at',
        'hour_window_started_at',
    ];

    protected function casts(): array
    {
        return [
            'last_request_at' => 'datetime',
            'hour_window_started_at' => 'datetime',
            'requests_this_hour' => 'integer',
        ];
    }
}
