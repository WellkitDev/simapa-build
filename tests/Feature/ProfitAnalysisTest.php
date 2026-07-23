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

    private function manualIncome(int $amount, ?string $produk, string $tgl = '2026-06-12', ?int $catId = null): \App\Models\CashEntry
    {
        return \App\Models\CashEntry::create([
            'tanggal' => $tgl, 'jenis' => 'pemasukan', 'amount' => $amount,
            'keterangan' => 'Manual ' . ($produk ?? 'x'), 'produk' => $produk,
            'cash_category_id' => $catId, 'source' => 'manual', 'is_transfer' => false,
        ]);
    }

    /** @test */
    public function manual_income_buku_splits_by_book_margin(): void
    {
        $this->manualIncome(1_000_000, 'buku');

        $h = $this->juni();

        $this->assertSame(1_000_000.0, $h['totalIn']);
        $this->assertSame(870_000.0, $h['totalMargin'], '87% x 1jt.');
        $this->assertSame(130_000.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_income_artikel_uses_lowest_25_percent(): void
    {
        $this->manualIncome(1_000_000, 'artikel');

        $h = $this->juni();

        $this->assertSame(250_000.0, $h['totalMargin'], '25% (S2 terendah) x 1jt.');
        $this->assertSame(750_000.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_income_non_product_is_fully_distributable(): void
    {
        $this->manualIncome(1_000_000, 'operasional');
        $this->manualIncome(500_000, null);
        $this->manualIncome(300_000, 'jasa editing'); // produk kustom

        $h = $this->juni();

        $this->assertSame(1_800_000.0, $h['totalIn']);
        $this->assertSame(1_800_000.0, $h['totalMargin'], 'Semua 100% siap dibagi.');
        $this->assertSame(0.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_and_payment_income_combine_without_double_count(): void
    {
        // Payment otomatis: at_kolab sinta2 1,5jt -> margin 375rb.
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);
        // Manual buku 1jt -> margin 870rb.
        $this->manualIncome(1_000_000, 'buku');

        $h = $this->juni();

        $this->assertSame(2_500_000.0, $h['totalIn'], '1,5jt + 1jt, tanpa dobel.');
        $this->assertSame(1_245_000.0, $h['totalMargin'], '375rb + 870rb.');
    }

    /** @test */
    public function transfers_and_manual_expenses_are_excluded_from_profit(): void
    {
        // Pengeluaran manual - bukan pemasukan.
        \App\Models\CashEntry::create(['tanggal' => '2026-06-12', 'jenis' => 'pengeluaran', 'amount' => 999_000, 'keterangan' => 'Biaya', 'source' => 'manual', 'is_transfer' => false]);
        // Sisi masuk sebuah transfer - internal, bukan pemasukan riil.
        \App\Models\CashEntry::create(['tanggal' => '2026-06-12', 'jenis' => 'pemasukan', 'amount' => 999_000, 'keterangan' => 'Transfer masuk', 'source' => 'manual', 'is_transfer' => true]);

        $h = $this->juni();

        $this->assertSame(0.0, $h['totalIn']);
        $this->assertSame(0.0, $h['totalMargin']);
    }

    /** @test */
    public function yearly_includes_manual_income(): void
    {
        $this->manualIncome(1_000_000, 'buku', '2026-05-12'); // Mei
        $this->manualIncome(1_000_000, 'buku', '2026-06-12'); // Juni

        $tahun = app(ProfitAnalysisService::class)->yearly(2026);

        $this->assertSame(870_000.0, $tahun[4]['totalMargin'], 'Mei (index 4).');
        $this->assertSame(870_000.0, $tahun[5]['totalMargin'], 'Juni (index 5).');
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

    /** @test */
    public function page_renders_and_links_to_distribution(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');
        $this->actingAs($sa)->get(route('accounting.profit', ['year' => 2026, 'month' => 6]))
            ->assertOk()
            ->assertSee('Siap Dibagi')
            ->assertSee('Akumulasi 2026')
            // Blade meng-escape '&' jadi '&amp;' di href → bandingkan versi ter-escape.
            ->assertSee(e(route('accounting.distribution', ['year' => 2026, 'month' => 6, 'profit' => 375000])), false);

        $acc = User::factory()->create();
        $acc->assignRole('accounting');
        $this->actingAs($acc)->get(route('accounting.profit'))->assertOk();

        $mk = User::factory()->create();
        $mk->assignRole('marketing');
        $this->actingAs($mk)->get(route('accounting.profit'))->assertForbidden();
    }
}
