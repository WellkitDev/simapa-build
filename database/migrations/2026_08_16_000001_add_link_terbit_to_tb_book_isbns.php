<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link terbit di web avidpedia — dipakai marketing untuk mengabari klien dan
 * ditampilkan langsung di Direktori ISBN. E-book & sertifikat TIDAK butuh kolom:
 * keduanya menumpang tb_manuscript_files lewat slot `ebook` / `sertifikat_isbn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_book_isbns', function (Blueprint $table) {
            $table->string('link_terbit', 500)->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('tb_book_isbns', function (Blueprint $table) {
            $table->dropColumn('link_terbit');
        });
    }
};
