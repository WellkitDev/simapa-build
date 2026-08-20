<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penarikan (refund penuh) sekarang MERUSAK: penulis dicabut dari babnya dan bab itu
 * dimundurkan ke 'menunggu'. Tanpa jalan pulang, satu salah klik menghapus data penulis
 * selamanya — test ini yang menjaga jalan pulangnya tetap ada dan tetap tepat.
 *
 * Penarikannya dibuat lewat layanan langsung (bukan route refund) supaya yang diuji
 * murni undo-nya; alur refund → penarikan sudah dikunci OrderWithdrawalTest.
 */
class WithdrawalUndoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        Queue::fake();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /**
     * Satu bab buku kolaborasi yang sudah ditarik: penulisnya tercabut, babnya kembali
     * 'menunggu', snapshotnya tersimpan.
     *
     * @return array{0: Order, 1: TitleProgress, 2: TitleChapter, 3: Author}
     */
    private function babDitarik(?User $pelaksana = null): array
    {
        $judul = 'Buku Kolaborasi ' . Str::random(6);
        $book  = Title::create(['title' => $judul, 'jenis' => 'buku',
                                'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $chapter = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $author  = Author::create(['name' => 'Penulis Bab 1 ' . Str::random(4)]);
        $chapter->authors()->attach($author->id, ['position' => 1]);
        $chapter->progress()->create([
            'status'            => 'editing',
            'started_at'        => now(),
            'pelaksana_user_id' => $pelaksana?->id,
        ]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $judul, 'title_id' => $book->id,
            'chapters' => 1, 'cost_amount' => 1000000,
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 1000000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund',
            'amount' => 1000000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Penulis mengundurkan diri',
        ]);

        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->user('superadmin'));

        return [$order->fresh(), $progress->fresh(), $chapter->fresh(), $author];
    }

    private function undo(Order $order, string $role = 'superadmin')
    {
        return $this->actingAs($this->user($role))
            ->post(route('order.refund.undo', $order->code_order));
    }

    /** @test */
    public function undo_memasang_kembali_penulis_dan_bab(): void
    {
        [$order, $progress, $chapter, $author] = $this->babDitarik();

        $this->assertSame(0, $chapter->authors()->count(), 'prasyarat: penulisnya sudah tercabut');

        $this->undo($order)->assertRedirect();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($progress->fresh()->withdrawn_at);
        $this->assertNull($progress->fresh()->withdrawal_snapshot);
        $this->assertSame(1, $chapter->authors()->count());
        $this->assertSame($author->id, $chapter->authors()->first()->id);
        $this->assertSame('editing', $chapter->progress->fresh()->status);

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $progress->id,
            'event'             => 'batal_penarikan',
            'from_value'        => 'Ditarik',
        ]);
    }

    /** @test */
    public function undo_ditolak_bila_bab_sudah_dipesan_order_lain(): void
    {
        [$order, $progress, $chapter] = $this->babDitarik();

        // Bab yang sudah bebas dijual ulang ke penulis lain — pemulihan akan menabraknya.
        $penerus = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $penerus->id, 'type' => 'bk_kolab',
            'title' => $order->details->title, 'title_id' => $order->details->title_id,
            'chapters' => 1, 'cost_amount' => 1000000,
        ]);

        $this->undo($order)->assertRedirect()->assertSessionHasErrors('undo');

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertNotNull($progress->fresh()->withdrawn_at);
        $this->assertSame(0, $chapter->authors()->count(), 'bab tetap milik pemesan barunya');
    }

    /**
     * Bukan 403 mentah: `order.refund` superadmin-only, dan EnforcePermission sengaja
     * menolak submit form (non-GET) lewat redirect + flash 'error', bukan halaman 403.
     * Yang dikunci di sini penolakannya, bukan kode statusnya.
     *
     * @test
     */
    public function selain_superadmin_tidak_boleh_undo(): void
    {
        [$order, $progress, $chapter] = $this->babDitarik();

        $this->undo($order, 'admin')->assertRedirect()->assertSessionHas('error');

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertNotNull($progress->fresh()->withdrawn_at);
        $this->assertSame(0, $chapter->authors()->count());
    }

    /**
     * Gerbang pertama undo().
     *
     * Tanpa gerbang ini, undo pada order biasa akan menimpa `fulfillment_status`-nya
     * dengan 'berjalan' — termasuk order yang sudah `selesai` atau `dibatalkan`, yang
     * berarti tombol ini bisa menghidupkan kembali order yang sengaja dimatikan orang.
     *
     * @test
     */
    public function undo_ditolak_bila_ordernya_tidak_sedang_ditarik(): void
    {
        $order = Order::factory()->create(['fulfillment_status' => 'berjalan']);
        OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'cost_amount' => 500000,
        ]);

        $this->undo($order)->assertRedirect()->assertSessionHasErrors('undo');

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
    }

    /**
     * Snapshot menyimpan DUA hal, dan yang kedua mudah terlupakan.
     *
     * Test pertama hanya membuktikan penulisnya kembali; kalau `pasangUlangBab()` hanya
     * menyentuh pivot penulis, pelaksana bab tetap null dan pekerjaan produksinya
     * berpindah tangan diam-diam meski penarikannya sudah dibatalkan.
     *
     * @test
     */
    public function undo_mengembalikan_pelaksana_bab_bukan_hanya_penulisnya(): void
    {
        $pelaksana = $this->user('production');
        [$order, , $chapter] = $this->babDitarik($pelaksana);

        $this->assertNull($chapter->progress->fresh()->pelaksana_user_id,
            'prasyarat: pelaksananya ikut dilepas saat ditarik');

        $this->undo($order)->assertRedirect();

        $this->assertSame($pelaksana->id, $chapter->progress->fresh()->pelaksana_user_id);
        $this->assertSame('editing', $chapter->progress->fresh()->status);
    }

    /**
     * Bahaya urutan di dalam undo(): OrderFulfillmentService::syncFromProgress() PULANG
     * LEBIH AWAL untuk order `ditarik`, jadi `ditarik` wajib dibersihkan lebih dulu.
     *
     * Artikel yang naskahnya sudah `publish` adalah kasus yang membuktikannya: kalau
     * sync-nya no-op, ordernya berhenti di 'berjalan' padahal naskahnya sudah final —
     * ordernya hilang dari laporan selesai dan `completed_at` tak pernah terisi.
     * Sekaligus mengunci jalur snapshot null (artikel tak punya bab).
     *
     * @test
     */
    public function undo_artikel_terbit_mengembalikan_status_selesai(): void
    {
        $judul = 'Artikel ' . Str::random(6);
        $title = Title::create(['title' => $judul, 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $judul, 'title_id' => $title->id, 'cost_amount' => 500000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'publish',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund',
            'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Salah input',
        ]);
        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->user('superadmin'));

        $this->assertNull($progress->fresh()->withdrawal_snapshot, 'artikel tak punya bab untuk dilepas');

        $this->undo($order->fresh())->assertRedirect();

        $this->assertNull($progress->fresh()->withdrawn_at);
        $this->assertSame('selesai', $order->fresh()->fulfillment_status,
            'naskahnya sudah publish — undo tidak boleh meninggalkannya di "berjalan"');
        $this->assertNotNull($order->fresh()->completed_at);
    }

    /**
     * Sama seperti babDitarik(), tapi naskahnya sudah di tahap `cetak` saat ditarik —
     * jadi lepasBab() tak mencabut apa pun dan `withdrawal_snapshot` tetap null.
     *
     * @return array{0: Order, 1: TitleProgress, 2: \App\Models\TitleChapter}
     */
    private function babDitarikSetelahIsbn(): array
    {
        $judul = 'Buku Kolaborasi ' . Str::random(6);
        $book  = Title::create(['title' => $judul, 'jenis' => 'buku',
                                'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $chapter = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $author  = Author::create(['name' => 'Penulis Bab 1 ' . Str::random(4)]);
        $chapter->authors()->attach($author->id, ['position' => 1]);
        $chapter->progress()->create(['status' => 'editing', 'started_at' => now()]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $judul, 'title_id' => $book->id,
            'chapters' => 1, 'cost_amount' => 1000000,
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 1000000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'cetak',
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund',
            'amount' => 1000000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Mundur terlambat',
        ]);

        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->user('superadmin'));

        return [$order->fresh(), $progress->fresh(), $chapter->fresh(), $book];
    }

    /**
     * Celah yang ditemukan saat review Task 7: gerbang penerus dulu digantungkan pada
     * `$snapshot !== null`. Buku kolaborasi yang ditarik pada/di atas tahap ISBN tak
     * pernah dicabut babnya, jadi snapshotnya null — padahal orderForChapter() menyaring
     * lewat `withdrawn_at`, bukan snapshot, sehingga babnya TETAP terbaca tak bermilik
     * dan bisa dipesan orang lain. Undo lalu menghidupkan order lama, dan dua order
     * hidup memiliki bab yang sama.
     *
     * @test
     */
    public function undo_tanpa_snapshot_tetap_ditolak_bila_bab_sudah_dipesan_lagi(): void
    {
        [$order, $progress, $chapter, $book] = $this->babDitarikSetelahIsbn();

        $this->assertNull($progress->withdrawal_snapshot, 'prasyarat: snapshot memang kosong');
        $this->assertNull($book->fresh()->orderForChapter(1), 'babnya terbaca tak bermilik');

        // Bab 1 dijual ulang ke penulis lain.
        $penerus = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $penerus->id, 'type' => 'bk_kolab',
            'title' => $book->title, 'title_id' => $book->id,
            'chapters' => 1, 'cost_amount' => 1000000,
        ]);

        $this->actingAs($this->user('superadmin'))
            ->post(route('order.refund.undo', $order->code_order))
            ->assertRedirect()
            ->assertSessionHasErrors('undo');

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status,
            'order lama tidak boleh hidup lagi di atas bab yang sudah punya pemilik baru');
    }
}
