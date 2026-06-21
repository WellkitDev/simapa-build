<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function orderWithPayment(User $u, string $code, int $cost, int $paid, string $status = 'pending'): Order
    {
        $o = Order::factory()->create(['user_id' => $u->id, 'status' => $status, 'code_order' => $code]);
        OrderDetail::factory()->create(['order_id' => $o->id, 'type' => 'bk_mandiri', 'cost_amount' => $cost]);
        if ($paid > 0) {
            Payment::create(['order_id' => $o->id, 'payment_type' => 'dp', 'amount' => $paid, 'paid_at' => now(), 'status' => 'paid']);
        }
        return $o;
    }

    /** @test */
    public function marketing_pemasukan_shows_only_own_and_includes_dp(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-MINE', 5000000, 2000000);
        $this->orderWithPayment($this->user('marketing'), 'ORD-OTHER', 9000000, 9000000);

        $this->actingAs($me);
        $this->get(route('income.pemasukan'))
            ->assertOk()
            ->assertSee('ORD-MINE')
            ->assertDontSee('ORD-OTHER')
            ->assertSee('2.000.000');
    }

    /** @test */
    public function piutang_shows_remaining_balance_scoped(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-DEBT', 5000000, 2000000);

        $this->actingAs($me);
        $this->get(route('income.piutang'))
            ->assertOk()
            ->assertSee('ORD-DEBT')
            ->assertSee('3.000.000');
    }

    /** @test */
    public function lunas_lists_completed_orders(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-DONE', 4000000, 4000000, 'lunas');
        $this->orderWithPayment($me, 'ORD-OPEN', 1000000, 0, 'pending');

        $this->actingAs($me);
        $this->get(route('income.lunas'))
            ->assertOk()
            ->assertSee('ORD-DONE')
            ->assertDontSee('ORD-OPEN');
    }

    /** @test */
    public function old_income_routes_are_replaced(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.order'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.payment'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.pending'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('income.pemasukan'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('income.piutang'));
    }

    /** @test */
    public function exports_return_correct_content_types(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-EXP', 5000000, 2000000);
        $this->actingAs($me);

        $pdf = $this->get(route('income.pemasukan.pdf'))->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));

        $csv = $this->get(route('income.pemasukan.csv'))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('ORD-EXP', $csv->streamedContent());

        $this->get(route('income.piutang.pdf'))->assertOk();
        $this->get(route('income.piutang.csv'))->assertOk();
        $this->get(route('income.lunas.pdf'))->assertOk();
        $this->get(route('income.lunas.csv'))->assertOk();
    }

    /** @test */
    public function laporan_pemasukan_matches_dashboard_income(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-A', 5000000, 2000000);
        $this->orderWithPayment($me, 'ORD-B', 3000000, 3000000, 'lunas');

        $fr   = app(\App\Services\FinancialReportService::class)->pemasukan($me);
        $dash = app(\App\Services\MarketingDashboardService::class)->forUser($me);

        $this->assertEquals($dash['pemasukan_tahun_ini'], $fr['kpi']['total']);
        $this->assertEquals(5000000, $fr['kpi']['total']); // 2jt + 3jt, by paid_at tahun ini
    }

    /** @test */
    public function production_cannot_access_financial_reports(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('income.pemasukan'))->assertStatus(403);
        $this->get(route('income.piutang'))->assertStatus(403);
        $this->get(route('income.lunas'))->assertStatus(403);
    }
}
