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

    /**
     * BUG (2026-08-10): penyemaian lama menyalin SELURUH author order ke SETIAP bab,
     * sehingga kolom "bab ini naskah dari siapa" tak menjawab apa pun — di data dev
     * ada buku 10 bab yang tiap babnya memuat kesepuluh author. Kolaborasi berarti
     * satu penulis menyumbang babnya sendiri: pasangkan satu author per bab.
     *
     * @test
     */
    public function buku_kolaborasi_dipasangkan_satu_author_per_bab(): void
    {
        $book   = \App\Models\Title::create(['title' => 'Buku Kolaborasi', 'jenis' => 'buku',
                                             'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 3,
        ]);

        foreach (['Penulis A', 'Penulis B', 'Penulis C'] as $i => $nama) {
            $detail->authors()->attach(
                \App\Models\Author::create(['name' => $nama])->id,
                ['position' => $i + 1]
            );
        }

        app(\App\Services\ChapterManuscriptService::class)->ensureChapters($book);

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertCount(3, $bab);

        foreach (['Penulis A', 'Penulis B', 'Penulis C'] as $i => $nama) {
            $this->assertCount(1, $bab[$i]->authors, "Bab " . ($i + 1) . " harus punya TEPAT satu author.");
            $this->assertSame($nama, $bab[$i]->authors->first()->name);
        }
    }

    /** @test */
    public function bab_yang_tak_kebagian_author_dibiarkan_kosong_bukan_ditebak(): void
    {
        $book   = \App\Models\Title::create(['title' => 'Kolab Kurang Author', 'jenis' => 'buku',
                                             'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 4,
        ]);
        $detail->authors()->attach(\App\Models\Author::create(['name' => 'Satu-satunya'])->id, ['position' => 1]);

        app(\App\Services\ChapterManuscriptService::class)->ensureChapters($book);

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertCount(1, $bab[0]->authors);
        foreach ([1, 2, 3] as $i) {
            $this->assertCount(0, $bab[$i]->authors,
                'Bab tanpa author harus tetap kosong supaya ditandai kuning dan dipetakan manusia.');
        }
    }

    /** @test */
    public function pemetaan_author_lama_yang_menumpuk_bisa_diperbaiki(): void
    {
        $book   = \App\Models\Title::create(['title' => 'Kolab Rusak', 'jenis' => 'buku',
                                             'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 3,
        ]);

        $ids = [];
        foreach (['A', 'B', 'C'] as $i => $nama) {
            $ids[] = $id = \App\Models\Author::create(['name' => 'Penulis ' . $nama])->id;
            $detail->authors()->attach($id, ['position' => $i + 1]);
        }

        // Bentuk data rusak: ketiga bab memuat ketiga author.
        $pivot = collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all();
        foreach ([1, 2, 3] as $n) {
            $book->chapters()->create(['judul' => 'Bab ' . $n, 'urutan' => $n])->authors()->sync($pivot);
        }

        $svc = app(\App\Services\ChapterAuthorService::class);
        $this->assertTrue($svc->repairCollaborativeMapping($book));

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        foreach ([0, 1, 2] as $i) {
            $this->assertCount(1, $bab[$i]->authors);
            $this->assertSame($ids[$i], $bab[$i]->authors->first()->id);
        }

        // Idempotent: data yang sudah benar tidak disentuh lagi.
        $this->assertFalse($svc->repairCollaborativeMapping($book->fresh()));
    }

    /** @test */
    public function pemetaan_manual_yang_berbeda_antar_bab_tidak_ditimpa_perbaikan(): void
    {
        $book   = \App\Models\Title::create(['title' => 'Kolab Manual', 'jenis' => 'buku',
                                             'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 2,
        ]);
        $a = \App\Models\Author::create(['name' => 'Penulis A'])->id;
        $b = \App\Models\Author::create(['name' => 'Penulis B'])->id;
        $detail->authors()->attach($a, ['position' => 1]);
        $detail->authors()->attach($b, ['position' => 2]);

        // Sengaja dibalik oleh manusia: Bab 1 → B, Bab 2 → A.
        $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1])->authors()->sync([$b => ['position' => 1]]);
        $book->chapters()->create(['judul' => 'Bab 2', 'urutan' => 2])->authors()->sync([$a => ['position' => 1]]);

        $this->assertFalse(app(\App\Services\ChapterAuthorService::class)->repairCollaborativeMapping($book));

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertSame($b, $bab[0]->authors->first()->id);
        $this->assertSame($a, $bab[1]->authors->first()->id);
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
