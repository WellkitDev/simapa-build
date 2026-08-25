<?php
// database/migrations/2026_08_25_000003_add_progress_to_tb_tasks.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kemajuan terakhir yang dilaporkan, disimpan di tugasnya sendiri.
 *
 * Utas aktivitas menyimpan SEJARAH kemajuan; kolom ini menyimpan KEADAAN SEKARANG,
 * supaya papan dan daftar bisa menampilkan bilah kemajuan tanpa membaca seluruh utas
 * tiap kartu. Pola record-page CRM: recordnya menunjukkan keadaan, utasnya menunjukkan
 * bagaimana ia sampai di situ.
 *
 * Sengaja TIDAK dihitung dari status. `in_progress` bisa berarti 10% atau 90%, dan
 * hanya pelaksananya yang tahu yang mana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('priority');
        });

        // Tugas yang sudah selesai memang 100% — membiarkannya 0 membuat papan
        // menampilkan bilah kosong pada pekerjaan yang jelas-jelas rampung.
        DB::table('tb_tasks')->where('status', 'done')->update(['progress' => 100]);
    }

    public function down(): void
    {
        Schema::table('tb_tasks', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
