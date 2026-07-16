<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashRecapService;
use App\Services\FinancialReportService;
use App\Services\PaymentCashBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Backfill payment lama ke Jurnal Kas. Sinkron hanya jalan lewat observer saat
 * payment disimpan, jadi payment yang ada sebelum fitur akuntansi tak pernah
 * tersentuh — di DB dev: 123 payment, 1 entri kas.
 */
class PaymentCashBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function order(string $type = 'at_kolab', int $cost = 5_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp', string $status = 'paid'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => $status, 'paid_at' => now(),
        ]);
    }

    /** Meniru keadaan "payment lama": ada payment, tapi entri kasnya tak pernah lahir. */
    private function lupakanEntriKas(): void
    {
        CashEntry::query()->delete();
    }

    /** @test */
    public function backfills_existing_payments(): void
    {
        $this->pay($this->order('at_kolab'), 5_000_000);
        $this->pay($this->order('at_mandiri'), 3_000_000);
        $this->pay($this->order('bk_mandiri'), 7_000_000);
        $this->lupakanEntriKas();
        $this->assertSame(0, CashEntry::count());

        $hasil = (new PaymentCashBackfillService())->run();

        $this->assertSame(3, $hasil['synced']);
        $this->assertSame(3, CashEntry::count());
        $this->assertSame(3, CashEntry::where('jenis', 'pemasukan')->count());
        $this->assertSame(2, CashEntry::where('produk', 'artikel')->count());
        $this->assertSame(1, CashEntry::where('produk', 'buku')->count());
    }

    /** @test */
    public function backfill_is_idempotent(): void
    {
        $this->pay($this->order(), 5_000_000);
        $this->pay($this->order(), 3_000_000);
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();
        (new PaymentCashBackfillService())->run();

        $this->assertSame(2, CashEntry::count(), 'Dijalankan dua kali tak boleh menggandakan entri.');
    }

    /** @test */
    public function backfills_refund_as_expense(): void
    {
        $order = $this->order('bk_mandiri', 10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();

        $this->assertSame(1, CashEntry::where('jenis', 'pemasukan')->count());
        $this->assertSame(1, CashEntry::where('jenis', 'pengeluaran')->count());
        $this->assertSame(4_000_000.0, (float) CashEntry::where('jenis', 'pengeluaran')->sum('amount'));
    }

    /** @test */
    public function skips_unpaid_payments(): void
    {
        $this->pay($this->order(), 5_000_000, 'dp', 'pending');
        $this->lupakanEntriKas();

        $hasil = (new PaymentCashBackfillService())->run();

        $this->assertSame(0, $hasil['synced']);
        $this->assertSame(0, CashEntry::count());
    }

    /** @test */
    public function refuses_when_opening_balance_set(): void
    {
        $this->pay($this->order(), 5_000_000);
        $this->lupakanEntriKas();
        CashAccount::query()->limit(1)->update(['opening_balance' => 1_000_000]);

        $this->expectException(\RuntimeException::class);

        try {
            (new PaymentCashBackfillService())->run();
        } finally {
            // Guard harus menolak SEBELUM menulis apa pun.
            $this->assertSame(0, CashEntry::count(), 'Guard menyala → tak boleh ada entri yang terlanjur dibuat.');
        }
    }

    /** @test */
    public function report_matches_journal_after_backfill(): void
    {
        $this->pay($this->order('at_kolab'), 5_000_000);
        $this->pay($this->order('bk_mandiri'), 7_000_000);
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();

        $laporan = app(FinancialReportService::class)->pemasukan(null)['kpi']['total'];
        $jurnal  = app(CashRecapService::class)->ytd(now()->year)['totalIn'];

        $this->assertSame(12_000_000.0, (float) $laporan);
        $this->assertSame((float) $laporan, (float) $jurnal, 'Setelah backfill, laporan dan jurnal harus sepakat.');
    }
}
