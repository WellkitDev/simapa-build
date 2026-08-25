<?php
// database/migrations/2026_08_25_000002_create_tb_task_updates_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utas aktivitas per tugas: laporan manusia dan peristiwa sistem dalam satu urutan.
 *
 * Sampai sekarang sebuah tugas cuma kotak centang pribadi — pemberi tugas tak punya
 * cara tahu apa pun selain "todo/in_progress/done", dan pelaksananya tak punya tempat
 * bercerita. Di aplikasi POS dan CRM, yang membuat sebuah record hidup adalah utas
 * aktivitasnya, bukan kolom statusnya.
 *
 * DUA JENIS dalam SATU tabel, disengaja: `laporan` ditulis orang, `sistem` dicatat
 * aplikasi (dialihkan, status berubah, tenggat digeser). Memisahkannya jadi dua tabel
 * akan memaksa penggabungan dan pengurutan ulang tiap kali utasnya dibaca, dan yang
 * dibaca orang memang satu kolom kronologis.
 *
 * APPEND-ONLY — tak ada updated_at, tak ada jalan menyunting. Laporan adalah catatan
 * apa yang terjadi; riwayat yang bisa disunting adalah akuntabilitas yang mati. Pola
 * yang sama dipakai tb_manuscript_files dan tb_title_progress_logs di repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tb_tasks')->cascadeOnDelete();

            // Boleh kosong: peristiwa yang dicatat penjadwal (pengingat tenggang) tak
            // punya pelaku manusia, dan menautkannya ke akun mana pun akan berbohong.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 10)->default('laporan');   // laporan | sistem
            $table->text('body');

            // Kemajuan yang DILAPORKAN, bukan yang dihitung. Nullable karena tak setiap
            // laporan perlu angka — memaksa persentase membuat orang mengarang.
            $table->unsignedTinyInteger('progress')->nullable();

            $table->timestamp('created_at')->nullable();

            // Utas selalu dibaca per tugas dan berurutan waktu.
            $table->index(['task_id', 'id'], 'task_updates_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_task_updates');
    }
};
