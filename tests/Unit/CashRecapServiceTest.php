<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Models\CashCategory;
use App\Models\CashSetting;
use App\Models\CashAccount;
use App\Services\CashRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashRecapServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $tanggal, string $jenis, $amount, ?string $produk = null, ?int $catId = null): void
    {
        CashEntry::create(['tanggal' => $tanggal, 'jenis' => $jenis, 'amount' => $amount, 'produk' => $produk, 'cash_category_id' => $catId, 'keterangan' => 'x', 'source' => 'manual']);
    }

    private function seedData(): void
    {
        CashAccount::incomeDefault()->update(['opening_balance' => 1000000]);
        $opCat = CashCategory::where('name', 'Operational')->first();
        $this->entry('2026-01-05', 'pemasukan', 500000, 'artikel');
        $this->entry('2026-01-10', 'pemasukan', 300000, 'buku');
        $this->entry('2026-01-15', 'pengeluaran', 200000, null, $opCat?->id);
        $this->entry('2026-02-05', 'pemasukan', 400000, 'artikel');
    }

    /** @test */
    public function monthly_recap_income_expense_laba_and_running_saldo(): void
    {
        $this->seedData();
        $r = (new CashRecapService())->monthlyRecap(2026);

        $jan = $r[0];
        $this->assertSame(500000.0, $jan['inArtikel']);
        $this->assertSame(300000.0, $jan['inBuku']);
        $this->assertSame(800000.0, $jan['totalIn']);
        $this->assertSame(200000.0, $jan['totalOut']);
        $this->assertSame(600000.0, $jan['laba']);
        $this->assertSame(1600000.0, $jan['saldoAkhir']); // 1jt awal + 600rb

        $feb = $r[1];
        $this->assertSame(400000.0, $feb['totalIn']);
        $this->assertSame(400000.0, $feb['laba']);
        $this->assertSame(2000000.0, $feb['saldoAkhir']);
    }

    /** @test */
    public function ytd_aggregates(): void
    {
        $this->seedData();
        $y = (new CashRecapService())->ytd(2026);

        $this->assertSame(1200000.0, $y['totalIn']);
        $this->assertSame(200000.0, $y['totalOut']);
        $this->assertSame(1000000.0, $y['laba']);
        $this->assertSame(2000000.0, $y['saldoAkhir']);
        $this->assertSame(900000.0, $y['incomeArtikel']);
        $this->assertSame(300000.0, $y['incomeBuku']);
        $this->assertSame('Jan', $y['bestMonthLabel']);
        $this->assertArrayHasKey('Operational', $y['expenseByCategory']);
        $this->assertSame(200000.0, $y['expenseByCategory']['Operational']);
    }

    /** @test */
    public function transfers_excluded_from_recap(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        CashEntry::create(['tanggal' => '2026-03-01', 'jenis' => 'pengeluaran', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g']);
        CashEntry::create(['tanggal' => '2026-03-01', 'jenis' => 'pemasukan', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g']);

        $mar = (new CashRecapService())->monthlyRecap(2026)[2];
        $this->assertSame(0.0, $mar['totalIn']);
        $this->assertSame(0.0, $mar['totalOut']);
        $this->assertSame(0.0, $mar['laba']);
    }
}
