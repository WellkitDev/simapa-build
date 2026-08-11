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
}
