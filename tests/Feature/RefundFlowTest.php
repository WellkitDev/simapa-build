<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Jobs\SendRefundJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function paidOrder(int $amount = 500000): Order
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        return $order;
    }

    /** @test */
    public function refund_form_loads_for_superadmin(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->user('superadmin'))->get(route('order.refund.form', $order->code_order))
            ->assertOk()->assertSee('Proses Refund');
    }

    /** @test */
    public function superadmin_processes_refund(): void
    {
        Queue::fake();
        $order = $this->paidOrder(500000);
        $sa = $this->user('superadmin');

        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), [
            'amount' => 200000, 'reason' => 'Batal cetak', 'method' => 'transfer', 'account' => 'BCA 1', 'tanggal' => '2026-06-05',
        ])->assertRedirect();

        $refund = Payment::where('order_id', $order->id)->where('payment_type', 'refund')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(200000, $refund->amount);
        $this->assertSame('paid', $refund->status);
        $this->assertSame('Batal cetak', $refund->refund_reason);
        $this->assertSame($sa->id, $refund->refunded_by);

        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $refund->id, 'jenis' => 'pengeluaran']);
        Queue::assertPushed(SendRefundJob::class);
    }

    /** @test */
    public function refund_amount_cannot_exceed_paid(): void
    {
        $order = $this->paidOrder(500000);
        $this->actingAs($this->user('superadmin'))->post(route('order.refund.store', $order->code_order), [
            'amount' => 999999, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::where('payment_type', 'refund')->count());
    }

    /** @test */
    public function cannot_refund_without_paid_payment(): void
    {
        $order = Order::factory()->create();
        $this->actingAs($this->user('superadmin'))->get(route('order.refund.form', $order->code_order))
            ->assertSessionHasErrors('refund');
    }

    /** @test */
    public function cannot_refund_twice(): void
    {
        Queue::fake();
        $order = $this->paidOrder(500000);
        $sa = $this->user('superadmin');
        $payload = ['amount' => 100000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05'];

        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), $payload)->assertRedirect();
        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), $payload)->assertSessionHasErrors('refund');
        $this->assertSame(1, Payment::where('order_id', $order->id)->where('payment_type', 'refund')->count());
    }

    /** @test */
    public function only_superadmin_can_refund(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->user('manager'))->get(route('order.refund.form', $order->code_order))->assertForbidden();
        $this->actingAs($this->user('manager'))->post(route('order.refund.store', $order->code_order), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertRedirect()->assertSessionHas('error');
    }
}
