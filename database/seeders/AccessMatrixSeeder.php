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
            'order.view', 'order.create', 'order.edit', 'order.cancel',
            'payment.view', 'payment.create',
            'invoice.view', 'invoice.export',
            'tagihan.*',
            'income.*',
            // marketing-target.index/store/paid/destroy dijaga role:manager|superadmin —
            // marketing TIDAK ikut. marketing-target.me ("Target Saya") beda gerbang
            // (role:marketing|manager|superadmin) — marketing IKUT di situ.
            'marketing-target.me',
            'title.view', 'journal.view', 'isbn.view', 'archive.view',
            'author.view',
            // manuscript.view (papan Kanban) TIDAK utk marketing — route manuscript.board
            // dijaga role:production|manager|superadmin. manuscript.detail (lihat progres
            // satu judul) TETAP dapat — route sumbernya tanpa penjagaan role: sama sekali.
            'manuscript.target', 'manuscript.detail',
            // Penugasan Naskah: marketing melihat semuanya read-only, set target
            // (request klien, tercatat di riwayat), upload naskah masuk dari klien,
            // dan memetakan author bab saat struktur buku dibuat. TANPA workdesk —
            // marketing tidak pernah memegang tugas naskah.
            'naskah.view', 'naskah.target', 'naskah.upload', 'naskah.author', 'naskah.struktur',
            'data.*',
        ],
        'production' => [
            // Papan Pelacakan kini hanya-baca (manuscript.view/detail); mutasi tahap/editor
            // pindah ke modul distribution.* (Distribusi Artikel/Buku).
            'manuscript.view', 'manuscript.detail',
            'distribution.*',
            // Penugasan Naskah: production = Pelaksana — meja kerja sendiri, claim
            // antrian tanpa pelaksana, upload naskah (pemicu auto-advance). TANPA
            // assign/advance — memajukan tahap adalah pekerjaan PJ (admin).
            'naskah.view', 'naskah.workdesk', 'naskah.upload', 'naskah.claim',
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.submit',
            'journal.view', 'isbn.*', 'archive.view', 'archive.artifacts', 'archive.submit',
            'data.*',
        ],
        'admin' => [
            'announcement.*',
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.deactivate', 'title.submit', 'title.info',
            'title.doc.*',
            'journal.*', 'isbn.*', 'author.view',
            'archive.view', 'archive.artifacts', 'archive.submit',
            // manuscript.detail (lihat progres satu judul) terbuka utk semua role login —
            // manuscript.view (papan Kanban) DITAMBAHKAN agar admin bisa lihat papan
            // read-only, setara production, seiring peran admin naik jadi pendistribusi.
            'manuscript.detail', 'manuscript.view',
            'distribution.*',
            // Penugasan Naskah: admin = PJ per bidang, aktor utama — distribusi/tarik/
            // oper PJ, maju tahap, prioritas/hold/batal, target, upload, pemetaan author
            // bab. naskah.correct TIDAK di sini (superadmin-only, lihat $superadminOnly);
            // scoping bidang (artikel|buku) ditegakkan di service, bukan di permission.
            'naskah.view', 'naskah.workdesk', 'naskah.target', 'naskah.upload',
            'naskah.assign', 'naskah.advance', 'naskah.priority', 'naskah.hold',
            'naskah.cancel', 'naskah.author', 'naskah.struktur',
            'data.*',
        ],
        'accounting' => [
            'accounting.*',
            'salary.*',
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
            //    template checklist dokumen);
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
        'permission.manage',
        // Koreksi tahap naskah (mundur/lompat, termasuk membuka tahap final) — keputusan
        // bisnis Penugasan Naskah #6/#7: hanya superadmin, wajib catatan, selalu tercatat.
        'naskah.correct',
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

        // Permission ber-scope khusus yang dikecualikan dari hibah wildcard '*' milik manager:
        // seluruh accounting.* (route accounting/* dijaga role:superadmin|accounting) DAN
        // seluruh salary.* (slip gaji sengaja hanya superadmin|accounting — manager TIDAK ikut).
        $financeScopedPermissions = array_values(array_filter($all,
            fn ($n) => str_starts_with($n, 'accounting.') || str_starts_with($n, 'salary.')));

        foreach ($this->grants as $roleName => $patterns) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            $exclude = $roleName === 'manager'
                ? array_values(array_unique(array_merge(
                    $this->superadminOnly,
                    $this->managerAlsoExcluded,
                    $financeScopedPermissions
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
