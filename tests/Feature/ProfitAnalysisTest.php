<?php

namespace Tests\Feature;

use App\Models\CashMargin;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\ProfitAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tiap uang masuk dipecah pakai margin Asumsi: cadangan APC + siap dibagi.
 * Dua contoh user dikunci di sini — lihat spec §"Pertanyaan user, dijawab".
 */
class ProfitAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** Order + detail; $indexation null meniru 31 order lapangan yang kosong. */
    private function order(string $type, ?string $indexation, int $cost = 10_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => '2026-06-01',
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'indexation' => $indexation, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp', string $tgl = '2026-06-10'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => 'paid', 'paid_at' => $tgl,
        ]);
    }

    private function juni(): array
    {
        return app(ProfitAnalysisService::class)->forMonth(2026, 6);
    }

    /** @test */
    public function kolaborasi_dp_sinta2_matches_user_example(): void
    {
        // Contoh user: "orderan artikel kolaborasi dengan DP 1,5jt" → M_KOL_S2 25%.
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(375_000.0, $h['totalMargin'], 'Siap dibagi = 25% x 1,5jt.');
        $this->assertSame(1_125_000.0, $h['totalReserve'], 'Cadangan APC = sisanya.');
        $this->assertSame(1_500_000.0, $h['totalIn']);
    }

    /** @test */
    public function kolaborasi_dp_sinta4_uses_30_percent(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 4'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(450_000.0, $h['totalMargin']);
        $this->assertSame(1_050_000.0, $h['totalReserve']);
    }

    /** @test */
    public function mandiri_lunas_uses_assumption_not_actual(): void
    {
        // Contoh user memberi 2jt (APC aktual 5,5jt), tapi keputusannya: ASUMSI menang.
        $this->pay($this->order('at_mandiri', 'sinta 2', 7_500_000), 7_500_000, 'pelunasan');

        $h = $this->juni();

        $this->assertSame(1_875_000.0, $h['totalMargin'], 'Asumsi 25% x 7,5jt — bukan 2jt dari biaya aktual.');
    }

    /** @test */
    public function book_uses_87_percent(): void
    {
        $this->pay($this->order('bk_kolab', null, 100_000), 100_000);

        $this->assertSame(87_000.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function uppercase_indexation_is_normalised(): void
    {
        // Data lapangan punya "sinta 2" DAN "SINTA 2".
        $this->pay($this->order('at_kolab', 'SINTA 2'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(375_000.0, $h['totalMargin']);
        $this->assertSame(0, $h['unknownTier'], 'SINTA 2 harus dikenali, bukan dianggap tak diketahui.');
    }

    /** @test */
    public function sinta3_uses_s2_and_sinta5_uses_s4(): void
    {
        $this->pay($this->order('at_mandiri', 'sinta 3'), 1_000_000);
        $this->pay($this->order('at_mandiri', 'sinta 5'), 1_000_000);

        // 25% x 1jt + 30% x 1jt
        $this->assertSame(550_000.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function unknown_indexation_uses_lowest_margin_and_is_flagged(): void
    {
        $this->pay($this->order('at_kolab', null), 1_500_000);
        $this->pay($this->order('at_kolab', 'coppernicus'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(750_000.0, $h['totalMargin'], 'Keduanya pakai 25% (margin terendah).');
        $this->assertSame(2, $h['unknownTier'], 'Keduanya ditandai agar bisa dibenahi.');
    }

    /** @test */
    public function refund_reduces_margin(): void
    {
        $o = $this->order('at_kolab', 'sinta 2');
        $this->pay($o, 1_500_000);
        $this->pay($o, 300_000, 'refund');

        $h = $this->juni();

        $this->assertSame(300_000.0, $h['totalMargin'], '375rb - (25% x 300rb) = 300rb.');
        $this->assertSame(1_200_000.0, $h['totalIn'], 'Uang masuk bersih setelah refund.');
    }

    /** @test */
    public function missing_margin_row_yields_zero_and_flag(): void
    {
        CashMargin::where('code', 'M_BK_ALL')->update(['active' => false]);
        $this->pay($this->order('bk_kolab', null, 100_000), 100_000);

        $h = $this->juni();

        $this->assertSame(0.0, $h['totalMargin'], 'Margin belum diatur → nol, bukan tebakan.');
        $this->assertTrue($h['rows'][0]['marginMissing']);
    }

    /** @test */
    public function payment_without_order_detail_is_counted_separately(): void
    {
        // tb_payments.order_id NOT NULL → payment selalu punya order. Tapi order
        // bisa saja belum punya OrderDetail; tanpa detail tak ada jenis/indeksasi,
        // jadi tak ada margin yang bisa dipakai.
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $tanpaDetail = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => '2026-06-01',
        ]);
        $this->pay($tanpaDetail, 500_000);

        $h = $this->juni();

        $this->assertSame(1, $h['noOrder']);
        $this->assertSame(0.0, $h['totalMargin'], 'Tanpa detail → tak punya margin → tak dihitung.');
        $this->assertSame(500_000.0, $h['totalIn'], 'Uangnya tetap diakui masuk, hanya tak dibagi.');
    }

    /** @test */
    public function other_months_are_not_mixed(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-05-10');

        $this->assertSame(0.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function yearly_accumulates_12_months(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-05-10');
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-06-10');

        $tahun = app(ProfitAnalysisService::class)->yearly(2026);

        $this->assertCount(12, $tahun);
        $this->assertSame(375_000.0, $tahun[4]['totalMargin'], 'Mei (index 4).');
        $this->assertSame(375_000.0, $tahun[5]['totalMargin'], 'Juni (index 5).');
        $this->assertSame(0.0, $tahun[0]['totalMargin'], 'Januari kosong.');
        $this->assertSame(750_000.0, array_sum(array_column($tahun, 'totalMargin')), 'Akumulasi setahun.');
    }
}
