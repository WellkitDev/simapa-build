<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function refund_pdf_data_and_mail_subject(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 250000, 'status' => 'paid', 'paid_at' => '2026-06-05']);
        $invoice = Invoice::factory()->create([
            'order_id' => $order->id, 'status' => 'refund', 'invoice_no' => 'INV-RF-1',
            'refund_reason' => 'Batal', 'refund_method' => 'transfer', 'refund_payment_id' => $payment->id,
        ]);

        $data = RefundPdfData::for($invoice);
        $this->assertEquals(250000.0, $data['amount']);
        $this->assertSame('Batal', $data['reason']);
        $this->assertSame('transfer', $data['method']);

        $mail = new RefundMail($invoice, $data, 'PDFBYTES');
        $this->assertStringContainsString('Bukti Refund', $mail->envelope()->subject);
        $this->assertStringContainsString('INV-RF-1', $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }
}
