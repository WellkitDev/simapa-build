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

    private function user_superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u;
    }

    private function paidOn(User $u, int $amount, $date): void
    {
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => $date, 'status' => 'paid']);
    }

    private function target(User $u, array $attrs = []): MarketingTarget
    {
        return MarketingTarget::create(array_merge([
            'user_id' => $u->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5,
        ], $attrs));
    }

    /** @test */
    public function progress_uses_date_range_and_derives_status_and_commission(): void
    {
        $u = $this->marketing();
        $t = $this->target($u);
        $this->paidOn($u, 6000000, now());
        $this->paidOn($u, 9000000, now()->subMonths(2));

        $p = $this->svc->progressFor($t);

        $this->assertSame(6000000, $p['realisasi']);
        $this->assertSame(60.0, $p['capaian_persen']);
        $this->assertSame(300000, $p['komisi']);
        $this->assertSame(4000000, $p['sisa']);
        $this->assertSame('aktif', $p['status']);
        $this->assertFalse($p['commission_paid']);
        $this->assertFalse($p['tertunggak']);
    }

    /** @test */
    public function expired_and_overdue_flags(): void
    {
        $u = $this->marketing();
        $t = $this->target($u, ['start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString()]);

        $p = $this->svc->progressFor($t);

        $this->assertSame('berakhir', $p['status']);
        $this->assertTrue($p['tertunggak']);
    }

    /** @test */
    public function paid_commission_is_not_overdue(): void
    {
        $u = $this->marketing();
        $t = $this->target($u, [
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString(),
            'commission_paid' => true,
        ]);

        $p = $this->svc->progressFor($t);
        $this->assertTrue($p['commission_paid']);
        $this->assertFalse($p['tertunggak']);
    }

    /** @test */
    public function current_for_marketing_picks_active_target(): void
    {
        $u = $this->marketing();
        $this->target($u, ['start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->subMonth()->toDateString()]);
        $active = $this->target($u);

        $cur = $this->svc->currentForMarketing($u);
        $this->assertNotNull($cur);
        $this->assertSame($active->id, $cur['id']);

        $other = $this->marketing();
        $this->assertNull($this->svc->currentForMarketing($other));
    }

    /** @test */
    public function create_target_individual_and_all(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();
        $actor = $this->user_superadmin();

        $this->svc->createTarget('individual', [$a->id], 5000000, 4, now()->toDateString(), now()->addMonth()->toDateString(), $actor);
        $this->assertSame(1, MarketingTarget::where('user_id', $a->id)->count());
        $this->assertNull(MarketingTarget::where('user_id', $a->id)->first()->batch_id);

        $this->svc->createTarget('all', [], 7000000, 6, now()->toDateString(), now()->addMonth()->toDateString(), $actor);
        $batch = MarketingTarget::whereNotNull('batch_id')->pluck('batch_id')->unique();
        $this->assertCount(1, $batch);
        $this->assertSame(2, MarketingTarget::whereNotNull('batch_id')->count());
    }

    /** @test */
    public function mark_commission_paid_sets_fields(): void
    {
        $u = $this->marketing();
        $t = $this->target($u);
        $actor = $this->user_superadmin();

        $this->svc->markCommissionPaid($t, $actor);

        $t->refresh();
        $this->assertTrue($t->commission_paid);
        $this->assertNotNull($t->commission_paid_at);
        $this->assertSame($actor->id, $t->commission_paid_by);
    }
}
