<?php
// tests/Feature/AccessParityTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessParityTest extends TestCase
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

    /**
     * Matriks yang HARUS tetap sama dengan sebelum migrasi.
     * [nama route, role, boleh?]
     */
    public static function matrix(): array
    {
        return [
            // role:marketing|manager|superadmin
            ['order.book.index',  'marketing',  true],
            ['order.book.index',  'manager',    true],
            ['order.book.index',  'superadmin', true],
            ['order.book.index',  'production', false],
            ['order.book.index',  'admin',      false],
            ['order.book.index',  'accounting', false],
            ['payment.index',     'marketing',  true],
            ['payment.index',     'production', false],
            ['invoice.index',     'marketing',  true],
            ['invoice.index',     'production', false],

            // role:manager|superadmin
            ['marketing-target.index', 'manager',    true],
            ['marketing-target.index', 'marketing',  false],
            ['task.monitor',           'manager',    true],
            ['task.monitor',           'production', false],
            ['report.submissions',     'manager',    true],
            ['report.submissions',     'marketing',  false],

            // role:superadmin
            ['accounting.period.lock', 'superadmin', true],
            ['accounting.period.lock', 'accounting', false],

            // role:superadmin|accounting
            ['accounting.journal', 'accounting', true],
            ['accounting.journal', 'superadmin', true],
            ['accounting.journal', 'marketing',  false],
            ['accounting.journal', 'manager',    false],

            // role:superadmin|manager|admin
            ['announcement.index', 'admin',      true],
            ['announcement.index', 'production', false],

            // terbuka utk semua role login (kini lewat permission view yang dihibahkan ke semua)
            ['title.index',   'production', true],
            ['journal.index', 'accounting', true],

            // benar-benar public (tanpa permission)
            ['dashboard',   'production', true],
            ['task.index',  'accounting', true],

            // role:superadmin|manager|admin|marketing — Direktori Author (dibatasi 2026-07)
            ['author.index', 'marketing',  true],
            ['author.index', 'admin',      true],
            ['author.index', 'production', false],
            ['author.index', 'accounting', false],

            // ── Penugasan Naskah (spec §4) ──
            // Pelacakan & arsip: semua role kerja; marketing hanya-baca tapi TETAP melihat.
            ['naskah.pelacakan', 'marketing',  true],
            ['naskah.pelacakan', 'production', true],
            ['naskah.pelacakan', 'admin',      true],
            ['naskah.pelacakan', 'accounting', false],
            ['naskah.arsip',     'marketing',  true],
            ['naskah.arsip',     'accounting', false],

            // Meja Kerja: hanya yang benar-benar memegang tugas.
            ['naskah.workdesk', 'production', true],
            ['naskah.workdesk', 'admin',      true],
            ['naskah.workdesk', 'marketing',  false],

            // Maju tahap = wewenang PJ (admin). Produksi menyerahkan naskah, tidak memajukan.
            ['naskah.selesaikan', 'admin',      true],
            ['naskah.selesaikan', 'production', false],
            ['naskah.selesaikan', 'marketing',  false],

            // Distribusi/oper tugas: admin. Claim: produksi.
            ['naskah.distribusi', 'admin',      true],
            ['naskah.distribusi', 'production', false],
            ['naskah.claim',      'production', true],
            ['naskah.claim',      'admin',      false],

            // Koreksi mundur/lompat: superadmin SAJA — manager pun tidak.
            ['naskah.koreksi', 'superadmin', true],
            ['naskah.koreksi', 'admin',      false],
            ['naskah.koreksi', 'manager',    false],

            // Target datang dari request klien lewat marketing; upload terbuka untuk semua.
            ['naskah.target', 'marketing',  true],
            ['naskah.target', 'production', false],
            ['naskah.file',   'marketing',  true],
            ['naskah.file',   'production', true],

            // Prioritas/hold/batal: admin, bukan produksi.
            ['naskah.prioritas', 'admin',      true],
            ['naskah.prioritas', 'production', false],
            ['naskah.batal',     'admin',      true],
            ['naskah.batal',     'production', false],
        ];
    }

    /**
     * @test
     * @dataProvider matrix
     */
    public function akses_sama_dengan_sebelum_migrasi(string $routeName, string $role, bool $allowed): void
    {
        $verb = $this->httpVerbFor($routeName);
        $res  = $this->actingAs($this->user($role))->$verb($this->urlFor($routeName));

        if ($allowed) {
            $this->assertNotSame(403, $res->getStatusCode(),
                "$role seharusnya BOLEH $routeName, tapi 403");
        } elseif ($verb === 'get') {
            // Navigasi GET terlarang → halaman 403 ramah (status 403).
            $res->assertForbidden();
        } else {
            // Submit terlarang → diblok middleware: redirect balik + flash error
            // (bukan 403 mentah). Keputusan aksesnya tetap sama: DIBLOK.
            $res->assertRedirect();
            $res->assertSessionHas('error');
        }
    }

    /**
     * Sebagian besar route di matriks ini adalah GET (index/show). Beberapa
     * route aksi (mis. accounting.period.lock) HANYA terdaftar sebagai POST —
     * dites dengan GET, request itu tidak pernah mencapai route bernamanya:
     * ia nyasar ke Route::fallback() (didaftar di luar grup auth+access, tanpa
     * penjagaan apa pun), yang selalu redirect 302 ke /dashboard bagi user
     * login — bukan 403, apa pun hak aksesnya. Pakai verb asli route supaya
     * request benar-benar diuji lewat middleware permission.
     */
    /**
     * Sebagian route beraksi butuh parameter (mis. naskah/{id}/selesaikan). Yang diuji
     * di sini adalah gerbang izin, yang berjalan SEBELUM controller — jadi id boneka
     * sudah cukup: yang berhak berakhir 404 (bukan 403), yang tak berhak tetap diblok.
     */
    private function urlFor(string $routeName): string
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
        $params = collect($route?->parameterNames() ?? [])
            ->mapWithKeys(fn (string $name) => [$name => 1])
            ->all();

        return route($routeName, $params);
    }

    private function httpVerbFor(string $routeName): string
    {
        $methods = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName)?->methods() ?? ['GET'];
        return in_array('GET', $methods, true) ? 'get' : strtolower($methods[0]);
    }

    /** @test */
    public function superadmin_lolos_walau_tanpa_hibah_permission(): void
    {
        Role::findByName('superadmin')->syncPermissions([]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->actingAs($this->user('superadmin'))
            ->get(route('accounting.journal'))
            ->assertOk();
    }

    /** @test */
    public function mencabut_permission_benar_benar_memblokir(): void
    {
        $mkt = $this->user('marketing');
        $this->actingAs($mkt)->get(route('order.book.index'))->assertOk();

        Role::findByName('marketing')->revokePermissionTo('order.view');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->actingAs($mkt)->get(route('order.book.index'))->assertForbidden();
    }

    /**
     * Perilaku penolakan (UX): submit form terlarang → redirect + flash error (SweetAlert);
     * navigasi GET terlarang → halaman 403 ramah; request JSON/AJAX → 403 murni.
     *
     * @test
     */
    public function penolakan_ramah_untuk_form_dan_json_403_untuk_ajax(): void
    {
        // production tak punya izin membuat marketing-target (view saja pun tidak).
        $prod = $this->user('production');

        // Submit form terlarang → redirect balik + flash, TIDAK mengeksekusi aksi.
        $this->actingAs($prod)
            ->post(route('marketing-target.store'), [])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseCount('tb_marketing_targets', 0);

        // Navigasi GET terlarang → halaman 403 ramah (status 403).
        $this->actingAs($prod)->get(route('marketing-target.index'))->assertForbidden();

        // Request JSON (AJAX) → 403 murni, bukan redirect.
        $this->actingAs($prod)
            ->postJson(route('marketing-target.store'), [])
            ->assertForbidden();
    }
}
