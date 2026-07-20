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
 * SETARA dengan matriks role: yang berlaku sebelum migrasi (routes/web.php hari ini) —
 * supaya hari pertama enforcement (Task 4) tidak ada satu pun user yang kehilangan
 * atau mendapat akses baru.
 *
 * Superadmin sengaja TIDAK diberi hibah: ia lolos lewat Gate::before di AuthServiceProvider.
 *
 * "order.view" dan "manuscript.view" DULU masing-masing mencampur route berpenjagaan
 * role: dengan route yang sama sekali tanpa penjagaan (order.indexJudul.detail/.progress,
 * title.progress.logs) — satu permission tidak bisa mewakili dua tingkat akses berbeda.
 * Sudah diperbaiki di config/permissions.php: ketiga route tanpa penjagaan itu dipindah
 * ke action baru "manuscript.detail" ("lihat detail progres satu judul"), yang memang
 * ditautkan dari papan manuskrip (resources/views/manuscript/list.blade.php &
 * partials/card.blade.php) — production perlu route ini utk alur kerja mereka. Dengan
 * pemisahan ini, order.view dan manuscript.view sekarang murni mengikuti gerbang role:
 * aslinya tanpa kompromi, dan manuscript.detail digelar ke SEMUA role sesuai keterbukaannya
 * hari ini.
 */
class AccessMatrixSeeder extends Seeder
{
    /** Permission per role. '*' = seluruh permission (kecuali dikecualikan). */
    private array $grants = [
        'marketing' => [
            'order.view', 'order.create', 'order.edit',
            'payment.view', 'payment.create',
            'invoice.view', 'invoice.export',
            'tagihan.*',
            'income.*',
            // marketing-target.index/store/paid/destroy dijaga role:manager|superadmin —
            // marketing TIDAK ikut (hanya marketing-target.me, yang public, di luar modul ini).
            'title.view', 'journal.view', 'isbn.view', 'archive.view',
            'author.view',
            // manuscript.view (papan Kanban) TIDAK utk marketing — route manuscript.board
            // dijaga role:production|manager|superadmin. manuscript.detail (lihat progres
            // satu judul) TETAP dapat — route sumbernya tanpa penjagaan role: sama sekali.
            'manuscript.target', 'manuscript.detail',
            'data.*',
        ],
        'production' => [
            // manuscript.* SENGAJA tidak dipakai sebagai wildcard: modul manuscript juga
            // memuat 'review' (route manuscript.reviewed, dijaga role:manager|superadmin —
            // production tidak ikut) dan 'clear-log' (superadmin only).
            'manuscript.view', 'manuscript.detail', 'manuscript.move', 'manuscript.assign',
            'manuscript.priority', 'manuscript.target',
            'chapter.*',
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.submit',
            'journal.view', 'isbn.*', 'archive.view', 'archive.artifacts', 'archive.submit',
            'data.*',
        ],
        'admin' => [
            'announcement.*',
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.submit', 'title.info',
            'title.doc.*',
            'journal.*', 'isbn.*', 'author.view',
            'archive.view', 'archive.artifacts', 'archive.submit',
            // manuscript.detail (lihat progres satu judul) terbuka utk semua role login —
            // bukan manuscript.view (papan Kanban), yang admin TIDAK punya hari ini.
            'manuscript.detail',
            'data.*',
        ],
        'accounting' => [
            'accounting.*',
            // title.view/journal.view/isbn.view/archive.view/manuscript.detail: route
            // sumbernya (title.index, journal.index, isbn.index, archive.index,
            // order.indexJudul.detail/.progress, title.progress.logs) tanpa role: middleware
            // sama sekali — terbuka utk SEMUA role yang login. manuscript.view (papan Kanban)
            // BUKAN bagian dari ini — accounting tidak punya akses ke situ hari ini.
            'title.view', 'journal.view', 'isbn.view', 'archive.view', 'manuscript.detail',
            'data.*',
        ],
        'manager' => [
            // Manager = seluruh permission KECUALI:
            //  - $superadminOnly (murni superadmin: refund order, kunci periode kas,
            //    template checklist dokumen, manuscript.clear-log);
            //  - seluruh accounting.* (route accounting/* dijaga role:superadmin|accounting —
            //    manager TIDAK ikut di gerbang itu sama sekali);
            //  - title.doc.edit/title.doc.submit (route titles/{id}/doc-check* dijaga
            //    role:superadmin|admin — manager TIDAK ikut).
            '*',
        ],
    ];

    /** Permission yang murni untuk superadmin saja (dikecualikan dari SEMUA wildcard). */
    private array $superadminOnly = [
        'order.refund',
        'accounting.period.lock',
        'doc-req.create', 'doc-req.edit', 'doc-req.delete',
        'manuscript.clear-log',
        'permission.manage',
        // CATATAN: user.view/create/edit/delete/restore TIDAK di sini walau kelihatan
        // "sensitif" — gate 'access-usermanagement' (AuthServiceProvider) adalah
        // superadmin|manager, jadi manager memang berhak dan permission ini justru harus
        // ikut lolos lewat hibah '*' manager di bawah.
    ];

    /**
     * Permission tambahan (bukan superadmin-exclusive — role lain seperti admin/accounting
     * tetap memilikinya) yang dikecualikan KHUSUS dari hibah wildcard '*' milik manager,
     * karena gerbang route aslinya tidak menyertakan role manager sama sekali.
     */
    private array $managerAlsoExcluded = [
        'title.doc.edit', 'title.doc.submit',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $all = PermissionMap::allPermissions();
        foreach ($all as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $accountingPermissions = array_values(array_filter($all,
            fn ($n) => str_starts_with($n, 'accounting.')));

        foreach ($this->grants as $roleName => $patterns) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            $exclude = $roleName === 'manager'
                ? array_values(array_unique(array_merge(
                    $this->superadminOnly,
                    $this->managerAlsoExcluded,
                    $accountingPermissions
                )))
                : $this->superadminOnly;

            $role->syncPermissions($this->expand($patterns, $all, $exclude));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Kembangkan pola ('*', 'modul.*', nama persis) jadi daftar permission nyata. */
    private function expand(array $patterns, array $all, array $exclude): array
    {
        $out = [];
        foreach ($patterns as $p) {
            if ($p === '*') {
                $out = array_merge($out, array_diff($all, $exclude));
                continue;
            }
            if (str_ends_with($p, '.*')) {
                $prefix = substr($p, 0, -1); // "modul."
                $out = array_merge($out, array_filter($all,
                    fn ($n) => str_starts_with($n, $prefix) && ! in_array($n, $exclude, true)));
                continue;
            }
            if (in_array($p, $all, true)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
