<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalarySlipModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function recalc_totals_computes_earnings_deductions_and_net(): void
    {
        $user = User::factory()->create();
        $slip = SalarySlip::create([
            'slip_no'       => 'SLP-202607-0001',
            'user_id'       => $user->id,
            'employee_name' => $user->name,
            'period_year'   => 2026,
            'period_month'  => 7,
            'status'        => 'draft',
        ]);
        $slip->lines()->createMany([
            ['type' => 'earning',   'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0],
            ['type' => 'earning',   'label' => 'Tunjangan',  'amount' => 1000000, 'position' => 1],
            ['type' => 'deduction', 'label' => 'BPJS',       'amount' => 300000,  'position' => 0],
        ]);

        $slip->recalcTotals();
        $slip->refresh();

        $this->assertEquals(6000000, $slip->total_earnings);
        $this->assertEquals(300000,  $slip->total_deductions);
        $this->assertEquals(5700000, $slip->net_pay);
        $this->assertCount(3, $slip->lines);
        $this->assertCount(2, $slip->earnings);
        $this->assertSame('Juli 2026', $slip->periodLabel());
    }
}
