<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capture which STOCS site a user first signed up on (bids, buy, b2b).
     * Nullable — existing users predate this column so their origin is unknown.
     * Stamped going forward at first OTP verify / B2B registration.
     *
     * Idempotent guard: the column may already exist in an environment where an
     * earlier (un-committed) copy of this migration was run.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'signup_platform')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_platform', 10)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_platform');
        });
    }
};
