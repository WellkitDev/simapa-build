<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoicePaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    /** order + detail(cost) + paid payment(amount) + invoice linked to that payment */
    private function graph(User $u, int $cost, int $paid): array
    {
        $order = Order::create(['code_order' => 'ORD-INV-1', 'user_id' => $u->id, 'status' => 'pending', 'ordered_at' => today()->toDateString()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'T', 'slug' => 't-' . $order->id, 'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => $cost]);
        $payment = Payment::create(['order_id' => $order->id, 'amount' => $paid, 'payment_type' => 'dp', 'status' => 'paid', 'paid_at' => now()]);
        $invoice = Invoice::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'invoice_no' => 'INV-1', 'status' => 'diterbitkan', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString()]);
        return [$order, $payment, $invoice];
    }

    /** @test */
    public function manager_corrects_linked_payment_and_recomputes_order(): void
    {
        $manager = $this->user('manager');
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 500000);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'payment_id' => $payment->id, 'amount' => 1000000, 'payment_type' => 'lunas',
        ])->assertRedirect(route('invoice.show', $invoice->id));

        $this->assertSame(1000000, (int) $payment->fresh()->amount);
        $this->assertSame('lunas', $payment->fresh()->payment_type);
        $this->assertSame('lunas', $order->fresh()->status);        // cost 1jt - paid 1jt = 0 -> lunas
        $this->assertSame(1, $invoice->logs()->count());            // koreksi tercatat
    }

    /** @test */
    public function partial_payment_keeps_order_pending(): void
    {
        $manager = $this->user('manager');
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 1000000);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'payment_id' => $payment->id, 'amount' => 400000, 'payment_type' => 'dp',
        ])->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);      // cost 1jt - paid 400rb > 0 -> pending
    }

    /** @test */
    public function non_manager_cannot_update_invoice(): void
    {
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 500000);
        $this->actingAs($this->user('marketing'))->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'amount' => 999, 'payment_type' => 'dp', 'payment_id' => $payment->id,
        ])->assertForbidden();
    }

    /** @test */
    public function invoice_without_payment_updates_invoice_fields_only(): void
    {
        $manager = $this->user('manager');
        $order = Order::create(['code_order' => 'ORD-INV-2', 'user_id' => $this->user('marketing')->id, 'status' => 'pending', 'ordered_at' => today()->toDateString()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'T', 'slug' => 't2-' . $order->id, 'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000]);
        $invoice = Invoice::create(['order_id' => $order->id, 'payment_id' => null, 'invoice_no' => 'INV-2', 'status' => 'draft', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString()]);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-2B', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $this->assertSame('INV-2B', $invoice->fresh()->invoice_no);
    }
}
