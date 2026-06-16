<?php
// tests/Unit/MarketingDashboardServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\MarketingDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new MarketingDashboardService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function orderFor(User $u, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge(['user_id' => $u->id], $attrs));
    }

    private function paid(Order $order, int $amount, string $type = 'dp', $paidAt = null): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type,
            'amount' => $amount, 'paid_at' => $paidAt ?? now(), 'status' => 'paid',
        ]);
    }

    private function naskah(Order $order, string $status, array $progressAttrs = []): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => 'production', 'started_at' => now(),
        ], $progressAttrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function income_sums_paid_payments_including_dp_and_partial_scoped_to_marketing(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->paid($o, 1000000, 'dp');         // DP
        $this->paid($o, 2000000, 'pelunasan');  // pelunasan
        Payment::create(['order_id' => $o->id, 'payment_type' => 'dp', 'amount' => 500000, 'paid_at' => now(), 'status' => 'rejected']); // ditolak → tidak dihitung
        $this->paid($this->orderFor($this->marketing()), 9999999, 'dp'); // marketing lain → tidak dihitung

        $d = $this->svc->forUser($mkt);

        $this->assertEquals(3000000, $d['pemasukan_tahun_ini']); // DP + pelunasan
        $this->assertEquals(3000000, $d['pemasukan_hari_ini']);
        $this->assertEquals(1, $d['jumlah_order_tahun_ini']);
    }

    /** @test */
    public function progress_kpis_are_scoped_and_categorised(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->naskah($o, 'menunggu_proses');                                              // belum diproses
        $this->naskah($o, 'editing');                                                      // aktif
        $this->naskah($o, 'layout', ['target_date' => now()->subDay()->toDateString()]);   // aktif + lewat target
        $this->naskah($o, 'terbit', ['started_at' => now()]);                              // selesai bulan ini + total
        $this->naskah($o, 'publish', ['started_at' => now()->subMonths(2)]);               // total selesai (bukan bulan ini)
        $this->naskah($this->orderFor($this->marketing()), 'editing');                     // marketing lain → tidak dihitung

        $d = $this->svc->forUser($mkt);

        $this->assertEquals(1, $d['belum_diproses']);
        $this->assertEquals(2, $d['naskah_aktif']);       // editing + layout
        $this->assertEquals(1, $d['lewat_target']);
        $this->assertEquals(1, $d['selesai_bulan_ini']);  // terbit bulan ini
        $this->assertEquals(2, $d['total_selesai']);      // terbit + publish
    }
}
