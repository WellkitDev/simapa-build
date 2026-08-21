<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderLabelPembayaranTest extends TestCase
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

    private function order(int $biaya = 500000): Order
    {
        $order = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'cost_amount' => $biaya,
        ]);

        return $order->fresh();
    }

    private function bayar(Order $order, int $jumlah, string $tipe = 'dp'): void
    {
        Payment::create(['order_id' => $order->id, 'payment_type' => $tipe,
                         'amount' => $jumlah, 'status' => 'paid', 'paid_at' => now()]);
    }

    /** @test */
    public function tanpa_pembayaran_berarti_menunggu(): void
    {
        $this->assertSame('Menunggu', $this->order()->labelPembayaran());
    }

    /** @test */
    public function pembayaran_sebagian_berarti_dp(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);

        $this->assertSame('DP', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function pembayaran_penuh_berarti_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');

        $this->assertSame('Lunas', $order->fresh()->labelPembayaran());
    }

    /**
     * Jebakan yang harus ditahan: PaymentBookController::approve() mencap invoice
     * "lunas" untuk SETIAP payment disetujui termasuk DP, dan Order::isLunas()
     * mengambil jalan pintas itu. Kalau label ini memakainya, nilai DP tak akan
     * pernah muncul sama sekali.
     *
     * @test
     */
    public function dp_tetap_dp_walau_invoicenya_tercap_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);
        Invoice::create([
            'order_id' => $order->id, 'invoice_no' => 'INV-' . uniqid(), 'status' => 'lunas',
        ]);

        $this->assertTrue($order->fresh()->isLunas(), 'prasyarat: jalan pintas memang aktif');
        $this->assertSame('DP', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function refund_menang_atas_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');
        $this->bayar($order, 500000, 'refund');

        $this->assertSame('Refund', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function dibatalkan_menang_atas_semuanya(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');
        $this->bayar($order, 500000, 'refund');
        $order->update(['status' => 'dibatalkan']);

        $this->assertSame('Dibatalkan', $order->fresh()->labelPembayaran());
    }

    /** Payment yang ditolak/dibatalkan bukan uang masuk. */
    /** @test */
    public function payment_yang_tak_berstatus_paid_tidak_dihitung(): void
    {
        $order = $this->order(500000);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp',
                         'amount' => 200000, 'status' => 'rejected', 'paid_at' => now()]);

        $this->assertSame('Menunggu', $order->fresh()->labelPembayaran());
    }

    /** Relasi yang sudah dimuat dipakai, bukan query baru — daftar order memanggil ini per baris. */
    /** @test */
    public function memakai_relasi_yang_sudah_dimuat(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);

        $dimuat = Order::with(['payments', 'details'])->find($order->id);

        \DB::enableQueryLog();
        $label = $dimuat->labelPembayaran();
        $jumlah = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame('DP', $label);
        $this->assertSame(0, $jumlah, 'tak boleh menembak query baru bila relasinya sudah dimuat');
    }

    /** @test */
    public function daftar_order_menampilkan_kolom_pembayaran(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        $this->actingAs($sa->fresh())->get(route('order.book.index'))
            ->assertOk()
            // Judul kolomnya, bukan sekadar kata "Pembayaran" di mana pun — tombol aksi
            // di baris yang sama sudah memakai kata itu sebagai title, jadi assertSee
            // polos lolos bahkan bila headernya tak pernah diganti.
            ->assertSee('<th>Pembayaran</th>', false)
            ->assertDontSee('<th>Status Order</th>', false)
            ->assertSee('DP')
            ->assertDontSee('Diproses');
    }
}
