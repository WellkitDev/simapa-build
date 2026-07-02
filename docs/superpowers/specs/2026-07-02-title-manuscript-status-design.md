# Spec — Title Directory Fase 2b-1: Status Manuskrip di Direktori Judul

- **Tanggal:** 2026-07-02
- **Branch:** `title-manuscript-status`
- **Scope (2b-1):** Tampilkan **status/tahap manuskrip** setiap Judul di Direktori Judul (index + detail), diturunkan dari order tertaut (`title_id → OrderDetail → TitleProgress`). Aditif — **tidak** mengubah mekanisme grouping `group_key`.
- **Di luar scope (sengaja):** mengganti kunci grup manuskrip `group_key` → `title_id` (**Fase 2b-2**); backfill `title_id` order lama; mengedit status manuskrip dari direktori (tetap lewat papan manuskrip); per-bab status.

> Lanjutan Fase 2a: `OrderDetail.title_id` sudah menautkan order ke `Title`. Di sini kita membaca `TitleProgress` (manuskrip) melalui relasi itu untuk menampilkan tahap di direktori. Konsisten dengan bottleneck yang dipakai papan manuskrip (`ManuscriptTrackerController::buildGroupCards`) dan Arsip Judul (`TitleArchiveService::summarize`).

---

## 1. Latar Belakang

Manuskrip (`TitleProgress`) menempel ke `OrderDetail`. Fase 2a menautkan `OrderDetail` ke `Title` via `title_id`. Namun Direktori Judul belum menampilkan sejauh mana naskah judul tsb diproses. 2b-1 menambahkan tampilan **tahap manuskrip** (roll-up + per-order) pada Direktori Judul, membaca lewat `title_id`, tanpa menyentuh logika `group_key` (yang masih menjadi kunci grup papan/arsip sampai 2b-2).

## 2. Tujuan & Kriteria Sukses

1. Direktori Judul (index) menampilkan **tahap manuskrip** ringkas per judul (bottleneck) atau "—" bila belum ada order.
2. Halaman detail judul menampilkan tahap manuskrip roll-up + tahap per order tertaut, dan tautan ke papan manuskrip bagi role yang berhak.
3. Perhitungan tahap konsisten dengan papan manuskrip (bottleneck = stage paling awal). Tak ada perubahan pada grouping/`group_key`, order, atau papan manuskrip.
4. Perilaku tertutup test; seluruh suite tetap hijau.

## 3. Komponen

### 3.1 Model `Title` — helper turunan
- `manuscriptStatus(): ?string` — dari `orderDetails` yang punya `titleProgress`, kembalikan status dengan indeks stage **terkecil** (bottleneck) memakai `TitleProgress::BOOK_STAGES` bila `jenis='buku'`, else `ARTICLE_STAGES`. `null` bila tak ada progress.
- `manuscriptStatusLabel(): ?string` — label rapi dari status (`loa`→"LoA", `isbn`→"ISBN", lainnya `Str::title(str_replace('_',' ',$s))`); `null` bila status null.
- Bekerja atas relasi `orderDetails.titleProgress` yang di-eager-load kontroler (hindari N+1).

### 3.2 Controller `TitleController`
- `index()`: tambahkan `orderDetails.titleProgress` ke eager-load (`with([...])`) agar `manuscriptStatus()` tak N+1. Kirim daftar seperti biasa.
- `show()`: tambahkan `titleProgress` pada eager-load `orderDetails` (jadi `orderDetails.order.user` + `orderDetails.titleProgress`). Tambah var `canOpenBoard = Auth::user()->hasAnyRole(['superadmin','manager','production'])`.

### 3.3 View `resources/views/titles/index.blade.php`
- Kolom baru **Manuskrip** (mis. setelah "Jml Author"): `manuscriptStatusLabel()` sebagai badge, atau "—". Warna badge berdasarkan handler stage (marketing=abu, production=info, superadmin/final=success) — sederhana; boleh satu warna `bg-info` + `bg-success` untuk final (`terbit`/`publish`).

### 3.4 View `resources/views/titles/show.blade.php`
- Baris **Manuskrip:** `<badge>` (roll-up) di dekat baris "Order tertaut".
- Pada tabel "Order Tertaut": tambah kolom **Manuskrip** = tahap `optional($od->titleProgress)` label (atau "—").
- Tombol **"Buka Papan Manuskrip"** → `route('manuscript.board', ['tipe' => $title->jenis === 'buku' ? 'buku' : 'artikel'])`, tampil hanya bila `$canOpenBoard`.

## 4. Rencana Test

- **Unit `TitleManuscriptStatusTest`** (atau tambahan pada test model): buat Title(buku) + 2 OrderDetail tertaut dengan TitleProgress di stage berbeda (mis. `editing` & `layout`) → `manuscriptStatus()` = `editing` (bottleneck/awal); Title tanpa order → `null`; `manuscriptStatusLabel()` mengubah `loa`→"LoA".
- **Feature `TitleDirectoryManuscriptTest`**: index (manager) menampilkan label tahap untuk judul yang punya order; show menampilkan roll-up + per-order + tombol papan untuk production, tanpa tombol untuk marketing (marketing hanya lihat judul disetujui tak di-assign).
- **Regresi:** suite tetap hijau; `php artisan view:cache` bersih. Tanpa migrasi.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock.

## 5. Komponen yang Disentuh

- **Diubah:** `app/Models/Title.php` (2 helper); `app/Http/Controllers/Pages/TitleController.php` (eager-load index/show + `canOpenBoard`); `resources/views/titles/index.blade.php` (kolom Manuskrip); `resources/views/titles/show.blade.php` (roll-up + kolom + tombol papan).
- **Baru:** test `TitleManuscriptStatusTest` (unit) + `TitleDirectoryManuscriptTest` (feature).

## 6. Asumsi & Risiko

- Bottleneck = stage paling awal di antara order tertaut (konsisten papan/arsip). Judul `asal=distribusi` tanpa order → "—".
- Membaca via `title_id` (Fase 2a); order lama `title_id=null` tak tertaut ke judul mana pun sehingga tak muncul di roll-up judul — itu wajar untuk 2b-1 (backfill = 2b-2).
- Tanpa migrasi, tanpa perubahan `group_key`/papan/order → risiko regresi minimal (murni pembacaan + tampilan).
- Label stage lokal di Title; bila kelak ada peta label terpusat, bisa dirujuk ulang (di luar scope).
