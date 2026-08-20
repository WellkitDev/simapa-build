<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unggahan berkas naskah/ISBN pindah ke queue: request tak lagi menunggu Google Drive.
 *
 * Baris kini bisa hidup dalam tiga keadaan, sehingga butuh kolomnya sendiri —
 * `drive_url` yang kosong saja tidak cukup, karena tak bisa membedakan "sedang
 * diproses" dari "gagal diunggah", dan bedanya menentukan apa yang boleh dilakukan
 * pengguna berikutnya.
 *
 * Baris lama seluruhnya sudah terunggah, jadi default 'selesai' membuat backfill-nya
 * benar tanpa sentuhan tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $table) {
            $table->string('status', 10)->default('selesai')->after('slot');
            // Berkas menunggu di disk server sampai job mengambilnya: berkas sementara
            // milik PHP sudah lenyap saat worker jalan semenit kemudian.
            $table->string('local_path', 255)->nullable()->after('drive_url');
            $table->text('upload_error')->nullable()->after('local_path');

            $table->index(['title_id', 'slot', 'status'], 'mf_title_slot_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $table) {
            $table->dropIndex('mf_title_slot_status_idx');
            $table->dropColumn(['status', 'local_path', 'upload_error']);
        });
    }
};
