<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceCatalogCrudTest extends TestCase
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

    /** @test */
    public function manager_can_list_create_update_and_delete(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('service.catalog.index'))->assertOk();

        $this->actingAs($manager)->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Perbaikan SMTP',
            'price' => '350.000', 'unit' => 'paket',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog = ServiceCatalog::firstWhere('name', 'Perbaikan SMTP');
        $this->assertNotNull($catalog);
        $this->assertEquals(350000, $catalog->price);   // pemisah ribuan dibuang

        $this->actingAs($manager)->put(route('service.catalog.update', $catalog->id), [
            'category' => 'perbaikan', 'name' => 'Perbaikan SMTP',
            'price' => '400000', 'price_max' => '600000', 'is_active' => '0',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog->refresh();
        $this->assertEquals(600000, $catalog->price_max);
        $this->assertFalse($catalog->is_active);

        $this->actingAs($manager)->delete(route('service.catalog.destroy', $catalog->id))->assertRedirect();
        $this->assertSoftDeleted('tb_service_catalogs', ['id' => $catalog->id]);
    }

    /** @test */
    public function price_max_must_not_be_below_price(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Salah Kisaran',
            'price' => '1000000', 'price_max' => '500000',
        ])->assertSessionHasErrors('price_max');

        $this->assertDatabaseMissing('tb_service_catalogs', ['name' => 'Salah Kisaran']);
    }

    /** @test */
    public function other_roles_are_locked_out(): void
    {
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.catalog.index'))
                ->assertForbidden();
        }
    }

    /** @test */
    public function seeder_fills_the_published_price_list(): void
    {
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        // Jumlah dipatok eksplisit. Tanpa ini, seeder yang kehilangan 27 dari 30
        // barisnya tetap lolos selama tiga baris yang kebetulan disebut di bawah
        // masih ada — dan seluruh gunanya task ini adalah menyalin daftar harga
        // klien dengan setia.
        $this->assertSame(30, ServiceCatalog::count());

        $perCategory = ServiceCatalog::get()->groupBy('category')->map->count()->all();
        $this->assertSame([
            'instalasi' => 5, 'perbaikan' => 7, 'upgrade' => 4, 'desain' => 4,
            'hosting' => 4, 'maintenance' => 3, 'bundle' => 3,
        ], $perCategory);

        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Instalasi OJS Basic', 'price' => 500000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Fix Error Sedang', 'price' => 500000.00, 'price_max' => 1000000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Paket Enterprise', 'category' => 'bundle']);

        // Kategori Turnitin/plagiasi sengaja kosong — tarifnya belum ditetapkan.
        $this->assertDatabaseMissing('tb_service_catalogs', ['category' => 'similarity']);

        // Idempoten: dijalankan dua kali tidak menggandakan baris.
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);
        $this->assertSame(30, ServiceCatalog::count());
    }

    /** @test */
    public function reseeding_does_not_resurrect_a_row_the_operator_deleted(): void
    {
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        ServiceCatalog::firstWhere('name', 'Setup Multi Jurnal')->delete();
        $this->assertSame(29, ServiceCatalog::count());

        // Lookup-nya memakai withTrashed, jadi baris yang sudah dihapus tetap
        // dikenali dan tidak dibuat ulang sebagai duplikat.
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        $this->assertSame(29, ServiceCatalog::count());
        $this->assertSame(30, ServiceCatalog::withTrashed()->count());
    }

    /** @test */
    public function reopening_and_saving_a_row_unchanged_keeps_its_price(): void
    {
        $manager = $this->user('manager');
        $catalog = ServiceCatalog::factory()->create([
            'name' => 'Perbaikan SMTP', 'price' => 350000, 'price_max' => 1000000,
        ]);

        // Ambil payload PERSIS seperti yang ditanam view ke tombol Edit, bukan
        // yang diketik tangan — jebakannya justru ada di payload itu.
        $html = $this->actingAs($manager)->get(route('service.catalog.index'))->getContent();
        $this->assertSame(1, preg_match('/data-catalog="([^"]*)"/', $html, $m));
        $payload = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        // Kalau ini "350000.00" dan bukan 350000, pembersih pemisah ribuan di
        // controller akan membuang titiknya dan harganya jadi 35.000.000 hanya
        // karena barisnya dibuka lalu disimpan tanpa diubah sama sekali.
        $this->assertSame(350000, $payload['price']);
        $this->assertSame(1000000, $payload['price_max']);

        $this->actingAs($manager)->put(route('service.catalog.update', $catalog->id), [
            'category'  => $payload['category'],
            'name'      => $payload['name'],
            'price'     => (string) $payload['price'],
            'price_max' => (string) $payload['price_max'],
            'is_active' => '1',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog->refresh();
        $this->assertEquals(350000, $catalog->price);
        $this->assertEquals(1000000, $catalog->price_max);
    }

    /** @test */
    public function a_rejected_save_is_shown_to_the_operator(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)
            ->from(route('service.catalog.index'))
            ->post(route('service.catalog.store'), [
                'category' => 'perbaikan', 'name' => 'Salah Kisaran',
                'price' => '1000000', 'price_max' => '500000',
            ])
            ->assertRedirect(route('service.catalog.index'));

        // Sesi punya galatnya belum cukup: layouts/master tidak merender $errors,
        // jadi tanpa blok galat di view-nya operator tak melihat apa pun dan
        // mengira aplikasinya macet.
        $this->actingAs($manager)
            ->get(route('service.catalog.index'))
            ->assertOk()
            ->assertSee('Data belum tersimpan.');
    }

    /** @test */
    public function an_empty_is_active_value_does_not_explode(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Checkbox Kosong',
            'price' => '350000', 'is_active' => '',
        ])->assertRedirect(route('service.catalog.index'));

        $this->assertFalse(ServiceCatalog::firstWhere('name', 'Checkbox Kosong')->is_active);
    }
}
