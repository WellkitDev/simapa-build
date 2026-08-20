<?php
// database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "order ini ditarik dari judul" (refund penuh).
 *
 * Denormalisasi DISENGAJA, mengikuti pola archived_at/cancelled_at di tabel yang sama:
 * alternatifnya JOIN ke tb_orders di groupOf(), manuscriptStatus(), dan applyGroup() —
 * tiga jalur terpanas modul naskah.
 *
 * `withdrawal_snapshot` menyimpan keadaan bab & penulis sebelum dicabut, supaya
 * "Batalkan Penarikan" bisa memasangnya kembali persis.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('cancel_reason')->index();
            $table->string('withdrawn_reason')->nullable()->after('withdrawn_at');
            $table->json('withdrawal_snapshot')->nullable()->after('withdrawn_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropIndex(['withdrawn_at']);
            $table->dropColumn(['withdrawn_at', 'withdrawn_reason', 'withdrawal_snapshot']);
        });
    }
};
