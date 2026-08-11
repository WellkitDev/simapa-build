<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInvoiceCalcTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithItems(float $discount = 0): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create(['discount' => $discount]);
        $inv->items()->create(['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => 750000, 'subtotal' => 750000, 'position' => 0]);
        $inv->items()->create(['name' => 'Maintenance',   'qty' => 3, 'unit_price' => 300000, 'subtotal' => 900000, 'position' => 1]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function subtotal_and_total_follow_items_and_discount(): void
    {
        $inv = $this->invoiceWithItems(discount: 150000);

        $this->assertEquals(1650000, $inv->subtotal);
        $this->assertEquals(1500000, $inv->total);
        $this->assertEquals(0,       $inv->paid_total);
        $this->assertEquals(1500000, $inv->remaining);
        $this->assertSame('belum',   $inv->payment_status);
    }

    /** @test */
    public function payment_status_walks_belum_to_dp_to_lunas(): void
    {
        $inv = $this->invoiceWithItems();
        $this->assertSame('belum', $inv->payment_status);

        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 650000]);
        $inv->recalcTotals();
        $inv->refresh();
        $this->assertSame('dp', $inv->payment_status);
        $this->assertEquals(1000000, $inv->remaining);

        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1000000]);
        $inv->recalcTotals();
        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(0, $inv->remaining);
    }

    /** @test */
    public function deleting_a_payment_rolls_the_totals_back(): void
    {
        $inv = $this->invoiceWithItems();
        $pay = $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 650000]);
        $inv->recalcTotals();

        $pay->delete();
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(0,       $inv->paid_total);
        $this->assertEquals(1650000, $inv->remaining);
        $this->assertSame('belum',   $inv->payment_status);
    }

    /** @test */
    public function overpayment_is_kept_visible_not_discarded(): void
    {
        $inv = $this->invoiceWithItems();
        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1700000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(-50000, $inv->remaining);
        $this->assertTrue($inv->isOverpaid());
        $this->assertEquals(50000, $inv->overpaidAmount());
    }

    /** @test */
    public function discount_larger_than_subtotal_clamps_total_at_zero(): void
    {
        $inv = ServiceInvoice::factory()->create(['discount' => 999999999]);
        $inv->items()->create(['name' => 'X', 'qty' => 1, 'unit_price' => 100000, 'subtotal' => 100000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(0, $inv->total);
    }
}
