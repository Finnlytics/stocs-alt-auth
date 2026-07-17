<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the multi-tier exponential ramp (30s -> 2m -> 10m -> 1h -> 6h ->
     * 24h) with a single rule: a short cooldown between requests plus a flat
     * hourly cap. `unverified_count` -> `requests_this_hour` (count within the
     * current rolling window) and `next_allowed_at` -> `hour_window_started_at`
     * (when that window began); the cooldown itself is derived from
     * `last_request_at` at check time rather than stored.
     */
    public function up(): void
    {
        Schema::table('otp_request_attempts', function (Blueprint $table) {
            $table->renameColumn('unverified_count', 'requests_this_hour');
        });

        Schema::table('otp_request_attempts', function (Blueprint $table) {
            $table->dropIndex(['next_allowed_at']);
            $table->dropColumn('next_allowed_at');
            $table->timestamp('hour_window_started_at')->nullable()->after('requests_this_hour');
        });
    }

    public function down(): void
    {
        Schema::table('otp_request_attempts', function (Blueprint $table) {
            $table->dropColumn('hour_window_started_at');
            $table->timestamp('next_allowed_at')->nullable();
        });

        Schema::table('otp_request_attempts', function (Blueprint $table) {
            $table->renameColumn('requests_this_hour', 'unverified_count');
            $table->index('next_allowed_at');
        });
    }
};
