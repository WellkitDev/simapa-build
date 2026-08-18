# Rencana Implementasi — Modul "Penugasan Naskah" (SiMAPA v2)

> Menggantikan: Distribusi Artikel, Distribusi Buku, Pelacak Naskah (Manuscript Tracker).
> Acuan: `docs/kesimpulan-jawaban-tim-distribusi.md` + `docs/wireframe-penugasan-naskah.html` (ACC 9 Agu 2026).

## 0. Prinsip

Backend domain **dipertahankan dan diperluas** (TitleProgress, TitleProgressService, log, notifier, file service). Yang diganti total: controller UI, view, routing, istilah. Sistem lama tetap jalan selama transisi (fase per fase, bukan big-bang).

**Klarifikasi wewenang (final):** PJ (admin) bisa dioper antar admin **sebidang** saja; distribusi pelaksana hanya ke akun **role produksi** — akun admin tidak pernah jadi pelaksana (orang rangkap pakai dua akun).

---

## 1. Perubahan Skema Database

### 1a. `tb_title_progress` — tambah kolom (migration baru, tanpa drop)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `pj_user_id` | FK users, nullable | Penanggung Jawab (admin bidang). Terpisah dari pelaksana |
| `pelaksana_user_id` | — | RENAME dari `assigned_user_id` (pelaksana produksi) |
| `bidang` | string(20) | `artikel` / `buku` — untuk scoping admin per bidang |
| `sla_due_at` | date, nullable | Jatuh tempo SLA tahap Pembuatan (7 hari kerja dari distribusi) |
| `overdue_reason` | string(30), nullable | Alasan keterlambatan: `internal` / `eksternal` / dsb (dropdown baku) |
| `overdue_note` | text, nullable | Keterangan alasan |
| `is_on_hold` | boolean default false | Naskah di-hold admin |
| `archived_at` | timestamp, nullable | Masuk arsip (menggantikan BOARD_RETENTION_DAYS) |
| `cancelled_at` / `cancelled_by` / `cancel_reason` | — | Status batal tanpa menghapus data |

### 1b. Status baru pada state machine

```php
ARTICLE_STAGES = ['menunggu_proses','pembuatan','editing','revisi','submit','loa','publish'];
// 'templating' DIHAPUS dari alur baru (dilebur ke editing); data lama dimigrasi → 'editing'
BOOK_STAGES    = ['menunggu_proses','pembuatan_bab','layout','proofreading','isbn','cetak','terbit'];
// 'pembuatan_bab' = fase per-bab (pembuatan+editing bab); 'editing' level buku dihapus
CHAPTER_STAGES = ['menunggu','pembuatan','editing','selesai'];   // BARU: tahapan bab
EXTRA_STATES   = ['dibatalkan','hold'];  // hold via flag, dibatalkan via cancelled_at
```

Aturan roll-up buku kolaborasi: status buku = `pembuatan_bab` selama ada bab belum `selesai`; `layout` dst terbuka hanya jika semua bab `selesai`. Buku mandiri: tanpa bab, `pembuatan_bab` diperlakukan sebagai `pembuatan+editing` satu kesatuan.

### 1c. `tb_chapter_progress` — tambah kolom
`pelaksana_user_id`, `sla_due_at`, `status` disesuaikan CHAPTER_STAGES. Pemetaan author sudah ada (`tb_title_chapter_authors`) — tambahkan **validasi wajib**: bab tanpa author tidak bisa didistribusikan.

### 1d. `manuscript_files` — perluas slot
Enum slot: `masuk`, `hasil_editing`, `hasil_layout`, `hasil_proofread`, `cover`, `final` (sebelumnya hanya `masuk`/`final`). Versi per slot sudah didukung — dipertahankan.

### 1e. `title_progress_logs` — tambah event types
`distribusi`, `claim`, `oper_pj`, `tarik_tugas`, `hold`, `unhold`, `dibatalkan`, `arsip`, `overdue_reason_set`, `author_dipetakan`. Struktur tabel tidak berubah.

### 1f. Users
Seeder/panduan buat akun terpisah untuk perangkap ganda: `Fitri Admin` + `Fitri Produksi`, dst. Role admin diberi atribut bidang (kolom `bidang` di `user_profiles` atau permission `admin.artikel` / `admin.buku`).

---

## 2. Lapisan Service

### 2a. `TitleProgressService` (modifikasi, bukan tulis ulang)
- `advance()`: maju **satu langkah** saja (tombol "Selesaikan tahap → X"). Catatan opsional. Hilangkan dropdown semua-tahap dari alur maju.
- `correct()`: jalur terpisah untuk mundur/lompat — catatan **wajib** (perbaiki bug label "opsional").
- Gerbang final: hanya superadmin, catatan wajib (buka kunci final untuk superadmin — ubah `authorizeChange`).
- Grup judul: `changeGroupStatus` dipertahankan + kembalikan info `affected_orders` untuk banner "berlaku untuk N order".
- Fix bug: submit tanpa ubah dropdown = no-op dengan pesan ramah, bukan error koreksi.

### 2b. `AssignmentService` (BARU)
- `distribute(progress|chapter, pelaksanaId, actor)` — admin bidang → produksi; hitung `sla_due_at` (7 hari kerja); tolak jika target bukan role produksi; tolak bab tanpa author.
- `claim(...)` — produksi ambil sendiri dari antrian tanpa pelaksana.
- `transferPj(progress, adminId, actor)` — oper PJ antar admin **sebidang**; superadmin bebas.
- `withdraw(...)`, `hold/unhold(...)`, `cancel(...)` — semua tercatat.

### 2c. `ChapterRollupService` (BARU, gantikan sebagian ChapterManuscriptService)
- Observer pada chapter_progress: setiap bab berubah → hitung ulang status buku.
- `allChaptersDone()` membuka tombol "Mulai Layout".

### 2d. `ManuscriptFileService` (modifikasi)
- Upload naskah oleh pelaksana pada tahap `pembuatan` → **auto-advance** ke `editing` + notifikasi PJ (satu-satunya transisi otomatis).
- Slot per tahap sesuai 1d.

### 2e. `Notifier` (modifikasi)
Matrix notifikasi: distribusi→pelaksana; upload→PJ; maju tahap→PJ+superadmin(+manager kelak); lewat SLA/target→PJ+superadmin; publish/terbit→marketing pemilik order; oper PJ→admin penerima.

---

## 3. Controller, Route, View

### 3a. Hapus (setelah fase transisi)
`ArticleDistributionController`, `BookDistributionController`, `ManuscriptTrackerController` + route `management/distribusi/*`, view `distribusi/*`, `manuscript/*`.

### 3b. Baru — prefix `naskah/`, satu namespace
| Route | Controller@method | Layar |
|---|---|---|
| GET `naskah/meja-kerja` | `MejaKerjaController@index` | Layar 1 |
| GET `naskah/pelacakan` | `PelacakanNaskahController@index` (?tipe, ?view=papan/daftar/riwayat) | Layar 2/2B |
| GET `naskah/arsip` | `PelacakanNaskahController@arsip` | Arsip |
| GET `naskah/{orderDetail}` | `DetailNaskahController@show` | Layar 3/3B |
| POST `naskah/{id}/selesaikan` · `koreksi` · `revisi` | aksi tahap | |
| POST `naskah/{id}/distribusi` · `claim` · `oper-pj` · `tarik` | penugasan | |
| POST `naskah/{id}/prioritas` · `target` · `hold` · `batal` · `file` | atribut & file | |
| POST `naskah/bab/{cp}/…` | aksi level bab (distribusi, upload, selesaikan) | |

Route lama → `Route::redirect()` ke halaman baru selama 1 bulan.

### 3c. View (Blade, ikuti wireframe)
`naskah/meja-kerja.blade.php`, `naskah/pelacakan.blade.php` (+partials: toolbar, zona, kartu, kartu-buku), `naskah/detail.blade.php` (+partials: stepper, info, aksi, bab-table, files, riwayat), `naskah/arsip.blade.php`. Sidebar: satu label "Pelacakan Naskah" + "Meja Kerja Saya" untuk semua role (hilangkan label berubah-ubah).

### 3d. Aturan UI wajib (dari temuan analisa)
Kartu papan → link ke Detail Naskah (bukan halaman order). Tombol aksi hanya tampil sesuai role. Tidak ada kata "editor". Aging "sudah X hari di tahap ini" di kartu & detail. Banner grup "berlaku untuk N order". Bab tanpa author = kuning + tombol "Petakan Author".

---

## 4. Migrasi Data (2 minggu berjalan — dipertahankan)

Migration + command `php artisan naskah:migrate-v2`:
1. `templating` → `editing` (catat log `status_corrected` oleh sistem, note "migrasi v2").
2. Isi `bidang` dari `order_details.type`.
3. Isi `pj_user_id`: dari `assigned_user_id` jika user tsb admin; sisanya NULL → admin bidang mengisi lewat UI (daftar "belum ada PJ" di minggu pertama).
4. `assigned_user_id` yang berisi admin → kosongkan `pelaksana_user_id` (admin bukan pelaksana).
5. Naskah `publish`/`terbit` → set `archived_at = now()` (masuk Arsip, tidak hilang).
6. Chapter progress: status lama dipetakan ke CHAPTER_STAGES (`editing/layout/…` → `editing`; `terbit/publish` → `selesai`).
7. Buat akun produksi untuk perangkap ganda (koordinasi dengan tim).

Rollback plan: semua migration additive (tanpa drop kolom lama sampai fase 4), snapshot DB sebelum eksekusi.

---

## 5. Fase Pengerjaan

| Fase | Isi | Estimasi |
|---|---|---|
| **0. Quick fix di sistem lama** (opsional, 1 hari) | Fix label "opsional" catatan; link kartu board → halaman distribusi; hentikan auto-hilang 30 hari (perpanjang retensi) | agar tim tidak tersiksa selama pembangunan |
| **1. Fondasi** (2–3 hari) | Migration 1a–1f; update model & konstanta stage; AssignmentService; unit test state machine + roll-up + permission | |
| **2. Layar Detail Naskah** (3–4 hari) | Layar 3 & 3B + semua aksi POST + auto-advance upload + notifikasi. Ini jantungnya — dibangun duluan | |
| **3. Meja Kerja + Pelacakan + Arsip** (3–4 hari) | Layar 1, 2, 2B, arsip; sidebar baru; redirect route lama | |
| **4. Migrasi & cutover** (1–2 hari) | `naskah:migrate-v2`; matikan menu lama; sosialisasi singkat ke tim (share wireframe HTML sebagai panduan); pantau 1 minggu; hapus kode lama | |

Total ± 10–14 hari kerja.

---

## 6. Uji Penerimaan (tes 5W1H — harus lulus semua)

1. Fitri (produksi) login → langsung tahu: tugas apa, mana yang telat, tombol apa yang harus diklik. ≤ 3 detik tanpa bertanya.
2. Fitri upload naskah → status maju sendiri ke Editing, Pia dapat notifikasi, log tercatat "dipicu upload".
3. Pia (admin artikel) di Detail Naskah hanya melihat SATU tombol maju ("Selesaikan Editing → Submit"), bukan dropdown semua tahap. Klik tanpa perubahan tidak menghasilkan error.
4. Ipit (admin buku) melihat tabel bab: tiap bab jelas author siapa, pelaksana siapa, status apa. Bab tanpa author kuning dan tidak bisa didistribusikan.
5. Semua bab selesai → tombol "Mulai Layout" terbuka; sebelumnya terkunci.
6. Dinda (marketing) membuka naskah kliennya: semua terlihat, tidak ada tombol aksi; saat publish dia dapat notifikasi.
7. Naskah publish → muncul di Arsip (bisa dicari), bukan hilang.
8. Superadmin mundurkan naskah publish → wajib catatan → tercatat di riwayat sebagai koreksi.
9. Aksi pada judul ber-3-order → banner "berlaku untuk 3 order" + ketiganya tercatat.
10. Tidak ada kata "editor", "tracker", "aging", "distribusi profit-membingungkan" di UI modul ini.
