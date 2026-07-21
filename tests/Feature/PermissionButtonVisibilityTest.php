<?php
// tests/Feature/PermissionButtonVisibilityTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mengunci bug yang dilaporkan: user dengan permission VIEW saja untuk suatu modul
 * masih MELIHAT tombol/form create-edit-delete, lalu ditolak saat submit.
 *
 * NB: 'marketing-target.index' (GET) dan 'marketing-target.store' (POST) berbagi
 * URI yang sama ("marketing-target"), jadi memeriksa kemunculan URL hasil route()
 * tidak bisa membedakan form store dari link filter/navigasi index yang memang
 * boleh tampil. Test ini karena itu memeriksa penanda unik form "Buat Target"
 * (judul kartu & tombol submit "Simpan Target") — bukan string URL.
 */
class PermissionButtonVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['production', 'manager', 'superadmin', 'marketing'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function grant(string $role, string ...$permissions): void
    {
        $roleModel = Role::findByName($role, 'web');
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $roleModel->givePermissionTo($permission);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** @test */
    public function view_only_role_does_not_see_create_target_form(): void
    {
        $this->grant('production', 'marketing-target.view');

        $response = $this->actingAs($this->user('production'))
            ->get(route('marketing-target.index'));

        $response->assertOk();
        $response->assertDontSee('Buat Target');
        $response->assertDontSee('Simpan Target');
    }

    /** @test */
    public function role_with_create_permission_sees_create_target_form(): void
    {
        $this->grant('manager', 'marketing-target.view', 'marketing-target.create');

        $response = $this->actingAs($this->user('manager'))
            ->get(route('marketing-target.index'));

        $response->assertOk();
        $response->assertSee('Buat Target');
        $response->assertSee(route('marketing-target.store'));
    }
}
