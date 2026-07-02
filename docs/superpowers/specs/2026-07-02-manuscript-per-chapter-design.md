# Spec — Manuskrip per Bab (Per-Chapter Manuscript Tracking)

- **Tanggal:** 2026-07-02
- **Branch:** `manuscript-per-chapter`
- **Scope:** Untuk **buku**, lacak progress manuskrip **per bab** (status + editor per bab); status buku/order = **roll-up bottleneck** dari bab-babnya. Bab bersumber dari `tb_title_chapters` (auto-generate dari `OrderDetail.chapters` bila kosong). Papan manuskrip: kartu buku menampilkan status roll-up + **expand-panel** untuk mengelola bab. **Artikel tidak berubah** (tetap alur per-order tanpa bab).
- **Eksekusi bertahap:** **3a (fondasi)** — data + model + service + roll-up + auto-generate + integrasi `createForDetail` + test (tanpa UI papan). **3b (papan)** — expand-panel & endpoint aksi bab di papan manuskrip. Spec ini mencakup keduanya; plan/branch dieksekusi 3a lebih dulu (mergeable), lalu 3b.
- **Di luar scope (sengaja):** author per bab; bab untuk artikel; menautkan bab ke direktori jurnal; drag per-bab di papan (dipilih expand-panel, bukan drag).

> Model manuskrip sekarang: 1 `OrderDetail` = 1 `TitleProgress` = 1 status + 1 editor untuk **seluruh** buku. Fitur ini menambah lapisan bab **tanpa** membuang TitleProgress: TitleProgress buku menjadi **roll-up** (status = bottleneck bab). Buku dikelompokkan per `title_id` (Fase 2b) → bab dilacak **sekali per bab pada Title**, dibagikan lintas order judul yang sama.

---

## 1. Latar Belakang & Tujuan

Naskah buku terdiri atas beberapa bab; sering dibagi ke beberapa editor produksi, dan tiap bab bisa berada di tahap berbeda. Model sekarang hanya melacak satu status/editor per buku. Fitur ini menambah **`ChapterProgress`** (progress per bab) sebagai sumber kebenaran tahap buku; `TitleProgress` buku di-derive (roll-up). 

**Kriteria sukses:**
1. Buku: tiap bab punya status (BOOK_STAGES) + editor sendiri; bisa maju/ditugaskan terpisah.
2. Status buku/order (TitleProgress + `Title::manuscriptStatus`) = **bottleneck** (tahap paling awal) bab-babnya, tersinkron otomatis tiap perubahan bab.
3. Bab bersumber `tb_title_chapters`; buku tanpa daftar bab di-auto-generate dari `OrderDetail.chapters` (N bab "Bab 1".."Bab N", dapat di-rename di direktori).
4. Otorisasi & aturan tahap bab **sama** dengan manuskrip existing (STAGE_HANDLER: production di tahap produksi; manager/superadmin oversight).
5. Artikel & alur non-buku **tak berubah**; suite tetap hijau.

---

## 2. Fase 3a — Fondasi (data + service + roll-up)

### 2.1 Data
- **`tb_chapter_progress`**: `id`, `title_chapter_id` (FK→`tb_title_chapters`, unique, cascadeOnDelete), `status` (string(16), default `menunggu_proses`), `assigned_user_id` (FK users nullable nullOnDelete), `needs_review` (bool default false), `note` (text nullable), `updated_by` (FK users nullable nullOnDelete), `started_at` (timestamp nullable), `last_log_at` (timestamp nullable), timestamps.
- **Model `App\Models\ChapterProgress`** (`tb_chapter_progress`): fillable kolom di atas; casts `needs_review`=bool, `started_at`/`last_log_at`=datetime; relasi `chapter()` belongsTo(TitleChapter), `assignedUser()`, `updatedBy()`.
- **`TitleChapter`**: relasi `progress()` hasOne(ChapterProgress). (`tb_title_chapters` sudah ada: title_id, judul, urutan.)

### 2.2 Auto-generate (`ChapterManuscriptService::ensureChapters(Title $book): void`)
- Hanya untuk `jenis=buku`. Bila `book->chapters()` kosong: tentukan `N = max(OrderDetail.chapters)` di antara order tertaut (`$book->orderDetails`), fallback 1 bila tak ada/nol; buat N `TitleChapter` (`judul='Bab '.$i`, `urutan=$i`).
- Untuk tiap `TitleChapter` tanpa `progress`, buat `ChapterProgress` dengan `status` = status TitleProgress buku saat ini (representatif) bila ada, else `menunggu_proses`; `assigned_user_id` = null. Idempotent (skip bab yang sudah punya progress).

### 2.3 Service `ChapterManuscriptService` (pakai ulang aturan `TitleProgress`)
- `changeStatus(ChapterProgress $cp, string $target, User $actor, ?string $note=null)`: validasi `$target` ∈ BOOK_STAGES; hitung next dari status kini; koreksi (lompat) wajib `note`; **otorisasi** meniru `TitleProgressService::authorizeChange` (superadmin/manager bebas; production hanya bila handler status **kini** = production); update status + `assigned_role`-equivalent + `needs_review` (koreksi non-superadmin → true); tulis log; lalu `syncBookStatus`.
- `assignEditor(ChapterProgress $cp, ?int $userId, User $actor)`: aktor production/manager/superadmin; editor = user role production/manager; update `assigned_user_id` + log.
- `syncBookStatus(Title $book)`: untuk tiap `OrderDetail` buku tertaut, set `TitleProgress.status` = **bottleneck** (stage terkecil) status bab-babnya (via BOOK_STAGES) + `assigned_role = getHandlerForStatus(bottleneck)`. Bila belum ada bab → biarkan.
- **Log**: catat perubahan bab ke `TitleProgressLog` pada TitleProgress buku representatif, `note` menyertakan judul bab (mis. `"Bab 'Pendahuluan' → Editing"`). (Tanpa tabel log baru.)

### 2.4 Roll-up read
- Karena `syncBookStatus` (2.3) menjaga `TitleProgress` buku = bottleneck bab, **`Title::manuscriptStatus()` (2b-1) tetap apa adanya** (membaca TitleProgress order) dan otomatis benar. Tak ada perubahan read wajib di 3a. (Opsional: kelak membaca langsung dari bab bila TitleProgress dihapuskan — di luar scope.)

### 2.5 Integrasi `createForDetail`
- Di `TitleProgressService::createForDetail` (atau pemanggilnya): setelah membuat TitleProgress untuk **order buku**, panggil `ChapterManuscriptService::ensureChapters($detail->titleRef)` bila `title_id` ada. Order artikel: tak ada perubahan.
- **Lazy-safe**: `ensureChapters` juga dipanggil saat papan meng-expand buku (3b) → buku lama otomatis ter-seed.

### 2.6 Testing 3a
- `ensureChapters`: buku tanpa bab + order `chapters=3` → 3 TitleChapter + 3 ChapterProgress (`menunggu_proses`); idempotent; buku dengan daftar bab existing → pakai itu, hanya seed progress.
- `changeStatus`: bab maju `menunggu_proses`→`editing`; roll-up buku ikut bottleneck; koreksi (lompat) tanpa note → error; **otorisasi**: production boleh memindah bab yang handler status **kini**-nya production (mis. `editing`), tetapi ditolak bila status kini handler-nya superadmin (mis. `cetak`); manager/superadmin bebas.
- `syncBookStatus`: 3 bab di [editing, layout, menunggu_proses] → status buku = `menunggu_proses` (bottleneck).
- `assignEditor`: set editor bab; non-editor role ditolak.
- Regresi: alur artikel & manuscript existing tetap hijau.

## 3. Fase 3b — Papan (expand-panel)

### 3.1 Controller & route
- Endpoint baru (grup manuskrip, gated `role:superadmin|manager|production`): `POST chapter/{id}/advance` (`chapter.advance`), `POST chapter/{id}/assign` (`chapter.assign`). `id` = ChapterProgress id. Kontroler `ChapterProgressController` memanggil service, kembalikan JSON (AJAX) / redirect (non-JSON), pola `ManuscriptTrackerController::runOrFlash`.
- `ManuscriptTrackerController::index` (tipe buku): untuk tiap kartu buku, muat bab + progress (`ensureChapters` lazy bila kosong) untuk panel.

### 3.2 View papan
- **Kartu buku** (`manuscript/partials/card.blade.php` bila `tipe=buku`): status badge = roll-up; tampilkan "Bab (n)"; tombol/aria expand (collapse Bootstrap). Kartu **tidak** draggable untuk buku (nonaktifkan handle drag saat tipe buku); artikel tetap draggable.
- **Panel expand**: daftar bab (urutan, judul) + per bab: badge tahap, nama editor, kontrol **Maju** (ke next stage) + **dropdown tahap** (lompat, dgn catatan) + **assign editor** (select production/manager). Aksi via fetch ke endpoint 3.1; sukses → update UI/reload.
- Filter/priority/target papan tetap di level buku (TitleProgress).

### 3.3 Testing 3b
- `chapter.advance` (production) → status bab maju + roll-up; non-authorized (marketing) → 403.
- `chapter.assign` → editor bab terset.
- Papan tipe buku merender panel bab (expand) untuk buku ber-bab; kartu buku tak punya handle drag.
- Regresi papan artikel tetap hijau.

## 4. Komponen

- **Baru (3a):** migrasi `tb_chapter_progress`; `App\Models\ChapterProgress`; `App\Services\ChapterManuscriptService`; `TitleChapter::progress()`; test unit.
- **Diubah (3a):** `TitleProgressService::createForDetail` (ensureChapters untuk order buku). (`Title::manuscriptStatus()` tak diubah — TitleProgress buku tetap di-sync ke roll-up.)
- **Baru (3b):** `ChapterProgressController` + 2 route; panel bab di view papan; test feature.
- **Diubah (3b):** `manuscript/partials/card.blade.php` (+ board JS: buku non-drag + expand + aksi bab), `ManuscriptTrackerController::index` (muat bab).

## 5. Asumsi & Risiko

- Bab dilacak per **Title** (grup title_id) — satu set bab per judul buku, dibagikan lintas order judul sama (konsisten Fase 2b). Multi-order buku memakai bab yang sama.
- TitleProgress buku dipertahankan sebagai roll-up (status di-derive) → papan/arsip/dashboard existing tetap jalan; buku kini digerakkan lewat bab (drag buku dinonaktifkan di papan 3b).
- Auto-generate memakai `max(OrderDetail.chapters)`; buku lama tanpa bab ter-seed lazy di status kini (tanpa migrasi data wajib).
- Otorisasi/aturan tahap bab meniru `TitleProgressService` → konsisten & aman.
- Artikel & non-buku sama sekali tak tersentuh.
- 3a mergeable tanpa 3b (fondasi + roll-up bekerja; interaksi bab menyusul di papan 3b).
