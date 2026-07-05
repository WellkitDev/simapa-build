<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function invoice_stores_refund_fields_and_links_payment(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 150000, 'status' => 'paid', 'paid_at' => '2026-06-05']);
        $invoice = Invoice::factory()->create([
            'order_id' => $order->id, 'status' => 'refund',
            'refund_reason' => 'Batal cetak', 'refund_method' => 'transfer', 'refund_account' => 'BCA 123',
            'refund_payment_id' => $payment->id,
        ]);
        $invoice->refresh();

        $this->assertSame('Batal cetak', $invoice->refund_reason);
        $this->assertSame('transfer', $invoice->refund_method);
        $this->assertSame($payment->id, $invoice->refundPayment->id);
        $this->assertEquals(150000, $invoice->refundPayment->amount);
    }
}
