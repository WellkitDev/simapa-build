<?php

namespace Tests\Feature;

use App\Models\MarketingTarget;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashRecapService;
use App\Services\FinancialReportService;
use App\Services\SalesDashboardService;
use App\Services\MarketingTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengunci definisi kanonik "uang masuk": Payment paid yang BUKAN refund.
 * Skenario tetap: order 10jt dibayar penuh, lalu direfund 4jt.
 * Pemasukan harus 10jt (kotor) — bukan 14jt (refund ditambahkan = bug lama),
 * dan bukan 6jt (bersih = divergen dari Jurnal Kas).
 */
class IncomeDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');

        $this->order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $this->marketing->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $this->order->id, 'type' => 'bk_mandiri', 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => 10_000_000,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        // Pelanggan bayar 10jt.
        Payment::create([
            'order_id' => $this->order->id, 'payment_type' => 'dp', 'amount' => 10_000_000,
            'status' => 'paid', 'paid_at' => now(),
        ]);
        // Lalu direfund 4jt — uang KELUAR (Jurnal Kas mencatatnya sebagai pengeluaran).
        Payment::create([
            'order_id' => $this->order->id, 'payment_type' => 'refund', 'amount' => 4_000_000,
            'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /** @test */
    public function pemasukan_excludes_refund(): void
    {
        $kpi = app(FinancialReportService::class)->pemasukan(null)['kpi'];

        $this->assertSame(10_000_000, $kpi['total'], 'Refund tak boleh menambah pemasukan.');
    }

    /** @test */
    public function piutang_paid_excludes_refund(): void
    {
        // Order masih 'pending' (belum lunas) → masuk daftar piutang.
        $kpi = app(FinancialReportService::class)->piutang(null)['kpi'];

        $this->assertSame(10_000_000, $kpi['dibayar'], 'Refund tak boleh menambah "sudah dibayar".');
        $this->assertSame(0, $kpi['sisa'], 'Sisa piutang = 10jt nilai - 10jt dibayar.');
    }

    /** @test */
    public function marketing_dashboard_income_excludes_refund(): void
    {
        $kpi = app(SalesDashboardService::class)->forUser($this->marketing);

        $this->assertSame(10_000_000, $kpi['pemasukan_tahun_ini'], 'Refund tak boleh menambah KPI dashboard.');
    }

    /** @test */
    public function marketing_target_realisasi_excludes_refund(): void
    {
        $target = MarketingTarget::create([
            'user_id' => $this->marketing->id,
            'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(),
            'target_amount' => 20_000_000, 'commission_type' => 'percent',
            'commission_rate' => 5, 'commission_flat' => 0,
            'created_by' => $this->marketing->id, 'updated_by' => $this->marketing->id,
        ]);

        $p = app(MarketingTargetService::class)->progressFor($target);

        $this->assertSame(10_000_000, $p['realisasi'], 'Refund tak boleh menggelembungkan realisasi.');
        $this->assertSame(500_000, $p['komisi'], 'Komisi 5% x 10jt — bukan 5% x 14jt.');
    }

    /** @test */
    public function main_dashboard_total_income_excludes_refund(): void
    {
        // Sejak peta role eksplisit (dashboard per role): dashboard superadmin tak lagi
        // menghitung uang masuk sendiri — ia mendelegasikan ke CashRecapService::ytd() lewat
        // blok 'cash' pada view company. Kunci yang sama tetap diuji: refund tak boleh
        // menggelembungkan pemasukan, dan tetap tercatat sebagai pengeluaran.
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $cash = $this->actingAs($superadmin)->get(route('dashboard'))
            ->assertOk()->viewData('cash');

        $this->assertSame(10_000_000.0, (float) $cash['ytd']['totalIn'], 'Refund tak boleh menambah totalIn Jurnal Kas di dashboard.');
        $this->assertSame(4_000_000.0, (float) $cash['ytd']['totalOut'], 'Refund harus tetap tercatat sebagai pengeluaran.');
    }

    /** @test */
    public function laporan_keuangan_matches_jurnal_kas(): void
    {
        // PaymentObserver sudah menyinkron kedua Payment ke tb_cash_entries.
        $laporan = app(FinancialReportService::class)->pemasukan(null)['kpi']['total'];
        $jurnal  = app(CashRecapService::class)->ytd(now()->year)['totalIn'];

        $this->assertSame((float) $laporan, (float) $jurnal, 'Laporan Keuangan dan Jurnal Kas harus sepakat soal pemasukan.');
    }

    /** @test */
    public function refund_still_recorded_as_expense(): void
    {
        // Prinsip user: "jika ada refund maka ada pengeluaran" — perbaikan ini tak boleh menghapusnya.
        $ytd = app(CashRecapService::class)->ytd(now()->year);

        $this->assertSame(4_000_000.0, (float) $ytd['totalOut'], 'Refund harus tetap tercatat sebagai pengeluaran.');
        $this->assertSame(6_000_000.0, (float) $ytd['laba'], 'Laba = 10jt masuk - 4jt refund.');
    }
}
