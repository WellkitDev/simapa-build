<?php
// database/migrations/2026_09_08_000001_create_tb_task_files_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran sebuah tugas: acuan dari yang memberi, hasil dari yang mengerjakan.
 *
 * Sampai sekarang satu-satunya tempat berkas mendarat adalah Laporan Harian — dan di
 * sana bukti bahkan wajib. Tapi laporan harian bersumbu HARI, sementara tugas bersumbu
 * PEKERJAAN: sebuah tugas bisa hidup dua minggu, dan hasilnya bukan milik hari tempat
 * ia kebetulan selesai. Menitipkan hasil tugas ke laporan hari itu membuatnya sulit
 * ditemukan kembali oleh orang yang mencarinya lewat tugasnya.
 *
 * `uploaded_by` yang membedakan berkas dari kedua pihak. TIDAK ada kolom peran
 * terpisah: perannya sudah terbaca dari `tb_tasks.user_id` vs `created_by`, dan
 * menyimpannya dua kali berarti dua sumber yang bisa berselisih.
 *
 * Bentuknya sengaja meniru `tb_daily_report_files` — pola unggah-ke-Drive di repo ini
 * sudah terbukti, dan dua tabel lampiran yang berbeda bentuk hanya akan membuat
 * pembacanya menebak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_task_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tb_tasks')->cascadeOnDelete();
            $table->string('drive_file_id');
            $table->string('name');
            $table->string('url', 1024);
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Lampiran selalu dibaca per tugas, dan diurutkan waktu masuknya.
            $table->index(['task_id', 'id'], 'task_files_task_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_task_files');
    }
};
