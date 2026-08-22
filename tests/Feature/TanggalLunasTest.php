<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kolom "Tanggal Lunas" harus tanggal UANG, bukan jejak penyimpanan baris.
 *
 * Sebelumnya `$o->tanggal_lunas = $o->updated_at`. OrderFulfillmentService menyimpan
 * ordernya tepat saat naskah mencapai publish/terbit, jadi `updated_at` tercap momen
 * TERBIT — dan `orderByDesc('updated_at')` menaikkan order yang baru terbit ke puncak
 * laporan uang. Tanggal pekerjaan menyamar jadi tanggal pelunasan.
 */
class TanggalLunasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function tanggal_lunas_diambil_dari_pembayaran_bukan_dari_updated_at(): void
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');

        $order = Order::create([
            'code_order' => 'ORD-TGL-1', 'user_id' => $u->id,
            'status' => 'pending', 'ordered_at' => '2026-01-01',
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku Tgl',
            'slug' => 'buku-tgl', 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $p = Payment::create([
            'order_id' => $order->id, 'amount' => 1000000, 'payment_type' => 'lunas',
            'status' => 'paid', 'paid_at' => '2026-03-10 08:00:00',
        ]);
        PaymentApproval::create(['payment_id' => $p->id, 'status' => 'approved']);
        $order->recalcStatus();

        // Meniru OrderFulfillmentService: naskah terbit jauh SESUDAH pelunasan, dan
        // penyimpanannya mencap updated_at hari ini.
        $order->fresh()->touch();

        $baris = collect(app(FinancialReportService::class)->orderSelesai(null)['detail'])
            ->firstWhere('id', $order->id);

        $this->assertNotNull($baris);
        $this->assertSame('2026-03-10', $baris->tanggal_lunas?->format('Y-m-d'),
            'Tanggal Lunas harus paid_at pembayaran yang melunasi, bukan updated_at.');
    }

    /** @test */
    public function urutan_laporan_ikut_tanggal_uang(): void
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');

        // Dibuat terbalik: yang lunas duluan dibuat belakangan, dan yang lunas
        // belakangan disentuh terakhir sehingga updated_at-nya paling baru.
        $lama = $this->orderLunas($u, 'ORD-TGL-LAMA', '2026-02-01');
        $baru = $this->orderLunas($u, 'ORD-TGL-BARU', '2026-06-01');
        $lama->fresh()->touch();

        $urutan = collect(app(FinancialReportService::class)->orderSelesai(null)['detail'])
            ->pluck('code_order')->values()->all();

        $this->assertSame(['ORD-TGL-BARU', 'ORD-TGL-LAMA'], $urutan,
            'Laporan uang diurutkan dari tanggal uang, bukan dari kapan barisnya terakhir disimpan.');
    }

    private function orderLunas(User $u, string $kode, string $tglBayar): Order
    {
        $order = Order::create([
            'code_order' => $kode, 'user_id' => $u->id,
            'status' => 'pending', 'ordered_at' => '2026-01-01',
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku ' . $kode,
            'slug' => strtolower($kode), 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 500000,
        ]);
        $p = Payment::create([
            'order_id' => $order->id, 'amount' => 500000, 'payment_type' => 'lunas',
            'status' => 'paid', 'paid_at' => $tglBayar . ' 09:00:00',
        ]);
        PaymentApproval::create(['payment_id' => $p->id, 'status' => 'approved']);
        $order->recalcStatus();

        return $order->fresh();
    }
}
