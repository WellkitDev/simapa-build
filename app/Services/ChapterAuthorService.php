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
     * Hanya bab yang MASIH KOSONG yang diisi (idempotent; author manual tak ditimpa).
     *
     * **Buku kolaborasi dipasangkan satu author : satu bab menurut urutan** — itulah arti
     * kolaborasi: tiap penulis menyumbang babnya sendiri, dan kolom Author menjawab
     * "bab ini naskah dari siapa". Menyalin SELURUH daftar author ke setiap bab (perilaku
     * lama) membuat pertanyaan itu tak terjawab sama sekali. Bab yang tak kebagian author
     * sengaja dibiarkan kosong: UI menandainya kuning dan memblokir distribusinya sampai
     * dipetakan manusia — jauh lebih baik daripada tebakan yang salah.
     *
     * Buku mandiri tidak dipecah per bab, jadi seluruh author order tetap dilekatkan
     * apa adanya (tabel bab pun tidak dirender untuk buku mandiri).
     */
    public function seedFromOrders(Title $book): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        $orderAuthors = $this->orderAuthors($book);
        if ($orderAuthors->isEmpty()) {
            return;
        }

        $chapters = $book->chapters()->get()->sortBy('urutan')->values();

        if ($this->isCollaborative($book)) {
            foreach ($chapters as $i => $chapter) {
                $author = $orderAuthors[$i] ?? null;
                if ($author === null || $chapter->authors()->exists()) {
                    continue;
                }
                $chapter->authors()->sync([$author->id => ['position' => 1]]);
            }

            return;
        }

        $pivot = [];
        $pos   = 1;
        foreach ($orderAuthors as $author) {
            $pivot[$author->id] = ['position' => $pos++];
        }

        foreach ($chapters as $chapter) {
            if ($chapter->authors()->exists()) {
                continue;
            }
            $chapter->authors()->sync($pivot);
        }
    }

    /**
     * Perbaiki buku kolaborasi yang seluruh babnya terlanjur memuat daftar author yang
     * sama persis — jejak penyemaian lama. Pemetaan yang benar-benar dikerjakan manusia
     * hampir pasti berbeda antar bab, jadi syarat "semua bab identik DAN berisi lebih
     * dari satu author" cukup tajam untuk membedakan keduanya.
     *
     * @return bool true bila buku ini benar-benar dipetakan ulang
     */
    public function repairCollaborativeMapping(Title $book): bool
    {
        if (! $this->isCollaborative($book)) {
            return false;
        }

        $chapters = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        if ($chapters->isEmpty()) {
            return false;
        }

        $sets = $chapters->map(fn ($c) => $c->authors->pluck('id')->sort()->values()->all());

        // Belum tersentuh masalahnya: ada bab kosong, atau tiap bab sudah beda author.
        if ($sets->contains([]) || $sets->unique(fn ($s) => implode(',', $s))->count() > 1) {
            return false;
        }
        if (count($sets->first()) < 2) {
            return false;
        }

        $orderAuthors = $this->orderAuthors($book);
        if ($orderAuthors->isEmpty()) {
            return false;
        }

        foreach ($chapters as $i => $chapter) {
            $author = $orderAuthors[$i] ?? null;
            $chapter->authors()->sync(
                $author === null ? [] : [$author->id => ['position' => 1]]
            );
        }

        return true;
    }

    /** Author order level buku, unik, urut posisi. */
    private function orderAuthors(Title $book)
    {
        return $book->orderDetails()
            ->with(['authors' => fn ($q) => $q->orderByPivot('position')])
            ->get()
            ->flatMap(fn ($detail) => $detail->authors)
            ->unique('id')
            ->values();
    }

    private function isCollaborative(Title $book): bool
    {
        return $book->orderDetails()->where('type', 'bk_kolab')->exists();
    }
}
