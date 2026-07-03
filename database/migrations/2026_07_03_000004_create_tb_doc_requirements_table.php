<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_doc_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // penerbit | hki
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $items = [
            ['penerbit', 'Naskah Lengkap (Final Draft)', 'Buku sudah melalui penyuntingan dan siap cetak.', 1],
            ['penerbit', 'Surat Pernyataan Keaslian Karya', 'Menyatakan naskah orisinal dan bukan plagiasi.', 2],
            ['penerbit', 'Kelengkapan Naskah Awal', 'Halaman judul, balik halaman judul, kata pengantar, dan daftar isi.', 3],
            ['penerbit', 'Sinopsis/Ringkasan Buku', 'Penjelasan singkat isi buku untuk keperluan katalog.', 4],
            ['penerbit', 'Data Penulis', 'NIK serta biodata singkat penulis.', 5],
            ['hki', 'Surat Pernyataan Kepemilikan', 'Template terisi, bermaterai, ditandatangani semua pencipta.', 1],
            ['hki', 'Contoh Ciptaan', 'File naskah buku lengkap dalam format PDF.', 2],
            ['hki', 'Identitas Pemohon', 'Scan KTP atau Paspor semua penulis/pencipta.', 3],
            ['hki', 'Surat Pengalihan Hak', 'Bila pemegang hak cipta bukan pencipta asli (mis. dialihkan ke penerbit/kampus).', 4],
        ];
        $rows = [];
        foreach ($items as [$cat, $label, $desc, $pos]) {
            $rows[] = ['category' => $cat, 'label' => $label, 'description' => $desc, 'position' => $pos, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tb_doc_requirements')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_doc_requirements');
    }
};
