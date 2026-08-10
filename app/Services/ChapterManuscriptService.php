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

        $seedStatus = optional(
            $book->orderDetails()->with('titleProgress')->get()
                ->map->titleProgress->filter()->first()
        )->status ?? 'menunggu_proses';

        foreach ($chapters as $chapter) {
            if (! $chapter->progress()->exists()) {
                $chapter->progress()->create(['status' => $seedStatus, 'started_at' => now()]);
            }
        }

        // Pre-fill author bab dari author order (bab kosong saja) → hindari input ulang.
        app(ChapterAuthorService::class)->seedFromOrders($book);
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
        foreach ($book->orderDetails()->with('titleProgress')->get() as $detail) {
            $tp = $detail->titleProgress;
            if (! $tp) {
                continue;
            }
            $idx = array_search($tp->status, $stages, true);
            if ($idx === false || $idx >= $targetIdx) {
                continue;
            }
            $tp->update(['status' => $target, 'assigned_role' => TitleProgress::getHandlerForStatus($target)]);
            $moved = true;
        }

        if ($moved) {
            $progress = $book->orderDetails()->with('titleProgress')->get()->map->titleProgress->filter()->first();
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
