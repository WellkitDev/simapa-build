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

    /** @test */
    public function invoice_pdf_data_counts_only_approved_payments(): void
    {
        $order = $this->makeOrder();
        $this->makePayment($order, 'dp', 400000, 'approved');
        $this->makePayment($order, 'pelunasan', 600000, 'pending'); // must be excluded
        $this->makePayment($order, 'dp', 100000, 'rejected');       // must be excluded
        $invoice = $this->makeInvoice($order, null, false);

        $data = \App\Support\InvoicePdfData::for($invoice);

        $this->assertSame(400000, (int) $data['alreadyPaid']);
        $this->assertSame(600000, (int) $data['remainingBalance']); // 1_000_000 - 400_000
        $this->assertCount(1, $data['order']->payments);            // only the approved one
    }

    /** @test */
    public function invoice_pdf_route_streams_a_pdf(): void
    {
        $order   = $this->makeOrder();
        $this->makePayment($order, 'lunas', 1000000, 'approved');
        $invoice = $this->makeInvoice($order, null, false);

        $resp = $this->actingAs($this->manager)->get(route('invoice.pdf', $invoice->id));

        $resp->assertOk();
        $this->assertSame('application/pdf', $resp->headers->get('content-type'));
    }

    /** @test */
    public function marketing_cannot_download_other_users_invoice_pdf(): void
    {
        $owner = User::factory()->create(); $owner->assignRole('marketing');
        $order = $this->makeOrder($owner); // belongs to someone else
        $invoice = $this->makeInvoice($order, null, false);

        $this->actingAs($this->marketing)
            ->get(route('invoice.pdf', $invoice->id))
            ->assertStatus(404);
    }

    /** @test */
    public function store_saves_email_flag_and_does_not_email_while_pending(): void
    {
        Queue::fake();

        // GoogleDriveService must return a folder id + upload url for store() to succeed.
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-123');
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'file-1', 'url' => 'https://drive/struk']);
        });

        $order = $this->makeOrder();

        $resp = $this->actingAs($this->marketing)->post(route('payment.store', $order->code_order), [
            'issued_at'          => now()->toDateString(),
            'dued_at'            => now()->addDays(14)->toDateString(),
            'status'             => 'dp',
            'pay_amount'         => 500000,
            'proof_url'          => UploadedFile::fake()->image('struk.jpg'),
            'send_invoice_email' => '1',
        ]);

        $resp->assertRedirect();
        $this->assertDatabaseHas('tb_invoices', [
            'order_id'        => $order->id,
            'email_requested' => true,
        ]);
        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    /** @test */
    public function approve_dispatches_email_when_requested(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'pending');
        $invoice = $this->makeInvoice($order, $payment, true, 'diterbitkan');

        $this->actingAs($this->manager)
            ->post(route('payment.approve', $payment->id))
            ->assertRedirect();

        Queue::assertPushed(SendInvoiceJob::class);
        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'lunas']);
    }

    /** @test */
    public function approve_does_not_email_when_not_requested(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'pending');
        $this->makeInvoice($order, $payment, false, 'diterbitkan');

        $this->actingAs($this->manager)->post(route('payment.approve', $payment->id))->assertRedirect();

        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    /** @test */
    public function approve_is_blocked_when_already_approved(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'approved');
        $this->makeInvoice($order, $payment, true, 'lunas');

        $this->actingAs($this->manager)->post(route('payment.approve', $payment->id))->assertRedirect();

        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    /** @test */
    public function manager_can_edit_pending_payment_and_order_status_recomputes(): void
    {
        $order   = $this->makeOrder(); // cost 1_000_000
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');

        $this->actingAs($this->manager)->put(route('payment.update', $payment->id), [
            'amount'       => 1000000,
            'payment_type' => 'lunas',
            'paid_at'      => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_payments', ['id' => $payment->id, 'amount' => 1000000, 'payment_type' => 'lunas']);
        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'status' => 'lunas']); // remaining <= 0
    }

    /** @test */
    public function approved_payment_cannot_be_edited(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'approved');

        $this->actingAs($this->manager)->put(route('payment.update', $payment->id), [
            'amount'       => 999,
            'payment_type' => 'dp',
            'paid_at'      => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_payments', ['id' => $payment->id, 'amount' => 300000]); // unchanged
    }

    /** @test */
    public function marketing_cannot_edit_payment(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');

        $this->actingAs($this->marketing)->put(route('payment.update', $payment->id), [
            'amount'       => 1,
            'payment_type' => 'dp',
            'paid_at'      => now()->toDateString(),
        ])->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function detail_order_page_shows_invoice_table_and_payment_actions(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');
        $invoice = $this->makeInvoice($order, $payment, false, 'diterbitkan');

        $resp = $this->actingAs($this->manager)->get(route('order.book.show', $order->code_order));

        $resp->assertOk();
        $resp->assertSee('Daftar Invoice');
        $resp->assertSee($invoice->invoice_no);
        $resp->assertSee('Download Invoice');
        $resp->assertSee('Edit Pembayaran'); // edit modal title shown for pending payment (manager)
    }
}
