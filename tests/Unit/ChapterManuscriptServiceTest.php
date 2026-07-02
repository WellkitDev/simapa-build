<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChapterManuscriptServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChapterManuscriptService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChapterManuscriptService();
    }

    private function bookWithOrder(int $chapters = 3, string $progressStatus = 'menunggu_proses'): Title
    {
        $user = User::factory()->create();
        $book = Title::create(['title' => 'Buku Uji', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-C-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri',
            'title' => 'Buku Uji', 'slug' => 'buku-uji', 'chapters' => $chapters, 'cost_amount' => 0,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $progressStatus, 'assigned_role' => 'marketing', 'started_at' => now()]);

        return $book;
    }

    /** @test */
    public function ensure_generates_chapters_and_progress_from_order_count(): void
    {
        $book = $this->bookWithOrder(3);
        $this->svc->ensureChapters($book);

        $this->assertSame(3, $book->chapters()->count());
        $this->assertSame(3, ChapterProgress::count());
        $this->assertSame('menunggu_proses', $book->chapters()->first()->progress->status);
    }

    /** @test */
    public function ensure_is_idempotent(): void
    {
        $book = $this->bookWithOrder(2);
        $this->svc->ensureChapters($book);
        $this->svc->ensureChapters($book->fresh());

        $this->assertSame(2, $book->chapters()->count());
        $this->assertSame(2, ChapterProgress::count());
    }

    /** @test */
    public function ensure_uses_existing_chapter_list(): void
    {
        $book = $this->bookWithOrder(5);
        $book->chapters()->create(['judul' => 'Pendahuluan', 'urutan' => 1]);
        $book->chapters()->create(['judul' => 'Isi', 'urutan' => 2]);

        $this->svc->ensureChapters($book->fresh());

        $this->assertSame(2, $book->chapters()->count()); // pakai daftar yang ada, bukan 5
        $this->assertSame('Pendahuluan', $book->chapters()->first()->judul);
        $this->assertSame(2, ChapterProgress::count());
    }

    /** @test */
    public function ensure_skips_articles(): void
    {
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->svc->ensureChapters($art);
        $this->assertSame(0, ChapterProgress::count());
    }

    /** @test */
    public function create_for_detail_seeds_chapters_for_book_order(): void
    {
        $user = User::factory()->create();
        $book = Title::create(['title' => 'Buku Order', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-CD-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri',
            'title' => 'Buku Order', 'slug' => 'buku-order', 'chapters' => 2, 'cost_amount' => 0,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        app(\App\Services\TitleProgressService::class)->createForDetail($detail, $user->id);

        $this->assertSame(2, $book->chapters()->count());
        $this->assertSame(2, ChapterProgress::count());
    }
}
