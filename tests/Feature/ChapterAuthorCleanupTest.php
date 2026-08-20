<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pembatalan order meninggalkan DUA jejak yang tak pernah dibersihkan siapa pun.
 *
 * 1. Penulisnya tetap tercantum di babnya SELAMANYA. cancel() menghapus (soft)
 *    detail + progress + order, tapi tak pernah menyentuh tb_title_chapter_authors,
 *    dan ChapterAuthorService::remapFromOrders() sengaja MELEWATI bab yang ordernya
 *    sudah hilang — jadi ia bukan penyapu cadangan, ia tak akan pernah menyapunya.
 *
 * 2. Ordernya terbaca sebagai pekerjaan yang masih `berjalan`. cancel() menulis
 *    `status`, bukan `fulfillment_status`; dan karena progress-nya ikut soft-deleted,
 *    OrderFulfillmentService::syncFromProgress() tak akan pernah dipicu untuk order
 *    itu lagi. Backfill data lama hanya menambal baris lama, bukan pembatalan berikutnya.
 *
 * Semuanya menggerakkan layanan langsung (bukan route) supaya yang diuji murni kontrak
 * cancel()/restore(); otorisasi & alur HTTP-nya sudah dikunci OrderCancelTest.
 */
class ChapterAuthorCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        Queue::fake();
        Notification::fake();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /**
     * Satu bab buku kolaborasi yang hidup: penulisnya terpasang di bab DAN di ordernya.
     *
     * SENGAJA tanpa payment lunas: Order::isCancellable() menutup gerbang begitu ada
     * payment yang disetujui atau refund, dan test ini butuh ordernya benar-benar bisa
     * dibatalkan.
     *
     * @return array{0: Order, 1: TitleChapter, 2: Author}
     */
    private function babBerpenulis(string $tahapBuku = 'editing'): array
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

        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $tahapBuku,
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        return [$order->fresh(), $chapter->fresh(), $author];
    }

    private function batalkan(Order $order): void
    {
        app(OrderCancellationService::class)->cancel($order, 'Salah input', $this->superadmin());
    }

    private function pulihkan(int $orderId): void
    {
        app(OrderCancellationService::class)
            ->restore(Order::withTrashed()->findOrFail($orderId), $this->superadmin());
    }

    /** @test */
    public function order_dibatalkan_mencabut_penulis_dari_babnya(): void
    {
        [$order, $chapter] = $this->babBerpenulis();

        $this->assertSame(1, $chapter->authors()->count(), 'prasyarat: babnya berpenulis');

        $this->batalkan($order);

        $this->assertSame(0, $chapter->authors()->count(),
            'penulis order yang dibatalkan tidak boleh tetap tercantum di babnya');
    }

    /** @test */
    public function order_dibatalkan_juga_berhenti_sebagai_pekerjaan(): void
    {
        [$order] = $this->babBerpenulis();

        $this->batalkan($order);

        $this->assertSame('dibatalkan', Order::withTrashed()->find($order->id)->fulfillment_status,
            'order yang dibatalkan tidak boleh terbaca sebagai pekerjaan yang masih berjalan');
    }

    /** @test */
    public function pemulihan_order_mengembalikan_status_pekerjaan(): void
    {
        [$order] = $this->babBerpenulis();

        $this->batalkan($order);
        $this->pulihkan($order->id);

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
    }

    /** @test */
    public function pemulihan_order_memasang_penulisnya_kembali(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis();

        $this->batalkan($order);
        $this->assertSame(0, $chapter->authors()->count(), 'prasyarat: penulisnya sudah tercabut');

        $this->pulihkan($order->id);

        $this->assertSame(1, $chapter->authors()->count());
        $this->assertSame($author->id, $chapter->authors()->first()->id);
        $this->assertSame(1, (int) $chapter->authors()->first()->pivot->position);
        $this->assertNull($order->fresh()->details->titleProgress->withdrawal_snapshot,
            'snapshot dibuang setelah dipakai, supaya pembatalan berikutnya mengambil yang baru');
    }

    /**
     * Order artikel tak punya bab sama sekali, jadi lepasPenulisBab() harus pulang lebih
     * awal tanpa menyentuh apa pun — dan pulang lebih awal itu tidak boleh ikut
     * membatalkan penulisan `fulfillment_status`.
     *
     * Dikunci karena keduanya ditulis di jalur yang sama: kalau suatu saat pencabutan bab
     * dipindah ke depan `update()` dan meledak untuk order tanpa bab, order artikel yang
     * dibatalkan akan diam-diam tetap terbaca 'berjalan'.
     *
     * @test
     */
    public function pembatalan_order_artikel_tanpa_bab_tetap_aman(): void
    {
        $judul = 'Artikel ' . Str::random(6);
        $title = Title::create(['title' => $judul, 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $judul, 'title_id' => $title->id, 'cost_amount' => 500000,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        $this->batalkan($order->fresh());

        $batal = Order::withTrashed()->find($order->id);
        $this->assertSame('dibatalkan', $batal->fulfillment_status);
        $this->assertSame('dibatalkan', $batal->status);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detail->id]);
    }

    /**
     * Bahaya urutan di dalam restore(), kembaran persis dari yang sudah dikunci di
     * WithdrawalUndoTest: syncFromProgress() PULANG LEBIH AWAL untuk order `dibatalkan`,
     * jadi 'berjalan' wajib ditulis lebih dulu — lalu tahap naskahlah yang menentukan
     * jawaban sebenarnya.
     *
     * Buku yang naskahnya sudah `terbit` adalah kasus yang membuktikannya: kalau sync-nya
     * no-op (atau tak dipanggil sama sekali), ordernya berhenti di 'berjalan' padahal
     * naskahnya sudah final — hilang dari laporan selesai dan `completed_at` tak terisi.
     *
     * @test
     */
    public function pemulihan_order_yang_naskahnya_sudah_terbit_berakhir_selesai(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis('terbit');

        $this->batalkan($order);
        $this->pulihkan($order->id);

        $pulih = $order->fresh();
        $this->assertSame('selesai', $pulih->fulfillment_status,
            'naskahnya sudah terbit — pemulihan tidak boleh meninggalkannya di "berjalan"');
        $this->assertNotNull($pulih->completed_at);
        $this->assertSame($author->id, $chapter->authors()->first()?->id);
    }

    /**
     * Snapshot itu SATU kolom yang dipakai berulang kali. Kalau restore() lupa
     * mengosongkannya, pembatalan kedua menulis di atas snapshot lama — atau lebih buruk,
     * pemulihan kedua memasang susunan penulis versi lama.
     *
     * Susunan penulisnya sengaja DIUBAH di antara dua siklus (bab dapat penulis kedua):
     * hanya snapshot yang benar-benar diambil ulang yang bisa memulangkan keduanya.
     *
     * @test
     */
    public function batal_pulih_batal_lagi_memakai_snapshot_baru(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis();

        $this->batalkan($order);
        $this->pulihkan($order->id);

        // Bab menerima penulis kedua setelah order dipulihkan.
        $author2 = Author::create(['name' => 'Penulis Pendamping ' . Str::random(4)]);
        $chapter->authors()->attach($author2->id, ['position' => 2]);

        $this->batalkan($order->fresh());
        $this->assertSame(0, $chapter->authors()->count());

        $this->pulihkan($order->id);

        $kembali = $chapter->authors()->pluck('tb_authors.id')->all();
        $this->assertSame([$author->id, $author2->id], $kembali,
            'yang dipasang ulang harus susunan TERAKHIR, bukan snapshot siklus pertama');
    }

    /**
     * Celah yang ditemukan saat review Task 8: pasangPenulisBab() memakai sync(), yang
     * otoritatif. Selagi order dibatalkan, babnya terbaca tak bermilik dan bisa dijual
     * ulang; tanpa gerbang, pemulihan akan MENIMPA penulis pemilik baru dan meninggalkan
     * dua order hidup di atas satu bab.
     *
     * Menolak, bukan melewatkan diam-diam — cermin dari OrderWithdrawalService::undo().
     *
     * @test
     */
    public function pemulihan_ditolak_bila_bab_sudah_dipesan_order_lain(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis();
        $sa = $this->superadmin();

        app(OrderCancellationService::class)->cancel($order, 'Klien batal', $sa);

        // Bab 1 dijual ulang ke penulis lain selagi ordernya dibatalkan.
        $penerusAuthor = Author::create(['name' => 'Penulis Pengganti']);
        $penerus       = Order::factory()->create();
        $detailPenerus = OrderDetail::factory()->create([
            'order_id' => $penerus->id, 'type' => 'bk_kolab',
            'title' => $order->details()->withTrashed()->first()->title,
            'title_id' => $order->details()->withTrashed()->first()->title_id,
            'chapters' => 1, 'cost_amount' => 1000000,
        ]);
        $detailPenerus->authors()->attach($penerusAuthor->id, ['position' => 1]);
        $chapter->authors()->sync([$penerusAuthor->id => ['position' => 1]]);

        $this->expectException(\App\Exceptions\OrderCancellationException::class);

        try {
            app(OrderCancellationService::class)->restore($order->fresh(), $sa);
        } finally {
            $this->assertTrue(
                $chapter->authors()->where('tb_authors.id', $penerusAuthor->id)->exists(),
                'penulis pemilik baru tidak boleh tertimpa'
            );
            $this->assertFalse(
                $chapter->authors()->where('tb_authors.id', $author->id)->exists(),
                'penulis lama tidak boleh dipasang kembali'
            );
        }
    }
}
