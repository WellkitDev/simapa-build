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

    /** Buat order tertaut ke buku + author (urut posisi). */
    private function attachOrderAuthors(Title $book, array $authors): void
    {
        $detail = \App\Models\OrderDetail::factory()->create(['title_id' => $book->id, 'type' => 'bk_kolab']);
        $pos = 1;
        foreach ($authors as $a) {
            $detail->authors()->attach($a->id, ['position' => $pos++]);
        }
    }

    /** @test */
    public function seed_from_orders_fills_empty_chapters_from_order_authors(): void
    {
        $book = $this->book(2);
        $a = Author::create(['name' => 'Ani']);
        $b = Author::create(['name' => 'Budi']);
        $this->attachOrderAuthors($book, [$a, $b]);

        $this->svc->seedFromOrders($book);

        foreach ($book->chapters()->get() as $ch) {
            $this->assertSame([$a->id, $b->id], $ch->authors()->pluck('tb_authors.id')->all());
        }
    }

    /** @test */
    public function seed_from_orders_does_not_overwrite_existing_chapter_authors(): void
    {
        $book = $this->book(2);
        $chapters = $book->chapters()->orderBy('urutan')->get();
        $manual = Author::create(['name' => 'Manual']);
        $chapters[0]->authors()->attach($manual->id, ['position' => 1]);

        $order = Author::create(['name' => 'Order']);
        $this->attachOrderAuthors($book, [$order]);

        $this->svc->seedFromOrders($book);

        // bab 1 dipertahankan (tak ditimpa); bab 2 (kosong) terisi dari order
        $this->assertSame([$manual->id], $chapters[0]->authors()->pluck('tb_authors.id')->all());
        $this->assertSame([$order->id], $chapters[1]->authors()->pluck('tb_authors.id')->all());
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
