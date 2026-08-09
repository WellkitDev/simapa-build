<?php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Status buku kolaborasi = roll-up dari bab paling belakang (bottleneck).
 *
 * Tahap Pembuatan & Editing berjalan PER BAB; tahap Layout→Terbit berjalan level buku
 * dan baru terbuka setelah SEMUA bab Selesai (`chapters_done`). Karena itu roll-up
 * hanya boleh menggerakkan buku selama ia masih di wilayah bab — begitu admin menekan
 * "Mulai Layout", perubahan bab tidak boleh menarik buku mundur lagi.
 *
 * Dipanggil eksplisit setiap ChapterProgress berubah (bukan lewat observer) supaya
 * urutan kejadian terbaca di kode pemanggil dan mudah diuji.
 */
class ChapterRollupService
{
    /** Batas wilayah bab: buku di tahap ini atau sebelumnya masih ikut roll-up. */
    private const CHAPTER_ZONE_LIMIT = 'editing';

    public function recalc(Title $book, ?User $actor = null): void
    {
        if (! $this->isCollaborative($book)) {
            return;
        }

        $statuses = $this->chapterStatuses($book);
        if ($statuses->isEmpty()) {
            return;
        }

        $rolled = $this->bottleneck($statuses);
        $done   = $statuses->every(fn (string $s) => $s === 'selesai');

        $progresses = $book->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter();

        if ($progresses->isEmpty()) {
            return;
        }

        $firstTimeDone = $done && $progresses->contains(fn (TitleProgress $p) => ! $p->chapters_done);

        DB::transaction(function () use ($progresses, $rolled, $done) {
            foreach ($progresses as $p) {
                $attrs = ['chapters_done' => $done];

                // Hanya sinkron status selama buku masih di wilayah bab.
                if ($this->inChapterZone($p->status) && $p->status !== $rolled) {
                    $attrs['status']        = $rolled;
                    $attrs['assigned_role'] = TitleProgress::getHandlerForStatus($rolled);
                    $attrs['started_at']    = now();
                }

                $p->update($attrs);
            }
        });

        if ($firstTimeDone) {
            $this->logChaptersDone($progresses->first(), $statuses->count(), $actor);
        }
    }

    /**
     * Buku kolaborasi dipecah per bab; buku mandiri satu kesatuan. Sumber kebenarannya
     * tipe order (`bk_kolab`) — bukan jumlah bab, karena bab ikut dibuat untuk buku
     * mandiri juga oleh ChapterManuscriptService.
     */
    public function isCollaborative(Title $book): bool
    {
        return $book->jenis === 'buku'
            && $book->orderDetails()->where('type', 'bk_kolab')->exists();
    }

    /** Semua bab sudah Selesai → tombol "Mulai Layout" terbuka. */
    public function chaptersDone(Title $book): bool
    {
        $statuses = $this->chapterStatuses($book);

        return $statuses->isNotEmpty() && $statuses->every(fn (string $s) => $s === 'selesai');
    }

    /**
     * Ringkasan bab untuk kartu papan & header detail:
     * "✓2 selesai · 1 editing · 1 pembuatan · 6 menunggu" + persen progres.
     */
    public function summary(Title $book): array
    {
        $statuses = $this->chapterStatuses($book);
        $counts   = [];

        foreach (ChapterProgress::CHAPTER_STAGES as $stage) {
            $counts[$stage] = $statuses->filter(fn (string $s) => $s === $stage)->count();
        }

        $total = $statuses->count();

        return [
            'total'   => $total,
            'counts'  => $counts,
            'selesai' => $counts['selesai'],
            'persen'  => $total > 0 ? (int) round($counts['selesai'] / $total * 100) : 0,
        ];
    }

    // ─── Internal ───

    /** @return \Illuminate\Support\Collection<int,string> */
    private function chapterStatuses(Title $book)
    {
        return $book->chapters()->with('progress')->get()
            ->map(fn ($c) => optional($c->progress)->status)
            ->filter()
            ->values();
    }

    /**
     * Status buku dari bab paling belakang: ada bab belum digarap/masih dibuat → buku
     * Pembuatan; ada bab masih editing → buku Editing; semua selesai → buku tetap
     * Editing (menunggu admin menekan "Mulai Layout" secara sadar).
     */
    private function bottleneck($statuses): string
    {
        if ($statuses->contains(fn (string $s) => in_array($s, ['menunggu', 'pembuatan'], true))) {
            return 'pembuatan';
        }

        return 'editing';
    }

    private function inChapterZone(string $status): bool
    {
        $stages = TitleProgress::BOOK_STAGES;
        $idx    = array_search($status, $stages, true);
        $limit  = array_search(self::CHAPTER_ZONE_LIMIT, $stages, true);

        return $idx !== false && $idx <= $limit;
    }

    private function logChaptersDone(TitleProgress $progress, int $total, ?User $actor): void
    {
        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => 'chapters_done',
            'from_value'        => 'Bab berjalan',
            'to_value'          => "Semua bab selesai ({$total} bab)",
            'changed_by'        => $actor?->id,
            'note'              => 'Tahap Layout sudah bisa dimulai.',
            'is_correction'     => false,
        ]);

        $progress->update(['last_log_at' => now()]);
    }
}
