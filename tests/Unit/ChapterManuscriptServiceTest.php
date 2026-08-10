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
     * Buku kolaborasi seperti data nyata: satu order per author, dan
     * `order_details.chapters` menyimpan NOMOR BAB yang dikontribusikan order itu.
     *
     * @param array<int,array{0:int,1:string,2:string}> $orders [nomorBab, namaAuthor, naskahType]
     */
    private function bukuKolaborasi(array $orders, int $jumlahBab): Title
    {
        $book = Title::create(['title' => 'Kolab ' . fake()->unique()->word(), 'jenis' => 'buku',
                               'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        foreach ($orders as [$nomorBab, $nama, $naskahType]) {
            $detail = OrderDetail::factory()->create([
                'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id,
                'chapters' => $nomorBab, 'naskah_type' => $naskahType,
            ]);
            $detail->authors()->attach(\App\Models\Author::create(['name' => $nama])->id, ['position' => 1]);
        }

        for ($i = 1; $i <= $jumlahBab; $i++) {
            $bab = $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
            $bab->progress()->create(['status' => 'menunggu', 'started_at' => now()]);
        }

        return $book->fresh();
    }

    /**
     * BENTUK DATA NYATA (judul 45): 5 order, tiap order satu author dan satu nomor bab.
     * Order bernomor bab 4 kebetulan tercatat PALING AWAL — menebak lewat urutan daftar
     * author akan menaruh authornya di Bab 1. Sumber kebenarannya nomor bab di order.
     *
     * @test
     */
    public function author_bab_mengikuti_nomor_bab_di_order_bukan_urutan_daftar(): void
    {
        $book = $this->bukuKolaborasi([
            [4, 'Dr. Reza Ronal', 'mandiri'],      // tercatat pertama, tapi mengisi Bab 4
            [1, 'Emaya Kurniawati', 'dibuatkan'],
            [2, 'I Gusti Kade', 'dibuatkan'],
            [3, 'Rahmat Hidayat', 'dibuatkan'],
            [5, 'Ibnu Adham', 'dibuatkan'],
        ], jumlahBab: 5);

        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book);

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertSame('Emaya Kurniawati', $bab[0]->authors->first()->name);
        $this->assertSame('Dr. Reza Ronal', $bab[3]->authors->first()->name, 'Bab 4 milik order bernomor bab 4.');
        $this->assertSame('Ibnu Adham', $bab[4]->authors->first()->name);
    }

    /** @test */
    public function sumber_naskah_bab_diambil_dari_naskah_type_ordernya(): void
    {
        $book = $this->bukuKolaborasi([
            [1, 'Penulis Satu', 'dibuatkan'],
            [2, 'Penulis Dua', 'mandiri'],
        ], jumlahBab: 3);

        $bab = $book->chapters()->with('progress')->get()->sortBy('urutan')->values();

        $this->assertSame('dibuatkan', $bab[0]->progress->sumberNaskah());
        $this->assertSame('mandiri', $bab[1]->progress->sumberNaskah());
        $this->assertTrue($bab[1]->progress->naskahDariAuthor());
        // Bab 3 belum dipesan siapa pun → belum diketahui, JANGAN diasumsikan dibuatkan.
        $this->assertNull($bab[2]->progress->sumberNaskah());
    }

    /**
     * Inti keluhan owner: kalau bab bernaskah mandiri diperlakukan 'dibuatkan',
     * pelaksana akan menulis naskah yang sebenarnya sudah dikirim authornya.
     *
     * @test
     */
    public function bab_bernaskah_mandiri_tidak_bisa_ditugaskan_ke_pelaksana(): void
    {
        $book = $this->bukuKolaborasi([[1, 'Penulis Mandiri', 'mandiri']], jumlahBab: 1);
        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book);

        $cp    = $book->chapters()->with('progress')->first()->progress;
        $admin = $this->user('admin');
        $prod  = $this->user('production');

        try {
            app(\App\Services\AssignmentService::class)->distribute($cp, $prod->id, $admin);
            $this->fail('Bab bernaskah mandiri mestinya menolak pelaksana.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('bernaskah mandiri', collect($e->errors())->flatten()->first());
        }

        $this->assertNull($cp->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function bab_dibuatkan_tetap_bisa_ditugaskan(): void
    {
        $book = $this->bukuKolaborasi([[1, 'Penulis Ditulis Tim', 'dibuatkan']], jumlahBab: 1);
        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book);

        $cp   = $book->chapters()->with('progress')->first()->progress;
        $prod = $this->user('production');

        app(\App\Services\AssignmentService::class)->distribute($cp, $prod->id, $this->user('admin'));

        $this->assertSame($prod->id, $cp->fresh()->pelaksana_user_id);
        $this->assertSame('pembuatan', $cp->fresh()->status);
    }

    /** @test */
    /**
     * Pemetaan lama (menyalin seluruh author ke tiap bab, lalu menebak lewat urutan
     * daftar author) sama-sama tidak melihat nomor bab di ordernya. Penyelarasan
     * MENIMPA pemetaan yang bertentangan dengan order — memang itu tujuannya, karena
     * ordernya yang jadi sumber kebenaran, bukan tebakan sebelumnya.
     *
     * @test
     */
    public function penyelarasan_menimpa_pemetaan_yang_bertentangan_dengan_order(): void
    {
        $book = $this->bukuKolaborasi([
            [1, 'Author Bab Satu', 'dibuatkan'],
            [2, 'Author Bab Dua', 'dibuatkan'],
        ], jumlahBab: 2);

        // Tiru hasil penyemaian lama: kedua bab memuat KEDUA author.
        $semua = \App\Models\Author::pluck('id')->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all();
        $bab = $book->chapters()->get()->sortBy('urutan')->values();
        foreach ($bab as $b) { $b->authors()->sync($semua); }

        $svc = app(\App\Services\ChapterAuthorService::class);
        $this->assertSame(2, $svc->remapFromOrders($book));

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertSame('Author Bab Satu', $bab[0]->authors->first()->name);
        $this->assertSame('Author Bab Dua', $bab[1]->authors->first()->name);
        $this->assertCount(1, $bab[0]->authors);

        // Idempotent: dijalankan lagi tak mengubah apa pun.
        $this->assertSame(0, $svc->remapFromOrders($book->fresh()));
    }

    /** @test */
    public function bab_yang_belum_dipesan_dibiarkan_kosong_bukan_ditebak(): void
    {
        // Hanya bab 1 yang dipesan; bab 2 dan 3 belum terjual.
        $book = $this->bukuKolaborasi([[1, 'Satu-satunya', 'dibuatkan']], jumlahBab: 3);

        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book);

        $bab = $book->chapters()->with('authors')->get()->sortBy('urutan')->values();
        $this->assertCount(1, $bab[0]->authors);
        $this->assertCount(0, $bab[1]->authors, 'Bab tanpa order harus tetap kosong.');
        $this->assertCount(0, $bab[2]->authors);
    }

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
