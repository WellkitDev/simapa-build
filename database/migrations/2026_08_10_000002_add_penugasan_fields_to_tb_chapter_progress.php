<?php
// database/migrations/2026_08_10_000002_add_penugasan_fields_to_tb_chapter_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan per bab (buku kolaborasi): pelaksana + SLA 7 hari kerja.
 * Kolom lama assigned_user_id (editor era distribusi) dibiarkan sampai cleanup Task 14.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_chapter_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('pelaksana_user_id')->nullable()->after('assigned_user_id');
            $table->foreign('pelaksana_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('pelaksana_user_id');
            $table->date('sla_due_at')->nullable()->after('pelaksana_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_chapter_progress', function (Blueprint $table) {
            $table->dropForeign(['pelaksana_user_id']);
            $table->dropIndex(['pelaksana_user_id']);
            $table->dropColumn(['pelaksana_user_id', 'sla_due_at']);
        });
    }
};
