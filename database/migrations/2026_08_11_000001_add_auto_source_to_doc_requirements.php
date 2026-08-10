<?php
// database/migrations/2026_08_11_000001_add_auto_source_to_doc_requirements.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sebagian item kelengkapan dokumen sebenarnya berkas yang SUDAH diunggah di tempat
 * lain — "Naskah Lengkap (Final Draft)" persis sama dengan slot Naskah Final di
 * Pelacakan Naskah. Meminta berkas yang sama dua kali membuat keduanya bisa berbeda
 * isi, dan tak ada cara tahu mana yang benar.
 *
 * Kolom ini menandai item yang dipenuhi otomatis dari sumber lain, sehingga item
 * tersebut tidak lagi punya kotak unggah sendiri: ia tercentang begitu berkas sumbernya
 * ada. Item lain (surat pernyataan, KTP, dst.) tetap diunggah manual di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_doc_requirements', function (Blueprint $table) {
            $table->string('auto_source', 30)->nullable()->after('description');
        });

        // Item bawaan "Naskah Lengkap (Final Draft)" ditautkan ke slot naskah final.
        // Dicocokkan lewat label bawaan migrasi pembuatnya; bila sudah diganti nama
        // oleh pengelola, tautannya bisa diatur ulang lewat CRUD template dokumen.
        DB::table('tb_doc_requirements')
            ->where('category', 'penerbit')
            ->where('label', 'Naskah Lengkap (Final Draft)')
            ->update(['auto_source' => 'naskah_final']);
    }

    public function down(): void
    {
        Schema::table('tb_doc_requirements', function (Blueprint $table) {
            $table->dropColumn('auto_source');
        });
    }
};
