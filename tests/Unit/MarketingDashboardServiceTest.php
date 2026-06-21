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

        // donut per-tahap = naskah aktif (kecuali menunggu_proses & final)
        $this->assertEqualsCanonicalizing(['Editing', 'Layout'], $d['per_stage']['labels']);
        $this->assertEquals($d['naskah_aktif'], array_sum($d['per_stage']['series']));
    }

    /** @test */
    public function jatuh_tempo_7_counts_today_through_7_days_inclusive_and_excludes_overdue(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->naskah($o, 'editing', ['target_date' => now()->toDateString()]);            // hari ini → masuk
        $this->naskah($o, 'editing', ['target_date' => now()->addDays(7)->toDateString()]); // batas atas → masuk
        $this->naskah($o, 'editing', ['target_date' => now()->addDays(8)->toDateString()]); // di luar 7 hari → tidak
        $this->naskah($o, 'editing', ['target_date' => now()->subDay()->toDateString()]);   // overdue → bukan jatuh tempo
        $this->naskah($o, 'terbit',  ['target_date' => now()->addDays(2)->toDateString()]);  // final → tidak

        $d = $this->svc->forUser($mkt);

        $this->assertEquals(2, $d['jatuh_tempo_7']);
        $this->assertEquals(1, $d['lewat_target']);  // the overdue one
    }

    /** @test */
    public function deadline_rows_are_scoped_flagged_and_sorted(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->naskah($o, 'editing',      ['target_date' => now()->subDays(3)->toDateString()]); // overdue
        $this->naskah($o, 'layout',       ['target_date' => now()->addDays(5)->toDateString()]); // d7 + month
        $this->naskah($o, 'proofreading', ['target_date' => now()->endOfMonth()->toDateString()]); // month
        $this->naskah($o, 'isbn',         ['target_date' => now()->addMonth()->toDateString()]);  // tidak ada flag
        $this->naskah($o, 'editing');                                                              // tanpa target → keluar
        $this->naskah($o, 'terbit',       ['target_date' => now()->addDay()->toDateString()]);     // final → keluar
        $this->naskah($this->orderFor($this->marketing()), 'editing', ['target_date' => now()->toDateString()]); // marketing lain → keluar

        $rows = $this->svc->deadlineRows($mkt);

        $this->assertCount(4, $rows);                               // scoping + exclusion
        $this->assertSame(1, $rows->first()['overdue']);           // overdue paling atas (sort)
        $this->assertSame(1, $rows->firstWhere('stage', 'Layout')['d7']);        // +5 hari selalu <= 7
        $this->assertSame(1, $rows->firstWhere('stage', 'Proofreading')['month']); // akhir bulan ini → selalu month
        $this->assertSame(0, $rows->firstWhere('stage', 'Isbn')['month']);       // +1 bulan → bukan bulan ini
        $this->assertSame(0, $rows->firstWhere('stage', 'Isbn')['d7']);
        $this->assertSame('Lewat 3 hari', $rows->first()['days_label']);
        // Catatan: flag 'month' untuk row 'Layout' (+5 hari) sengaja TIDAK di-assert —
        // dekat akhir bulan +5 hari bisa jatuh ke bulan depan (sensitif tanggal jalan test).
    }

    /** @test */
    public function exposes_new_kpis_and_delta_keys(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt, ['status' => 'pending']);
        OrderDetail::factory()->create(['order_id' => $o->id, 'cost_amount' => 1000000]);
        $this->paid($o, 400000, 'dp'); // approved 400rb → sisa piutang 600rb

        $d = $this->svc->forUser($mkt);

        $this->assertSame(600000, $d['total_piutang']);    // 1.000.000 - 400.000
        $this->assertSame(1000000, $d['rata_rata_order']); // 1 order, cost 1.000.000

        foreach ([
            'pemasukan_hari_ini_delta', 'pemasukan_minggu_ini_delta',
            'pemasukan_tahun_ini_delta', 'jumlah_order_delta',
        ] as $key) {
            $this->assertArrayHasKey($key, $d);
            $this->assertArrayHasKey('dir', $d[$key]);
        }

        $this->assertArrayHasKey('deadline_rows', $d);
        $this->assertCount(90, $d['income_trend']['series']); // tren diperpanjang ke 90 hari
    }

    /** @test */
    public function average_order_value_is_zero_without_orders(): void
    {
        $d = $this->svc->forUser($this->marketing());
        $this->assertSame(0, $d['rata_rata_order']);
        $this->assertSame(0, $d['total_piutang']);
    }
}
