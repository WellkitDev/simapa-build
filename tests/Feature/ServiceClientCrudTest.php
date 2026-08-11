<?php

namespace Tests\Feature;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceClientCrudTest extends TestCase
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
    public function manager_can_create_update_and_delete_a_client(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('service.client.index'))->assertOk();

        $this->actingAs($manager)->post(route('service.client.store'), [
            'name' => 'Dr. Sartika', 'institution' => 'Universitas Batanghari',
            'email' => 'jurnal@unbari.ac.id', 'phone' => '081234567890',
        ])->assertRedirect(route('service.client.index'));

        $client = ServiceClient::firstWhere('email', 'jurnal@unbari.ac.id');
        $this->assertNotNull($client);
        $this->assertSame($manager->id, $client->created_by);

        $this->actingAs($manager)->put(route('service.client.update', $client->id), [
            'name' => 'Dr. Sartika', 'institution' => 'UNBARI',
            'email' => 'jurnal@unbari.ac.id',
        ])->assertRedirect(route('service.client.index'));

        $client->refresh();
        $this->assertSame('UNBARI', $client->institution);
        $this->assertSame($manager->id, $client->updated_by);

        $this->actingAs($manager)->delete(route('service.client.destroy', $client->id))->assertRedirect();
        $this->assertSoftDeleted('tb_service_clients', ['id' => $client->id]);
    }

    /** @test */
    public function client_detail_lists_that_clients_invoices(): void
    {
        $client = ServiceClient::factory()->create();
        ServiceInvoice::factory()->count(2)->create(['service_client_id' => $client->id]);
        ServiceInvoice::factory()->create();   // klien lain

        $this->actingAs($this->user('manager'))
            ->get(route('service.client.show', $client->id))
            ->assertOk()
            ->assertViewHas('client', fn ($c) => $c->invoices->count() === 2);
    }

    /** @test */
    public function deleting_a_client_leaves_its_invoices_intact(): void
    {
        $client  = ServiceClient::factory()->create();
        $invoice = ServiceInvoice::factory()->create([
            'service_client_id' => $client->id,
            'client_name'       => 'Nama Tersalin',
        ]);

        $this->actingAs($this->user('manager'))->delete(route('service.client.destroy', $client->id));

        $invoice->refresh();
        $this->assertNull($invoice->service_client_id);       // FK dilepas
        $this->assertSame('Nama Tersalin', $invoice->client_name);   // snapshot utuh
    }

    /** @test */
    public function other_roles_are_locked_out(): void
    {
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.client.index'))
                ->assertForbidden();
        }
    }

    /** @test */
    public function a_rejected_save_is_shown_to_the_operator(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)
            ->from(route('service.client.index'))
            ->post(route('service.client.store'), ['name' => 'Tanpa Email Valid', 'email' => 'bukan-email'])
            ->assertRedirect(route('service.client.index'));

        // Galat yang cuma sampai ke sesi tidak berguna: layouts/master tidak
        // merender $errors, jadi view-nya harus menampilkannya sendiri.
        $this->actingAs($manager)
            ->get(route('service.client.index'))
            ->assertOk()
            ->assertSee('Data belum tersimpan.');
    }
}
