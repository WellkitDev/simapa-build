<?php
// database/migrations/2026_08_10_000001_add_penugasan_fields_to_tb_title_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi modul Penugasan Naskah: PJ (admin per bidang) + Pelaksana (production),
 * SLA & keterlambatan, hold, arsip, dan pembatalan per naskah.
 *
 * Rename assigned_user_id→pelaksana_user_id memakai raw CHANGE karena MariaDB 10.4
 * (XAMPP) belum mendukung RENAME COLUMN dan doctrine/dbal tidak terpasang; FK & index
 * dilepas dulu lalu dipasang ulang atas nama kolom baru agar deterministik.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['assigned_user_id']);
        });

        DB::statement('ALTER TABLE tb_title_progress CHANGE assigned_user_id pelaksana_user_id BIGINT UNSIGNED NULL DEFAULT NULL');

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->index('pelaksana_user_id');
            $table->foreign('pelaksana_user_id')->references('id')->on('users')->nullOnDelete();

            $table->unsignedBigInteger('pj_user_id')->nullable()->after('updated_by');
            $table->foreign('pj_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('pj_user_id');

            $table->string('bidang', 10)->nullable()->after('priority')->index(); // artikel|buku
            $table->date('sla_due_at')->nullable()->after('bidang');
            $table->string('overdue_reason', 30)->nullable()->after('sla_due_at'); // internal|eksternal|lainnya
            $table->text('overdue_note')->nullable()->after('overdue_reason');
            $table->boolean('is_on_hold')->default(false)->after('overdue_note');
            $table->boolean('chapters_done')->default(false)->after('is_on_hold');
            $table->timestamp('archived_at')->nullable()->after('chapters_done')->index();
            $table->timestamp('cancelled_at')->nullable()->after('archived_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['pj_user_id']);
            $table->dropIndex(['pj_user_id']);
            $table->dropIndex(['bidang']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'pj_user_id', 'bidang', 'sla_due_at', 'overdue_reason', 'overdue_note',
                'is_on_hold', 'chapters_done', 'archived_at', 'cancelled_at',
                'cancelled_by', 'cancel_reason',
            ]);
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropForeign(['pelaksana_user_id']);
            $table->dropIndex(['pelaksana_user_id']);
        });

        DB::statement('ALTER TABLE tb_title_progress CHANGE pelaksana_user_id assigned_user_id BIGINT UNSIGNED NULL DEFAULT NULL');

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->index('assigned_user_id');
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
