<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\ExpenseGapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Peringatan celah pengeluaran: bila periode tak punya pengeluaran tercatat
 * sama sekali, angka "laba" sebenarnya pemasukan kotor. Ambang sengaja NOL —
 * lihat spec §1 (ambang "kurang dari biaya tetap" sering salah → diabaikan).
 */
class ExpenseGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function pengeluaran(string $tanggal, int $amount = 500_000, bool $transfer = false): CashEntry
    {
        return CashEntry::create([
            'tanggal'          => $tanggal,
            'kode'             => 'X' . uniqid(),
            'keterangan'       => 'Uji',
            'jenis'            => 'pengeluaran',
            'amount'           => $amount,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
            'source'           => 'manual',
            'is_transfer'      => $transfer,
        ]);
    }

    /** @test */
    public function no_expense_recorded_sets_gap(): void
    {
        $hasil = app(ExpenseGapService::class)->check(2026, 6);

        $this->assertTrue($hasil['hasGap']);
        $this->assertSame(0.0, $hasil['recorded']);
    }

    /** @test */
    public function recorded_expense_clears_gap(): void
    {
        $this->pengeluaran('2026-06-10');

        $hasil = app(ExpenseGapService::class)->check(2026, 6);

        $this->assertFalse($hasil['hasGap']);
        $this->assertSame(500_000.0, $hasil['recorded']);
    }

    /** @test */
    public function transfer_is_not_an_expense(): void
    {
        // Pemindahan antar akun sendiri bukan pengeluaran — celahnya tetap ada.
        $this->pengeluaran('2026-06-10', 500_000, true);

        $this->assertTrue(app(ExpenseGapService::class)->check(2026, 6)['hasGap']);
    }

    /** @test */
    public function month_scope_is_independent(): void
    {
        $this->pengeluaran('2026-01-10');

        $svc = app(ExpenseGapService::class);
        $this->assertFalse($svc->check(2026, 1)['hasGap'], 'Januari punya pengeluaran.');
        $this->assertTrue($svc->check(2026, 2)['hasGap'], 'Februari tidak.');
        $this->assertFalse($svc->check(2026)['hasGap'], 'Setahun penuh: ada pengeluaran di Januari.');
    }
}
