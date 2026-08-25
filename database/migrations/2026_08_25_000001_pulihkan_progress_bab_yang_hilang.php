<?php
// database/migrations/2026_08_25_000001_pulihkan_progress_bab_yang_hilang.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memulihkan ChapterProgress untuk bab yang kehilangan atau tak pernah punya.
 *
 * Kolom Pelaksana, Status, Lama, dan Aksi di tabel bab semuanya dibaca dari baris itu.
 * Bab tanpa progress karena itu tampil sebagai deretan strip di Pelacakan Naskah — dan
 * tak ada satu pun tombol di layar untuk memperbaikinya.
 *
 * Dua jalan yang membuatnya hilang, keduanya sudah ditutup di kode:
 *
 *   1. `TitleService::syncChapters()` versi lama menghapus SELURUH bab tiap kali judul
 *      disimpan lalu membuatnya ulang. `tb_chapter_progress.title_chapter_id` memakai
 *      ON DELETE CASCADE, jadi kemajuan tiap bab ikut musnah, dan bab penggantinya
 *      lahir tanpa progress sama sekali.
 *   2. Progress hanya dibuat di `ChapterManuscriptService::ensureChapters()`, yang
 *      dipanggil dari SATU tempat: saat TitleProgress sebuah order dibuat. Bab yang
 *      lahir sesudah ordernya dipesan karena itu tak pernah kebagian.
 *
 * Author bab selamat dari keduanya karena `ChapterAuthorService::seedFromOrders()`
 * berjalan tiap kali halaman judul dibuka. Itulah kenapa gejalanya khas: kolom Author
 * terisi rapi sementara sisanya strip.
 *
 * Di dump produksi 2026-08-25: 24 dari 109 bab, tersebar di tiga judul.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh. Sudah terjadi tiga kali di repo ini. Pemetaan tahapnya pun ditulis
 * ulang di sini alih-alih memanggil ChapterProgress::semaianDariTahapBuku(), supaya
 * migrasi ini membeku pada perilaku hari ini dan tak ikut berubah bila kelak
 * kosakatanya diperluas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $yatim = DB::table('tb_title_chapters as c')
            ->leftJoin('tb_chapter_progress as cp', 'cp.title_chapter_id', '=', 'c.id')
            ->whereNull('cp.id')
            ->orderBy('c.title_id')->orderBy('c.urutan')
            ->get(['c.id', 'c.title_id']);

        if ($yatim->isEmpty()) {
            return;
        }

        $sekarang    = now();
        $tahapPerJudul = [];
        $baris       = [];

        foreach ($yatim as $bab) {
            // Tahap bukunya, dari order yang belum ditarik. Dihitung sekali per judul.
            if (! array_key_exists($bab->title_id, $tahapPerJudul)) {
                $tahapPerJudul[$bab->title_id] = DB::table('tb_order_details as od')
                    ->join('tb_title_progress as tp', 'tp.order_detail_id', '=', 'od.id')
                    ->where('od.title_id', $bab->title_id)
                    ->whereNull('od.deleted_at')
                    ->whereNull('tp.deleted_at')
                    ->whereNull('tp.withdrawn_at')
                    ->orderBy('tp.id')
                    ->value('tp.status');
            }

            $baris[] = [
                'title_chapter_id' => $bab->id,
                'status'           => $this->semaian($tahapPerJudul[$bab->title_id]),
                'started_at'       => $sekarang,
                'created_at'       => $sekarang,
                'updated_at'       => $sekarang,
            ];
        }

        // Sisipkan berkelompok; title_chapter_id punya unique index, jadi tak mungkin ganda.
        foreach (array_chunk($baris, 100) as $potong) {
            DB::table('tb_chapter_progress')->insert($potong);
        }
    }

    /**
     * Tahap BUKU (delapan nilai) diterjemahkan ke tahap BAB (empat nilai).
     *
     * Menyalinnya mentah-mentah akan menuliskan status seperti `layout` yang tak ada di
     * CHAPTER_STAGES sama sekali; `nextStage()` lalu mengembalikan null dan babnya
     * terkunci selamanya tanpa satu pun pesan galat.
     *
     * Buku yang sudah melewati `editing` hanya bisa sampai di sana bila SELURUH babnya
     * selesai (dijaga assertLayoutUnlocked), jadi `selesai` itulah yang benar. Tahap
     * yang tak dikenal diperlakukan sebagai belum mulai: menandai bab selesai atas tahap
     * yang tak kita pahami adalah kebohongan yang menutup pekerjaan.
     */
    private function semaian(?string $tahapBuku): string
    {
        $tahapBukuSah = ['menunggu_proses', 'pembuatan', 'editing', 'layout',
                         'proofreading', 'isbn', 'cetak', 'terbit'];

        if ($tahapBuku === null || $tahapBuku === 'menunggu_proses') {
            return 'menunggu';
        }

        if ($tahapBuku === 'pembuatan' || $tahapBuku === 'editing') {
            return $tahapBuku;
        }

        return in_array($tahapBuku, $tahapBukuSah, true) ? 'selesai' : 'menunggu';
    }

    /**
     * Sengaja tanpa isi: sesudah dipulihkan, baris ini tak bisa dibedakan lagi dari
     * progress bab yang memang selalu ada. Menghapusnya kembali justru mengembalikan
     * babnya jadi deretan strip.
     */
    public function down(): void
    {
        //
    }
};
