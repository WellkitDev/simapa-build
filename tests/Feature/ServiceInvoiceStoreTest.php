<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'client_name'        => 'Dr. Sartika',
            'client_institution' => 'Universitas Batanghari',
            'client_email'       => 'jurnal@unbari.ac.id',
            'client_phone'       => '081234567890',
            'issued_at'          => '2026-08-11',
            'due_at'             => '2026-08-25',
            'discount'           => '0',
            'items'              => [
                ['name' => 'Instalasi + Konfigurasi OJS', 'qty' => 1, 'unit_price' => '750.000'],
                ['name' => 'Maintenance Bulanan',         'qty' => 3, 'unit_price' => '300.000'],
            ],
        ], $override);
    }

    /** @test */
    public function store_creates_invoice_with_number_items_and_totals(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload())
            ->assertRedirect();

        $inv = ServiceInvoice::first();
        $this->assertNotNull($inv);
        $this->assertSame('INV-JS-202608-0001', $inv->invoice_no);
        $this->assertCount(2, $inv->items);
        $this->assertEquals(1650000, $inv->subtotal);   // pemisah ribuan dibuang
        $this->assertEquals(1650000, $inv->total);
        $this->assertEquals(1650000, $inv->remaining);
        $this->assertSame('belum', $inv->work_status);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertSame('created', $inv->logs->first()->event);
    }

    /** @test */
    public function typing_a_new_client_creates_a_master_row_and_snapshots_it(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload());

        $client = ServiceClient::firstWhere('email', 'jurnal@unbari.ac.id');
        $this->assertNotNull($client, 'Klien baru harus tersimpan sebagai master.');

        $inv = ServiceInvoice::first();
        $this->assertSame($client->id, $inv->service_client_id);
        $this->assertSame('Dr. Sartika', $inv->client_name);
        $this->assertSame('Universitas Batanghari', $inv->client_institution);
    }

    /** @test */
    public function typing_the_same_client_twice_reuses_the_master_row(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->post(route('service.invoice.store'), $this->payload());
        $this->actingAs($manager)->post(route('service.invoice.store'), $this->payload());

        // Dicocokkan lewat email. Tanpa itu, operator yang mengetik klien yang sama
        // berkali-kali memecah riwayat pekerjaannya menjadi beberapa baris master
        // dan halaman detail klien hanya menampilkan sebagian invoice-nya.
        $this->assertSame(1, ServiceClient::count());
        $this->assertSame(2, ServiceClient::first()->invoices()->count());
    }

    /** @test */
    public function picking_an_existing_client_does_not_duplicate_the_master_row(): void
    {
        $client = ServiceClient::factory()->create(['name' => 'Klien Lama']);

        $this->actingAs($this->user('manager'))->post(
            route('service.invoice.store'),
            $this->payload(['service_client_id' => $client->id, 'client_name' => 'Klien Lama'])
        );

        $this->assertSame(1, ServiceClient::count());
        $this->assertSame($client->id, ServiceInvoice::first()->service_client_id);
    }

    /** @test */
    public function catalog_id_is_only_a_trace_the_name_and_price_are_copied(): void
    {
        $catalog = ServiceCatalog::factory()->create(['name' => 'Instalasi OJS Basic', 'price' => 500000]);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload([
            'items' => [[
                'service_catalog_id' => $catalog->id,
                'name'               => 'Instalasi OJS Basic (rumit)',
                'qty'                => 1,
                'unit_price'         => '850000',
            ]],
        ]));

        $item = ServiceInvoice::first()->items->first();
        $this->assertSame($catalog->id, $item->service_catalog_id);
        $this->assertSame('Instalasi OJS Basic (rumit)', $item->name);   // nama ditimpa operator
        $this->assertEquals(850000, $item->unit_price);                  // harga ditimpa operator
    }

    /** @test */
    public function at_least_one_item_is_required(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, ServiceInvoice::count());
    }

    /** @test */
    public function discount_cannot_exceed_subtotal(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload(['discount' => '99.000.000']))
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, ServiceInvoice::count());
    }

    /** @test */
    public function fractional_qty_survives_normalisation(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload([
            'items' => [['name' => 'Maintenance', 'qty' => '1.5', 'unit_price' => '300.000']],
        ]));

        $item = ServiceInvoice::first()->items->first();
        $this->assertEquals(1.5, $item->qty);        // BUKAN 15 — qty tidak boleh ikut dibuang titiknya
        $this->assertEquals(450000, $item->subtotal);
    }

    /** @test */
    public function index_and_create_render_for_manager(): void
    {
        ServiceInvoice::factory()->create();

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.index'))->assertOk();
        $this->actingAs($manager)->get(route('service.invoice.create'))->assertOk();
    }
}
