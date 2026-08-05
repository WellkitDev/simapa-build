<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleEditApprovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function approvedTitle(User $creator): Title
    {
        return Title::create([
            'title' => 'Judul Salah Ketik', 'code' => 'JSK', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $creator->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => 'Judul Sudah Benar', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'indeksasi' => null,
            'scope_id' => null, 'assigned_to' => null, 'chapters' => [],
        ], $override);
    }

    /** @test */
    public function admin_bisa_mengedit_judul_disetujui(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);

        $this->actingAs($admin)->get(route('title.edit', $title->id))->assertOk();

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())
            ->assertRedirect(route('title.show', $title->id));

        $title->refresh();
        $this->assertSame('Judul Sudah Benar', $title->title);
        $this->assertSame('disetujui', $title->status, 'Status tidak boleh turun ke menunggu setelah diedit.');
        $this->assertStringContainsString('judul-sudah-benar', $title->slug);
    }

    /** @test */
    public function production_ditolak_mengedit_judul_disetujui(): void
    {
        $title = $this->approvedTitle($this->user('admin'));

        $this->actingAs($this->user('production'))->get(route('title.edit', $title->id))->assertForbidden();
        $this->actingAs($this->user('production'))
            ->put(route('title.update', $title->id), $this->payload())->assertForbidden();
    }

    /** @test */
    public function production_tetap_bisa_mengedit_judul_draft(): void
    {
        $prod = $this->user('production');
        $title = Title::create([
            'title' => 'Draf Produksi', 'code' => 'DP', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'draft', 'asal' => 'distribusi',
            'created_by' => $prod->id,
        ]);

        $this->actingAs($prod)->get(route('title.edit', $title->id))->assertOk();
    }

    /** @test */
    public function perubahan_teks_judul_tersinkron_ke_detail_order_dan_tercatat(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);
        $order = Order::factory()->create();

        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'Judul Salah Ketik', 'slug' => 'judul-salah-ketik-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())->assertRedirect();

        $this->assertSame('Judul Sudah Benar', $detail->fresh()->title);
        $this->assertDatabaseHas('tb_title_logs', [
            'title_id'   => $title->id,
            'event'      => 'updated',
            'changed_by' => $admin->id,
        ]);
    }

    /** @test */
    public function kode_judul_tidak_ikut_berubah(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())->assertRedirect();

        $this->assertSame('JSK', $title->fresh()->code, 'Kode sudah tercetak di invoice & arsip — tidak boleh berubah otomatis.');
    }

    /** @test */
    public function judul_order_yang_dibatalkan_ikut_disinkron(): void
    {
        $admin  = $this->user('admin');
        $title  = $this->approvedTitle($admin);
        $order  = Order::factory()->create();

        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'Judul Salah Ketik', 'slug' => 'judul-salah-ketik-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
        $detail->delete(); // order dibatalkan → detail ikut soft-deleted

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())->assertRedirect();

        // withTrashed(): order yang dibatalkan pun tidak boleh menyimpan judul basi.
        $this->assertSame('Judul Sudah Benar', OrderDetail::withTrashed()->find($detail->id)->title);
    }
}
