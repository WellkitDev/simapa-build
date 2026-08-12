<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function manager(): User
    {
        $u = User::factory()->create();
        $u->assignRole('manager');
        return $u;
    }

    /** @test */
    public function changing_catalog_price_or_client_details_never_touches_issued_invoices(): void
    {
        $catalog = ServiceCatalog::factory()->create(['name' => 'Instalasi OJS Basic', 'price' => 500000]);
        $client  = ServiceClient::factory()->create(['name' => 'Dr. Sartika', 'institution' => 'UNBARI']);

        $this->actingAs($this->manager())->post(route('service.invoice.store'), [
            'service_client_id'  => $client->id,
            'client_name'        => 'Dr. Sartika',
            'client_institution' => 'UNBARI',
            'client_email'       => 'lama@unbari.ac.id',
            'issued_at'          => '2026-08-11',
            'items'              => [[
                'service_catalog_id' => $catalog->id,
                'name'               => 'Instalasi OJS Basic',
                'qty'                => 1,
                'unit_price'         => '500000',
            ]],
        ]);

        $invoice = ServiceInvoice::first();

        $catalog->update(['name' => 'Instalasi OJS Basic (baru)', 'price' => 900000]);
        $client->update(['institution' => 'Universitas Batanghari', 'email' => 'baru@unbari.ac.id']);

        $invoice->refresh();
        $item = $invoice->items->first();

        $this->assertSame('Instalasi OJS Basic', $item->name);
        $this->assertEquals(500000, $item->unit_price);
        $this->assertEquals(500000, $invoice->total);
        $this->assertSame('UNBARI', $invoice->client_institution);
        $this->assertSame('lama@unbari.ac.id', $invoice->client_email);
    }

    /** @test */
    public function deleting_catalog_and_client_leaves_the_invoice_printable(): void
    {
        $catalog = ServiceCatalog::factory()->create();
        $client  = ServiceClient::factory()->create();

        $invoice = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);
        $invoice->items()->create([
            'service_catalog_id' => $catalog->id,
            'name' => 'Instalasi OJS Basic', 'qty' => 1, 'unit_price' => 500000, 'subtotal' => 500000,
        ]);
        $invoice->recalcTotals();

        $manager = $this->manager();
        $this->actingAs($manager)->delete(route('service.client.destroy', $client->id));
        $this->actingAs($manager)->delete(route('service.catalog.destroy', $catalog->id));

        $invoice->refresh();
        $this->assertNull($invoice->service_client_id);
        $this->assertSame('Instalasi OJS Basic', $invoice->items->first()->name);

        $this->actingAs($manager)->get(route('service.invoice.pdf', $invoice->id))->assertOk();
    }

    /**
     * T-ISO — penjaga keputusan produk: modul ini SENGAJA tidak tersambung ke
     * keuangan/order/payment. Kalau tes ini merah, seseorang menyambungkannya;
     * itu butuh keputusan produk baru, bukan tambalan supaya tesnya hijau.
     *
     * @test
     */
    public function service_invoices_never_leak_into_orders_payments_or_cash(): void
    {
        $before = [
            'tb_orders'       => DB::table('tb_orders')->count(),
            'tb_payments'     => DB::table('tb_payments')->count(),
            'tb_invoices'     => DB::table('tb_invoices')->count(),
            'tb_cash_entries' => DB::table('tb_cash_entries')->count(),
        ];

        $manager = $this->manager();

        $this->actingAs($manager)->post(route('service.invoice.store'), [
            'client_name' => 'Klien Jasa',
            'client_email' => 'klien@example.test',
            'issued_at'   => '2026-08-11',
            'items'       => [['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => '2500000']],
        ]);

        $invoice = ServiceInvoice::first();

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $invoice->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '1000000', 'method' => 'transfer',
        ]);
        $this->actingAs($manager)->post(route('service.invoice.status', $invoice->id), ['work_status' => 'selesai']);

        // Invoice jasanya sendiri memang tercatat...
        $this->assertEquals(1000000, $invoice->fresh()->paid_total);

        // ...tapi TIDAK satu baris pun bocor ke modul lain.
        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::table($table)->count(),
                "Modul layanan menulis ke {$table} — itu melanggar keputusan standalone di spec §2."
            );
        }
    }
}
