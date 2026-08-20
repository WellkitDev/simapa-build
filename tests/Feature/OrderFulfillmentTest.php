<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterManuscriptService;
use App\Services\FinancialReportService;
use App\Services\GoogleDriveService;
use App\Services\OrderFulfillmentService;
use App\Services\TitleProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u->fresh();
    }

    /** Artikel di tahap `loa` — satu langkah sebelum `publish`. */
    private function naskah(string $status = 'loa'): TitleProgress
    {
        $title  = Title::create(['title' => 'Artikel Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Artikel Uji', 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'artikel', 'started_at' => now(),
        ]);
    }

    /** @test */
    public function order_baru_berstatus_berjalan(): void
    {
        $order = Order::factory()->create();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($order->fresh()->completed_at);
    }

    /** @test */
    public function naskah_publish_membuat_ordernya_selesai(): void
    {
        $progress = $this->naskah('loa');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $order = $progress->orderDetail->order->fresh();
        $this->assertSame('publish', $progress->fresh()->status);
        $this->assertSame('selesai', $order->fulfillment_status);
        $this->assertNotNull($order->completed_at);
    }

    /** @test */
    public function koreksi_mundur_mengembalikan_order_ke_berjalan(): void
    {
        $progress = $this->naskah('publish');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);

        app(TitleProgressService::class)
            ->correct($progress, 'revisi', $this->superadmin(), 'Salah tandai');

        $order->refresh();
        $this->assertSame('berjalan', $order->fulfillment_status);
        $this->assertNull($order->completed_at);
    }

    /** @test */
    public function order_dibatalkan_tidak_ditimpa_jadi_selesai(): void
    {
        $progress = $this->naskah('loa');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'dibatalkan']);

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('dibatalkan', $order->fresh()->fulfillment_status);
    }

    /** Buku di tahap `cetak` — satu langkah sebelum `terbit`, jalur registrasi ISBN. */
    private function buku(string $status = 'cetak'): TitleProgress
    {
        $title  = Title::create(['title' => 'Buku Uji', 'jenis' => 'buku',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'Buku Uji', 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'buku', 'started_at' => now(),
        ]);
    }

    /** @test */
    public function registrasi_isbn_yang_menerbitkan_buku_menutup_ordernya(): void
    {
        $progress = $this->buku('cetak');
        $book     = $progress->orderDetail->titleRef;

        app(ChapterManuscriptService::class)->advanceBookToStage($book, 'terbit', $this->superadmin());

        $order = $progress->fresh()->orderDetail->order;
        $this->assertSame('terbit', $progress->fresh()->status);
        $this->assertSame('selesai', $order->fulfillment_status);
        $this->assertNotNull($order->completed_at);
    }

    /** @test */
    public function order_baru_pada_judul_yang_sudah_terbit_langsung_selesai(): void
    {
        $lama = $this->naskah('publish');

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Artikel Uji', 'title_id' => $lama->orderDetail->title_id,
        ]);

        $baru = app(TitleProgressService::class)->createForDetail($detail);

        $this->assertSame('publish', $baru->status);
        $this->assertSame('selesai', $order->fresh()->fulfillment_status);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    /** @test */
    public function completed_at_yang_kosong_disembuhkan_saat_sinkron_ulang(): void
    {
        $progress = $this->naskah('publish');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'selesai', 'completed_at' => null]);

        app(OrderFulfillmentService::class)->syncFromProgress($progress->fresh());

        $this->assertNotNull($order->fresh()->completed_at);
    }

    /** @test */
    public function sinkron_ulang_tidak_menggeser_tanggal_terbit_yang_sudah_tercatat(): void
    {
        $progress = $this->naskah('publish');
        $order    = $progress->orderDetail->order;
        $awal     = now()->subDays(30)->startOfSecond();
        $order->update(['fulfillment_status' => 'selesai', 'completed_at' => $awal]);

        app(OrderFulfillmentService::class)->syncFromProgress($progress->fresh());

        $this->assertTrue($awal->equalTo($order->fresh()->completed_at));
    }

    /** @test */
    public function completed_at_kembali_sebagai_carbon_bukan_string(): void
    {
        $progress = $this->naskah('loa');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertInstanceOf(Carbon::class, $progress->orderDetail->order->fresh()->completed_at);
    }

    /**
     * @test
     * Laporan lunas tak lagi MEMBACA completed_at. Sengaja tidak diberi nama "tanggal
     * lunas lepas dari tanggal terbit": kebocoran lewat updated_at masih ada — order
     * disimpan tepat saat naskah terbit — dan tes ini tidak memagarinya. Yang dipagari
     * cuma hilangnya cabang `completed_at ?? updated_at`.
     */
    public function tanggal_lunas_tidak_lagi_membaca_completed_at(): void
    {
        $progress = $this->naskah('loa');
        $order    = $progress->orderDetail->order;
        $order->update(['status' => 'lunas']);

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        // Naskah terbit sebulan lalu, ordernya baru disentuh hari ini. Tanpa jarak ini
        // completed_at dan updated_at ditulis pada save() yang sama dan tes tak bisa
        // membedakan keduanya — assertion-nya lulus semu.
        $order->fresh()->update(['completed_at' => now()->subMonth()]);

        $baris = app(FinancialReportService::class)->orderSelesai(null)['detail']->first();

        // Dibaca ulang lepas dari $baris: membandingkan $baris->updated_at dengan
        // $baris->tanggal_lunas berarti membandingkan objek dengan dirinya sendiri.
        $updatedAt = Order::findOrFail($order->id)->updated_at;

        $this->assertNotNull($baris);
        $this->assertInstanceOf(Carbon::class, $baris->tanggal_lunas);
        $this->assertTrue($updatedAt->equalTo($baris->tanggal_lunas));
        $this->assertFalse($baris->completed_at->equalTo($baris->tanggal_lunas));
    }
}
