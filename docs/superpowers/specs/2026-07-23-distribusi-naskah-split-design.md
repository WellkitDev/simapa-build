# Spec: Pisah Pelacakan Naskah ↔ Distribusi Naskah

**Tanggal:** 2026-07-23
**Status:** Draft — menunggu review
**Area:** Produksi (Pelacakan Naskah / Meja Kerja), Distribusi Naskah, Kontrol Naskah, upload file naskah

---

## Ringkasan

Saat ini satu halaman — **Pelacakan Naskah / Meja Kerja Saya** (papan Kanban, `management/manuscript`) — mencampur dua pekerjaan yang berbeda:

1. **Memantau** progres: judul ada di tahap mana.
2. **Mendistribusikan**: menetapkan editor/PIC, prioritas, target, memindahkan tahap, dan menugaskan editor per bab.

Revisi ini **memisahkan** keduanya:

- **Pelacakan Naskah** menjadi **monitor read-only** — hanya menampilkan status. Tanpa tombol aksi.
- **Distribusi Naskah** menjadi dua menu terpisah — **Distribusi Artikel** dan **Distribusi Buku** — masing-masing punya index + halaman detail kontrol sendiri, agar alur artikel (1 editor per judul) tidak tercampur dengan alur buku (per bab).
- Alur persetujuan (`needs_review` / "perlu ditinjau") **dimatikan**; setiap perubahan hanya menulis **log + notifikasi**.
- Ditambahkan **upload file naskah** (2 slot: Naskah Masuk & Naskah Final) dengan riwayat versi via Google Drive.

Model data inti **sudah mendukung** kebutuhan ini (editor per bab, author per bab, sumber naskah per order). Perubahan bersifat **reorganisasi + penyesuaian izin + satu tabel file baru**, bukan perombakan model.

### Keputusan yang sudah dikunci (hasil brainstorming)

1. **Struktur:** dua menu terpisah penuh — Distribusi Artikel & Distribusi Buku (index + detail masing-masing).
2. **Pelacakan Naskah:** monitor read-only murni; semua kontrol pindah ke halaman Distribusi.
3. **Approval:** `needs_review` dibuang total; perubahan hanya log + notifikasi.
4. **Granularitas buku:** editor & file per bab, dengan pintasan "terapkan ke semua bab". Artikel selalu 1 editor + file di level judul.
5. **Penyimpanan file:** Google Drive + simpan riwayat versi (upload ulang = versi baru, versi lama tetap ada).
6. **Slot file:** 2 slot per unit — **Naskah Masuk** (bahan dari customer) & **Naskah Final** (hasil olah editor).
7. **Peran:** admin naik jadi pendistribusi penuh **dan** bisa dipilih sebagai editor; marketing tetap read-only (papan, order sendiri).
8. **Halaman lama** `management/title/order/{id}` (progressDetail + "Kontrol Naskah" lama) **dipensiunkan**; kontrol pindah ke halaman Distribusi.
9. **URL:** `management/distribusi/artikel` dan `management/distribusi/buku`.
10. **Notifikasi perubahan:** ke marketing pemilik order **(dipertahankan)** + superadmin/manager/admin/production.

---

## 1. Peta halaman (surface): monitor vs kontrol

| Surface | Route | Peran | Sifat | Isi |
|---|---|---|---|---|
| **Pelacakan Naskah** (papan Kanban) | `management/manuscript` (`manuscript.board`) | superadmin/manager/admin/production | **Read-only** | Kolom per tahap; kartu menampilkan editor, target, prioritas, penanda telat & aktivitas terbaru. Filter tipe/scope/editor/prioritas/telat tetap. View `list` & `log` tetap. **Tanpa** tombol aksi. |
| Detail progres judul (dibuka dari papan & dari index judul) | `management/title/details/{id}` (`order.indexJudul.detail`) | 4 role produksi + marketing (order sendiri) + accounting | **Read-only** | Halaman `orders/detail-title-group.blade.php` yang sudah ada: ringkasan judul + daftar order + status agregat. |
| **Distribusi Artikel** | `management/distribusi/artikel` + `/{id}` | superadmin/manager/admin/production | **Kontrol** | Index judul artikel + detail: 1 editor/judul, ubah tahap, prioritas, target, 2 slot file. |
| **Distribusi Buku** | `management/distribusi/buku` + `/{id}` | superadmin/manager/admin/production | **Kontrol** | Index judul buku + detail: grid per bab (editor, author, tahap, file) + pintasan "semua bab". |
| ~~progressDetail + "Kontrol Naskah" lama~~ | ~~`management/title/order/{id}`~~ | — | **Dipensiunkan** | Dihapus. View `orders/detail-title.blade.php` dihapus. Semua link diarahkan ke `management/title/details/{id}` (read-only). |

Prinsip: **papan hanya memantau**; semua *tindakan* hidup di dua menu Distribusi.

Menu sidebar (bagian "Produksi"):
- `Pelacakan Naskah` / `Meja Kerja Saya` (nama tetap adaptif per peran) → papan read-only.
- `Distribusi Artikel` (baru) → `distribusi.artikel.index`.
- `Distribusi Buku` (baru) → `distribusi.buku.index`.

---

## 2. Peran, izin & "approval dimatikan"

### 2.1 Perubahan peran

- **Admin menjadi pendistribusi penuh** (baru): masuk ke gate `authorizeChange` & assign, **setara production**.
- **Admin bisa dipilih sebagai editor**: kelayakan editor diubah dari `['production','manager']` menjadi `['production','manager','admin']`. Berlaku di `TitleProgressService::assignEditor` dan `ChapterManuscriptService::assignEditor`, serta daftar dropdown editor di semua view.
- **Marketing tetap read-only**: hanya papan Pelacakan (scope order sendiri) + detail read-only. Tidak punya menu Distribusi (403 bila diakses langsung).

### 2.2 Gate pemindahan tahap (dipertahankan, hanya +admin)

Perilaku `TitleProgressService::authorizeChange` dipertahankan ("algoritma sudah hampir benar"), dengan admin ditambahkan setara production:

- **superadmin** — bebas (maju, mundur, lompat), termasuk tahap final.
- **manager** — semua tahap non-final.
- **production & admin** — hanya tahap yang handler-nya `production` (lihat `TitleProgress::STAGE_HANDLER`).
- tahap final (`terbit`/`publish`) terkunci untuk selain superadmin.

### 2.3 "Approval dimatikan" — buang `needs_review`

- Berhenti menyetel `needs_review` di `TitleProgressService::applyStatus` dan `ChapterManuscriptService::changeStatus`.
- Hapus dari UI: badge "⚑ tinjau" (card papan), blok "Lompat tahap … perlu ditinjau", tombol "Tandai sudah ditinjau".
- Hapus aksi & route `manuscript.reviewed`, method `markReviewed`, dan `Notifier::naskahNeedsReview`.
- Kolom DB `needs_review` (di `tb_title_progress` & `tb_chapter_progress`) **dibiarkan ada tapi tak dipakai** — menghindari migrasi destruktif. Drop kolom masuk backlog terpisah.
- **Koreksi/lompat tak lagi wajib catatan** dan tak memblok apa pun. Catatan bersifat opsional.
- **Label `is_correction` di log tetap dipertahankan** (informasi audit "maju" vs "koreksi"), dihitung otomatis (`target !== next`).

### 2.4 Notifikasi

Setiap perubahan distribusi (pindah tahap, assign editor, ubah prioritas/target, upload file) mengirim notifikasi ke **superadmin + manager + admin + production** (kecuali pelaku). Khusus **perubahan tahap**, notifikasi ke **marketing pemilik order tetap dipertahankan** (`Notifier::naskahStageChanged`).

Implementasi: tambah metode notifier tim, mis. `distribusiChanged(...)` yang menyasar keempat role; panggil dari service/controller distribusi. `naskahStageChanged` (ke marketing) tetap dipanggil pada perubahan tahap.

### 2.5 Permission map (`config/permissions.php`) & seeder

- Modul baru **`distribution`** dengan aksi: `view`, `assign`, `move`, `priority`, `target`, `upload` → dipetakan ke route `distribusi.*` (artikel & buku berbagi izin yang sama).
- Modul **`manuscript`** disusutkan menjadi:
  - `view` → `manuscript.board` (papan read-only).
  - `detail` → `order.indexJudul.detail`, `order.indexJudul.progress`(dipensiunkan → dihapus dari peta), `title.progress.logs`.
  - Lepas: `move`, `assign`, `priority`, `review`, `target`, `clear-log` (papan tak lagi punya aksi).
- Modul **`chapter`** (`advance`, `assign`) dipindah di bawah cakupan `distribution` (route bab kini milik Distribusi Buku).
- **Hibah `AccessMatrixSeeder`**: tambahkan `distribution.*` (dan `chapter.*`) ke `admin`, `production`, `manager`; `manuscript.view` ditambahkan ke `admin`. Wajib `php artisan db:seed --class=AccessMatrixSeeder` setelah deploy.

> Catatan (memory access-control): setiap route baru **wajib** dipetakan di `config/permissions.php`, kalau tidak akan 403 + test merah karena `EnforcePermission` fail-closed.

---

## 3. Model distribusi: artikel vs buku

### 3.1 Artikel (`at_mandiri`, `at_kolab`)

- **1 editor untuk seluruh judul-grup.** Pakai `TitleProgressService::assignGroup` (assign ke semua varian order judul yang sama).
- Ubah tahap: `changeGroupStatus` (sudah ada), 1 alur `ARTICLE_STAGES`.
- Prioritas & target: `setGroupPriority`, `setGroupTargetDate` (sudah ada).
- File: 2 slot (Masuk/Final) di **level judul** (`title_chapter_id = null`).

### 3.2 Buku (`bk_mandiri`, `bk_kolab`)

Detail per bab (data sudah didukung: `ChapterProgress.assigned_user_id`, `TitleChapter.authors`, `ChapterManuscriptService`):

- **Grid per bab**: nomor + judul bab · author bab · dropdown editor · tahap + tombol maju/koreksi · 2 slot file.
- **Pintasan "semua bab"**:
  - *Terapkan 1 editor ke semua bab* — set `assigned_user_id` semua `ChapterProgress` judul tsb ke editor terpilih.
  - *Upload 1 file untuk seluruh buku* — simpan file di level judul (`title_chapter_id = null`), berlaku sebagai naskah buku utuh (mis. bk_mandiri yang dibuatkan sebagai satu naskah penuh).
- Roll-up status buku tetap = bab paling lambat (`ChapterManuscriptService::syncBookStatus`, sudah ada).
- Mendukung 4 skenario order tanpa model baru:

| Skenario | Editor | Sumber file |
|---|---|---|
| bk_mandiri, naskah dibuatkan, N bab | 1 editor semua bab (pintasan) atau per bab | **Final** diisi produksi; **Masuk** kosong/opsional |
| bk_mandiri, naskah dari customer, N bab | 1 editor atau per bab | **Masuk** dari customer → **Final** hasil olah |
| bk_kolab, 1..N bab/author, dari customer | per bab (bisa beda editor) | **Masuk** per bab dari author → **Final** per bab |
| bk_kolab, 1..N bab/author, dibuatkan | per bab / campuran | **Final** per bab dari produksi |

> Slot tidak dikunci oleh `naskah_type`; keduanya selalu tersedia. `naskah_type` order hanya menjadi label/petunjuk pada UI.

---

## 4. Model file naskah (2 slot, versi, Google Drive)

### 4.1 Tabel baru `tb_manuscript_files`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `title_id` | bigint FK → `tb_titles` | Unit dasar (judul). |
| `title_chapter_id` | bigint FK → `tb_title_chapters`, **nullable** | **null = level judul/buku**; terisi = bab tertentu. |
| `slot` | varchar/enum | `masuk` / `final`. |
| `version` | int | Naik per (`title_id`, `title_chapter_id`, `slot`). Tiap upload = baris baru. |
| `original_name` | varchar | Nama file asli. |
| `drive_file_id` | varchar nullable | ID file di Google Drive. |
| `drive_url` | varchar nullable | URL file. |
| `file_size` | bigint nullable | Ukuran (opsional). |
| `uploaded_by` | bigint FK → `users` | |
| `created_at` | timestamp | (cukup `created_at`; baris bersifat append-only). |

- **Riwayat versi**: tidak menimpa. Upload baru menyisipkan baris dengan `version = max(prev)+1`. Versi terbaru per (title, chapter, slot) yang ditampilkan; versi lama dapat dibuka/diunduh dari daftar.
- **Keying pada `title_id`**: order yang dibuat lewat sistem selalu menaut Title (`TitleService::resolveForOrder`), sehingga `title_id` tersedia. Detail legacy tanpa `title_id` di luar cakupan upload (tetap bisa dipantau); ditangani dengan pesan "judul belum tertaut" bila terjadi.

### 4.2 `ManuscriptFileService` (baru)

Tanggung jawab tunggal: upload ke Drive + versioning + catat + notif.

- `upload(Title $title, ?TitleChapter $chapter, string $slot, UploadedFile $file, User $actor): ManuscriptFile`
  - Validasi slot ∈ {masuk, final}; validasi mime & ukuran (mengikuti pola upload lain, mis. pdf/doc/docx, maks wajar).
  - Upload via `GoogleDriveService::uploadFile` (pola sama `DocChecklistService`).
  - Hitung `version` berikutnya; simpan baris; tulis `TitleProgressLog` (event mis. `file_uploaded`) pada progress representatif; panggil notifier tim.
- `latest(Title $title, ?int $chapterId, string $slot): ?ManuscriptFile` dan `versions(...)` untuk daftar.
- (Opsional, backlog) hapus versi — default tidak disediakan agar riwayat utuh.

### 4.3 Model `ManuscriptFile` (baru)

Relasi: `belongsTo(Title)`, `belongsTo(TitleChapter)`, `belongsTo(User, uploaded_by)`. Konstanta `SLOTS = ['masuk' => 'Naskah Masuk', 'final' => 'Naskah Final']`.

---

## 5. Struktur backend & yang dipensiunkan

### 5.1 Komponen baru

| Komponen | Peran |
|---|---|
| `ArticleDistributionController` | `index()` (list judul artikel), `show($id)` (detail kontrol), `assignEditor`, `moveStage`, `setPriority`, `setTarget`, `uploadFile`. Tipis — delegasi ke service. |
| `BookDistributionController` | `index()`, `show($id)`, `assignEditor` (per bab + pintasan semua bab), `moveChapter`, `setPriority`, `setTarget`, `uploadFile` (per bab & level buku). Tipis. |
| `ManuscriptFileService` | Upload Drive + versioning + log + notif. |
| `ManuscriptFile` (model) + migrasi `tb_manuscript_files` | Lihat §4. |
| View `distribusi/artikel/{index,show}.blade.php` | Index + detail artikel. |
| View `distribusi/buku/{index,show}.blade.php` | Index + detail buku (grid bab + pintasan). |

Pakai ulang (tanpa perubahan logika besar): `TitleProgressService`, `ChapterManuscriptService`, `TitleArchiveService::groupDetails/groupKey/summarize`, `GoogleDriveService`.

### 5.2 Disusutkan / dipensiunkan

| Berkas | Tindakan |
|---|---|
| `ManuscriptTrackerController` | Sisakan `index()` read-only + helper papan (`buildGroupCards`, `buildZones`). **Hapus** `move`, `assign`, `priority`, `reviewed`, `target`, `clearLog`. |
| `OrderBookController::progressDetail` | **Hapus** method + route `order.indexJudul.progress`. |
| `orders/detail-title.blade.php` | **Hapus** (berisi Kontrol Naskah lama). |
| `manuscript/partials/card.blade.php` | Jadikan **read-only**: tampilkan editor + status (dan daftar bab untuk buku) tanpa form/dropdown/tombol. |
| `manuscript/board.blade.php`, `list.blade.php`, `partials/toolbar.blade.php` | Hilangkan kontrol drag/aksi; sisakan tampilan + filter. |
| `TitleProgressController` (`update`, `logs`) | `update` dipensiunkan (perpindahan tahap kini via Distribusi); `logs` (JSON riwayat) tetap bila masih dipakai read-only. |
| Route `manuscript.move/assign/priority/reviewed/target/clearLog`, `chapter.*` lama | Dilepas dari `manuscript` prefix; fungsionalitas pindah ke route `distribusi.*`. |

### 5.3 Peta route baru

```
Route::prefix('management/distribusi')->name('distribusi.')->group(function () {
    // Artikel
    Route::get('artikel',            [ArticleDistributionController::class, 'index'])->name('artikel.index');
    Route::get('artikel/{id}',       [ArticleDistributionController::class, 'show'])->name('artikel.show')->whereNumber('id');
    Route::post('artikel/{id}/editor',   [ArticleDistributionController::class, 'assignEditor'])->name('artikel.editor');
    Route::post('artikel/{id}/tahap',    [ArticleDistributionController::class, 'moveStage'])->name('artikel.tahap');
    Route::post('artikel/{id}/prioritas',[ArticleDistributionController::class, 'setPriority'])->name('artikel.prioritas');
    Route::post('artikel/{id}/target',   [ArticleDistributionController::class, 'setTarget'])->name('artikel.target');
    Route::post('artikel/{id}/file',     [ArticleDistributionController::class, 'uploadFile'])->name('artikel.file');

    // Buku
    Route::get('buku',               [BookDistributionController::class, 'index'])->name('buku.index');
    Route::get('buku/{id}',          [BookDistributionController::class, 'show'])->name('buku.show')->whereNumber('id');
    Route::post('buku/{id}/editor-semua', [BookDistributionController::class, 'assignEditorAll'])->name('buku.editorSemua');
    Route::post('buku/{id}/prioritas',    [BookDistributionController::class, 'setPriority'])->name('buku.prioritas');
    Route::post('buku/{id}/target',       [BookDistributionController::class, 'setTarget'])->name('buku.target');
    Route::post('buku/{id}/file',         [BookDistributionController::class, 'uploadFile'])->name('buku.file');      // level buku
    Route::post('buku/chapter/{cp}/editor', [BookDistributionController::class, 'assignChapterEditor'])->name('buku.chapter.editor')->whereNumber('cp');
    Route::post('buku/chapter/{cp}/tahap',  [BookDistributionController::class, 'moveChapter'])->name('buku.chapter.tahap')->whereNumber('cp');
    Route::post('buku/chapter/{cp}/file',   [BookDistributionController::class, 'uploadChapterFile'])->name('buku.chapter.file')->whereNumber('cp');
});
```

(Nama akhir bisa disesuaikan; poin pentingnya prefix `management/distribusi` dan pemisahan artikel/buku.)

---

## 6. Alur data (ringkas)

```
DISTRIBUSI ARTIKEL — detail judul
  assign editor  → assignGroup(varian) → log → notif (mkt owner + 4 role)
  ubah tahap     → changeGroupStatus   → log (is_correction bila mundur/lompat) → notif
  upload file    → ManuscriptFileService.upload(title, null, slot) → versi+1 di Drive → log → notif

DISTRIBUSI BUKU — detail judul
  editor semua bab → set semua ChapterProgress.assigned_user_id → log → notif
  editor 1 bab     → ChapterManuscriptService.assignEditor(cp) → log → notif
  ubah tahap bab   → ChapterManuscriptService.changeStatus(cp) → syncBookStatus (roll-up) → log → notif
  upload file bab  → ManuscriptFileService.upload(title, chapter, slot) → versi+1 → log → notif
  upload file buku → ManuscriptFileService.upload(title, null, slot)    → versi+1 → log → notif

PELACAKAN NASKAH (papan) — read-only
  hanya menampilkan status/editor/target; tidak ada endpoint aksi
```

---

## 7. Error handling

| Kondisi | Penanganan |
|---|---|
| Marketing membuka menu/route Distribusi | 403 (permission `distribution.*` tak dimiliki). |
| Editor dipilih bukan production/manager/admin | Validasi gagal: "Editor harus production, manager, atau admin." |
| Pindah tahap oleh production/admin pada tahap non-production | 403 dari `authorizeChange` (perilaku lama dipertahankan). |
| Pindah tahap naskah final oleh selain superadmin | 403 "Naskah sudah final dan terkunci." |
| Upload slot selain masuk/final, atau mime/ukuran tak valid | Validasi gagal dengan pesan jelas. |
| Upload pada judul tanpa `title_id` (legacy) | Pesan "Judul belum tertaut; upload tidak tersedia." |
| Kegagalan Google Drive saat upload | Transaksi dibatalkan; flash error; tidak menyisakan baris file setengah jadi. |

---

## 8. Testing

**Feature**
- Index Distribusi Artikel hanya memuat judul `at_*`; Distribusi Buku hanya `bk_*`.
- Assign editor artikel → tersimpan ke semua varian; log & notifikasi (mkt owner + 4 role) terkirim.
- Admin dapat dipilih sebagai editor (artikel & bab).
- Pindah tahap → log tercatat (`is_correction` benar untuk mundur/lompat) + notifikasi; **tanpa** wajib catatan; **tanpa** `needs_review`.
- Upload file → baris `tb_manuscript_files` bertambah dgn `version` naik (mock `GoogleDriveService`); slot masuk/final terpisah; level judul vs bab terpisah.
- Pintasan "editor semua bab" → semua `ChapterProgress` ikut berubah.
- Papan Pelacakan: tidak ada endpoint aksi; role admin & production bisa membuka (read-only); marketing hanya order sendiri.
- Izin: admin bisa akses Distribusi; marketing 403 di Distribusi.
- Regresi: route & UI `needs_review`/`reviewed` benar-benar hilang (tak ada badge, route `manuscript.reviewed` 404/absen).

**Unit**
- `ManuscriptFileService`: `version` increment per (title, chapter, slot); resolusi unit judul vs bab; kegagalan Drive → tidak menyimpan baris.

**Catatan test (memory):** jalankan terhadap `avidpedi_simapa_test` via `.env.testing`; role `accounting`/lainnya via `Role::firstOrCreate`.

---

## 9. Operasional / migrasi

- `php artisan migrate` untuk `tb_manuscript_files` — jalankan juga di **dev `avidpedi_simapa`** (bukan hanya test DB), agar app live tidak 500.
- `php artisan db:seed --class=AccessMatrixSeeder` untuk permission baru (`distribution.*`, `chapter.*`, `manuscript.view` utk admin).
- Verifikasi menu sidebar muncul sesuai peran setelah seeding.

---

## 10. Di luar cakupan spec ini

- Menghidupkan kembali alur approval/persetujuan naskah (sengaja dimatikan sekarang).
- Drop kolom `needs_review` dari DB (backlog terpisah; sekarang cukup berhenti dipakai).
- Hapus/rollback versi file dari UI (riwayat dibuat append-only).
- Integrasi file naskah ke Arsip Judul (artefak arsip tetap alur tersendiri).
- Perubahan pada perhitungan roll-up "bab paling lambat" (sudah benar, dipertahankan).

---

## Dependensi

- `GoogleDriveService` sudah terpasang & dipakai (`DocChecklistService`, upload bukti bayar).
- Spatie Permission + `config/permissions.php` + `AccessMatrixSeeder` sudah ada.
- Tidak ada package baru.
