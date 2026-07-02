<?php

namespace App\Services;

use App\Models\Title;

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
    }
}
