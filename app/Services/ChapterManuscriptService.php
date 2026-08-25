<?php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChapterManuscriptService
{
    /** Pastikan buku punya daftar bab + ChapterProgress. Auto-generate dari OrderDetail.chapters bila kosong. Idempotent. */
    public function ensureChapters(Title $book): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        $chapters = $book->chapters()->get();

        if ($chapters->isEmpty()) {
            $n = max(1, (int) $book->orderDetails()->max('chapters'));
            for ($i = 1; $i <= $n; $i++) {
                $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
            }
            $chapters = $book->chapters()->get();
        }

        $this->pastikanProgress($book);

        // Pre-fill author bab dari author order (bab kosong saja) → hindari input ulang.
        app(ChapterAuthorService::class)->seedFromOrders($book);
    }

    /**
     * Setiap bab wajib punya ChapterProgress. Mengisi yang belum punya, idempoten.
     *
     * Kolom Pelaksana, Status, Lama, dan Aksi di tabel bab semuanya dibaca dari baris
     * itu, jadi bab tanpa progress tampil sebagai deretan strip — dan tak ada satu pun
     * tombol di layar untuk memperbaikinya.
     *
     * TERPISAH dari ensureChapters() supaya bisa dipanggil dari TitleService saat
     * formulir judul disimpan. ensureChapters() ikut MEMBUAT bab bila daftarnya kosong;
     * dipanggil sesudah orang sengaja menghapus semua bab, ia akan menghidupkannya lagi.
     * Yang dibutuhkan di sana cuma bagian progressnya.
     *
     * @return int jumlah progress yang baru dibuat
     */
    public function pastikanProgress(Title $book): int
    {
        if ($book->jenis !== 'buku') {
            return 0;
        }

        $tanpaProgress = $book->chapters()->doesntHave('progress')->get();
        if ($tanpaProgress->isEmpty()) {
            return 0;
        }

        $tahapBuku = optional(
            $book->orderDetails()->notWithdrawn()->with('titleProgress')->get()
                ->map->titleProgress->filter()->first()
        )->status;

        // Diterjemahkan, BUKAN disalin: tahap buku dan tahap bab beda kosakata.
        $status = ChapterProgress::semaianDariTahapBuku($tahapBuku);

        foreach ($tanpaProgress as $chapter) {
            $chapter->progress()->create(['status' => $status, 'started_at' => now()]);
        }

        return $tanpaProgress->count();
    }

    /**
     * Majukan manuskrip buku ke tahap target (maju-saja) — dipicu registrasi ISBN.
     * Menggerakkan bab (bila ada) + TitleProgress tiap order-variant; tak pernah memundurkan.
     */
    public function advanceBookToStage(Title $book, string $target, User $actor): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }
        $stages    = TitleProgress::BOOK_STAGES;
        $targetIdx = array_search($target, $stages, true);
        if ($targetIdx === false) {
            return;
        }

        /*
         | Jalur ISBN menulis tahap secara langsung, jadi gerbang di
         | TitleProgressService::advance() tak berlaku di sini. Tanpa penjagaan ini
         | buku bisa mendarat di 'terbit' tanpa link — persis kebocoran yang sudah
         | menggigit sekali saat OrderFulfillmentService dipasang.
         |
         | MELEWATI, bukan melempar: ini sinkronisasi yang dipicu penyimpanan form ISBN,
         | bukan aksi "majukan tahap" milik pengguna. Melempar di sini akan menggagalkan
         | penyimpanan ISBN yang sah. BookIsbnController sudah mewajibkan link_terbit
         | untuk status Cetak/Terbit, jadi cabang ini praktis tak terpicu — ia ada supaya
         | invariannya benar tak peduli siapa pemanggilnya.
         */
        if (TitleProgress::isFinal($target) && $book->butuhLinkTerbit()) {
            return;
        }

        $moved = false;

        /*
         | Bab punya alurnya sendiri (CHAPTER_STAGES) dan berhenti di 'selesai' — tahap
         | Layout→Terbit adalah urusan level buku. Karena itu buku yang melewati wilayah
         | bab menandai SEMUA babnya 'selesai', bukan menyalin nama tahap buku ke bab
         | (yang dulu menghasilkan status bab tak dikenal seperti 'isbn'/'cetak').
         */
        if ($targetIdx > array_search('editing', $stages, true)) {
            foreach ($book->chapters()->with('progress')->get() as $chapter) {
                $cp = $chapter->progress;
                if (! $cp || $cp->status === 'selesai') {
                    continue;
                }
                $cp->update([
                    'status'      => 'selesai',
                    'updated_by'  => $actor->id,
                    'started_at'  => now(),
                    'last_log_at' => now(),
                ]);
                $moved = true;
            }
        }

        // TitleProgress tiap order-variant (sumber manuscriptStatus) — maju-saja.
        foreach ($book->orderDetails()->notWithdrawn()->with('titleProgress')->get() as $detail) {
            $tp = $detail->titleProgress;
            if (! $tp) {
                continue;
            }
            $idx = array_search($tp->status, $stages, true);
            if ($idx === false || $idx >= $targetIdx) {
                continue;
            }
            $tp->update(['status' => $target, 'assigned_role' => TitleProgress::getHandlerForStatus($target)]);

            // Jalur ini tidak lewat TitleProgressService::applyStatus(), padahal
            // `terbit` adalah tahap final — tanpa kail ini order buku yang terbit
            // lewat registrasi ISBN akan tertinggal `berjalan` selamanya.
            // $detail sudah di tangan: disuapkan supaya sinkron tak menembak SELECT
            // orderDetail-nya sendiri per order-variant.
            $tp->setRelation('orderDetail', $detail);
            app(OrderFulfillmentService::class)->syncFromProgress($tp);

            $moved = true;
        }

        if ($moved) {
            $progress = $book->orderDetails()->notWithdrawn()->with('titleProgress')->get()->map->titleProgress->filter()->first();
            if ($progress) {
                TitleProgressLog::create([
                    'title_progress_id' => $progress->id,
                    'event'             => 'isbn_sync',
                    'from_value'        => 'Registrasi ISBN',
                    'to_value'          => Str::title(str_replace('_', ' ', $target)),
                    'changed_by'        => $actor->id,
                    'note'              => 'Sinkron otomatis dari registrasi ISBN.',
                    'is_correction'     => false,
                ]);
            }
        }
    }

}
