<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class RefundNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function refund_issued_notifies_superadmin_but_not_actor(): void
    {
        $recipient = User::factory()->create(); $recipient->assignRole('superadmin');
        $actor = User::factory()->create(); $actor->assignRole('superadmin');
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 200000, 'status' => 'paid', 'paid_at' => '2026-06-05']);

        app(Notifier::class)->refundIssued($payment, $actor);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $actor->id]);
    }
}
