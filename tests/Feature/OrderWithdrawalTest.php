<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Refund PENUH menarik ordernya dari judul; refund SEBAGIAN tidak menarik apa pun.
 *
 * Semua test menembak route refund sungguhan, bukan layanannya langsung: yang perlu
 * dikunci adalah bahwa penarikan benar-benar terjadi saat superadmin menekan tombol,
 * bukan sekadar bahwa layanannya bekerja bila dipanggil.
 */
class OrderWithdrawalTest extends TestCase
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /** Kirim form refund sebagai superadmin. */
    private function refund(Order $order, int $amount, string $alasan = 'Penulis mengundurkan diri')
    {
        return $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount'  => $amount,
                'reason'  => $alasan,
                'method'  => 'transfer',
                'tanggal' => '2026-06-05',
            ]);
    }

    /** Satu artikel mandiri: judul + order + pembayaran lunas 500rb + progress. */
    private function orderArtikel(string $status = 'editing'): TitleProgress
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

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'artikel', 'started_at' => now(),
        ]);
    }

    /**
     * Buku kolaborasi 3 bab: satu order = satu author = satu bab (order_details.chapters
     * menyimpan NOMOR babnya), masing-masing lunas 1jt dan punya ChapterProgress.
     *
     * @return array{0: Title, 1: \Illuminate\Support\Collection<int,TitleProgress>}
     */
    private function bukuKolaborasi(string $status = 'editing'): array
    {
        $judul = 'Buku Kolaborasi ' . Str::random(6);
        $book  = Title::create(['title' => $judul, 'jenis' => 'buku',
                                'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $progresses = collect();
        for ($bab = 1; $bab <= 3; $bab++) {
            $chapter = $book->chapters()->create(['judul' => "Bab {$bab}", 'urutan' => $bab]);
            $author  = Author::create(['name' => "Penulis Bab {$bab} " . Str::random(4)]);
            $chapter->authors()->attach($author->id, ['position' => 1]);

            $order  = Order::factory()->create();
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => $judul, 'title_id' => $book->id,
                'chapters' => $bab, 'cost_amount' => 1000000,
            ]);
            $detail->authors()->attach($author->id, ['position' => 1]);

            Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                             'amount' => 1000000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

            $progresses->push(TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'bidang' => 'buku', 'started_at' => now(),
            ]));

            $chapter->progress()->create(['status' => 'editing', 'started_at' => now()]);
        }

        return [$book->fresh(), $progresses];
    }

    /** Order pemesan bab ke-$urutan pada koleksi progress dari bukuKolaborasi(). */
    private function orderBab($progresses, int $urutan): Order
    {
        return $progresses->get($urutan - 1)->orderDetail->order;
    }

    /** @test */
    public function refund_penuh_menarik_ordernya(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->refund($order, 500000, 'Penulis batal terbit')->assertRedirect();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertNotNull($progress->fresh()->withdrawn_at);
        $this->assertSame('Penulis batal terbit', $progress->fresh()->withdrawn_reason);
    }

    /** @test */
    public function refund_sebagian_tidak_menarik_apa_pun(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->refund($order, 200000, 'Potongan harga')->assertRedirect();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($progress->fresh()->withdrawn_at);
    }

    /** @test */
    public function penarikan_tercatat_di_riwayat_naskah(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->refund($order, 500000)->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $progress->id,
            'event'             => 'penarikan',
            'to_value'          => 'Ditarik',
        ]);
    }

    /** @test */
    public function refund_sebelum_isbn_mencabut_bab_dan_penulisnya(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('editing');

        $this->refund($this->orderBab($progresses, 1), 1000000)->assertRedirect();

        $bab1 = $book->chapters()->where('urutan', 1)->first();
        $bab2 = $book->chapters()->where('urutan', 2)->first();

        $this->assertSame(0, $bab1->authors()->count());
        $this->assertSame('menunggu', $bab1->progress->fresh()->status);
        $this->assertSame(1, $bab2->authors()->count(), 'penulis bab lain tidak boleh tersentuh');
        $this->assertSame('editing', $bab2->progress->fresh()->status);
    }

    /** @test */
    public function refund_setelah_isbn_hanya_mencatat_tanpa_mencabut(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('cetak');
        $order = $this->orderBab($progresses, 1);

        $this->refund($order, 1000000)->assertRedirect();

        $bab1 = $book->chapters()->where('urutan', 1)->first();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertSame(1, $bab1->authors()->count(), 'buku sudah beredar — karyanya tak ditarik');
        $this->assertNull($progresses->first()->fresh()->withdrawal_snapshot);
    }

    /** @test */
    public function artikel_ditarik_tanpa_menyentuh_bab(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->refund($order, 500000)->assertRedirect();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertSame(0, $progress->orderDetail->titleRef->chapters()->count());
        $this->assertNull($progress->fresh()->withdrawal_snapshot);
    }

    /**
     * Inti seluruh rancangan: satu penulis mundur, sembilan belas lainnya jalan terus.
     * Dikunci lewat route sungguhan supaya rangkaian refund → penarikan → pengecualian
     * dari manuscriptStatus()/isPaidOff() terbukti utuh, bukan per potong.
     *
     * @test
     */
    public function order_lain_sejudul_tidak_terpengaruh_penarikan(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('editing');

        $this->refund($this->orderBab($progresses, 1), 1000000)->assertRedirect();

        // Dua penulis sisanya menuntaskan bukunya sampai terbit.
        $progresses->slice(1)->each(fn (TitleProgress $p) => $p->update(['status' => 'terbit']));

        $segar = $book->fresh();

        $this->assertSame('terbit', $segar->manuscriptStatus(),
            'baris yang ditarik tidak boleh lagi jadi bottleneck judul');
        $this->assertTrue($segar->isPaidOff(),
            'order yang uangnya dikembalikan tidak boleh menuntut pelunasan');
        $this->assertTrue($segar->archiveEligible());

        // Order penulis lain tetap berjalan apa adanya.
        $this->assertSame('berjalan', $this->orderBab($progresses, 2)->fresh()->fulfillment_status);
        $this->assertSame('berjalan', $this->orderBab($progresses, 3)->fresh()->fulfillment_status);
    }

    /**
     * Batas pencabutan tepat di 'isbn': proofreading (persis sebelumnya) MASIH mencabut,
     * isbn sendiri TIDAK. Mengunci `<` — `<=` atau indeks meleset satu akan mencabut bab
     * dari buku yang nomor ISBN-nya sudah terdaftar.
     *
     * @test
     */
    public function batas_pencabutan_persis_di_tahap_isbn(): void
    {
        [$bukuProof, $proofProgresses] = $this->bukuKolaborasi('proofreading');
        $this->refund($this->orderBab($proofProgresses, 1), 1000000)->assertRedirect();

        [$bukuIsbn, $isbnProgresses] = $this->bukuKolaborasi('isbn');
        $this->refund($this->orderBab($isbnProgresses, 1), 1000000)->assertRedirect();

        $this->assertSame(0, $bukuProof->chapters()->where('urutan', 1)->first()->authors()->count(),
            'proofreading masih di bawah batas — bab dicabut');
        $this->assertSame(1, $bukuIsbn->chapters()->where('urutan', 1)->first()->authors()->count(),
            'isbn sudah mencapai batas — bab dipertahankan');
    }

    /**
     * Order tanpa baris progress (dibuat sebelum naskahnya dijadwalkan) tetap ditandai
     * ditarik, tidak fatal karena null.
     *
     * @test
     */
    public function order_tanpa_progress_tetap_ditandai_ditarik(): void
    {
        $order = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'cost_amount' => 500000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $this->refund($order, 500000)->assertRedirect();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
    }

    /**
     * Pencabutan bab harus BERTAHAN, bukan sekadar terjadi sesaat.
     *
     * ChapterAuthorService::seedFromOrders() dipanggil TitleController::show() setiap
     * kali halaman judul dibuka, dan ia mengisi bab yang kosong dari
     * Title::orderForChapter(). Selama resolver itu masih menunjuk order yang ditarik,
     * penulis yang baru dicabut akan terpasang lagi begitu ada yang membuka halamannya —
     * pencabutannya jadi hiasan. Ini yang menahannya.
     *
     * @test
     */
    public function penulis_yang_dicabut_tidak_dipasang_ulang_saat_judul_dibuka(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('editing');
        $order = $progresses->first()->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 1000000, 'reason' => 'Penulis mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $bab1 = $book->chapters()->where('urutan', 1)->first();
        $this->assertSame(0, $bab1->authors()->count(), 'prasyarat: sudah tercabut');

        // Persis yang dilakukan TitleController::show().
        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book->fresh());

        $this->assertSame(0, $bab1->authors()->count(),
            'bab yang penulisnya mundur tidak boleh diisi ulang dari order yang ditarik');
        $this->assertNull($book->fresh()->orderForChapter(1),
            'bab itu kini tak dimiliki order mana pun — siap dijual ulang');
    }

    /**
     * Cermin test di atas: bab milik order yang MASIH hidup tetap harus terisi otomatis.
     * Tanpa ini, saringan withdrawn di orderForChapter() bisa saja terlalu rakus dan
     * mengosongkan seluruh buku tanpa ketahuan.
     *
     * @test
     */
    public function bab_milik_order_hidup_tetap_terisi_otomatis(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('editing');
        $order = $progresses->first()->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 1000000, 'reason' => 'Penulis mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $bab2 = $book->chapters()->where('urutan', 2)->first();
        $bab2->authors()->detach();

        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book->fresh());

        $this->assertSame(1, $bab2->authors()->count(),
            'bab yang ordernya masih hidup harus tetap dipetakan ulang');
    }

    /** @test */
    public function daftar_order_menampilkan_lencana_pekerjaan(): void
    {
        $progress = $this->orderArtikel('publish');
        $progress->orderDetail->order->update([
            'fulfillment_status' => 'selesai', 'completed_at' => now(),
        ]);

        $this->actingAs($this->superadmin())->get(route('order.book.index'))
            ->assertOk()
            ->assertSee('Pekerjaan')
            ->assertSee('Selesai');
    }

    /**
     * Tombol Batalkan Penarikan adalah SATU-SATUNYA jalan pulang dari refund yang salah
     * klik — tanpa UI, `order.refund.undo` cuma route yang tak pernah tersentuh manusia.
     * Muncul hanya untuk order yang benar-benar ditarik; refund sebagian tidak menarik
     * apa pun, jadi tombolnya tak boleh ikut muncul di sana.
     *
     * @test
     */
    public function order_ditarik_punya_tombol_batalkan_penarikan(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500000, 'reason' => 'Klien mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->actingAs($this->superadmin())->get(route('order.book.index'))
            ->assertOk()
            ->assertSee('Ditarik')
            ->assertSee(route('order.refund.undo', $order->code_order));
    }

    /** @test */
    public function refund_sebagian_tidak_memunculkan_tombol_batalkan_penarikan(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 200000, 'reason' => 'Potongan harga',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->actingAs($this->superadmin())->get(route('order.book.index'))
            ->assertOk()
            ->assertDontSee(route('order.refund.undo', $order->code_order));
    }

    /**
     * Naskah yang ditarik hilang dari papan lewat scopeActive(). Tanpa tab sendiri di
     * Arsip Naskah ia juga tak masuk tab Selesai (archived_at biasanya masih kosong)
     * maupun tab Batal (cancelled_at tak pernah diisi jalur refund) — lenyap sama sekali
     * dari UI. Ini yang menahannya.
     *
     * @test
     */
    public function naskah_ditarik_muncul_di_tab_arsip_sendiri(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500000, 'reason' => 'Klien mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $sa = $this->superadmin();

        $this->actingAs($sa)->get(route('naskah.arsip', ['hanya' => 'ditarik']))
            ->assertOk()
            ->assertSee('Ditarik — Refund', false)
            ->assertSee('Klien mundur');

        // Dan TIDAK bocor ke tab Selesai.
        $this->actingAs($sa)->get(route('naskah.arsip'))
            ->assertOk()
            ->assertDontSee($order->code_order);
    }

    /**
     * Naskah yang sudah terbit LALU di-refund punya archived_at DAN withdrawn_at —
     * tanpa saringan ia akan tampil di dua tab sekaligus.
     *
     * @test
     */
    public function naskah_terbit_yang_ditarik_tidak_bocor_ke_tab_selesai(): void
    {
        $progress = $this->orderArtikel('publish');
        $progress->update(['archived_at' => now()]);
        $order = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500000, 'reason' => 'Terbit lalu mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $sa = $this->superadmin();

        $this->actingAs($sa)->get(route('naskah.arsip'))
            ->assertOk()
            ->assertDontSee($order->code_order);

        $this->actingAs($sa)->get(route('naskah.arsip', ['hanya' => 'ditarik']))
            ->assertOk()
            ->assertSee($order->code_order);
    }
}
