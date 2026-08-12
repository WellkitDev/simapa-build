<?php

namespace Tests\Feature;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function invoice_relates_to_client_items_payments_and_logs(): void
    {
        $client  = ServiceClient::factory()->create();
        $invoice = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);

        $invoice->items()->create([
            'name' => 'Instalasi OJS Basic', 'qty' => 1,
            'unit_price' => 500000, 'subtotal' => 500000, 'position' => 0,
        ]);
        $invoice->payments()->create([
            'paid_at' => now()->toDateString(), 'type' => 'dp',
            'amount' => 200000, 'method' => 'transfer',
        ]);
        $invoice->logs()->create(['event' => 'created']);

        $invoice->refresh();
        $this->assertSame($client->id, $invoice->client->id);
        $this->assertCount(1, $invoice->items);
        $this->assertCount(1, $invoice->payments);
        $this->assertCount(1, $invoice->logs);
        $this->assertCount(1, $client->invoices);
    }

    /** @test */
    public function deleting_invoice_cascades_items_and_payments(): void
    {
        $invoice = ServiceInvoice::factory()->create();
        $invoice->items()->create(['name' => 'X', 'qty' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);
        $invoice->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 500]);

        $invoice->forceDelete();

        $this->assertDatabaseCount('tb_service_invoice_items', 0);
        $this->assertDatabaseCount('tb_service_invoice_payments', 0);
    }

    /** @test */
    public function status_constants_are_defined(): void
    {
        $this->assertSame('Belum Dikerjakan', ServiceInvoice::WORK_STATUS['belum']);
        $this->assertSame('Dibatalkan', ServiceInvoice::WORK_STATUS['batal']);
        $this->assertSame('Lunas', ServiceInvoice::PAYMENT_STATUS['lunas']);
    }

    /** @test */
    public function derived_money_columns_are_not_mass_assignable(): void
    {
        $inv = ServiceInvoice::factory()->create();

        $inv->update([
            'subtotal'   => 999999,
            'total'      => 999999,
            'paid_total' => 999999,
            'remaining'  => 999999,
        ]);

        $inv->refresh();
        $this->assertEquals(0, $inv->subtotal);
        $this->assertEquals(0, $inv->total);
        $this->assertEquals(0, $inv->paid_total);
        $this->assertEquals(0, $inv->remaining);
    }

    /** @test */
    public function overdue_starts_the_day_after_due_date_and_only_when_money_is_owed(): void
    {
        // remaining tidak fillable (lihat $fillable), jadi diisi lewat forceFill —
        // yang sekaligus jalur yang dipakai recalcTotals() di Task 3.
        $owed = ServiceInvoice::factory()->create(['due_at' => today()->toDateString()]);
        $owed->forceFill(['remaining' => 500000])->save();

        $this->assertFalse($owed->isOverdue(), 'Hari jatuh tempo masih hak klien, belum telat.');

        $owed->update(['due_at' => today()->subDay()->toDateString()]);
        $this->assertTrue($owed->fresh()->isOverdue());

        $settled = ServiceInvoice::factory()->create(['due_at' => today()->subDays(30)->toDateString()]);
        $this->assertFalse($settled->isOverdue(), 'Tanpa sisa tagihan tidak ada yang telat.');

        $cancelled = ServiceInvoice::factory()->create([
            'due_at'      => today()->subDays(30)->toDateString(),
            'work_status' => 'batal',
        ]);
        $cancelled->forceFill(['remaining' => 500000])->save();
        $this->assertFalse($cancelled->isOverdue(), 'Invoice batal tidak pernah telat.');
    }
}
