# Spec — Direktori Judul (Title Directory) — Fase 1

- **Tanggal:** 2026-07-02
- **Branch:** `title-directory`
- **Scope (Fase 1):** Entitas **Judul** berdiri sendiri + menu **Direktori Judul** + CRUD (artikel/buku, bab buku, indeksasi, tipe naskah) + alur **approval** (admin/production buat → tunggu approve superadmin/manager; superadmin/manager langsung disetujui). Belum menyentuh order/invoice/manuskrip.
- **Di luar scope (Fase 2 / sengaja):** integrasi ke alur order→invoice→manuskrip, buat-judul-inline-saat-order ("judul belum ada di list"), hitung jumlah author dari order, distribusi/assign judul ke marketing tertentu, direktori Jurnal/ISBN/HKI, per-bab status/author.

> Ini fondasi restrukturisasi "Judul sebagai entitas pusat". Saat ini judul terikat di `OrderDetail.title` + manuskrip menempel ke `OrderDetail`; Fase 2 (siklus terpisah) menyambungkan order→judul→manuskrip. Skema Fase 1 dirancang agar Fase 2 tinggal colok (field `asal`).

---

## 1. Latar Belakang

SiMAPA belum punya judul yang berdiri sendiri: judul lahir bersama order (`OrderDetail`), manuskrip (`TitleProgress`) menempel ke `OrderDetail`, "Arsip Judul" hanya pengelompokan OrderDetail. User ingin **Judul dibuat lebih dulu** (oleh superadmin/manager/admin/production), di-approve, lalu jadi kolam judul yang bisa dipakai marketing. Fase 1 membangun entitas + direktori + approval; integrasi ke order/manuskrip menyusul di Fase 2.

## 2. Tujuan & Kriteria Sukses

1. 4 role (superadmin/manager/admin/production) bisa membuat Judul (artikel/buku); buku bisa punya daftar **bab** (CRUD).
2. Judul buatan **admin/production** wajib di-approve superadmin/manager; buatan **superadmin/manager** langsung disetujui.
3. Hanya judul **disetujui** yang tampil sebagai judul tersedia (termasuk untuk marketing, read-only).
4. Penolakan menyertakan catatan; judul draft/ditolak bisa diedit & diajukan ulang.
5. Perilaku tertutup test; suite tetap hijau.

---

## 3. Data Model (2 tabel baru)

### 3.1 `tb_titles`
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `title` | string | judul |
| `jenis` | string(16) | `artikel` / `buku` |
| `indeksasi` | string(64), nullable | mis. `none`, `SINTA 1`..`SINTA 6`, `Scopus Q1`..`Scopus Q4`, `Copernicus`, `WoS`, `DOAJ`, `Garuda`, atau kustom |
| `tipe_naskah` | string(16) | `mandiri` / `kolaborasi` |
| `status` | string(16), default `draft` | `draft` / `menunggu` / `disetujui` / `ditolak` |
| `asal` | string(16), default `distribusi` | `distribusi` / `order` (untuk Fase 2) |
| `slug` | string, nullable | dari judul + id |
| `created_by` | FK users, nullOnDelete | |
| `approved_by` | FK users, nullable nullOnDelete | |
| `approved_at` | timestamp, nullable | |
| `reject_note` | text, nullable | |
| timestamps | | |

Index `(status, jenis)`.

### 3.2 `tb_title_chapters` (bab; hanya untuk `jenis=buku`)
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `title_id` | FK → tb_titles, cascadeOnDelete | |
| `judul` | string | judul bab |
| `urutan` | unsignedInteger, default 0 | |
| timestamps | | |

Model `App\Models\Title` (`$table='tb_titles'`): fillable semua kolom di atas; const `JENIS=['artikel','buku']`, `TIPE=['mandiri','kolaborasi']`, `STATUSES=['draft','menunggu','disetujui','ditolak']`, `INDEKSASI` (daftar tercuratel di §4.1); relasi `chapters()` hasMany (urut `urutan`), `creator()`, `approver()`; helper `isEditable()` (`in [draft, ditolak]`), `isApproved()`. Model `App\Models\TitleChapter` (fillable title_id/judul/urutan).

*Catatan: jumlah author bersifat turunan dari order (Fase 2) — tidak disimpan, tidak ditampilkan di Fase 1.*

## 4. Alur Status & Approval

Transisi (di `TitleService`):
- **Buat** (`store`) → `status = draft`, `created_by = actor`. Untuk `jenis=buku`, simpan bab dari repeater.
- **Ajukan** (`submit`, oleh pembuat/pengelola) → jika actor **admin/production** → `menunggu`; jika **superadmin/manager** → langsung `disetujui` (set `approved_by`/`approved_at`). Hanya boleh dari `draft`/`ditolak`.
- **Setujui** (`approve`, superadmin/manager) → dari `menunggu` → `disetujui` (+ approved_by/at). 
- **Tolak** (`reject`, superadmin/manager) → dari `menunggu` → `ditolak` (+ `reject_note`).
- **Edit/Hapus**: hanya saat `draft`/`ditolak` (pembuat), atau superadmin. Judul `menunggu`/`disetujui` terkunci dari edit (ubah butuh alur khusus di luar scope).

### 4.1 Daftar indeksasi (const, boleh diperluas + entri kustom)
`none`, `SINTA 1`, `SINTA 2`, `SINTA 3`, `SINTA 4`, `SINTA 5`, `SINTA 6`, `Scopus Q1`, `Scopus Q2`, `Scopus Q3`, `Scopus Q4`, `Copernicus`, `WoS`, `DOAJ`, `Garuda`. Field `indeksasi` disimpan sebagai **string**; UI pakai **select2** dengan daftar ini + **tags** (boleh ketik nilai baru) — pola seperti field scope pada order.

## 5. Halaman & Route

Grup `auth`. Controller `App\Http\Controllers\Pages\TitleController`.

| Route | Nama | Aksi | Akses |
|---|---|---|---|
| `GET /titles` | `title.index` | List (DataTables) | 5 role (marketing hanya `disetujui`) |
| `GET /titles/create` | `title.create` | form buat | superadmin/manager/admin/production |
| `POST /titles` | `title.store` | simpan (draft) | superadmin/manager/admin/production |
| `GET /titles/{id}` | `title.show` | detail (judul + bab) | 5 role (marketing hanya `disetujui`) |
| `GET /titles/{id}/edit` | `title.edit` | form edit | pembuat/superadmin, saat draft/ditolak |
| `PUT /titles/{id}` | `title.update` | perbarui + bab | pembuat/superadmin, saat draft/ditolak |
| `DELETE /titles/{id}` | `title.destroy` | hapus | pembuat (draft) / superadmin |
| `POST /titles/{id}/submit` | `title.submit` | ajukan | pembuat/pengelola |
| `POST /titles/{id}/approve` | `title.approve` | setujui | superadmin/manager |
| `POST /titles/{id}/reject` | `title.reject` | tolak (+catatan) | superadmin/manager |

Grup route `role:superadmin|manager|admin|production` untuk create/store/edit/update/destroy/submit; `role:superadmin|manager` untuk approve/reject; index/show untuk semua auth (kontroler men-scope marketing ke `disetujui`).

- **List view** (`resources/views/titles/index.blade.php`): DataTables — Judul · Jenis · Indeksasi · Tipe · Status(badge draf abu/menunggu kuning/disetujui hijau/ditolak merah) · Pembuat · Aksi (Lihat, Edit bila editable, Ajukan bila draft/ditolak & pembuat, Setujui/Tolak bila menunggu & manager, Hapus). Filter jenis/status (segmented/select). Marketing: hanya baris `disetujui`, aksi Lihat saja.
- **Form** (`resources/views/titles/form.blade.php`): judul, jenis (select artikel/buku), indeksasi (select2 tags), tipe_naskah (mandiri/kolaborasi); **repeater bab** tampil hanya bila jenis=buku (judul bab + urutan, tambah/hapus baris — pola repeater author di order). SweetAlert2 untuk konfirmasi (hapus/tolak) sesuai konvensi global.
- **Detail** (`resources/views/titles/show.blade.php`): info judul + daftar bab (bila buku) + tombol aksi sesuai status/role + catatan tolak bila ada.
- **Sidebar**: item **"Direktori Judul"** (grup Order & Naskah), `@role(['superadmin','manager','admin','production','marketing'])`.

## 6. Komponen yang Dibuat / Disentuh

- **Baru:** migrasi `tb_titles` + `tb_title_chapters`; `app/Models/Title.php`, `TitleChapter.php`; `app/Services/TitleService.php` (store+chapters, submit, approve, reject, transisi); `app/Http/Controllers/Pages/TitleController.php`; `resources/views/titles/{index,form,show}.blade.php`.
- **Diubah:** `routes/web.php` (grup route titles), `resources/views/layouts/sidebar.blade.php` (menu Direktori Judul).

## 7. Rencana Test

- **Unit `TitleServiceTest`**: store buku menyimpan bab (urut); `submit` oleh production → `menunggu`; `submit` oleh superadmin → `disetujui` (+approved_by); `approve`/`reject` transisi + reject_note; `isEditable` benar.
- **Feature `TitleControllerTest`**: production buat → draft → ajukan → menunggu; superadmin approve → disetujui; production **tak bisa** approve (403); marketing index hanya melihat judul `disetujui` (tidak melihat draft/menunggu); edit judul `disetujui` diblok; hapus draft oleh pembuat.
- Suite pakai DB test (`.env.testing`, `RefreshDatabase`); mock `GoogleDriveService`. **Dev/prod: `php artisan migrate`** untuk 2 tabel (lihat [[migrate-dev-db-after-new-migration]]).

## 8. Asumsi & Risiko

- Judul berdiri sendiri; **belum** terhubung ke order/manuskrip (Fase 2). `asal='distribusi'` default; Fase 2 memakai `asal='order'` untuk judul yang lahir dari order (author bawa judul sendiri + marketing langsung order).
- Approval hanya untuk buatan admin/production; superadmin/manager auto-disetujui saat Ajukan.
- Indeksasi string + select2 tags → mendukung SINTA/Scopus/dll + nilai kustom tanpa migrasi.
- Bab sederhana (judul + urutan); status/author per-bab menyusul (Fase 2).
- Tidak mengubah struktur/alur order/manuskrip yang ada → suite existing tetap hijau.
