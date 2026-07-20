<?php
// tests/Feature/PermissionPageTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function hanya_superadmin_yang_bisa_membuka_halaman(): void
    {
        $this->actingAs($this->user('superadmin'))->get(route('permission.index'))->assertOk();
        $this->actingAs($this->user('manager'))->get(route('permission.index'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->get(route('permission.index'))->assertForbidden();
    }

    /** @test */
    public function menyimpan_mengubah_akses_sungguhan(): void
    {
        $this->actingAs($this->user('superadmin'))
            ->put(route('permission.update'), ['role' => 'production', 'permissions' => ['order.view']])
            ->assertRedirect();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // NB: EnforcePermission belum terpasang (task terpisah, lihat plan Task 4), jadi rute
        // seperti order.book.index/manuscript.board masih dijaga role: lama, bukan permission ini.
        // Test ini memverifikasi apa yang benar-benar sudah ditegakkan sekarang: sinkronisasi
        // permission pada model Role itu sendiri — production kini benar-benar punya order.view
        // (sebelumnya tidak) ...
        $production = Role::findByName('production');
        $this->assertTrue($production->hasPermissionTo('order.view'));
        // ... dan kehilangan permission yang tak dicentang (mis. manuscript.view, dihibahkan seeder).
        $this->assertFalse($production->hasPermissionTo('manuscript.view'));
    }

    /** @test */
    public function role_superadmin_tidak_bisa_diubah(): void
    {
        $this->actingAs($this->user('superadmin'))
            ->put(route('permission.update'), ['role' => 'superadmin', 'permissions' => []])
            ->assertForbidden();
    }

    /** @test */
    public function role_tak_dikenal_ditolak(): void
    {
        // putJson (bukan put): mengikuti konvensi berkas lain di suite ini untuk memeriksa
        // 422 dari $request->validate() — tanpa header Accept: application/json, Laravel
        // membalas 302 redirect-back, bukan 422 (lihat mis. ChapterStageJumpTest, ManuscriptTrackerTest).
        $this->actingAs($this->user('superadmin'))
            ->putJson(route('permission.update'), ['role' => 'hantu', 'permissions' => []])
            ->assertStatus(422);
    }
}
