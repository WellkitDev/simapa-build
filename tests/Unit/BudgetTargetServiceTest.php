<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use App\Services\CashRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BudgetTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BudgetTargetService
    {
        return new BudgetTargetService(new CashRecapService());
    }

    private function income(string $tanggal, $amount): void
    {
        CashEntry::create(['tanggal' => $tanggal, 'jenis' => 'pemasukan', 'amount' => $amount, 'keterangan' => 'x', 'source' => 'manual']);
    }

    /** @test */
    public function monthly_achievement_computes_pct_and_status(): void
    {
        CashSetting::singleton()->update(['target_operasional' => 1000000]);
        $this->income('2026-06-05', 800000);
        $this->income('2026-07-05', 1200000);

        $m = $this->service()->monthlyAchievement(2026);

        $this->assertSame(800000.0, $m[5]['realisasi']); // indeks 5 = bulan 6 (Jun)
        $this->assertSame(80, $m[5]['pct']);
        $this->assertFalse($m[5]['achieved']);
        $this->assertSame(120, $m[6]['pct']); // Jul
        $this->assertTrue($m[6]['achieved']);
    }

    /** @test */
    public function target_zero_is_guarded(): void
    {
        $this->income('2026-06-05', 800000); // target default 0
        $m = $this->service()->monthlyAchievement(2026);
        $this->assertSame(0, $m[5]['pct']);
        $this->assertFalse($m[5]['achieved']);
    }
}
