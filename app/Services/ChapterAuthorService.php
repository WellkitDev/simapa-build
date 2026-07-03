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
}
