<?php
// tests/Unit/PermissionMapTest.php

namespace Tests\Unit;

use App\Support\PermissionMap;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionMapTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function tidak_ada_route_terdaftar_di_dua_aksi_berbeda(): void
    {
        $seen = [];
        $dupes = [];
        foreach (config('permissions.modules') as $module => $def) {
            foreach ($def['actions'] as $action => $routes) {
                foreach ($routes as $r) {
                    if (isset($seen[$r])) {
                        $dupes[] = "$r ({$seen[$r]} vs $module.$action)";
                    }
                    $seen[$r] = "$module.$action";
                }
            }
        }
        $this->assertSame([], $dupes, 'Route terdaftar ganda: ' . implode('; ', $dupes));
    }

    /** @test */
    public function seeder_membuat_seluruh_permission_dari_peta(): void
    {
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        (new AccessMatrixSeeder())->run();

        foreach (PermissionMap::allPermissions() as $perm) {
            $this->assertTrue(Permission::where('name', $perm)->exists(), "Permission hilang: $perm");
        }
    }

    /** @test */
    public function seeder_memberi_hibah_paritas_ke_role(): void
    {
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Contoh paritas dari matriks lama (role:marketing|manager|superadmin).
        $this->assertTrue(Role::findByName('marketing')->hasPermissionTo('order.view'));
        $this->assertTrue(Role::findByName('manager')->hasPermissionTo('order.view'));
        // production TIDAK boleh menembus listing order.
        $this->assertFalse(Role::findByName('production')->hasPermissionTo('order.view'));
        // accounting hanya keuangan.
        $this->assertTrue(Role::findByName('accounting')->hasPermissionTo('accounting.journal.view'));
        $this->assertFalse(Role::findByName('accounting')->hasPermissionTo('order.view'));
    }
}
