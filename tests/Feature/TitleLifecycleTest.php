<?php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleLifecycleTest extends TestCase
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

    private function makeTitle(User $creator, array $attrs = []): Title
    {
        return Title::create(array_merge([
            'title'       => 'Judul Uji',
            'code'        => 'JU',
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $creator->id,
        ], $attrs));
    }

    /** @test */
    public function kolom_siklus_hidup_judul_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_titles', ['deleted_at', 'deactivated_at', 'deactivated_by']));
    }

    /** @test */
    public function judul_memakai_soft_delete(): void
    {
        $title = $this->makeTitle($this->user('admin'));
        $title->delete();

        $this->assertSoftDeleted('tb_titles', ['id' => $title->id]);
        $this->assertNull(Title::find($title->id));
        $this->assertNotNull(Title::withTrashed()->find($title->id));
    }

    /** @test */
    public function scope_active_menyaring_judul_nonaktif(): void
    {
        $admin  = $this->user('admin');
        $aktif  = $this->makeTitle($admin, ['title' => 'Judul Aktif', 'code' => 'JA']);
        $mati   = $this->makeTitle($admin, ['title' => 'Judul Nonaktif', 'code' => 'JN']);
        $mati->update(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);

        $this->assertTrue($aktif->fresh()->isActive());
        $this->assertFalse($mati->fresh()->isActive());

        $ids = Title::active()->pluck('id')->all();
        $this->assertContains($aktif->id, $ids);
        $this->assertNotContains($mati->id, $ids);
    }

    private function linkOrder(Title $title): OrderDetail
    {
        $order = Order::factory()->create();

        return OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => $title->title, 'slug' => 'judul-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
    }

    /** @test */
    public function judul_tanpa_pemakaian_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);

        $this->assertTrue($title->isDeletable());
        $this->assertNull($title->deleteBlockReason());

        $this->actingAs($admin)->delete(route('title.destroy', $title->id))
            ->assertRedirect(route('title.index'))->assertSessionHas('success');

        $this->assertSoftDeleted('tb_titles', ['id' => $title->id]);
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'deleted']);
    }

    /** @test */
    public function judul_dengan_order_aktif_tidak_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        $this->linkOrder($title);

        $this->assertFalse($title->fresh()->isDeletable());
        $this->assertSame('Dipakai 1 order', $title->fresh()->deleteBlockReason());

        $this->actingAs($admin)->delete(route('title.destroy', $title->id))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('tb_titles', ['id' => $title->id, 'deleted_at' => null]);
    }

    /** @test */
    public function judul_dengan_isbn_tidak_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        BookIsbn::create(['title_id' => $title->id, 'status' => 'pendaftaran', 'created_by' => $admin->id]);

        $this->assertSame('Sudah punya ISBN', $title->fresh()->deleteBlockReason());
    }

    /** @test */
    public function judul_yatim_karena_order_dibatalkan_bisa_dihapus(): void
    {
        $admin  = $this->user('admin');
        $owner  = $this->user('marketing');
        $title  = $this->makeTitle($admin);
        $detail = $this->linkOrder($title);

        $this->assertFalse($title->fresh()->isDeletable());

        app(OrderCancellationService::class)->cancel($detail->order, null, $owner);

        // OrderDetail ikut soft-deleted → judul tidak lagi punya order aktif.
        $this->assertTrue($title->fresh()->isDeletable());
    }

    /** @test */
    public function nonaktifkan_menyembunyikan_judul_dari_dropdown_order(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);

        $this->actingAs($admin)->post(route('title.deactivate', $title->id))
            ->assertRedirect()->assertSessionHas('success');

        $title->refresh();
        $this->assertFalse($title->isActive());
        $this->assertSame($admin->id, $title->deactivated_by);
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'deactivated']);

        // Hilang dari dropdown order, TAPI masih ada di direktori & laporan.
        $this->actingAs($this->user('marketing'))->get(route('order.book.create'))
            ->assertOk()->assertDontSee('Judul Uji');
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function aktifkan_lagi_hanya_manager_atau_superadmin(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        $title->update(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);

        $this->actingAs($admin)->post(route('title.activate', $title->id))->assertForbidden();

        $this->actingAs($this->user('manager'))->post(route('title.activate', $title->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($title->fresh()->isActive());
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'activated']);
    }

    /** @test */
    public function production_tidak_bisa_menonaktifkan(): void
    {
        $title = $this->makeTitle($this->user('admin'));

        $this->actingAs($this->user('production'))
            ->postJson(route('title.deactivate', $title->id))->assertForbidden();
    }
}
