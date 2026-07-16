<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\User;
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** @test */
    public function warning_appears_on_three_pages(): void
    {
        $u = $this->superadmin();
        $pesan = 'Belum ada pengeluaran tercatat';

        $this->actingAs($u)->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee($pesan);
        $this->actingAs($u)->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertSee($pesan);
        $this->actingAs($u)->get(route('accounting.overview', ['year' => 2026]))
            ->assertOk()->assertSee($pesan);
    }

    /** @test */
    public function warning_absent_when_expense_exists(): void
    {
        $this->pengeluaran('2026-06-10');
        $u = $this->superadmin();
        $pesan = 'Belum ada pengeluaran tercatat';

        $this->actingAs($u)->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertDontSee($pesan);
        $this->actingAs($u)->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertDontSee($pesan);
        $this->actingAs($u)->get(route('accounting.overview', ['year' => 2026]))
            ->assertOk()->assertDontSee($pesan);
    }

    /** @test */
    public function warning_does_not_change_distribution(): void
    {
        // Peringatan hanya bicara — tak boleh diam-diam memotong laba.
        // Keputusan user: hanya pengeluaran nyata di Jurnal Kas yang memotong.
        $u = $this->superadmin();

        $profit = $this->actingAs($u)
            ->get(route('accounting.distribution', ['year' => 2026, 'month' => 6, 'profit' => 5_050_000]))
            ->assertOk()->viewData('profit');

        $this->assertSame(5_050_000.0, (float) $profit, 'Laba yang dibagi tak boleh berubah karena peringatan.');
    }
}
