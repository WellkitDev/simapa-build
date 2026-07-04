<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashDistribution;
use App\Services\ProfitDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProfitDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function distributes_percent_and_per_member(): void
    {
        // seed migrasi: Harta 5%, Saving 10%, Fee 85% per_member; team_members default 8
        $r = (new ProfitDistributionService())->distribute(1000000, null);
        $lines = $r['lines']->keyBy('name');

        $this->assertSame(8, $r['members']);
        $this->assertSame(50000.0, $lines['Harta/Pemilik']['amount']);
        $this->assertSame(100000.0, $lines['Saving + Dana Tak Terduga']['amount']);
        $this->assertSame(850000.0, $lines['Fee Tim']['amount']);
        $this->assertSame(106250.0, $lines['Fee Tim']['perPerson']); // 850000 / 8
        $this->assertNull($lines['Harta/Pemilik']['perPerson']);
        $this->assertSame(1000000.0, $r['totalAllocated']);
        $this->assertSame(0.0, $r['remainder']);
    }

    /** @test */
    public function flat_rule_is_fixed_and_members_min_one(): void
    {
        CashDistribution::create(['name' => 'PPn Bank', 'type' => 'flat', 'value' => 20000, 'per_member' => false, 'active' => true, 'position' => 4]);

        $r = (new ProfitDistributionService())->distribute(1000000, 0); // members 0 → min 1
        $lines = $r['lines']->keyBy('name');

        $this->assertSame(1, $r['members']);
        $this->assertSame(20000.0, $lines['PPn Bank']['amount']); // flat, tak tergantung profit
        $this->assertSame(850000.0, $lines['Fee Tim']['perPerson']); // members 1
        $this->assertSame(1020000.0, $r['totalAllocated']);
        $this->assertSame(-20000.0, $r['remainder']); // profit − alokasi (over-allocated)
    }

    /** @test */
    public function inactive_rule_excluded(): void
    {
        CashDistribution::where('name', 'Fee Tim')->update(['active' => false]);
        $r = (new ProfitDistributionService())->distribute(1000000, null);
        $this->assertFalse($r['lines']->contains('name', 'Fee Tim'));
        $this->assertSame(150000.0, $r['totalAllocated']); // 5% + 10%
    }

    /** @test */
    public function flat_per_member_is_salary_per_person(): void
    {
        // Gaji pokok 2,5jt PER ORANG, 8 anggota → per orang = 2,5jt, total = 20jt.
        CashDistribution::create(['name' => 'Gaji Pokok', 'type' => 'flat', 'value' => 2500000, 'per_member' => true, 'active' => true, 'position' => 5]);

        $line = (new ProfitDistributionService())->distribute(1000000, 8)['lines']->firstWhere('name', 'Gaji Pokok');
        $this->assertSame(2500000.0, $line['perPerson']); // nominal = per orang
        $this->assertSame(20000000.0, $line['amount']);   // total = 2,5jt × 8
    }
}
