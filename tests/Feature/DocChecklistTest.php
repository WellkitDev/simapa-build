<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\DocRequirement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DocChecklistTest extends TestCase
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

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Doc ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function superadmin_crud_requirement(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'Item Baru'])->assertRedirect();
        $req = DocRequirement::where('label', 'Item Baru')->first();
        $this->assertNotNull($req);

        $this->actingAs($sa)->put(route('doc-req.update', $req->id), ['category' => 'penerbit', 'label' => 'Item Ubah'])->assertRedirect();
        $this->assertSame('Item Ubah', $req->fresh()->label);

        $this->actingAs($sa)->delete(route('doc-req.destroy', $req->id))->assertRedirect();
        $this->assertNull(DocRequirement::find($req->id));
    }

    /** @test */
    public function non_superadmin_cannot_crud_template(): void
    {
        $this->actingAs($this->user('admin'))->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'X'])->assertForbidden();
    }
}
