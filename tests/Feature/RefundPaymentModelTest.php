<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundPaymentModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function payment_stores_refund_metadata_and_refunded_by(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 150000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Batal cetak', 'refund_method' => 'transfer', 'refund_account' => 'BCA 123', 'refunded_by' => $user->id,
        ])->refresh();

        $this->assertSame('Batal cetak', $payment->refund_reason);
        $this->assertSame('transfer', $payment->refund_method);
        $this->assertSame($user->id, $payment->refundedBy->id);
    }
}
