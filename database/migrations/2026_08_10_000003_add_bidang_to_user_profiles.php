<?php
// database/migrations/2026_08_10_000003_add_bidang_to_user_profiles.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoping admin per bidang (artikel|buku): aksi admin pada naskah hanya sah bila
 * bidang naskah = bidang admin (superadmin bebas). NULL = tanpa scoping.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('bidang', 10)->nullable()->after('job_name');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('bidang');
        });
    }
};
