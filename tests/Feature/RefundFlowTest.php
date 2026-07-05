<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
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

    /** @return array{0:Order,1:Invoice} */
    private function lunasOrder(int $amount = 500000): array
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas', 'amount' => $amount, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        $invoice = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'lunas']);
        return [$order, $invoice];
    }

    /** @test */
    public function refund_form_loads_for_superadmin(): void
    {
        [$order, $invoice] = $this->lunasOrder();
        $this->actingAs($this->user('superadmin'))->get(route('invoice.refund.form', $invoice->id))
            ->assertOk()->assertSee('Proses Refund');
    }

    /** @test */
    public function superadmin_processes_refund(): void
    {
        Queue::fake();
        [$order, $invoice] = $this->lunasOrder(500000);

        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 200000, 'reason' => 'Batal cetak', 'method' => 'transfer', 'account' => 'BCA 1', 'tanggal' => '2026-06-05',
        ])->assertRedirect();

        $refund = Payment::where('order_id', $order->id)->where('payment_type', 'refund')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(200000, $refund->amount);
        $this->assertSame('paid', $refund->status);

        $invoice->refresh();
        $this->assertSame('refund', $invoice->status);
        $this->assertSame($refund->id, $invoice->refund_payment_id);
        $this->assertSame('Batal cetak', $invoice->refund_reason);
        $this->assertNotNull($invoice->refunded_at);

        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $refund->id, 'jenis' => 'pengeluaran']);

        Queue::assertPushed(SendRefundJob::class);
    }

    /** @test */
    public function refund_amount_cannot_exceed_paid(): void
    {
        [$order, $invoice] = $this->lunasOrder(500000);
        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 999999, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::where('payment_type', 'refund')->count());
    }

    /** @test */
    public function cannot_refund_non_lunas(): void
    {
        $order = Order::factory()->create();
        $invoice = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'draft']);
        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors();
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    /** @test */
    public function only_superadmin_processes_refund(): void
    {
        [$order, $invoice] = $this->lunasOrder();
        $this->actingAs($this->user('manager'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertForbidden();
    }
}
