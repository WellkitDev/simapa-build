<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\Author;
use App\Services\ChapterAuthorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChapterAuthorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChapterAuthorService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChapterAuthorService();
    }

    private function book(int $chapters = 2): Title
    {
        $book = Title::create(['title' => 'Buku Author', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        for ($i = 1; $i <= $chapters; $i++) {
            $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
        }
        return $book;
    }

    /** @test */
    public function sync_links_existing_and_creates_new_authors_with_position(): void
    {
        $book = $this->book(2);
        $ch1 = $book->chapters()->orderBy('urutan')->first();
        $existing = Author::create(['name' => 'Penulis Lama']);

        $this->svc->syncChapterAuthors($book, [
            $ch1->id => [(string) $existing->id, 'Penulis Baru'],
        ]);

        $authors = $ch1->authors()->get();
        $this->assertSame(2, $authors->count());
        $this->assertSame($existing->id, $authors[0]->id);
        $this->assertSame(1, (int) $authors[0]->pivot->position);
        $this->assertSame('Penulis Baru', $authors[1]->name);
        $this->assertSame(2, (int) $authors[1]->pivot->position);
        $this->assertSame(1, Author::where('name', 'Penulis Baru')->count());
    }

    /** @test */
    public function sync_replaces_previous_set_and_clears_when_absent(): void
    {
        $book = $this->book(2);
        $chapters = $book->chapters()->orderBy('urutan')->get();
        $a = Author::create(['name' => 'A']);
        $b = Author::create(['name' => 'B']);

        $this->svc->syncChapterAuthors($book, [$chapters[0]->id => [(string) $a->id], $chapters[1]->id => [(string) $b->id]]);
        $this->assertSame(1, $chapters[0]->authors()->count());

        $this->svc->syncChapterAuthors($book, [$chapters[0]->id => [(string) $b->id]]);
        $this->assertSame([$b->id], $chapters[0]->authors()->pluck('tb_authors.id')->all());
        $this->assertSame(0, $chapters[1]->authors()->count());
    }

    /** @test */
    public function non_book_is_ignored(): void
    {
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->svc->syncChapterAuthors($art, [999 => ['X']]);
        $this->assertSame(0, Author::count());
    }

    /**
     * Satu order kolaborasi = satu author = satu bab tertentu; `chapters` menyimpan
     * NOMOR bab yang dikontribusikan order itu.
     */
    private function orderBab(Title $book, int $nomorBab, Author $author, string $naskahType = 'dibuatkan'): void
    {
        \App\Models\OrderDetail::factory()->create([
            'title_id' => $book->id, 'type' => 'bk_kolab',
            'chapters' => $nomorBab, 'naskah_type' => $naskahType,
        ])->authors()->attach($author->id, ['position' => 1]);
    }

    /** Buat order tertaut ke buku + author (urut posisi) — dipakai kasus buku mandiri. */
    private function attachOrderAuthors(Title $book, array $authors): void
    {
        $detail = \App\Models\OrderDetail::factory()->create(['title_id' => $book->id, 'type' => 'bk_kolab']);
        $pos = 1;
        foreach ($authors as $a) {
            $detail->authors()->attach($a->id, ['position' => $pos++]);
        }
    }

    /**
     * DIUBAH 2026-08-11 setelah owner mengoreksi model domainnya. Test ini sempat dua kali
     * salah: mula-mula menuntut SETIAP bab memuat seluruh author, lalu menebak author
     * ke-N → bab ke-N. Keduanya mengabaikan nomor bab yang tercatat di ordernya, padahal
     * di situlah jawabannya — dan salah pasang berarti nama author bab jadi salah orang.
     *
     * @test
     */
    public function seed_from_orders_memasangkan_author_sesuai_nomor_bab_ordernya(): void
    {
        $book = $this->book(2);
        $a = Author::create(['name' => 'Ani']);
        $b = Author::create(['name' => 'Budi']);

        // Sengaja dibuat terbalik: Budi dicatat lebih dulu, tapi ordernya untuk Bab 2.
        $this->orderBab($book, 2, $b);
        $this->orderBab($book, 1, $a);

        $this->svc->seedFromOrders($book);

        $chapters = $book->chapters()->get()->sortBy('urutan')->values();
        $this->assertSame([$a->id], $chapters[0]->authors()->pluck('tb_authors.id')->all());
        $this->assertSame([$b->id], $chapters[1]->authors()->pluck('tb_authors.id')->all());
    }

    /**
     * Penyemaian tak menimpa bab yang sudah punya author, dan bab yang BELUM DIPESAN
     * dibiarkan kosong — UI menandainya "Bab belum dipesan" supaya manusia yang
     * memutuskan, bukan sistem menebak.
     *
     * (Beda dengan `remapFromOrders()` yang memang sengaja menimpa: itu dipakai command
     * migrasi untuk membetulkan pemetaan lama yang bertentangan dengan ordernya.)
     *
     * @test
     */
    public function seed_from_orders_tidak_menimpa_dan_membiarkan_bab_tanpa_order(): void
    {
        $book = $this->book(2);
        $chapters = $book->chapters()->orderBy('urutan')->get();
        $manual = Author::create(['name' => 'Manual']);
        $chapters[0]->authors()->attach($manual->id, ['position' => 1]);

        // Hanya Bab 1 yang dipesan — dan babnya sudah dipetakan manusia.
        $this->orderBab($book, 1, Author::create(['name' => 'Order']));

        $this->svc->seedFromOrders($book);

        $this->assertSame([$manual->id], $chapters[0]->authors()->pluck('tb_authors.id')->all());
        $this->assertSame([], $chapters[1]->authors()->pluck('tb_authors.id')->all());
    }

    /** @test */
    public function seed_from_orders_noop_without_order_authors(): void
    {
        $book = $this->book(2);
        $this->svc->seedFromOrders($book);
        foreach ($book->chapters()->get() as $ch) {
            $this->assertSame(0, $ch->authors()->count());
        }
    }
}
