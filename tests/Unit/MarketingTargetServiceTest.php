<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\MarketingTarget;
use App\Services\MarketingTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingTargetService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new MarketingTargetService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function paidThisMonth(User $u, int $amount): void
    {
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => now(), 'status' => 'paid']);
    }

    private function setTarget(User $u, int $target, float $rate): void
    {
        MarketingTarget::create([
            'user_id' => $u->id, 'year' => now()->year, 'month' => now()->month,
            'target_amount' => $target, 'commission_rate' => $rate,
        ]);
    }

    /** @test */
    public function progress_full_achievement_with_commission(): void
    {
        $u = $this->marketing();
        $this->setTarget($u, 10000000, 5);
        $this->paidThisMonth($u, 10000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertTrue($p['has_target']);
        $this->assertSame(10000000, $p['target']);
        $this->assertSame(10000000, $p['realisasi']);
        $this->assertSame(100.0, $p['capaian_persen']);
        $this->assertSame(500000, $p['komisi']);
        $this->assertSame(0, $p['sisa']);
    }

    /** @test */
    public function progress_partial_achievement(): void
    {
        $u = $this->marketing();
        $this->setTarget($u, 10000000, 5);
        $this->paidThisMonth($u, 6000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertSame(60.0, $p['capaian_persen']);
        $this->assertSame(4000000, $p['sisa']);
        $this->assertSame(300000, $p['komisi']);
    }

    /** @test */
    public function progress_without_target_is_safe(): void
    {
        $u = $this->marketing();
        $this->paidThisMonth($u, 2000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertFalse($p['has_target']);
        $this->assertSame(0, $p['target']);
        $this->assertSame(0.0, $p['rate']);
        $this->assertSame(0, $p['komisi']);
        $this->assertSame(2000000, $p['realisasi']);
        $this->assertSame(0.0, $p['capaian_persen']);
    }

    /** @test */
    public function realisasi_scoped_to_marketing_and_month(): void
    {
        $u = $this->marketing();
        $other = $this->marketing();
        $this->paidThisMonth($u, 1000000);
        $this->paidThisMonth($other, 9999999);
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5000000, 'paid_at' => now()->subMonthNoOverflow(), 'status' => 'paid']);

        $p = $this->svc->progressFor($u, now()->year, now()->month);
        $this->assertSame(1000000, $p['realisasi']);
    }

    /** @test */
    public function monthly_overview_lists_all_marketing_with_realisasi(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();
        $this->setTarget($a, 5000000, 10);
        $this->paidThisMonth($a, 2000000);

        $rows = $this->svc->monthlyOverview(now()->year, now()->month);

        $this->assertCount(2, $rows);
        $rowA = $rows->firstWhere('user_id', $a->id);
        $this->assertSame(5000000, $rowA['target']);
        $this->assertSame(2000000, $rowA['realisasi']);
        $this->assertSame(200000, $rowA['komisi']);
        $rowB = $rows->firstWhere('user_id', $b->id);
        $this->assertFalse($rowB['has_target']);
        $this->assertSame(0, $rowB['realisasi']);
    }

    /** @test */
    public function upsert_many_creates_then_updates(): void
    {
        $u = $this->marketing();
        $actor = $this->marketing();

        $this->svc->upsertMany(now()->year, now()->month, [
            ['user_id' => $u->id, 'target' => 7000000, 'rate' => 5],
        ], $actor);

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $u->id, 'year' => now()->year, 'month' => now()->month, 'target_amount' => 7000000,
        ]);

        $this->svc->upsertMany(now()->year, now()->month, [
            ['user_id' => $u->id, 'target' => 8000000, 'rate' => 7],
        ], $actor);

        $this->assertSame(1, MarketingTarget::where('user_id', $u->id)->count());
        $this->assertSame(8000000, (int) MarketingTarget::where('user_id', $u->id)->first()->target_amount);
    }
}
