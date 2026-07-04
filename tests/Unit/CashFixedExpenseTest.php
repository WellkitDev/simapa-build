<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashFixedExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashFixedExpenseTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function monthly_amount_derives_from_period(): void
    {
        $tahunan = CashFixedExpense::create(['name' => 'Hosting', 'period' => 'tahunan', 'amount' => 1200000]);
        $bulanan = CashFixedExpense::create(['name' => 'Saving', 'period' => 'bulanan', 'amount' => 500000]);

        $this->assertSame(100000.0, $tahunan->monthlyAmount()); // 1.200.000 / 12
        $this->assertSame(500000.0, $bulanan->monthlyAmount());
    }
}
