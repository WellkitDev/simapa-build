<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\Invoice;
use App\Jobs\SendInvoiceJob;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

class DetailOrderPaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private User $manager;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Controllers' constructors inject GoogleDriveService — avoid real API.
        $this->mock(GoogleDriveService::class);

        Role::create(['name' => 'marketing',  'guard_name' => 'web']);
        Role::create(['name' => 'manager',    'guard_name' => 'web']);
        Role::create(['name' => 'superadmin', 'guard_name' => 'web']);

        $this->marketing  = User::factory()->create(); $this->marketing->assignRole('marketing');
        $this->manager    = User::factory()->create(); $this->manager->assignRole('manager');
        $this->superadmin = User::factory()->create(); $this->superadmin->assignRole('superadmin');
    }

    /** Order (pending) + one detail + a contact. */
    private function makeOrder(?User $owner = null): Order
    {
        $owner = $owner ?? $this->marketing;
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        OrderDetail::factory()->create(['order_id' => $order->id, 'cost_amount' => 1000000]);
        OrderContact::create(['order_id' => $order->id, 'cp_email' => 'cust@example.com', 'cp_phone' => '0812']);
        return $order->fresh(['details', 'contact']);
    }

    /** Payment + its approval row. $approval in: pending|approved|rejected. */
    private function makePayment(Order $order, string $type, int $amount, string $approval): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => $type,
            'amount'       => $amount,
            'proof_url'    => null,
            'paid_at'      => now(),
            'status'       => $approval === 'rejected' ? 'rejected' : 'paid',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approval]);
        return $payment;
    }

    private function makeInvoice(Order $order, ?Payment $payment, bool $emailRequested = false, string $status = 'diterbitkan'): Invoice
    {
        return Invoice::factory()->create([
            'order_id'        => $order->id,
            'payment_id'      => $payment?->id,
            'status'          => $status,
            'email_requested' => $emailRequested,
        ]);
    }

    /** @test */
    public function invoice_persists_email_requested_flag(): void
    {
        $order   = $this->makeOrder();
        $invoice = $this->makeInvoice($order, null, true);

        $this->assertDatabaseHas('tb_invoices', [
            'id'              => $invoice->id,
            'email_requested' => true,
        ]);
    }
}
