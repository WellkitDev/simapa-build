<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A3 + A4 — `tb_orders.status` adalah keadaan UANG, dan ia tak boleh berbohong.
 *
 * Hari ini ia bisa: bilang "lunas" untuk uang yang belum diverifikasi siapa pun,
 * bilang "lunas" untuk DP yang tipenya salah ketik, dan tak berubah saat harganya
 * berubah. Pembacanya nyata dan banyak — Piutang, laporan Order Lunas, gerbang
 * kelayakan arsip, gerbang pembatalan, target komisi marketing.
 *
 * Jawabannya satu fungsi: status uang = fungsi murni dari pembayaran DISETUJUI
 * dibanding cost_amount. Tak ada controller yang menulis 'lunas' harfiah.
 */
class OrderStatusUangTest extends TestCase
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

    private function order(int $biaya = 1000000): Order
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-UANG-' . fake()->unique()->numerify('####'),
            'user_id' => $u->id, 'status' => 'pending', 'ordered_at' => today()->toDateString(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku Uang',
            'slug' => 'buku-uang-' . $order->id, 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => $biaya,
        ]);
        // store() menyusun nama berkas bukti dari contact->cp_email; tanpa kontak
        // request-nya 500 sebelum sempat menyentuh logika uang.
        OrderContact::create([
            'order_id' => $order->id, 'cp_email' => 'klien@example.com', 'cp_phone' => '0812',
        ]);

        return $order->fresh();
    }

    private function bayar(Order $order, int $nominal, string $tipe, string $approval = 'approved'): Payment
    {
        $p = Payment::create([
            'order_id' => $order->id, 'amount' => $nominal, 'payment_type' => $tipe,
            'status' => $approval === 'approved' ? 'paid' : 'pending',
            'paid_at' => now(),
        ]);
        PaymentApproval::create(['payment_id' => $p->id, 'status' => $approval]);

        return $p;
    }

    // ─── A3: uang yang sah ───

    /** @test */
    public function status_lunas_dihitung_dari_nominal_bukan_dari_tipe_pembayaran(): void
    {
        $order = $this->order(5000000);

        // DP Rp 500.000 yang tipenya SALAH KETIK jadi "lunas" atas order Rp 5.000.000.
        $this->bayar($order, 500000, 'lunas');
        $order->recalcStatus();

        $this->assertSame('pending', $order->fresh()->status,
            'Tipe pembayaran tak boleh melunasi order; yang menentukan nominalnya.');
    }

    /** @test */
    public function pembayaran_yang_belum_disetujui_tidak_melunasi(): void
    {
        $order = $this->order(1000000);
        $this->bayar($order, 1000000, 'lunas', 'pending');
        $order->recalcStatus();

        $this->assertSame('pending', $order->fresh()->status,
            'Uang yang belum diverifikasi siapa pun tak boleh menggerakkan laporan.');
    }

    /** @test */
    public function pembayaran_disetujui_yang_cukup_melunasi(): void
    {
        $order = $this->order(1000000);
        $this->bayar($order, 1000000, 'lunas');
        $order->recalcStatus();

        $this->assertSame('lunas', $order->fresh()->status);
    }

    /** @test */
    public function order_dibatalkan_tak_pernah_dihitung_ulang(): void
    {
        $order = $this->order(1000000);
        $order->update(['status' => 'dibatalkan']);
        $this->bayar($order, 1000000, 'lunas');
        $order->recalcStatus();

        $this->assertSame('dibatalkan', $order->fresh()->status);
    }

    /**
     * reject() menyapu buta ke 'pending'. Order yang sudah benar-benar lunas dari
     * pembayaran LAIN ikut jatuh hanya karena satu pembayaran tambahan ditolak.
     *
     * @test
     */
    public function menolak_satu_pembayaran_tak_menjatuhkan_order_yang_lunas_dari_pembayaran_lain(): void
    {
        $order = $this->order(1000000);
        $this->bayar($order, 1000000, 'lunas');           // sudah melunasi
        $lebih = $this->bayar($order, 250000, 'dp');      // pembayaran tambahan

        $this->actingAs($this->super())->post(route('payment.reject', $lebih->id));

        $this->assertSame('lunas', $order->fresh()->status,
            'Menolak pembayaran tambahan tak boleh membatalkan pelunasan yang sah.');
    }

    /**
     * Jaminan inti A3, diuji lewat jalur yang benar-benar dipakai orang.
     *
     * Sebelumnya store() menulis 'paid' langsung, sehingga scopeIncome() — yang
     * menyaring status 'paid' — sudah menghitung uang itu sebagai pemasukan sebelum
     * siapa pun memverifikasinya, dan approve() cuma menulis 'paid' lagi.
     *
     * @test
     */
    public function pembayaran_dari_formulir_lahir_pending_dan_baru_jadi_pemasukan_setelah_disetujui(): void
    {
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-uji');
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'x', 'url' => 'https://drive/x']);
        });

        $order = $this->order(1000000);

        $this->actingAs($this->super())->post(route('payment.store', $order->code_order), [
            'issued_at'  => now()->toDateString(),
            'dued_at'    => now()->addDays(14)->toDateString(),
            'status'     => 'lunas',                 // tipe "lunas", nominal penuh
            'pay_amount' => 1000000,
            'proof_url'  => UploadedFile::fake()->image('struk.jpg'),
        ])->assertRedirect();

        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('pending', $payment->status, 'Pembayaran baru belum diverifikasi siapa pun.');
        $this->assertSame(0, $order->fresh()->paidNet(), 'Uang yang belum disetujui bukan pemasukan.');
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($this->super())->post(route('payment.approve', $payment->id));

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1000000, $order->fresh()->paidNet());
        $this->assertSame('lunas', $order->fresh()->status, 'Barulah sekarang ordernya lunas.');
    }

    // ─── A4: harga berubah ───

    /** @test */
    public function menaikkan_biaya_membuat_order_lunas_jatuh_ke_pending_dan_muncul_di_piutang(): void
    {
        $order = $this->order(1000000);
        $this->bayar($order, 1000000, 'lunas');
        $order->recalcStatus();
        $this->assertSame('lunas', $order->fresh()->status);

        $order->details()->update(['cost_amount' => 2000000]);
        $order->fresh()->recalcStatus();

        $this->assertSame('pending', $order->fresh()->status);

        // Diperiksa di LAPORANNYA, bukan cuma di kolomnya: kolom benar tapi laporan
        // tak berubah adalah kegagalan yang pernah terjadi di repo ini.
        $piutang = app(\App\Services\FinancialReportService::class)->piutang(null);
        $ids = collect($piutang['detail'])->pluck('id');
        $this->assertTrue($ids->contains($order->id),
            'Order yang kurang bayar setelah biaya naik harus muncul di Piutang.');
    }

    /** @test */
    public function menurunkan_biaya_membuat_order_pending_jadi_lunas(): void
    {
        $order = $this->order(2000000);
        $this->bayar($order, 1000000, 'dp');
        $order->recalcStatus();
        $this->assertSame('pending', $order->fresh()->status);

        $order->details()->update(['cost_amount' => 800000]);
        $order->fresh()->recalcStatus();

        $this->assertSame('lunas', $order->fresh()->status);
    }

    private function super(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }
}
