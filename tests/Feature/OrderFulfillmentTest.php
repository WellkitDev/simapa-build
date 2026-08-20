<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function order_baru_berstatus_berjalan(): void
    {
        $order = Order::factory()->create();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($order->fresh()->completed_at);
    }
}
