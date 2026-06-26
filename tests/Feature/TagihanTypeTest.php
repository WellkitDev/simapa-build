<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TagihanTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    private function tagihan(User $u, string $type): Tagihan
    {
        return Tagihan::create([
            'tagihan_no' => 'TAG-' . substr(md5(uniqid('', true)), 0, 8),
            'client_name' => 'Klien', 'title' => 'Judul', 'type' => $type,
            'amount' => 1000000, 'created_by' => $u->id, 'status' => 'disetujui',
        ]);
    }

    /** @test */
    public function store_accepts_four_service_types(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('tagihan.store'), [
            'client_name' => 'Klien', 'title' => 'Judul', 'type' => 'bk_kolab', 'amount' => 1000000,
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', ['type' => 'bk_kolab', 'title' => 'Judul']);
    }

    /** @test */
    public function buat_order_routes_book_vs_journal_including_legacy(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u);

        $bk = $this->tagihan($u, 'bk_mandiri');
        $this->get(route('tagihan.buatOrder', $bk->id))->assertRedirect(route('order.book.create', ['from_tagihan' => $bk->id]));

        $at = $this->tagihan($u, 'at_kolab');
        $this->get(route('tagihan.buatOrder', $at->id))->assertRedirect(route('order.journal.create', ['from_tagihan' => $at->id]));

        $legacyBuku = $this->tagihan($u, 'buku');
        $this->get(route('tagihan.buatOrder', $legacyBuku->id))->assertRedirect(route('order.book.create', ['from_tagihan' => $legacyBuku->id]));

        $legacyJurnal = $this->tagihan($u, 'jurnal');
        $this->get(route('tagihan.buatOrder', $legacyJurnal->id))->assertRedirect(route('order.journal.create', ['from_tagihan' => $legacyJurnal->id]));
    }

    /** @test */
    public function type_label_maps_new_and_legacy(): void
    {
        $this->assertSame('Buku Kolaborasi', (new Tagihan(['type' => 'bk_kolab']))->type_label);
        $this->assertSame('Jurnal Mandiri', (new Tagihan(['type' => 'at_mandiri']))->type_label);
        $this->assertSame('Buku', (new Tagihan(['type' => 'buku']))->type_label);
        $this->assertSame('Jurnal', (new Tagihan(['type' => 'jurnal']))->type_label);
    }
}
