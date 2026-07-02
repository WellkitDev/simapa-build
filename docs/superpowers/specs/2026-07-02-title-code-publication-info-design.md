# Spec — Judul: Kode Unik + Panel Informasi Publikasi

- **Tanggal:** 2026-07-02
- **Branch:** `title-order-link` (lanjutan, sebelum merge)
- **Scope:** (A) **Kode unik** judul (auto dari inisial, bisa ditimpa) untuk search/tracking, tampil di direktori + select2 order. (B) **Panel Informasi Publikasi** gaya profile-detail inline di `/titles/{id}` (target terbit, jurnal target + link + template, APC, catatan, repeater opsi jurnal lain). (C) **Log history** perubahan + **notifikasi lonceng** ke superadmin tiap update.
- **Di luar scope (sengaja):** direktori Jurnal/ISBN/HKI itu sendiri; menautkan opsi jurnal ke direktori jurnal riil; marketing melihat panel publikasi.

> Melanjutkan Fase 1 + 2a (entitas `Title` sudah tertaut order). Reuse pola `Notifier` (lonceng DB) & `TitleProgressLog` (log). Halaman detail judul saat ini satu kartu `col-md-8`; panel publikasi jadi kartu kedua.

---

## 1. Tujuan & Kriteria Sukses

1. Setiap judul punya **kode unik** singkat, otomatis dari judul, dapat ditimpa manual (superadmin/manager/admin) dengan validasi unik; judul lama ter-backfill.
2. Kode tampil di direktori (kolom + search) dan sebagai prefix di select2 form order (`KODE — Judul…`) untuk memudahkan pencarian.
3. Halaman detail judul punya **panel Informasi Publikasi** yang dapat dilihat superadmin/manager/admin/**production** dan **diubah** hanya superadmin/manager/admin.
4. Tiap perubahan panel → **entri log** (siapa, kapan, ringkasan) tampil sebagai Riwayat Perubahan, dan **superadmin dapat notifikasi lonceng**.
5. Perilaku tertutup test; seluruh suite tetap hijau.

---

## 2. A — Kode Unik Judul

### 2.1 Data
- Kolom `code` (string(16), `nullable`, `unique`) di `tb_titles`.

### 2.2 Generator (`TitleCodeService`)
- `generate(string $title, ?int $ignoreId = null): string`:
  1. Pecah judul jadi kata; buang tanda baca/simbol (`:`, `,`, dst); buang token kosong.
  2. Ambil huruf pertama (uppercase) dari **4 kata pertama** → `base` (mis. "Blockchain dalam Fintech Syariah: Transparansi Akad untuk UMKM Halal" → 4 kata pertama Blockchain, dalam, Fintech, Syariah → `BDFS`). **Tidak membuang stopword** — mengikuti contoh yang diminta. Bila kata < 2, pakai 2–4 huruf pertama judul yang dibersihkan (huruf/angka saja, uppercase); bila judul kosong/simbol semua, fallback `JDL`.
  3. **Keunikan**: bila `base` sudah dipakai judul lain (kecuali `$ignoreId`), coba `base.'2'`, `base.'3'`, … sampai unik.
- Dipanggil saat **membuat** judul: `TitleService::create` dan `TitleService::resolveForOrder` (judul inline dari order) → set `code` bila kosong.
- **Override manual**: field `code` di panel/edit; bila diisi, validasi `unique:tb_titles,code` (kecuali dirinya). Bila dikosongkan, regenerasi otomatis dari judul.

### 2.3 Backfill
- Migrasi menambah kolom lalu **mengisi `code` untuk semua judul yang sudah ada** (loop, pakai generator, jaga keunikan). Idempotent (hanya isi yang `null`).

### 2.4 Tampilan
- **Direktori index**: kolom **Kode** (paling kiri), termasuk dalam pencarian DataTables.
- **Detail**: kode di header (mis. badge `KODE`).
- **select2 order** (buku & jurnal, create & edit): teks opsi `"{{ $t->code }} — {{ $t->title }}"` sehingga ketik kode **atau** judul sama-sama menemukan. (Judul baru yang diketik tetap boleh; kode dibuat saat store.)

## 3. B — Panel Informasi Publikasi

### 3.1 Data
Kolom baru di `tb_titles` (semua `nullable`):
| Kolom | Tipe | Ket |
|---|---|---|
| `target_terbit` | date | rencana/target terbit |
| `jurnal_target` | string | nama jurnal target utama |
| `jurnal_link` | string | link jurnal target |
| `template_link` | string | link **template artikel** jurnal (dilihat production) |
| `apc_info` | string | info APC (mis. "Rp 3.000.000 / gratis") |
| `catatan_publikasi` | text | catatan bebas |

Tabel `tb_title_journal_options` (opsi jurnal lain, repeater):
| Kolom | Tipe |
|---|---|
| `id` | bigint PK |
| `title_id` | FK → tb_titles cascadeOnDelete |
| `nama_jurnal` | string |
| `link` | string nullable |
| `apc` | string nullable |
| `urutan` | unsignedInteger default 0 |
| timestamps | |

Model `App\Models\TitleJournalOption` (fillable title_id/nama_jurnal/link/apc/urutan); relasi `Title::journalOptions()` hasMany (urut `urutan`).

### 3.2 Panel (halaman detail, kartu kedua)
- Terlihat bila aktor ∈ {superadmin, manager, admin, production}. **Marketing tidak melihat**.
- **Read-only** menampilkan: kode, target terbit, jurnal target (+link), template link, APC, catatan, daftar opsi jurnal, dan **Riwayat Perubahan** (log terbaru).
- Bila aktor ∈ {superadmin, manager, admin}: tombol **"Edit Informasi"** → form inline (collapse) berisi: `code`, `target_terbit` (flatpickr), `jurnal_target`, `jurnal_link`, `template_link`, `apc_info`, `catatan_publikasi`, dan **repeater opsi jurnal** (nama_jurnal + link + apc; tambah/hapus baris — pola repeater bab). Tombol Simpan.

### 3.3 Endpoint & Controller
- Route `PUT titles/{id}/info` name `title.info.update`, middleware `role:superadmin|manager|admin`.
- `TitleController@updateInfo(Request, $id)`:
  - Validasi: `code` nullable string max 16 unik (kecuali dirinya); `target_terbit` nullable date; `jurnal_target|jurnal_link|template_link|apc_info` nullable string; `catatan_publikasi` nullable string; `journal_options` nullable array; `journal_options.*.nama_jurnal` nullable string; `.link|.apc` nullable string.
  - Via `TitleService::updateInfo($title, $data, $journalOptions, $actor)`: hitung **field yang berubah** (untuk ringkasan log), update kolom (kode kosong → regen), sync `journalOptions` (hapus & buat ulang, abaikan baris nama kosong), tulis `TitleLog`, panggil `Notifier::titleInfoUpdated`.
  - Redirect balik ke `title.show` + flash sukses.

## 4. C — Log & Notifikasi

### 4.1 Log
- Tabel `tb_title_logs`: `id`, `title_id` (FK cascade), `event` (string, mis. `info_updated`), `note` (text — ringkasan field berubah, mis. "Target terbit, Jurnal target, Opsi jurnal diperbarui"), `changed_by` (FK users nullOnDelete), `created_at` (timestamp; `$timestamps=false`, isi `created_at` manual seperti `TitleProgressLog`).
- Model `App\Models\TitleLog` (fillable + `changedBy()` + `title()`). Relasi `Title::logs()` hasMany (latest).
- Panel menampilkan ~10 log terbaru: `note` · oleh `changedBy->name` · `created_at`.

### 4.2 Notifikasi
- `Notifier::titleInfoUpdated(Title $title, User $actor)`: kirim ke `roleUsers(['superadmin'], $actor)` (aktor sendiri otomatis dikecualikan) payload: kategori `title`, judul "Info publikasi judul diperbarui", pesan `"{$title->code} — {$title->title}"`, url `route('title.show', $title->id)`, icon `edit`.

## 5. Komponen yang Disentuh

- **Baru:** migrasi (kolom `code` + backfill; kolom publikasi; `tb_title_journal_options`; `tb_title_logs`); `app/Services/TitleCodeService.php`; `app/Models/TitleJournalOption.php`, `TitleLog.php`; method `TitleService::updateInfo` + integrasi generator; `Notifier::titleInfoUpdated`; route `title.info.update`; test `TitleCodeServiceTest`, `TitlePublicationInfoTest`.
- **Diubah:** `app/Models/Title.php` (fillable + relasi journalOptions/logs + code); `app/Services/TitleService.php` (create/resolveForOrder set code); `app/Http/Controllers/Pages/TitleController.php` (updateInfo + show kirim panel data + kode di index); `resources/views/titles/{index,show}.blade.php` (kolom kode + panel); `resources/views/orders/{book/create,edit,journal/create,journal/edit}.blade.php` (label opsi select2 `code — title`).

## 6. Rencana Test

- **`TitleCodeServiceTest`**: inisial 4 kata pertama → `BDFS` untuk contoh; judul 1 kata → fallback huruf judul; keunikan → sufiks angka (`BDFS`, `BDFS2`); `ignoreId` saat regen (judul yang sama tak bentrok dengan dirinya).
- **`TitlePublicationInfoTest`**: create judul → `code` terisi & unik; order-inline (`resolveForOrder`) → `code` terisi; `PUT title.info.update` (manager) → field + opsi jurnal tersimpan, `TitleLog` bertambah, superadmin dapat notifikasi (assert DB notifications); production **melihat** panel tapi **tak bisa** PUT (403); marketing tak melihat panel; override `code` unik divalidasi (duplikat ditolak).
- **Regresi**: suite tetap hijau; `php artisan view:cache` bersih. Sesuaikan test order/select bila mengecek label opsi (nilai/`value` opsi tetap = id/nama; hanya teks tampilan berubah).

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** (kolom + 2 tabel + backfill). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Asumsi & Risiko

- Kode = inisial ~4 kata penting; bila judul sangat pendek/aneh, fallback huruf judul; keunikan dijamin sufiks angka. Override manual tetap tervalidasi unik.
- Panel publikasi terpisah dari lifecycle draft/approval judul — dapat diisi kapan pun oleh superadmin/manager/admin, dilihat production untuk template.
- Opsi jurnal disimpan bebas (nama/link/apc); penautan ke direktori jurnal riil menyusul saat direktori dibangun.
- Backfill kode berjalan di migrasi (idempotent, hanya `null`).
- Perubahan select2 order hanya **teks tampilan** (prefix kode) — `value` opsi tetap id judul / nama baru, tak memutus resolusi `title_id`.
