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

        // manuscript.detail ("lihat detail progres satu judul") tanpa penjagaan role: sama
        // sekali di routes/web.php hari ini — terbuka utk SEMUA role yang login.
        foreach (['manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            $this->assertTrue(
                Role::findByName($r)->hasPermissionTo('manuscript.detail'),
                "$r seharusnya punya manuscript.detail (route sumbernya tanpa penjagaan role)"
            );
        }

        // Modul lama (papan Kanban + Distribusi Artikel/Buku) dihapus 2026-08-10 —
        // permission-nya harus ikut lenyap, bukan tertinggal jadi hibah mati.
        foreach (['manuscript.view', 'distribution.view', 'distribution.assign',
                  'distribution.move', 'distribution.priority', 'distribution.target',
                  'distribution.upload'] as $mati) {
            $this->assertNull(
                \Spatie\Permission\Models\Permission::where('name', $mati)->first(),
                "Permission $mati seharusnya sudah tidak ada."
            );
        }
    }

    /**
     * Matriks Penugasan Naskah (spec §4). Dikunci di tingkat PERMISSION di sini;
     * paritas per-route ditambahkan ke AccessParityTest saat route naskah.* lahir.
     *
     * @test
     */
    public function matriks_naskah_sesuai_keputusan_bisnis(): void
    {
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // [permission, role yang HARUS punya] — role lain di luar daftar harus TIDAK punya.
        $matrix = [
            'naskah.view'     => ['marketing', 'production', 'admin', 'manager'],
            'naskah.workdesk' => ['production', 'admin', 'manager'],
            'naskah.target'   => ['marketing', 'admin', 'manager'],
            'naskah.upload'   => ['marketing', 'production', 'admin', 'manager'],
            'naskah.claim'    => ['production', 'manager'],
            'naskah.assign'   => ['admin', 'manager'],
            'naskah.advance'  => ['admin', 'manager'],
            'naskah.priority' => ['admin', 'manager'],
            'naskah.hold'     => ['admin', 'manager'],
            'naskah.cancel'   => ['admin', 'manager'],
            'naskah.author'   => ['marketing', 'admin', 'manager'],
            'naskah.struktur' => ['marketing', 'admin', 'manager'],
            // Koreksi mundur/lompat termasuk tahap final: superadmin SAJA. Superadmin
            // lolos lewat Gate::before, bukan hibah — karena itu daftar ini kosong dan
            // SEMUA role (termasuk manager) harus gagal.
            'naskah.correct'  => [],
        ];

        foreach ($matrix as $permission => $allowed) {
            foreach (['marketing', 'production', 'admin', 'manager', 'accounting'] as $role) {
                $should = in_array($role, $allowed, true);
                $this->assertSame(
                    $should,
                    Role::findByName($role)->hasPermissionTo($permission),
                    "$role " . ($should ? 'seharusnya punya' : 'TIDAK boleh punya') . " $permission"
                );
            }
        }
    }
}
