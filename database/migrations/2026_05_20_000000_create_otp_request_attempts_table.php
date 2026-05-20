<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BUSINESS RULE: tracks unverified OTP requests per identifier so we can
        // apply exponential backoff (resists email-spam abuse where an attacker
        // floods someone's inbox with OTPs). Distinct from the in-memory rate
        // limiter — this state must survive process restarts and ramps over
        // hours/days, not minutes.
        Schema::create('otp_request_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('identifier');
            $table->string('identifier_type', 10)->default('email');
            $table->unsignedSmallInteger('unverified_count')->default(0);
            $table->timestamp('last_request_at')->nullable();
            $table->timestamp('next_allowed_at')->nullable();
            $table->timestamps();

            $table->unique(['identifier', 'identifier_type']);
            $table->index('next_allowed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_request_attempts');
    }
};
