# Halaman Hak Akses (Permission Custom) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memindahkan penegakan akses dari 53 `role:` hardcoded di `web.php` ke peta terpusat route→permission, lalu menyediakan halaman ceklis modul × aksi per role — tanpa mengubah hak akses siapa pun pada hari pertama.

**Architecture:** `config/permissions.php` jadi sumber kebenaran tunggal (modul → aksi → daftar nama route). Middleware `EnforcePermission` (fail-closed) menerjemahkan nama route jadi permission dan memanggil `$user->can()`. Seeder paritas memberi tiap role permission yang setara dengan matriks `role:` sekarang. Halaman Hak Akses dibangun dari peta yang sama sehingga UI dan penegakan tak bisa menyimpang. Superadmin lolos lewat `Gate::before` yang sudah ada.

**Tech Stack:** Laravel 10, PHP 8, Spatie laravel-permission (alias `role`/`permission` sudah terdaftar di `app/Http/Kernel.php:67-69`), PHPUnit, Bootstrap 4 (NobleUI).

---

## Spec

`docs/superpowers/specs/2026-07-21-hak-akses-permission-design.md`

## Urutan aman (JANGAN diubah)

Migrasi ini berbahaya bila urutannya salah — mencabut `role:` sebelum permission ditegakkan akan
membuka seluruh aplikasi. Urutan yang dipakai:

1. Peta + uji kelengkapan (data saja, **nol** perubahan perilaku)
2. Seeder paritas (role dapat permission setara matriks sekarang; belum ada yang membacanya)
3. Middleware `EnforcePermission` (sekarang `role:` DAN permission sama-sama menjaga → gerbang ganda,
   perilaku tetap identik karena permission sudah setara)
4. Cabut 53 `role:` dari `web.php` (kini hanya permission yang menjaga; uji paritas membuktikan sama)

## Catatan lingkungan

- Test lewat DB test (`.env.testing`) otomatis via `php artisan test`. **Jangan** sentuh DB nyata.
- Role di test: **selalu** `Role::firstOrCreate` (role `accounting` di-seed migrasi).
- Setelah mengubah permission di runtime/test: `app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();`
- Commit: `git add` path eksplisit; pesan diakhiri `Co-Authored-By: Mira <admin@avidpedia.com>`; jangan pernah menyebut AI.
- Jangan sentuh berkas milik pekerjaan lain: `.gitignore`, `app/Http/Controllers/Pages/AuthorController.php`, `resources/views/layouts/sidebar.blade.php` (kecuali Task 9), `routes/web.php` (kecuali Task 5), `tests/Feature/AuthorDirectoryTest.php`, `tests/Feature/SidebarTest.php`, `avidpedi_simapa.sql`, `template-web/`, `public/error_log`.

## File Structure

**Dibuat:**
- `config/permissions.php` — peta `public` + `modules`.
- `app/Support/PermissionMap.php` — pembaca peta (nama route → permission, daftar permission, matriks UI).
- `app/Http/Middleware/EnforcePermission.php` — penegak fail-closed.
- `database/seeders/AccessMatrixSeeder.php` — buat permission + hibah paritas per role.
- `app/Http/Controllers/Pages/PermissionController.php` — halaman Hak Akses.
- `resources/views/permissions/index.blade.php` — grid ceklis.
- `tests/Unit/PermissionMapTest.php`
- `tests/Feature/PermissionMapCompletenessTest.php`
- `tests/Feature/AccessParityTest.php`
- `tests/Feature/PermissionPageTest.php`

**Dimodifikasi:**
- `app/Http/Kernel.php` — daftarkan `EnforcePermission`.
- `routes/web.php` — cabut `role:`, tambah rute halaman Hak Akses.
- `database/seeders/DatabaseSeeder.php` — panggil `AccessMatrixSeeder`.
- `app/Providers/AuthServiceProvider.php` — perbaiki directive `@permission` (Task 9).
- `resources/views/layouts/sidebar.blade.php` — `@role` → `@can` (Task 9).

---

# FASE 1 — Fondasi (perilaku identik)

## Task 1: Peta kosong + uji kelengkapan (RED)

Uji kelengkapan inilah yang menyetir pekerjaan data-entry Task 2: ia mendaftar tepat route mana yang belum dipetakan.

**Files:**
- Create: `config/permissions.php`
- Create: `app/Support/PermissionMap.php`
- Create: `tests/Feature/PermissionMapCompletenessTest.php`

- [ ] **Step 1: Buat kerangka peta (masih kosong)**

Create `config/permissions.php`:

```php
<?php
// Sumber kebenaran tunggal hak akses. Halaman Hak Akses DAN middleware sama-sama membaca berkas ini,
// sehingga UI tak mungkin menjanjikan sesuatu yang tidak ditegakkan.
//
// 'public'  = route yang cukup terautentikasi (milik-sendiri / lintas-role), tanpa permission.
// 'modules' = modul => ['label' => ..., 'actions' => ['aksi' => [nama route, ...]]]
//             Nama permission yang dihasilkan = "<kunci modul>.<aksi>".
return [
    'public' => [
        'dashboard', 'profile', 'profile.image',
        'notifications.index', 'notifications.read', 'notifications.readAll',
        'announcement.seen',
        // Tugas & laporan PRIBADI — didaftar satu per satu, BUKAN wildcard, karena
        // task.monitor & report.submissions justru harus berizin.
        'task.index', 'task.board', 'task.calendar', 'task.events', 'task.reorder',
        'task.store', 'task.update', 'task.destroy', 'task.status', 'task.schedule',
        'report.daily', 'report.note', 'report.submit',
        'report.files.store', 'report.files.destroy', 'report.monthly',
        'marketing-target.me',
    ],

    'modules' => [
        // Diisi pada Task 2, dituntun PermissionMapCompletenessTest.
    ],
];
```

- [ ] **Step 2: Buat pembaca peta**

Create `app/Support/PermissionMap.php`:

```php
<?php
// app/Support/PermissionMap.php

namespace App\Support;

class PermissionMap
{
    /** @return array<string,string> nama route => nama permission */
    public static function routeToPermission(): array
    {
        static $flat = null;
        if ($flat !== null) {
            return $flat;
        }

        $flat = [];
        foreach (config('permissions.modules', []) as $module => $def) {
            foreach ($def['actions'] ?? [] as $action => $routes) {
                foreach ($routes as $routeName) {
                    $flat[$routeName] = $module . '.' . $action;
                }
            }
        }

        return $flat;
    }

    /** Semua nama permission yang dikenal sistem. */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_values(self::routeToPermission())));
    }

    public static function isPublic(string $routeName): bool
    {
        return in_array($routeName, config('permissions.public', []), true);
    }

    /** null = route tidak terpeta (middleware menolaknya: fail-closed). */
    public static function permissionFor(string $routeName): ?string
    {
        return self::routeToPermission()[$routeName] ?? null;
    }

    /** Untuk UI: [modul => ['label' => ..., 'actions' => [aksi => permission]]] */
    public static function matrix(): array
    {
        $out = [];
        foreach (config('permissions.modules', []) as $module => $def) {
            $actions = [];
            foreach (array_keys($def['actions'] ?? []) as $action) {
                $actions[$action] = $module . '.' . $action;
            }
            $out[$module] = ['label' => $def['label'] ?? $module, 'actions' => $actions];
        }

        return $out;
    }
}
```

- [ ] **Step 3: Tulis uji kelengkapan (akan GAGAL dan mendaftar semua route belum terpeta)**

Create `tests/Feature/PermissionMapCompletenessTest.php`:

```php
<?php
// tests/Feature/PermissionMapCompletenessTest.php

namespace Tests\Feature;

use App\Support\PermissionMap;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMapCompletenessTest extends TestCase
{
    /**
     * Route yang memang di luar kendali hak akses aplikasi: auth bawaan, debug, dan
     * halaman tanpa nama. Ditulis sebagai prefix.
     */
    private array $ignoredPrefixes = [
        'login', 'logout', 'register', 'password.', 'verification.',
        'sanctum.', 'ignition.', 'livewire.',
    ];

    private function isIgnored(string $name): bool
    {
        foreach ($this->ignoredPrefixes as $p) {
            if ($name === rtrim($p, '.') || str_starts_with($name, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @test */
    public function setiap_route_bernama_terpeta_atau_public(): void
    {
        $unmapped = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $this->isIgnored($name)) {
                continue;
            }
            if (PermissionMap::isPublic($name) || PermissionMap::permissionFor($name) !== null) {
                continue;
            }
            $unmapped[] = $name;
        }

        sort($unmapped);
        $this->assertSame([], $unmapped,
            "Route berikut belum ada di config/permissions.php (public atau modules):\n  - "
            . implode("\n  - ", $unmapped));
    }

    /** @test */
    public function tidak_ada_route_yang_sekaligus_public_dan_berizin(): void
    {
        $both = array_intersect(config('permissions.public'), array_keys(PermissionMap::routeToPermission()));
        $this->assertSame([], array_values($both),
            'Route ini terdaftar public sekaligus punya permission: ' . implode(', ', $both));
    }
}
```

- [ ] **Step 4: Jalankan — pastikan GAGAL dengan daftar route**

Run: `php artisan test --filter=PermissionMapCompletenessTest`
Expected: FAIL. Pesan gagal memuat daftar lengkap route yang belum dipetakan — **daftar ini adalah lembar kerja Task 2**. Simpan/salin outputnya.

- [ ] **Step 5: Commit (peta kosong + alat ukurnya)**

```bash
git add config/permissions.php app/Support/PermissionMap.php tests/Feature/PermissionMapCompletenessTest.php
git commit -m "feat(access): kerangka peta permission + uji kelengkapan route

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 2: Isi peta modul sampai uji kelengkapan HIJAU

**Files:**
- Modify: `config/permissions.php`

- [ ] **Step 1: Lihat daftar route yang belum terpeta**

Run: `php artisan test --filter=setiap_route_bernama_terpeta_atau_public`
Gunakan daftar pada pesan gagal sebagai checklist. Bantu diri dengan `php artisan route:list` bila perlu melihat URI/controller sebuah route.

- [ ] **Step 2: Isi `modules` modul demi modul**

Tulis ke dalam `'modules' => [ ... ]` di `config/permissions.php`. Pakai tabel modul di spec
(bagian "Daftar modul") sebagai kerangka. Contoh bentuk yang benar (modul `order` — tiru polanya):

```php
        'order' => [
            'label'   => 'Order',
            'actions' => [
                'view'   => ['order.book.index', 'order.book.indexJudul', 'order.book.show',
                             'order.journal.show', 'order.indexJudul.detail', 'order.indexJudul.progress'],
                'create' => ['order.book.create', 'order.book.store',
                             'order.journal.create', 'order.journal.store'],
                'edit'   => ['order.book.edit', 'order.book.update',
                             'order.journal.edit', 'order.journal.update'],
                'refund' => ['order.refund.form', 'order.refund.store', 'order.refund.pdf'],
            ],
        ],

        'payment' => [
            'label'   => 'Pembayaran',
            'actions' => [
                'view'    => ['payment.index', 'payment.dp.index', 'payment.fp.index'],
                'create'  => ['payment.create', 'payment.store'],
                'edit'    => ['payment.update'],
                'approve' => ['payment.approve', 'payment.reject'],
            ],
        ],

        'accounting.journal' => [
            'label'   => 'Keuangan — Jurnal Kas',
            'actions' => [
                'view'     => ['accounting.journal'],
                'create'   => ['accounting.entry.store'],
                'edit'     => ['accounting.entry.update'],
                'delete'   => ['accounting.entry.destroy'],
                'export'   => ['accounting.journal.export.csv', 'accounting.journal.export.pdf'],
                'transfer' => ['accounting.transfer.store'],
            ],
        ],
```

Aturan penting saat mengisi:
- **Satu route hanya boleh muncul di SATU aksi** (uji lain akan menangkap duplikat di Task 3 Step 1).
- Kunci modul boleh bertitik untuk sub-modul (`accounting.journal` → permission `accounting.journal.view`).
- `title.progress.logs` **harus dipetakan** (mis. ke `manuscript.view`) — saat ini route itu tanpa penjagaan sama sekali; memetakannya sekaligus menutup celah tersebut.
- Bila menemukan route yang seharusnya bebas (milik sendiri), tambahkan ke `public`, bukan ke modul.

- [ ] **Step 3: Ulangi sampai HIJAU**

Run: `php artisan test --filter=PermissionMapCompletenessTest`
Expected: PASS (2 tests). Ulangi Step 2 selama masih ada yang terdaftar.

- [ ] **Step 4: Commit**

```bash
git add config/permissions.php
git commit -m "feat(access): peta lengkap route->permission untuk seluruh modul

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 3: Seeder paritas — role dapat permission setara matriks sekarang

**Files:**
- Create: `database/seeders/AccessMatrixSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Unit/PermissionMapTest.php`

- [ ] **Step 1: Tulis test peta + paritas seeder (RED)**

Create `tests/Unit/PermissionMapTest.php`:

```php
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
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=PermissionMapTest`
Expected: FAIL (`Class "Database\Seeders\AccessMatrixSeeder" not found`).

- [ ] **Step 3: Tulis seeder paritas**

Create `database/seeders/AccessMatrixSeeder.php`. Isi `$grants` dengan menerjemahkan matriks
`role:` yang berlaku sekarang di `web.php` (12 kombinasi) menjadi daftar permission per role.
Gunakan `git show 6dc2e20~1:routes/web.php` bila `role:` sudah dicabut saat Anda mengerjakannya.

```php
<?php
// database/seeders/AccessMatrixSeeder.php

namespace Database\Seeders;

use App\Support\PermissionMap;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Membuat seluruh permission dari config/permissions.php lalu memberi hibah per role
 * SETARA dengan matriks role: yang berlaku sebelum migrasi — supaya hari pertama
 * tidak ada satu pun user yang kehilangan/mendapat akses baru.
 *
 * Superadmin sengaja TIDAK diberi hibah: ia lolos lewat Gate::before.
 */
class AccessMatrixSeeder extends Seeder
{
    /** Permission per role. '*' = seluruh permission modul tsb. */
    private array $grants = [
        'marketing' => [
            'order.view', 'order.create', 'order.edit',
            'payment.view', 'payment.create',
            'invoice.view', 'invoice.export',
            'tagihan.*',
            'income.*',
            'marketing-target.view',
            'title.view', 'journal.view', 'isbn.view', 'archive.view',
            'author.view',
            'manuscript.target',
            'data.*',
        ],
        'production' => [
            'manuscript.*', 'chapter.*',
            'title.view', 'title.create', 'title.edit', 'title.delete',
            'journal.view', 'isbn.*', 'archive.view', 'archive.artifacts', 'archive.submit',
            'data.*',
        ],
        'admin' => [
            'announcement.*',
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.info',
            'title.doc.*',
            'journal.*', 'isbn.*', 'author.view',
            'archive.view', 'archive.artifacts', 'archive.submit',
            'data.*',
        ],
        'accounting' => [
            'accounting.*',
            'data.*',
        ],
        'manager' => [
            // Manager = seluruh permission KECUALI yang khusus superadmin
            // (refund order, kunci periode kas, template checklist dokumen, manuscript.clear-log).
            '*',
        ],
    ];

    /** Permission yang hanya untuk superadmin — dikecualikan dari '*'. */
    private array $superadminOnly = [
        'order.refund',
        'accounting.period.lock',
        'doc-req.create', 'doc-req.edit', 'doc-req.delete',
        'manuscript.clear-log',
        'user.view', 'user.create', 'user.edit', 'user.delete', 'user.restore',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $all = PermissionMap::allPermissions();
        foreach ($all as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($this->grants as $roleName => $patterns) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            $role->syncPermissions($this->expand($patterns, $all));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Kembangkan pola ('*', 'modul.*', nama persis) jadi daftar permission nyata. */
    private function expand(array $patterns, array $all): array
    {
        $out = [];
        foreach ($patterns as $p) {
            if ($p === '*') {
                $out = array_merge($out, array_diff($all, $this->superadminOnly));
                continue;
            }
            if (str_ends_with($p, '.*')) {
                $prefix = substr($p, 0, -1); // "modul."
                $out = array_merge($out, array_filter($all,
                    fn ($n) => str_starts_with($n, $prefix) && ! in_array($n, $this->superadminOnly, true)));
                continue;
            }
            if (in_array($p, $all, true)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
```

- [ ] **Step 4: Daftarkan di DatabaseSeeder**

Di `database/seeders/DatabaseSeeder.php`, tambahkan pemanggilan di dalam `run()`:
```php
        $this->call(AccessMatrixSeeder::class);
```

- [ ] **Step 5: Jalankan — pastikan lulus**

Run: `php artisan test --filter=PermissionMapTest`
Expected: PASS (3 tests). Bila assertion paritas gagal, setel `$grants`/`$superadminOnly` (bukan test-nya) sampai cocok dengan matriks lama.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/AccessMatrixSeeder.php database/seeders/DatabaseSeeder.php tests/Unit/PermissionMapTest.php
git commit -m "feat(access): seeder paritas permission per role

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 4: Middleware `EnforcePermission` (gerbang ganda, perilaku tetap)

**Files:**
- Create: `app/Http/Middleware/EnforcePermission.php`
- Modify: `app/Http/Kernel.php`
- Test: `tests/Feature/AccessParityTest.php`

- [ ] **Step 1: Tulis uji paritas + fail-closed (RED)**

Create `tests/Feature/AccessParityTest.php`:

```php
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

            // role:production|manager|superadmin
            ['manuscript.board', 'production', true],
            ['manuscript.board', 'manager',    true],
            ['manuscript.board', 'marketing',  false],

            // role:superadmin|manager|admin
            ['announcement.index', 'admin',      true],
            ['announcement.index', 'production', false],

            // terbuka utk semua role login (kini lewat permission view yang dihibahkan ke semua)
            ['title.index',   'production', true],
            ['journal.index', 'accounting', true],

            // benar-benar public (tanpa permission)
            ['dashboard',   'production', true],
            ['task.index',  'accounting', true],
        ];
    }

    /**
     * @test
     * @dataProvider matrix
     */
    public function akses_sama_dengan_sebelum_migrasi(string $routeName, string $role, bool $allowed): void
    {
        $res = $this->actingAs($this->user($role))->get(route($routeName));

        if ($allowed) {
            $this->assertNotSame(403, $res->getStatusCode(),
                "$role seharusnya BOLEH $routeName, tapi 403");
        } else {
            $res->assertForbidden();
        }
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
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=AccessParityTest`
Expected: FAIL — khususnya `mencabut_permission_benar_benar_memblokir` (belum ada penegak permission).

- [ ] **Step 3: Tulis middleware**

Create `app/Http/Middleware/EnforcePermission.php`:

```php
<?php
// app/Http/Middleware/EnforcePermission.php

namespace App\Http\Middleware;

use App\Support\PermissionMap;
use Closure;
use Illuminate\Http\Request;

/**
 * Penegak hak akses tunggal. Menerjemahkan nama route jadi permission lewat
 * config/permissions.php, lalu memanggil can(). FAIL-CLOSED: route bernama yang
 * tidak terpeta ditolak — route baru tidak bisa bocor diam-diam.
 * Superadmin lolos lebih dulu lewat Gate::before (AuthServiceProvider).
 */
class EnforcePermission
{
    public function handle(Request $request, Closure $next)
    {
        $name = $request->route()?->getName();

        // Route tanpa nama (mis. asset/fallback) tidak dijaga di sini.
        if ($name === null || PermissionMap::isPublic($name)) {
            return $next($request);
        }

        $permission = PermissionMap::permissionFor($name);
        abort_if($permission === null, 403, 'Route belum terdaftar di peta hak akses.');
        abort_unless($request->user()?->can($permission), 403);

        return $next($request);
    }
}
```

- [ ] **Step 4: Daftarkan alias di Kernel**

Di `app/Http/Kernel.php`, tambahkan pada `$middlewareAliases` (setelah baris `'role_or_permission'`):
```php
        'access' => \App\Http\Middleware\EnforcePermission::class,
```

- [ ] **Step 5: Pasang pada grup auth di web.php**

Di `routes/web.php`, ubah pembuka grup besar:
```php
Route::middleware('auth')->group(function () {
```
menjadi:
```php
Route::middleware(['auth', 'access'])->group(function () {
```
Dan pada baris dashboard:
```php
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware(['auth', 'verified', 'access']);
```
**Jangan cabut `role:` apa pun pada task ini** — gerbang ganda ini yang membuat langkahnya aman.

- [ ] **Step 6: Jalankan — pastikan lulus**

Run: `php artisan test --filter=AccessParityTest`
Expected: PASS (semua baris matriks + 2 test lain).

- [ ] **Step 7: Jalankan seluruh suite**

Run: `php artisan test`
Expected: tidak ada regresi. Bila ada test lama gagal 403, artinya ada route yang salah petakan — perbaiki `config/permissions.php` atau `$grants`, **bukan** test lamanya.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/EnforcePermission.php app/Http/Kernel.php routes/web.php tests/Feature/AccessParityTest.php
git commit -m "feat(access): middleware EnforcePermission (fail-closed) + uji paritas

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 5: Cabut 53 `role:` dari `web.php`

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Cabut seluruh `role:` middleware**

Hapus setiap `->middleware('role:...')` dan setiap `Route::middleware('role:...')->group(...)`
(pertahankan grupnya bila ia juga membawa prefix/name — cukup buang argumen `role:`-nya;
bila grup HANYA berisi `role:`, bongkar grupnya dan biarkan isinya di grup induk).

**Pengecualian — JANGAN dicabut:** `->middleware(['can:access-usermanagement'])` pada grup
`user-management`. Itu Gate, bukan role, dan sudah punya semantik sendiri. (Task 2 tetap memetakan
route `user.*` ke permission `user.*`, jadi ia berlapis; aman.)

- [ ] **Step 2: Pastikan tak ada sisa**

Run: `grep -n "role:" routes/web.php`
Expected: tidak ada keluaran.

- [ ] **Step 3: Uji paritas harus tetap hijau**

Run: `php artisan test --filter=AccessParityTest`
Expected: PASS — inilah bukti bahwa permission benar-benar menggantikan role tanpa mengubah akses.

- [ ] **Step 4: Seluruh suite**

Run: `php artisan test`
Expected: tidak ada regresi.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php
git commit -m "refactor(access): cabut 53 role: dari web.php, penegakan lewat permission

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

# FASE 2 — Halaman Hak Akses

## Task 6: Controller + view ceklis + rute

**Files:**
- Create: `app/Http/Controllers/Pages/PermissionController.php`
- Create: `resources/views/permissions/index.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/PermissionPageTest.php`

- [ ] **Step 1: Tulis test halaman (RED)**

Create `tests/Feature/PermissionPageTest.php`:

```php
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

        // production kini boleh listing order (sebelumnya tidak) ...
        $this->actingAs($this->user('production'))->get(route('order.book.index'))->assertOk();
        // ... dan kehilangan yang tak dicentang.
        $this->actingAs($this->user('production'))->get(route('manuscript.board'))->assertForbidden();
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
        $this->actingAs($this->user('superadmin'))
            ->put(route('permission.update'), ['role' => 'hantu', 'permissions' => []])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=PermissionPageTest`
Expected: FAIL (route `permission.index` belum ada).

- [ ] **Step 3: Controller**

Create `app/Http/Controllers/Pages/PermissionController.php`:

```php
<?php
// app/Http/Controllers/Pages/PermissionController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\PermissionMap;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    /** Role yang tak boleh disunting: superadmin selalu penuh lewat Gate::before. */
    private const LOCKED = ['superadmin'];

    public function index(Request $request)
    {
        $roles    = Role::orderBy('name')->pluck('name')->all();
        $selected = in_array($request->query('role'), $roles, true)
            ? $request->query('role')
            : (collect($roles)->first(fn ($r) => ! in_array($r, self::LOCKED, true)) ?? $roles[0]);

        $role    = Role::findByName($selected);
        $granted = $role->permissions->pluck('name')->all();

        return view('permissions.index', [
            'roles'    => $roles,
            'selected' => $selected,
            'locked'   => in_array($selected, self::LOCKED, true),
            'matrix'   => PermissionMap::matrix(),
            'granted'  => $granted,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'role'          => ['required', 'string', Rule::exists('roles', 'name')],
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionMap::allPermissions())],
        ]);

        abort_if(in_array($data['role'], self::LOCKED, true), 403,
            'Hak akses superadmin tidak dapat diubah.');

        Role::findByName($data['role'])->syncPermissions($data['permissions'] ?? []);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()
            ->route('permission.index', ['role' => $data['role']])
            ->with('success', 'Hak akses diperbarui.');
    }
}
```

- [ ] **Step 4: Rute + peta**

Di `routes/web.php`, tambahkan di dalam grup `['auth', 'access']`:
```php
    Route::get('hak-akses', [\App\Http\Controllers\Pages\PermissionController::class, 'index'])->name('permission.index');
    Route::put('hak-akses', [\App\Http\Controllers\Pages\PermissionController::class, 'update'])->name('permission.update');
```

Di `config/permissions.php`, tambahkan modulnya (kalau tidak, uji kelengkapan akan merah):
```php
        'permission' => [
            'label'   => 'Hak Akses',
            'actions' => ['manage' => ['permission.index', 'permission.update']],
        ],
```
`permission.manage` masuk daftar `$superadminOnly` di `AccessMatrixSeeder` — tambahkan.

- [ ] **Step 5: View ceklis**

Create `resources/views/permissions/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Hak Akses - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Hak Akses</h5>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted mb-0" style="font-size:13px">Role</label>
        <select name="role" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            @foreach($roles as $r)
                <option value="{{ $r }}" @selected($r === $selected)>{{ ucfirst($r) }}</option>
            @endforeach
        </select>
    </form>
</div>

@if($locked)
    <div class="alert alert-info">
        Role <strong>{{ ucfirst($selected) }}</strong> selalu memiliki seluruh hak akses dan tidak dapat diubah —
        ini pengaman agar tidak ada yang terkunci dari sistem.
    </div>
@endif

<form method="POST" action="{{ route('permission.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="role" value="{{ $selected }}">

    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th style="min-width:220px">Modul</th><th>Hak</th></tr></thead>
                <tbody>
                @foreach($matrix as $module => $def)
                    <tr>
                        <td class="dt-judul">
                            {{ $def['label'] }}
                            <div class="text-muted" style="font-size:11px">{{ $module }}</div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach($def['actions'] as $action => $permission)
                                    <label class="form-check-label d-flex align-items-center gap-1 mb-0">
                                        <input type="checkbox" class="form-check-input mt-0"
                                               name="permissions[]" value="{{ $permission }}"
                                               @checked($locked || in_array($permission, $granted, true))
                                               @disabled($locked)>
                                        <span>{{ $action }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @unless($locked)
            <button class="btn btn-primary btn-sm mt-2">Simpan</button>
        @endunless
    </div></div>
</form>
@endsection
```

- [ ] **Step 6: Jalankan — pastikan lulus**

Run: `php artisan test --filter=PermissionPageTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Suite penuh**

Run: `php artisan test`
Expected: hijau (uji kelengkapan peta ikut menjaga route baru sudah terdaftar).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/PermissionController.php resources/views/permissions/index.blade.php routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php tests/Feature/PermissionPageTest.php
git commit -m "feat(access): halaman Hak Akses (ceklis modul x aksi per role)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

# FASE 3 — Menu ikut permission

## Task 7: Sidebar `@role` → `@can` + perbaiki directive `@permission`

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Test: `tests/Feature/SidebarTest.php` (sudah ada — jangan rusak)

> **PERHATIAN:** `resources/views/layouts/sidebar.blade.php` dan `tests/Feature/SidebarTest.php`
> punya perubahan lokal dari pekerjaan lain. Sebelum mulai, jalankan `git status` — bila keduanya
> masih termodifikasi & belum ter-commit oleh pemiliknya, **hentikan dan laporkan** (jangan menimpa).

- [ ] **Step 1: Perbaiki directive `@permission`**

Di `app/Providers/AuthServiceProvider.php`, ganti blok yang salah:
```php
        Blade::if('permission', function ($permission) {
            $permission = \is_array($permission) ? $permission : explode('|', $permission);
             return Auth::check() && Auth::user()->hasAnyRole($permission);
        });
```
menjadi:
```php
        // Sebelumnya keliru memeriksa hasAnyRole() padahal namanya permission.
        Blade::if('permission', function ($permission) {
            $permission = \is_array($permission) ? $permission : explode('|', $permission);
            return Auth::check() && Auth::user()->hasAnyPermission($permission);
        });
```

- [ ] **Step 2: Ganti penjaga menu di sidebar**

Di `resources/views/layouts/sidebar.blade.php`, ganti tiap `@role('a|b')` / `@hasanyrole('a|b')`
yang membungkus item menu dengan `@can('<permission view modul tsb>')` sesuai peta. Contoh:
```blade
@hasanyrole('marketing|manager|superadmin')
    <li class="nav-item ..."><a href="{{ route('order.book.index') }}" ...>Order</a></li>
@endhasanyrole
```
menjadi:
```blade
@can('order.view')
    <li class="nav-item ..."><a href="{{ route('order.book.index') }}" ...>Order</a></li>
@endcan
```
Aturan: pakai permission dari route yang dituju item menu itu (lihat `config/permissions.php`).

- [ ] **Step 3: Jalankan test sidebar + suite**

Run: `php artisan test --filter=SidebarTest`
Expected: PASS. Bila test lama mengharapkan menu berdasar role, sesuaikan **hanya bila** ekspektasinya
memang berubah secara sah (menu kini mengikuti permission); jangan melemahkan assertion.

Run: `php artisan test`
Expected: hijau seluruhnya.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php app/Providers/AuthServiceProvider.php
git commit -m "feat(access): menu sidebar ikut permission + perbaiki directive @permission

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 8: Verifikasi akhir + browser

- [ ] **Step 1: Suite penuh**

Run: `php artisan test`
Expected: hijau, tanpa skip baru.

- [ ] **Step 2: Migrasi DB dev**

Run: `php artisan db:seed --class=AccessMatrixSeeder`
Expected: sukses. (DB dev `avidpedi_simapa` perlu permission ini, kalau tidak aplikasi live akan 403 di mana-mana.)

- [ ] **Step 3: Cek di browser (tak bisa headless)**

- [ ] Login superadmin → buka **Hak Akses**, ceklis tampil, role superadmin terkunci.
- [ ] Cabut satu hak (mis. `order.view` dari marketing) → login marketing → menu Order hilang & URL langsung 403.
- [ ] Kembalikan → akses pulih.
- [ ] Tiap role buka dashboard & menu utamanya: tidak ada 403 tak terduga.
- [ ] Manager/accounting/admin/production/marketing: bandingkan dengan sebelum migrasi — tidak ada yang kehilangan akses.

- [ ] **Step 4: Update memory**

Catat di memory: penegakan akses kini lewat `config/permissions.php` + `EnforcePermission` (fail-closed);
`role:` sudah tidak dipakai di `web.php`; menambah route baru WAJIB memetakannya atau test kelengkapan merah.

---

## Self-review (penulis plan)

- **Spec coverage:** peta terpusat (Task 1-2) ✓ · middleware fail-closed (Task 4) ✓ · seed paritas (Task 3) ✓ · cabut `role:` (Task 5) ✓ · halaman ceklis superadmin-only + superadmin terkunci (Task 6) ✓ · sidebar ikut permission + perbaikan `@permission` (Task 7) ✓ · uji paritas/kelengkapan/fail-closed/toggle (Task 1,3,4,6) ✓ · 3 tahap (Fase 1/2/3) ✓ · `accounting` sub-modul (Task 2 contoh + tabel spec) ✓ · `title.progress.logs` ditutup (Task 2 Step 2) ✓.
- **Placeholder scan:** Task 2 sengaja data-entry yang dituntun test — bukan placeholder, karena keluaran test mendaftar persis apa yang kurang dan contoh bentuknya diberikan lengkap.
- **Type consistency:** `PermissionMap::routeToPermission/allPermissions/isPublic/permissionFor/matrix` dipakai konsisten di middleware (Task 4), seeder (Task 3), controller & view (Task 6). Bentuk `matrix()` = `[modul => ['label', 'actions' => [aksi => permission]]]` cocok dengan pemakaian di blade.
- **Risiko urutan:** ditegaskan di "Urutan aman" dan Task 4 Step 5 (jangan cabut `role:` di task itu).
