<?php

namespace Tests\Feature;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // Penghapusannya harus LUNAK. Tanpa dua assertion ini, mengganti seluruh
        // destroy() dengan forceDelete() polos tetap membuat tes ini hijau — FK
        // nullOnDelete di basis data ikut menyalakan kolomnya jadi null, dan
        // snapshot-nya memang tak pernah tersentuh. Yang hilang justru kemampuan
        // menelusuri klien yang sudah dihapus.
        $this->assertSoftDeleted('tb_service_clients', ['id' => $client->id]);
        $this->assertSame(1, ServiceClient::withTrashed()->whereKey($client->id)->count());
    }

    /** @test */
    public function deleting_a_client_also_unlinks_its_soft_deleted_invoices(): void
    {
        $client  = ServiceClient::factory()->create();
        $trashed = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);
        $trashed->delete();

        $this->actingAs($this->user('manager'))->delete(route('service.client.destroy', $client->id));

        // Global scope SoftDeletes akan melewatkan baris ini kalau pelepasan FK-nya
        // memakai Eloquent, meninggalkan tautan menggantung ke klien yang tak ada.
        $this->assertNull(ServiceInvoice::withTrashed()->find($trashed->id)->service_client_id);
    }

    /** @test */
    public function unlinking_invoices_does_not_pretend_they_were_edited(): void
    {
        $client  = ServiceClient::factory()->create();
        $invoice = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);

        $stamp = '2026-01-01 08:00:00';
        DB::table('tb_service_invoices')->where('id', $invoice->id)->update(['updated_at' => $stamp]);

        $this->actingAs($this->user('manager'))->delete(route('service.client.destroy', $client->id));

        // Isi invoice tidak berubah, jadi jejak "terakhir diubah" tidak boleh ikut
        // maju — kalau ia maju, barisnya mengaku disunting oleh orang yang cuma
        // menghapus kliennya, sementara updated_by masih menunjuk penyunting lama.
        $this->assertSame($stamp, DB::table('tb_service_invoices')->where('id', $invoice->id)->value('updated_at'));
    }

    /** @test */
    public function superadmin_can_open_a_client_detail_page(): void
    {
        $client = ServiceClient::factory()->create();
        ServiceInvoice::factory()->create(['service_client_id' => $client->id]);

        // BUKAN pengulangan tes manager. Gate::before (AuthServiceProvider) meloloskan
        // superadmin untuk ability APA PUN, termasuk permission yang belum terdaftar —
        // jadi blok @can yang benar-benar aman bagi manager tetap DIEVALUASI bagi
        // superadmin, dan setiap route() yang belum ada di dalamnya meledak jadi 500.
        // Tes ini yang menahan pola itu supaya tidak masuk lagi.
        $this->actingAs($this->user('superadmin'))
            ->get(route('service.client.show', $client->id))
            ->assertOk();
    }

    /** @test */
    public function other_roles_are_locked_out(): void
    {
        foreach (['admin', 'marketing', 'production', 'accounting'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.client.index'))
                ->assertForbidden();
        }

        // Sisi positifnya WAJIB ada di tes yang sama. EnforcePermission fail-closed:
        // rute yang tak terpeta ditolak untuk SEMUA orang, jadi tanpa baris ini
        // menghapus seluruh blok `service_client` dari config/permissions.php tetap
        // membuat tes ini hijau — ia hanya membuktikan "tiga role ini ditolak",
        // yang juga benar ketika semua orang ditolak.
        $this->actingAs($this->user('manager'))
            ->get(route('service.client.index'))
            ->assertOk();
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
