<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id folder Google Drive milik judul ini.
 *
 * Tanpa kolom ini setiap unggahan menelusuri ulang seluruh lapis folder — beberapa
 * panggilan API hanya untuk mencari tempat menaruh berkas, tiap kali, selamanya.
 *
 * Disimpan sebagai id, bukan nama: kode judul boleh disunting kapan saja tanpa membuat
 * folder lamanya hilang. Yang basi cuma namanya di Drive, dan itu diterima (spec §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $t) {
            $t->string('drive_folder_id', 100)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $t) {
            $t->dropColumn('drive_folder_id');
        });
    }
};
