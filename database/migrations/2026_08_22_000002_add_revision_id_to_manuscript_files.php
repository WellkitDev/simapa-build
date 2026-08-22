<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan berkas ke putaran perbaikannya.
 *
 * Nullable karena sebagian besar berkas (naskah masuk, hasil layout, cover, berkas
 * ISBN) tak pernah milik putaran mana pun. Hanya slot revisi_minta/revisi_hasil yang
 * mengisinya.
 *
 * `version` yang sudah ada tetap mengurus berkas berulang di slot yang sama; putaran
 * diurus kolom ini. Tanpa pemisahan itu, "tiga berkas di putaran 1" tak bisa dibedakan
 * dari "tiga putaran berisi satu berkas".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $t) {
            $t->foreignId('manuscript_revision_id')->nullable()->after('title_chapter_id')
              ->constrained('tb_manuscript_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $t) {
            $t->dropConstrainedForeignId('manuscript_revision_id');
        });
    }
};
