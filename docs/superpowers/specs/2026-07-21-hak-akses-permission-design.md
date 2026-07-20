# Halaman Hak Akses — Permission Custom Berbasis Ceklis

**Tanggal:** 2026-07-21
**Status:** Disetujui (brainstorm), menunggu plan implementasi
**Terkait:** memory `ui-conventions`, `dashboard-per-role-followups`; commit `4bed5f4` (perbaikan kebocoran nilai order)

## Masalah

Kontrol akses aplikasi saat ini **hardcoded di route**: 53 deklarasi `role:...` di `routes/web.php`
menjaga 212 route, dengan 12 kombinasi role berbeda. Sementara itu tabel permission Spatie
**ada tapi mati total**: `PermissionSeed` membuat 11 permission (`view.order`, `create.order`,
`aproval.order` [sic], …) dan hanya memberikannya ke role `marketing` — tidak ada satu baris kode
pun yang memakainya (tak ada middleware `permission:`, tak ada `can('view.order')`, tak ada
`hasPermissionTo`).

User ingin **halaman untuk mengatur hak akses lewat ceklis, termasuk hak CRUD**.

**Kendala inti yang menentukan seluruh desain:** halaman ceklis akan jadi **hiasan** selama keputusan
akses masih ditentukan `role:` di `web.php`. Dicentang atau tidak, aksesnya tetap dari baris route.
Karena itu penegakan harus berpindah dari "role apa" ke "punya permission apa".

## Keputusan yang sudah dikunci (dari brainstorm)

1. **Peta terpusat route→permission** (`config/permissions.php`) + satu middleware generik.
   Halaman ceklis dibangun dari peta yang SAMA → UI dan penegakan tak mungkin menyimpang.
2. **Granularitas modul × aksi (CRUD), diberikan per ROLE** (bukan per user).
3. **Superadmin selalu bypass** (`Gate::before` yang sudah ada dipertahankan); halaman hak akses
   khusus superadmin; baris role superadmin tak bisa diubah dari UI.
4. **`accounting` dipecah jadi sub-modul** (35 route terlalu kasar untuk satu izin, menyangkut uang).
5. **Dikerjakan 3 tahap** (lihat "Tahapan").

## Arsitektur

### A. `config/permissions.php` — sumber kebenaran tunggal

```php
return [
    // Route yang TIDAK butuh permission (cukup terautentikasi): milik-sendiri / lintas-role.
    'public' => [
        'dashboard', 'profile', 'profile.image',
        'notifications.index', 'notifications.read', 'notifications.readAll',
        'announcement.seen',
        // Tugas & laporan PRIBADI. Sengaja didaftar satu per satu, BUKAN 'task.*',
        // karena task.monitor (papan tugas semua orang) justru harus berizin.
        'task.index', 'task.board', 'task.calendar', 'task.events', 'task.reorder',
        'task.store', 'task.update', 'task.destroy', 'task.status', 'task.schedule',
        'report.daily', 'report.note', 'report.submit',
        'report.files.store', 'report.files.destroy', 'report.monthly',
        'marketing-target.me',
    ],

    'modules' => [
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
        // … modul lain, lihat "Daftar modul" di bawah
    ],
];
```

Nama permission = `"<modul>.<aksi>"` → `order.view`, `order.refund`, `accounting.journal.edit`.
Sub-modul ditulis bertitik: kunci modul `accounting.journal` → permission `accounting.journal.edit`.

**Aturan wajib:** setiap route bernama HARUS berada di `public` atau di salah satu `actions`.
Ada test yang menjaga ini (lihat Testing) agar route baru tak diam-diam lolos tanpa izin.

### B. Middleware generik `EnforcePermission`

Dipasang pada grup `auth` di `web.php` (menggantikan seluruh `role:...`):

```php
$name = $request->route()?->getName();
if ($name === null || $this->isPublic($name)) return $next($request);
$perm = $this->permissionFor($name);          // dari config, dengan cache
abort_if($perm === null, 403);                // route tak terpeta = tolak (fail-closed)
abort_unless($request->user()->can($perm), 403);
return $next($request);
```

- **Fail-closed**: route bernama yang tidak terpeta ditolak, bukan diloloskan. Ini yang mencegah
  celah seperti `title.progress.logs` (saat ini terbuka tanpa penjagaan sama sekali).
- Superadmin lolos lebih dulu lewat `Gate::before` → `can()` selalu true.
- Peta dibalik jadi `route name => permission` sekali lalu di-cache (array datar, bukan pencarian bersarang).

### C. Seed paritas — perilaku hari pertama identik

Seeder/migrasi menurunkan hibah role→permission dari **matriks yang berlaku sekarang**.
Contoh: semua route yang kini `role:marketing|manager|superadmin` → permission-nya diberikan ke
ketiga role itu. Route yang kini terbuka untuk semua role login tetapi masuk peta modul (mis.
`title.index`, `journal.index`, `isbn.index`, `archive.index`) → permission `view`-nya diberikan ke
SEMUA role, sehingga perilaku tak berubah tapi kini **bisa dicabut lewat ceklis**.

Ini jaring pengaman utama migrasi 53 titik; dikunci uji paritas.

### D. Halaman "Hak Akses"

- Route: `permission.index` (GET), `permission.update` (PUT) — middleware superadmin.
- Menu sidebar: grup "Akun & Sistem", di dekat Manajemen User.
- UI: pilih role (tab/dropdown, kecuali superadmin) → tabel **baris = modul, kolom = aksi**
  (view/create/edit/delete + aksi khusus), sel = checkbox. Ada "centang sebaris" dan "centang sekolom".
  Simpan → `syncPermissions()` untuk role tsb, lalu `forgetCachedPermissions()`.
- **Baris superadmin dikunci** (tampil penuh, disabled) — pencegah mengunci diri sendiri.
- Aksi yang tak berlaku untuk suatu modul dirender sebagai sel kosong (bukan checkbox).

### E. Menu & tombol ikut permission

`resources/views/layouts/sidebar.blade.php` dan tombol aksi kini pakai `@role`/`@hasanyrole`
(28 pemakaian). Diganti `@can('modul.aksi')` supaya menu yang pasti 403 tidak muncul.
**Directive `@permission` yang rusak diperbaiki** — sekarang isinya `hasAnyRole()` padahal namanya
permission; diganti memakai `hasAnyPermission()` (atau dihapus, cukup `@can` bawaan Laravel).

### F. Daftar modul (rencana awal, difinalkan saat implementasi)

| Modul | Aksi |
|---|---|
| `order` | view, create, edit, refund |
| `payment` | view, create, approve, edit |
| `invoice` | view, edit, cancel, export |
| `tagihan` | view, create, edit, approve, cancel, export |
| `income` | view, export |
| `marketing-target` | view, create, edit, delete |
| `title` | view, create, edit, delete, approve, info |
| `title.doc` | edit, submit (cek kelengkapan dokumen) |
| `doc-req` | create, edit, delete (template checklist) |
| `author` | view |
| `journal` | view, create, edit, delete, submission |
| `isbn` | view, create, edit, delete |
| `manuscript` | view, move, assign, priority, review, target, clear-log |
| `chapter` | advance, assign |
| `archive` | view, artifacts, submit, approve |
| `announcement` | view, create, edit, delete, status |
| `task.monitor` | view (papan tugas semua orang) |
| `report.submissions` | view (rekap setoran laporan) |
| `user` | view, create, edit, delete, restore |
| `data` | view, create, edit, delete, download (kepemilikan tetap dijaga controller) |
| `accounting.overview` | view |
| `accounting.journal` | view, create, edit, delete, export, transfer |
| `accounting.master` | create, edit, delete (akun kas & kategori) |
| `accounting.recap` | view, export |
| `accounting.distribution` | view, edit |
| `accounting.assumption` | view, create, edit, delete |
| `accounting.target` | view, edit |
| `accounting.period` | lock |
| `accounting.audit` | view |
| `accounting.profit` | view |

≈ 30 modul, ≈ 100 permission.

## Tahapan

**Tahap 1 — Fondasi (perilaku identik, belum ada UI).** `config/permissions.php` + `EnforcePermission`
+ seed paritas + cabut 53 `role:` dari `web.php` + uji paritas. Setelah tahap ini akses sudah
digerakkan permission, tapi tak ada perubahan yang terasa bagi pengguna. **Ini tahap paling berisiko.**

**Tahap 2 — Halaman Hak Akses.** Controller + view ceklis + rute superadmin + test.

**Tahap 3 — Menu ikut permission.** Sidebar & tombol `@role` → `@can`; perbaiki directive `@permission`.

Tiap tahap berdiri sendiri (aplikasi tetap jalan & hijau di akhir tiap tahap).

## Testing

- **Uji paritas (kunci utama):** matriks role×route sebelum migrasi direkam sebagai data uji; setelah
  migrasi, untuk tiap role dan tiap route yang dipetakan, hasil akses (200/302 vs 403) harus sama.
- **Uji kelengkapan peta:** setiap route bernama di `route:list` harus ada di `public` atau di peta modul
  — gagal bila ada yang terlewat (mencegah route baru lolos diam-diam).
- **Uji fail-closed:** route bernama yang sengaja dihapus dari peta → 403, bukan lolos.
- **Uji toggle:** cabut `order.view` dari marketing → `order.book.index` jadi 403; centang lagi → 200.
- **Uji superadmin:** tetap lolos semua walau seluruh permission dicabut dari role lain.
- **Uji halaman:** hanya superadmin yang bisa buka `permission.index`; percobaan mengubah role
  superadmin ditolak; menyimpan benar-benar mengubah akses (end-to-end dengan toggle di atas).
- Semua test lewat DB test (`.env.testing`), role di-seed dengan `Role::firstOrCreate`.

## Risiko

- **Salah petakan → user terkunci.** Ditutup uji paritas + `Gate::before` superadmin + baris superadmin
  dikunci di UI. Bila terjadi di produksi, superadmin selalu bisa memperbaiki lewat halaman.
- **Route baru lupa dipetakan.** Fail-closed membuatnya 403 (aman, bukan bocor) dan uji kelengkapan
  peta membuatnya ketahuan saat test.
- **Cache permission Spatie basi** setelah menyimpan → wajib `forgetCachedPermissions()` di controller.
- **Peta besar (≈212 route)** melelahkan disusun manual; disusun modul-per-modul dengan `route:list`
  sebagai daftar periksa, dan uji kelengkapan memastikan tak ada yang tertinggal.

## Di luar cakupan (YAGNI)

- Override permission **per user** (hanya per role; Spatie mendukung, bisa menyusul).
- Membuat/menghapus **role** dari UI (daftar role tetap 6, dikelola kode/migrasi).
- Permission tingkat baris (mis. "hanya order milik sendiri") — tetap di controller seperti sekarang
  (scoping marketing, kepemilikan Gudang Data).
- Audit log perubahan permission.
- Membersihkan 11 permission lama (`view.order` dkk) di luar penggantiannya oleh skema baru.
