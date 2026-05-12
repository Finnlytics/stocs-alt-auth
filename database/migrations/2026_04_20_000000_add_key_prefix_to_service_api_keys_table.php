<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_api_keys', function (Blueprint $table) {
            // Supports O(1) lookup: client sends `{key_prefix}.{secret}`,
            // server resolves the row by prefix then verifies secret hash.
            $table->string('key_prefix', 32)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('service_api_keys', function (Blueprint $table) {
            $table->dropUnique(['key_prefix']);
            $table->dropColumn('key_prefix');
        });
    }
};
