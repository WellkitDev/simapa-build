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
     * **Buku kolaborasi: bab diambil dari ORDER-nya** — `order_details.chapters` menyimpan
     * nomor bab yang dikontribusikan order itu, dan authornya adalah author order tersebut.
     * Bab yang belum dipesan siapa pun sengaja dibiarkan kosong: UI menandainya dan
     * memblokir distribusinya sampai dipetakan manusia — jauh lebih baik daripada tebakan.
     *
     * Buku mandiri tidak dipecah per bab, jadi seluruh author order dilekatkan apa adanya
     * (tabel bab pun tidak dirender untuk buku mandiri).
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
            // Pasangan bab↔author diambil dari ORDER-nya: `order_details.chapters` pada
            // buku kolaborasi adalah NOMOR BAB yang dikontribusikan order itu. Menebak
            // lewat urutan daftar author salah — di data nyata ada order bernomor bab 4
            // yang authornya kebetulan tercatat pertama.
            foreach ($chapters as $chapter) {
                if ($chapter->authors()->exists()) {
                    continue;
                }

                $order = $book->orderForChapter((int) $chapter->urutan);
                if (! $order) {
                    continue; // babnya belum dipesan siapa pun → biarkan kosong
                }

                $pivot = [];
                $pos   = 1;
                foreach ($order->authors()->orderByPivot('position')->get() as $author) {
                    $pivot[$author->id] = ['position' => $pos++];
                }
                if ($pivot !== []) {
                    $chapter->authors()->sync($pivot);
                }
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
     * Selaraskan author bab buku kolaborasi dengan ORDER-nya (sumber kebenaran).
     *
     * Menimpa pemetaan yang ada bila berbeda — memang itu tujuannya: pemetaan lama
     * dihasilkan otomatis (mula-mula seluruh author disalin ke tiap bab, lalu ditebak
     * lewat urutan daftar author) dan keduanya tidak melihat nomor bab di ordernya.
     * Bab yang belum dipesan siapa pun dibiarkan apa adanya, bukan dikosongkan.
     *
     * @return int jumlah bab yang pemetaannya berubah
     */
    public function remapFromOrders(Title $book): int
    {
        if (! $this->isCollaborative($book)) {
            return 0;
        }

        $berubah = 0;

        foreach ($book->chapters()->with('authors')->get() as $chapter) {
            $order = $book->orderForChapter((int) $chapter->urutan);
            if (! $order) {
                continue;
            }

            $seharusnya = $order->authors()->orderByPivot('position')->pluck('tb_authors.id')->all();
            $sekarang   = $chapter->authors->pluck('id')->all();

            if ($seharusnya === [] || $seharusnya === $sekarang) {
                continue;
            }

            $pivot = [];
            $pos   = 1;
            foreach ($seharusnya as $id) {
                $pivot[$id] = ['position' => $pos++];
            }
            $chapter->authors()->sync($pivot);
            $berubah++;
        }

        return $berubah;
    }

    private function isCollaborative(Title $book): bool
    {
        return $book->orderDetails()->where('type', 'bk_kolab')->exists();
    }

    /** Author order level buku, unik, urut posisi — dipakai buku mandiri. */
    private function orderAuthors(Title $book)
    {
        return $book->orderDetails()
            ->with(['authors' => fn ($q) => $q->orderByPivot('position')])
            ->get()
            ->flatMap(fn ($detail) => $detail->authors)
            ->unique('id')
            ->values();
    }
}
