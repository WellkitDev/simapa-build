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
    public function the_same_client_is_matched_by_email_not_by_name(): void
    {
        $manager = $this->user('manager');

        // Email sama, nama beda → satu baris master (email yang jadi kuncinya).
        $this->actingAs($manager)->post(route('service.invoice.store'), $this->payload());
        $this->actingAs($manager)->post(route('service.invoice.store'), $this->payload(['client_name' => 'Nama Lain']));
        $this->assertSame(1, ServiceClient::count());

        // Nama sama, email beda → dua baris. Kalau pencocokannya jatuh ke nama,
        // assertion inilah yang merah.
        $this->actingAs($manager)->post(route('service.invoice.store'), $this->payload(['client_email' => 'lain@unbari.ac.id']));
        $this->assertSame(2, ServiceClient::count());
    }

    /** @test */
    public function discount_is_persisted_and_subtracted_from_the_total(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload(['discount' => '150.000']));

        $inv = ServiceInvoice::first();
        $this->assertEquals(150000, $inv->discount);
        $this->assertEquals(1650000, $inv->subtotal);
        $this->assertEquals(1500000, $inv->total);
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
    public function every_screen_renders_for_manager_and_superadmin(): void
    {
        $inv = ServiceInvoice::factory()->create();
        $inv->items()->create(['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => 750000, 'subtotal' => 750000]);
        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 250000]);
        $inv->logs()->create(['event' => 'created']);
        $inv->recalcTotals();

        // Blade yang gagal DIKOMPILASI hanya terlihat kalau view-nya benar-benar
        // dirender — tes store() cuma menegaskan redirect dan tak pernah mengikutinya.
        // Superadmin ikut diuji karena Gate::before membuatnya menempuh jalur berbeda
        // dari manager (aturan global 10).
        foreach (['manager', 'superadmin'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('service.invoice.index'))->assertOk();
            $this->actingAs($user)->get(route('service.invoice.create'))->assertOk();
            $this->actingAs($user)->get(route('service.invoice.show', $inv->id))
                ->assertOk()
                ->assertSee($inv->invoice_no);
        }
    }

    /** @test */
    public function a_bounced_form_keeps_the_items_the_operator_typed(): void
    {
        // Kunci berlubang meniru operator yang menghapus baris di tengah.
        $items = [
            1 => ['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => '750000'],
            2 => ['name' => 'Maintenance',   'qty' => 3, 'unit_price' => '300000'],
        ];

        $this->actingAs($this->user('manager'))
            ->from(route('service.invoice.create'))
            ->post(route('service.invoice.store'), $this->payload(['items' => $items, 'discount' => '99000000']))
            ->assertSessionHasErrors('discount');

        $content = $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.create'))
            ->assertOk()
            ->assertSee('Instalasi OJS')
            ->assertSee('Maintenance')
            ->getContent();

        // assertSee() di atas TIDAK CUKUP untuk membuktikan baris itemnya benar-benar
        // muncul di form: nama layanan selalu ada di HTML mentah lewat JSON yang
        // ditanam @json() di dalam <script>, baik itu dikodekan sebagai array MAUPUN
        // objek JS — PHPUnit tak pernah mengeksekusi JavaScript, jadi ia tak bisa
        // melihat bahwa existingItems.length bernilai undefined pada objek dan
        // cabang else (satu addRow() kosong) itulah yang berjalan di browser
        // sungguhan. Yang BISA dibuktikan lewat HTML mentah adalah BENTUK datanya:
        // kalau existingItems tercetak sebagai objek ({"1":...,"2":...}, kunci
        // berlubang dari items[1]/items[2]) alih-alih larik ([...]), maka baris
        // yang sudah diketik operator lenyap di layar walau teksnya tetap ada di
        // HTML yang tak pernah dijalankan mesin JS manapun di sini.
        preg_match('/const existingItems = (.+);\s*$/m', $content, $m);
        $this->assertNotEmpty($m, 'existingItems tidak ditemukan di HTML.');

        $decoded = json_decode($m[1], true);
        $this->assertTrue(
            array_is_list($decoded),
            'existingItems harus larik JS ([...]), bukan objek ({...}) — kunci berlubang '
            . 'membuat json_encode memancarkan objek dan existingItems.length jadi undefined.'
        );
        $this->assertCount(2, $decoded);
        $this->assertSame('Instalasi OJS', $decoded[0]['name'] ?? null);
        $this->assertSame('Maintenance', $decoded[1]['name'] ?? null);
    }
}
