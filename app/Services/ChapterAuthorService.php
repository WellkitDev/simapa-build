<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Title;

class ChapterAuthorService
{
    /**
     * Sinkron author per bab untuk buku. Panel otoritatif atas SEMUA bab:
     * bab tanpa entri di $chapterAuthors → dikosongkan.
     * @param array $chapterAuthors [title_chapter_id => [authorRef, …]] (authorRef = id existing atau nama baru)
     */
    public function syncChapterAuthors(Title $book, array $chapterAuthors): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        foreach ($book->chapters()->get() as $chapter) {
            $refs  = $chapterAuthors[$chapter->id] ?? [];
            $pivot = [];
            $pos   = 1;

            foreach ((array) $refs as $ref) {
                $ref = is_string($ref) ? trim($ref) : $ref;
                if ($ref === '' || $ref === null) {
                    continue;
                }
                $authorId = is_numeric($ref)
                    ? (int) $ref
                    : Author::firstOrCreate(['name' => $ref])->id;
                $pivot[$authorId] = ['position' => $pos++];
            }

            $chapter->authors()->sync($pivot);
        }
    }

    /**
     * Isi author bab dari author order (level buku) untuk menghindari input ulang.
     * Hanya bab yang MASIH KOSONG yang diisi (idempotent; author manual/seeded tak ditimpa).
     * Author order = distinct dari OrderDetail tertaut (tb_author_orders), urut posisi.
     */
    public function seedFromOrders(Title $book): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        $orderAuthors = $book->orderDetails()
            ->with(['authors' => fn ($q) => $q->orderByPivot('position')])
            ->get()
            ->flatMap(fn ($detail) => $detail->authors)
            ->unique('id')
            ->values();

        if ($orderAuthors->isEmpty()) {
            return;
        }

        $pivot = [];
        $pos = 1;
        foreach ($orderAuthors as $author) {
            $pivot[$author->id] = ['position' => $pos++];
        }

        foreach ($book->chapters()->get() as $chapter) {
            if ($chapter->authors()->exists()) {
                continue; // sudah ada author (manual/seeded) → jangan timpa
            }
            $chapter->authors()->sync($pivot);
        }
    }
}
