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
    public function exactly_paid_invoice_with_odd_cents_is_lunas_not_dp(): void
    {
        // Jebakan float sungguhan: (float) total menghasilkan ...9900000002
        // sementara (float) paid menghasilkan ...9899999999, sehingga perbandingan
        // mentah `paid >= total` gagal dan invoice yang sudah lunas tersangkut di
        // 'dp' SELAMANYA — sementara `remaining` tersimpan 0.00 karena kolomnya
        // decimal(15,2). Barisnya jadi menyangkal dirinya sendiri.
        $inv = ServiceInvoice::factory()->create(['discount' => 636766.00]);
        $inv->items()->create([
            'name' => 'Hosting prorata', 'qty' => 1,
            'unit_price' => 2548189.99, 'subtotal' => 2548189.99,
        ]);
        $inv->payments()->create([
            'paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1911423.99,
        ]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(1911423.99, $inv->total);
        $this->assertEquals(1911423.99, $inv->paid_total);
        $this->assertEquals(0, $inv->remaining);
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertFalse($inv->isOverpaid());
    }

    /** @test */
    public function zero_total_invoice_stays_belum_until_money_arrives(): void
    {
        $inv = ServiceInvoice::factory()->create([
            'discount' => 999999999,
            'due_at'   => today()->subDays(10)->toDateString(),
        ]);
        $inv->items()->create(['name' => 'X', 'qty' => 1, 'unit_price' => 100000, 'subtotal' => 100000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(0, $inv->total);
        $this->assertEquals(0, $inv->remaining);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertFalse($inv->isOverdue(), 'Utang nol tak pernah telat, walau jatuh temponya lewat.');

        // Cabang sebaliknya: total nol tapi ada uang masuk = lebih bayar.
        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 50000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(-50000, $inv->remaining);
        $this->assertTrue($inv->isOverpaid());
    }
}
