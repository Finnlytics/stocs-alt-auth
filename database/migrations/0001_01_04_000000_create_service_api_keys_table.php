<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash');
            $table->string('platform', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('key_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_api_keys');
    }
};
