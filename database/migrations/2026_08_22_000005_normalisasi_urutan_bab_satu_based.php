<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menormalkan `tb_title_chapters.urutan` jadi 1-based.
 *
 * Dua tempat membuat bab dengan konvensi berbeda: `ChapterManuscriptService::
 * ensureChapters()` mulai dari 1, sementara `TitleService::syncChapters()` mulai dari 0.
 * Judul yang pernah disimpan lewat layar judul karena itu jadi 0-based.
 *
 * Selisih satu itu bukan kosmetik. `ChapterAuthorService::seedFromOrders()` memasangkan
 * author lewat `Title::orderForChapter((int) $chapter->urutan)`, dan
 * `order_details.chapters` menyimpan NOMOR BAB yang 1-based. Pada judul 0-based:
 *
 *   bab urutan 0 → dicari "order bab 0" → tak ada  → bab pertama kosong & "belum dipesan"
 *   bab urutan 1 → dicari "order bab 1" → author bab 1 mendarat di bab KEDUA
 *
 * Seluruh peta author bergeser satu langkah. Itu yang terlihat sebagai "bab baru
 * disisipkan di paling atas".
 *
 * Per 2026-08-22 di `avidpedi_simapa128`: 4 judul 0-based, 12 sudah 1-based.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh. Sudah terjadi tiga kali di repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $judulNol = DB::table('tb_title_chapters')
            ->select('title_id')
            ->groupBy('title_id')
            ->havingRaw('MIN(urutan) = 0')
            ->pluck('title_id');

        if ($judulNol->isEmpty()) {
            return;
        }

        // +1 pada seluruh babnya. Dikerjakan per judul dan dari urutan TERBESAR lebih
        // dulu supaya tak pernah bertabrakan dengan baris yang belum digeser, andaikan
        // kelak ada unique index (title_id, urutan).
        foreach ($judulNol as $titleId) {
            $ids = DB::table('tb_title_chapters')
                ->where('title_id', $titleId)
                ->orderByDesc('urutan')
                ->pluck('urutan', 'id');

            foreach ($ids as $id => $urutan) {
                DB::table('tb_title_chapters')->where('id', $id)->update(['urutan' => $urutan + 1]);
            }
        }

        /*
         | Author TIDAK dipasang ulang di sini, dan itu disengaja.
         |
         | ChapterAuthorService::seedFromOrders() berjalan setiap kali halaman judul atau
         | naskah dibuka, dan ia hanya mengisi bab yang MASIH KOSONG. Begitu urutannya
         | benar, bab yang tadinya kosong akan terisi sendiri dari ordernya — sementara
         | author yang sudah dipasang tangan tak tersentuh.
         |
         | Memasang ulang di sini justru berisiko menimpa koreksi manual yang sudah
         | dikerjakan orang atas gejala bug ini.
         */
    }

    /**
     * Sengaja tanpa isi: sesudah dinormalkan, judul yang tadinya 0-based tak bisa
     * dibedakan lagi dari yang memang selalu 1-based. Memutar balik akan menebak.
     */
    public function down(): void
    {
        //
    }
};
