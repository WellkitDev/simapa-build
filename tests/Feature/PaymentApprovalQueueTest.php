<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\PaymentApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PaymentApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function paymentWith(string $approvalStatus, string $paymentStatus, string $inv): Payment
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 100000, 'status' => $paymentStatus, 'paid_at' => now()]);
        Invoice::factory()->create(['order_id' => $order->id, 'payment_id' => $payment->id, 'invoice_no' => $inv]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approvalStatus, 'note' => $approvalStatus === 'rejected' ? 'Data tidak valid' : null]);

        return $payment;
    }

    /** @test */
    public function index_splits_payments_into_pending_approved_rejected_tables(): void
    {
        $pending  = $this->paymentWith('pending', 'pending', 'INV-PENDING');
        $approved = $this->paymentWith('approved', 'paid', 'INV-APPROVED');
        $rejected = $this->paymentWith('rejected', 'rejected', 'INV-REJECTED');

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        $res = $this->actingAs($sa)->get(route('payment.index'));
        $res->assertOk()
            ->assertSee('Pembayaran Perlu Disetujui')
            ->assertSee('Pembayaran Disetujui')
            ->assertSee('Pembayaran Ditolak')
            ->assertSee('INV-PENDING')->assertSee('INV-APPROVED')->assertSee('INV-REJECTED');

        // pemisahan koleksi per status
        $this->assertTrue($res->viewData('pending')->contains('id', $pending->id));
        $this->assertFalse($res->viewData('pending')->contains('id', $approved->id));
        $this->assertFalse($res->viewData('pending')->contains('id', $rejected->id));
        $this->assertTrue($res->viewData('approved')->contains('id', $approved->id));
        $this->assertTrue($res->viewData('rejected')->contains('id', $rejected->id));

        // tombol Setujui hanya untuk yang pending
        $res->assertSee(route('payment.approve', $pending->id), false);
        $res->assertDontSee(route('payment.approve', $approved->id), false);
    }
}
