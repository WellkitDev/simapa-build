<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawnExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function scope_active_menyembunyikan_baris_yang_ditarik(): void
    {
        $title  = Title::create(['title' => 'Judul Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Judul Uji', 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        $this->assertSame(1, TitleProgress::active()->count());

        $progress->update(['withdrawn_at' => now(), 'withdrawn_reason' => 'Refund penuh']);

        $this->assertSame(0, TitleProgress::active()->count());
        $this->assertTrue($progress->fresh()->isWithdrawn());
    }

    /**
     * Buku kolaborasi $jumlah bab: satu Title, satu order + satu progress per bab.
     *
     * @return array{0: Title, 1: \Illuminate\Support\Collection<int,TitleProgress>}
     */
    private function bukuKolaborasi(int $jumlah, string $status = 'editing'): array
    {
        $book = Title::create(['title' => 'Buku Kolaborasi', 'jenis' => 'buku',
                               'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $progresses = collect();
        for ($bab = 1; $bab <= $jumlah; $bab++) {
            $book->chapters()->create(['judul' => "Bab {$bab}", 'urutan' => $bab]);

            $order  = Order::factory()->create();
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => 'Buku Kolaborasi', 'title_id' => $book->id,
                'chapters' => $bab, 'cost_amount' => 1000000,
            ]);
            Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                             'amount' => 1000000, 'status' => 'paid', 'paid_at' => now()]);

            $progresses->push(TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'bidang' => 'buku', 'started_at' => now(),
            ]));
        }

        return [$book->fresh(), $progresses];
    }

    /** @test */
    public function baris_ditarik_tidak_menahan_bottleneck_judul(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(3, 'terbit');

        $progresses->first()->update(['status' => 'menunggu_proses']);
        $this->assertSame('menunggu_proses', $book->fresh()->manuscriptStatus());

        $progresses->first()->update(['withdrawn_at' => now()]);

        $this->assertSame('terbit', $book->fresh()->manuscriptStatus());
        $this->assertTrue($book->fresh()->manuscriptIsFinal());
    }

    /** @test */
    public function satu_refund_tidak_mematikan_arsip_judul(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(3, 'terbit');

        $ditarik = $progresses->first();
        Payment::create(['order_id' => $ditarik->orderDetail->order_id,
                         'payment_type' => 'refund', 'amount' => 1000000,
                         'status' => 'paid', 'paid_at' => now()]);
        $ditarik->orderDetail->order->update(['fulfillment_status' => 'ditarik']);

        $this->assertFalse($book->fresh()->isPaidOff(), 'sebelum ditandai, arsip mati');

        $ditarik->update(['withdrawn_at' => now()]);

        $this->assertTrue($book->fresh()->isPaidOff());
        $this->assertTrue($book->fresh()->archiveEligible());
    }

    /** @test */
    public function baris_ditarik_tidak_ikut_maju_saat_order_lain_dimajukan(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(2, 'proofreading');

        $ditarik = $progresses->first();
        $ditarik->update(['withdrawn_at' => now()]);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        app(TitleProgressService::class)->advance($progresses->last(), $sa->fresh());

        $this->assertSame('isbn', $progresses->last()->fresh()->status);
        $this->assertSame('proofreading', $ditarik->fresh()->status,
            'baris yang ditarik tidak boleh ikut terseret maju');
    }

    /**
     * Gerbang untuk scopeNotWithdrawn(): detail yang belum punya baris progress sama
     * sekali harus TETAP ikut terhitung. `whereDoesntHave` bernilai benar untuk relasi
     * kosong, jadi itu memang perilakunya — tapi kalau suatu saat scope-nya ditulis
     * ulang jadi `whereHas(... whereNull)`, order yang progressnya belum dibuat akan
     * lenyap diam-diam dari judulnya.
     *
     * @test
     */
    public function detail_tanpa_progress_tetap_terhitung(): void
    {
        $title = Title::create(['title' => 'Belum Ada Progress', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Belum Ada Progress', 'title_id' => $title->id,
        ]);

        $this->assertSame(1, $title->orderDetails()->notWithdrawn()->count());
    }
}
