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

        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Instalasi OJS Basic', 'price' => 500000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Fix Error Sedang', 'price' => 500000.00, 'price_max' => 1000000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Paket Enterprise', 'category' => 'bundle']);

        // Kategori Turnitin/plagiasi sengaja kosong — tarifnya belum ditetapkan.
        $this->assertDatabaseMissing('tb_service_catalogs', ['category' => 'similarity']);

        // Idempoten: dijalankan dua kali tidak menggandakan baris.
        $before = ServiceCatalog::count();
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);
        $this->assertSame($before, ServiceCatalog::count());
    }
}
