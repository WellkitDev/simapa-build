<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new FinancialReportService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function orderFor(User $u, string $status, int $cost): Order
    {
        $o = Order::factory()->create(['user_id' => $u->id, 'status' => $status]);
        OrderDetail::factory()->create(['order_id' => $o->id, 'type' => 'bk_mandiri', 'cost_amount' => $cost]);
        return $o;
    }

    private function paid(Order $o, int $amount, string $type = 'dp', $paidAt = null): Payment
    {
        return Payment::create([
            'order_id' => $o->id, 'payment_type' => $type, 'amount' => $amount,
            'paid_at' => $paidAt ?? now(), 'status' => 'paid',
        ]);
    }

    /** @test */
    public function pemasukan_is_cash_basis_including_dp_on_unsettled_orders(): void
    {
        $me = $this->marketing();
        $pending = $this->orderFor($me, 'pending', 5000000);
        $this->paid($pending, 2000000, 'dp');
        $lunas = $this->orderFor($me, 'lunas', 3000000);
        $this->paid($lunas, 3000000, 'pelunasan');
        Payment::create(['order_id' => $pending->id, 'payment_type' => 'dp', 'amount' => 999, 'paid_at' => now(), 'status' => 'rejected']);

        $d = $this->svc->pemasukan($me);

        $this->assertEquals(5000000, $d['kpi']['total']);
        $this->assertEquals(2, $d['kpi']['pembayaran']);
        $this->assertEquals(2, $d['kpi']['order']);
        $this->assertCount(2, $d['detail']);
    }

    /** @test */
    public function pemasukan_scoped_to_own_orders(): void
    {
        $me = $this->marketing();
        $other = $this->marketing();
        $this->paid($this->orderFor($me, 'pending', 1000000), 1000000, 'dp');
        $this->paid($this->orderFor($other, 'pending', 9000000), 9000000, 'dp');

        $this->assertEquals(1000000, $this->svc->pemasukan($me)['kpi']['total']);
    }

    /** @test */
    public function piutang_kpi_and_list_are_both_scoped_consistently(): void
    {
        $me = $this->marketing();
        $other = $this->marketing();
        $o = $this->orderFor($me, 'pending', 5000000);
        $this->paid($o, 2000000, 'dp');
        $this->orderFor($other, 'pending', 8000000);

        $d = $this->svc->piutang($me);

        $this->assertEquals(5000000, $d['kpi']['nilai']);
        $this->assertEquals(2000000, $d['kpi']['dibayar']);
        $this->assertEquals(3000000, $d['kpi']['sisa']);
        $this->assertCount(1, $d['detail']);
    }

    /** @test */
    public function order_selesai_only_lunas(): void
    {
        $me = $this->marketing();
        $this->orderFor($me, 'lunas', 3000000);
        $this->orderFor($me, 'pending', 1000000);

        $d = $this->svc->orderSelesai($me);
        $this->assertEquals(1, $d['kpi']['jumlah']);
        $this->assertEquals(3000000, $d['kpi']['nilai']);
    }

    /** @test */
    public function resolve_scope_returns_user_only_for_marketing_only(): void
    {
        $mkt = $this->marketing();
        $mgr = User::factory()->create(); $mgr->assignRole('manager');

        $this->assertNotNull($this->svc->resolveScope($mkt));
        $this->assertNull($this->svc->resolveScope($mgr));
    }
}
