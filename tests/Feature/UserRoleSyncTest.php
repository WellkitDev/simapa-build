<?php
// tests/Feature/UserRoleSyncTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengubah role lewat Manajemen User harus MENGGANTI, bukan menumpuk.
 * `assignRole()` hanya menambah: menurunkan superadmin jadi marketing dulu
 * menyisakan kedua role, dan superadmin lolos duluan lewat Gate::before —
 * hak istimewa tidak pernah benar-benar tercabut lewat UI.
 */
class UserRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['superadmin', 'marketing'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** @test */
    public function menurunkan_role_mencabut_role_lama(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $target = User::factory()->create(['username' => 'target']);
        $target->assignRole('superadmin');

        $marketing = Role::where('name', 'marketing')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('user.management.update', $target), [
                'name'      => $target->name,
                'username'  => $target->username,
                'email'     => $target->email,
                'role_id'   => $marketing->id,
                'is_active' => 1,
            ])
            ->assertRedirect(route('user.management'));

        $target->refresh();
        $target->unsetRelation('roles');

        $this->assertTrue($target->hasRole('marketing'), 'Role baru harus melekat.');
        $this->assertFalse($target->hasRole('superadmin'), 'Role lama WAJIB tercabut.');
        $this->assertSame(['marketing'], $target->getRoleNames()->all());
    }
}
