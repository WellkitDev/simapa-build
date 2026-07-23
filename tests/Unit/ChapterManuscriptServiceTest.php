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
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            \Spatie\Permission\Models\Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new ChapterManuscriptService();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
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

    /** @test */
    public function production_advances_a_production_stage_chapter_and_rolls_up(): void
    {
        $prod = $this->user('production');
        $book = $this->bookWithOrder(2, 'editing'); // progress buku 'editing'
        $this->svc->ensureChapters($book);          // 2 bab @ editing

        $chapters = $book->chapters()->with('progress')->orderBy('urutan')->get();
        // Majukan bab pertama editing -> layout (keduanya handler production)
        $this->svc->changeStatus($chapters[0]->progress, 'layout', $prod);

        $this->assertSame('layout', $chapters[0]->progress->fresh()->status);
        // roll-up buku = bottleneck = 'editing' (bab kedua masih editing)
        $bookProgress = $book->orderDetails()->first()->titleProgress;
        $this->assertSame('editing', $bookProgress->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_a_superadmin_stage_chapter(): void
    {
        $prod = $this->user('production');
        $book = $this->bookWithOrder(1, 'cetak'); // handler superadmin
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->svc->changeStatus($cp, 'terbit', $prod);
    }

    /** @test */
    public function correction_requires_note(): void
    {
        $mgr = $this->user('manager');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc->changeStatus($cp, 'menunggu_proses', $mgr); // lompat mundur tanpa note
    }

    /** @test */
    public function assign_editor_sets_chapter_editor(): void
    {
        $mgr = $this->user('manager');
        $editor = $this->user('production');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->svc->assignEditor($cp, $editor->id, $mgr);
        $this->assertSame($editor->id, $cp->fresh()->assigned_user_id);
    }

    /** @test */
    public function assign_editor_rejects_non_editor_role(): void
    {
        $mgr = $this->user('manager');
        $marketing = $this->user('marketing');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc->assignEditor($cp, $marketing->id, $mgr);
    }

    /** @test */
    public function admin_can_be_chapter_editor_and_move_chapter(): void
    {
        $admin = $this->user('admin');
        $book = $this->bookWithOrder(1, 'editing'); // handler production
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->svc->assignEditor($cp, $admin->id, $admin);
        $this->assertSame($admin->id, $cp->fresh()->assigned_user_id);

        $this->svc->changeStatus($cp, 'layout', $admin);
        $this->assertSame('layout', $cp->fresh()->status);
    }
}
