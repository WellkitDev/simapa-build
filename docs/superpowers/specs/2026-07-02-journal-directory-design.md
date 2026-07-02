# Spec — Direktori Jurnal (A: CRUD)

- **Tanggal:** 2026-07-02
- **Branch:** `journal-directory`
- **Scope (A):** Entitas **Jurnal** + halaman admin (index DataTables, create/edit/detail, hapus) + menu sidebar. "Gudang jurnal" tempat rujukan submit/publish artikel. CRUD oleh superadmin/manager/admin; **lihat oleh semua staf**.
- **Di luar scope (sengaja):** (**B**) tracking submit artikel ke jurnal (submission: LoA/Drive, tgl submit/terbit, OJS, bukti bayar, link publish) — siklus terpisah; (**C**) panel publikasi judul memilih jurnal dari direktori ini (ganti teks bebas `TitleJournalOption`) — menyusul setelah B.

> Pola mengikuti Direktori Judul: DataTables (`datatables.net-bs4`), select2 (akreditasi & scope), SweetAlert2 (hapus). Reuse `Title::INDEKSASI` (akreditasi) & `Scope`/`tb_scopes` (bidang jurnal) agar kosakata konsisten lintas judul/order/jurnal.

---

## 1. Tujuan & Kriteria Sukses

1. Pengelola (superadmin/manager/admin) bisa **CRUD** jurnal; semua staf bisa **melihat** direktori (read-only).
2. Index menampilkan kolom: Jurnal · Akreditasi · Terbitan (badge bulan) · Scope · APC Reguler · Fastrack · Link · Aksi.
3. Jurnal menyimpan bulan terbitan **jamak** (mis. Jan & Jun) dan menampilkannya sebagai badge.
4. Detail jurnal menampilkan seluruh atribut + kontak editor (WA, email); seksi "Artikel di jurnal ini" disiapkan sebagai placeholder untuk Fase B.
5. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model — `tb_journals`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `nama` | string | nama jurnal (required) |
| `akreditasi` | string(64), nullable | mis. `SINTA 2`, `Scopus Q2` — select2-tags dari `Title::INDEKSASI` + kustom |
| `scope_id` | FK → tb_scopes, nullable nullOnDelete | bidang jurnal; reuse `Scope` (select2-tags, `firstOrCreate` bila nama baru) |
| `apc_reguler` | string, nullable | bebas ("Rp 3.000.000" / "Gratis") |
| `apc_fastrack` | string, nullable | bebas |
| `link` | string, nullable | URL |
| `kontak_wa` | string, nullable | WA editor |
| `kontak_email` | string, nullable | email editor |
| `terbitan_bulan` | json, nullable | array bulan (1–12) |
| `catatan` | text, nullable | |
| `created_by` | FK → users, nullable nullOnDelete | |
| timestamps | | |

Model `App\Models\Journal` (`$table='tb_journals'`): fillable semua kolom; `$casts['terbitan_bulan']='array'`; const `MONTHS = [1=>'Jan',2=>'Feb',…,12=>'Des']`; relasi `scope()` belongsTo(Scope), `creator()` belongsTo(User); helper `terbitanLabels(): array` (map `terbitan_bulan` → nama bulan, urut). (Reuse `Scope` model `tb_scopes` yang sudah ada.)

## 3. Halaman & Route

Grup `auth`. Controller `App\Http\Controllers\Pages\JournalController`.

| Route | Nama | Aksi | Akses |
|---|---|---|---|
| `GET /journals` | `journal.index` | List (DataTables) | semua staf |
| `GET /journals/create` | `journal.create` | form buat | superadmin/manager/admin |
| `POST /journals` | `journal.store` | simpan | superadmin/manager/admin |
| `GET /journals/{id}` | `journal.show` | detail | semua staf |
| `GET /journals/{id}/edit` | `journal.edit` | form edit | superadmin/manager/admin |
| `PUT /journals/{id}` | `journal.update` | perbarui | superadmin/manager/admin |
| `DELETE /journals/{id}` | `journal.destroy` | hapus | superadmin/manager/admin |

`index`/`show` untuk semua auth; create/store/edit/update/destroy dalam grup `role:superadmin|manager|admin`. Controller mengirim `canManage` (bool) ke view untuk menampilkan tombol.

- **Index view** (`resources/views/journals/index.blade.php`): DataTables — Jurnal · Akreditasi(badge) · Terbitan(badge bulan) · Scope · APC Reguler · Fastrack · Link(anchor) · Aksi (Lihat; Edit/Hapus bila `canManage`). Tombol "Tambah Jurnal" bila `canManage`.
- **Form** (`resources/views/journals/form.blade.php`): nama, akreditasi (select2-tags dari `Title::INDEKSASI`), scope (select2-tags dari `Scope::all()`), apc_reguler, apc_fastrack, link, kontak_wa, kontak_email, **terbitan_bulan** (checkbox Jan–Des, `name="terbitan_bulan[]"`), catatan. SweetAlert2 hapus via `data-confirm` global.
- **Detail** (`resources/views/journals/show.blade.php`): semua atribut + kontak editor + badge terbitan; seksi **"Artikel di jurnal ini"** menampilkan placeholder "(akan hadir di fase berikutnya)"; tombol Edit/Hapus bila `canManage`.
- **Sidebar**: item **"Direktori Jurnal"** (grup Order & Naskah), `@role(['superadmin','manager','admin','production','marketing'])`.

## 4. Controller Detail

- `JournalController@store`/`@update` validasi: `nama` required string max 255; `akreditasi` nullable string max 64; `scope_id` nullable string max 255 (id atau nama baru → `Scope::firstOrCreate`); `apc_reguler`/`apc_fastrack`/`link`/`kontak_wa`/`kontak_email` nullable string max 255; `terbitan_bulan` nullable array; `terbitan_bulan.*` integer between 1..12; `catatan` nullable string. Scope diselesaikan seperti judul (numerik → id; selain itu → `firstOrCreate`). `created_by` di-set saat store.
- `canManage()` = `Auth::user()->hasAnyRole(['superadmin','manager','admin'])`.

## 5. Rencana Test

- **Feature `JournalControllerTest`**: manager create → tersimpan (assert DB, `terbitan_bulan` array); update mengubah field; destroy menghapus; marketing `GET index`/`show` → 200 tapi `GET create`/`POST store` → 403; scope nama baru → `Scope` dibuat & tertaut; `terbitanLabels()` menghasilkan nama bulan.
- **Smoke**: index & show render 200 untuk pengelola & marketing.
- Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** untuk `tb_journals` (lihat [[migrate-dev-db-after-new-migration]]).

## 6. Komponen

- **Baru:** migrasi `create_tb_journals`; `app/Models/Journal.php`; `app/Http/Controllers/Pages/JournalController.php`; `resources/views/journals/{index,form,show}.blade.php`; test `JournalControllerTest`.
- **Diubah:** `routes/web.php` (grup route journals), `resources/views/layouts/sidebar.blade.php` (menu Direktori Jurnal).

## 7. Asumsi & Risiko

- APC bebas (teks) agar fleksibel (Rp/USD/Gratis); bila kelak butuh angka/pelaporan, bisa ditambah kolom numerik terpisah (di luar scope).
- `terbitan_bulan` = array bulan (JSON) → cukup untuk badge; bila butuh tanggal terbit spesifik per edisi, itu urusan submission (Fase B).
- Reuse `Scope`/`tb_scopes` & `Title::INDEKSASI` menjaga kosakata konsisten; scope jurnal memakai pool yang sama dengan judul/order.
- Tracking submit artikel & integrasi panel judul sengaja ditunda (B & C) agar (A) kecil dan mergeable.
