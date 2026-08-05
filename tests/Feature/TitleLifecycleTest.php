<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\User;
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
}
