<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function refund_pdf_data_includes_history_and_mail_subject(): void
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 200000, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 300000, 'status' => 'paid', 'paid_at' => '2026-06-03']);
        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 250000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Batal', 'refund_method' => 'transfer',
        ]);

        $data = RefundPdfData::for($refund);
        $this->assertEquals(250000.0, $data['refundAmount']);
        $this->assertEquals(500000.0, $data['paidIn']);
        $this->assertCount(3, $data['payments']);
        $this->assertSame('Batal', $data['reason']);

        $mail = new RefundMail($refund, $data, 'PDFBYTES');
        $this->assertStringContainsString('Bukti Refund', $mail->envelope()->subject);
        $this->assertStringContainsString($order->code_order, $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }
}
