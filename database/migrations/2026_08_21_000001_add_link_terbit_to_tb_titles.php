<?php
// database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link artikel/buku terbit — sumber kanonik untuk KEDUA jenis.
 *
 * Sebelumnya link terbit tersebar di dua modul: `tb_book_isbns.link_terbit` (buku) dan
 * `tb_journal_submissions.link_publish` (artikel). Keduanya butuh baris induk yang
 * mungkin belum dibuat — dan untuk artikel, baris submission menuntut `journal_id` yang
 * NOT NULL, sehingga jurnal yang belum terdaftar di direktori akan MENGUNCI naskahnya
 * dari publish. Menaruhnya di judul membuat linknya selalu bisa diisi; Title::linkTerbit()
 * tetap membaca kedua sumber lama sebagai cadangan supaya data lama tak terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->string('link_terbit', 500)->nullable()->after('catatan_publikasi');
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropColumn('link_terbit');
        });
    }
};
