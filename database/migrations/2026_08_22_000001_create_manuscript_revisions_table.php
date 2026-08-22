<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu putaran perbaikan naskah.
 *
 * Kenapa tabel sendiri dan bukan kolom di tb_manuscript_files: putaran bisa ada SEBELUM
 * berkasnya ada — PJ menulis permintaan dulu, berkasnya menyusul, atau permintaannya
 * memang berupa catatan saja. Selain itu catatan dan tujuan yang tersalin ke tiap
 * berkas bisa saling bertentangan, dan "kapan putaran dibuka" tak punya tempat.
 *
 * Terikat ke JUDUL, bukan order — mengikuti pola tb_manuscript_files. Untuk artikel
 * kolaborasi satu putaran berlaku sejudul, sama seperti berkasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_manuscript_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $t->foreignId('title_chapter_id')->nullable()
              ->constrained('tb_title_chapters')->nullOnDelete();

            $t->unsignedInteger('round');            // urut per judul: 1, 2, 3
            $t->string('stage', 20);                 // 'revisi' | 'pembuatan'
            $t->string('from_stage', 20);            // 'submit' | 'loa' | 'editing'

            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->text('request_note');

            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            // close_note terisi HANYA saat ditutup paksa lewat pintu darurat. Kosong =
            // ditutup wajar karena naskahnya maju. Bedanya perlu terbaca di riwayat.
            $t->text('close_note')->nullable();

            $t->timestamps();

            $t->index(['title_id', 'stage', 'closed_at'], 'mr_title_stage_open_idx');
            $t->index(['title_id', 'round'], 'mr_title_round_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_manuscript_revisions');
    }
};
