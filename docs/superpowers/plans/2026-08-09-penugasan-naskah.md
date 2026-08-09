# Penugasan Naskah v2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti UI modul Distribusi Artikel/Buku + Manuscript Tracker dengan modul **Penugasan Naskah** (3 layar: Meja Kerja Saya, Pelacakan Naskah, Detail Naskah + Arsip), sesuai wireframe yang sudah di-ACC. Backend domain diperluas, bukan ditulis ulang.

**Architecture:** `TitleProgress` tetap sumber kebenaran status per order-detail; ditambah kolom PJ/pelaksana/bidang/SLA/arsip/batal. `AssignmentService` baru menangani distribusi-claim-oper. `TitleProgressService` di-refactor: `advance()` satu-langkah + `correct()` terpisah. `ChapterRollupService` menghitung status buku dari bab. Auto-advance dipicu di `ManuscriptFileService::upload()`. Controller & view lama diganti; route lama redirect 1 bulan lalu dihapus.

**Tech Stack:** Laravel 10, Spatie Permission (AccessMatrixSeeder pattern), Blade + Bootstrap 5 + Alpine.js, DataTables, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-09-penugasan-naskah-design.md`
**Wireframe (sumber kebenaran visual, WAJIB diikuti):** `docs/wireframe-penugasan-naskah.html`
**Keputusan bisnis:** `docs/kesimpulan-jawaban-tim-distribusi.md`

> **Catatan testing (penting):** jalankan `php artisan test` — otomatis memakai `.env.testing` (DB `avidpedi_simapa_test`), **bukan** DB asli. Suite saat ini harus tetap hijau. Test lama `ArticleDistributionTest`/`BookDistributionTest`/`ManuscriptTracker*` baru dihapus di Task 14 (cleanup), bukan sebelumnya.

> **Catatan istilah (wajib):** dilarang menulis kata "editor", "tracker", "aging" di UI/label/flash message. Gunakan: **PJ**, **Pelaksana**, "sudah X hari di tahap ini". Identitas utama selalu **kode order**.

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `database/migrations/2026_08_10_000001_add_penugasan_fields_to_tb_title_progress.php` | Kolom PJ/pelaksana/bidang/SLA/hold/arsip/batal |
| Create | `database/migrations/2026_08_10_000002_add_penugasan_fields_to_tb_chapter_progress.php` | pelaksana + sla per bab |
| Create | `database/migrations/2026_08_10_000003_add_bidang_to_user_profiles.php` | Scoping admin per bidang |
| Modify | `app/Models/TitleProgress.php` | Konstanta stage baru, relasi pj/pelaksana, helper |
| Modify | `app/Models/ChapterProgress.php` | CHAPTER_STAGES, relasi pelaksana |
| Modify | `database/seeders/AccessMatrixSeeder.php` | Action `naskah.*` per §4 spec |
| Create | `app/Services/AssignmentService.php` | distribute/claim/transferPj/withdraw/hold/cancel |
| Modify | `app/Services/TitleProgressService.php` | `advance()`/`correct()`, unlock final utk superadmin, no-op fix, grup |
| Create | `app/Services/ChapterRollupService.php` | Roll-up bab → status buku, gate "Mulai Layout" |
| Modify | `app/Services/ManuscriptFileService.php` | Slot baru + auto-advance saat upload |
| Modify | `app/Services/Notifier.php` | Method notifikasi baru per §7 spec |
| Create | `app/Http/Controllers/Pages/Naskah/MejaKerjaController.php` | Layar 1 |
| Create | `app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php` | Layar 2/2B + arsip + riwayat |
| Create | `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` | Layar 3/3B + semua aksi POST |
| Modify | `routes/web.php` | Prefix `naskah/`, redirect route lama |
| Create | `resources/views/naskah/meja-kerja.blade.php` | Layar 1 |
| Create | `resources/views/naskah/pelacakan.blade.php` + partials `toolbar`,`zona`,`kartu`,`kartu-buku` | Layar 2/2B |
| Create | `resources/views/naskah/daftar.blade.php`, `riwayat.blade.php`, `arsip.blade.php` | View alternatif |
| Create | `resources/views/naskah/detail.blade.php` + partials `stepper`,`info`,`aksi`,`bab-table`,`files`,`riwayat` | Layar 3/3B |
| Modify | `resources/views/layouts/sidebar.blade.php` | Menu NASKAH: Meja Kerja Saya / Pelacakan Naskah / Arsip |
| Create | `app/Console/Commands/NaskahMigrateV2.php` | Migrasi data lama (§8 spec) |
| Create | `app/Console/Commands/NaskahCheckOverdue.php` | Penanda & notifikasi keterlambatan (daily) |
| Create | `tests/Unit/AssignmentServiceTest.php`, `tests/Unit/ChapterRollupServiceTest.php` | Unit |
| Modify | `tests/Unit/TitleProgressServiceTest.php` | advance/correct/final-unlock/no-op |
| Create | `tests/Feature/NaskahMejaKerjaTest.php`, `NaskahPelacakanTest.php`, `NaskahDetailTest.php`, `NaskahMigrasiTest.php` | Feature |
| Delete (Task 14) | Controller/view/route/test modul distribusi & manuscript lama | Cleanup |

---

## Task 1: Migrations — fondasi skema

**Files:** 3 migration baru (lihat File Map).

- [x] **Step 1:** `tb_title_progress`: tambah `pj_user_id` (FK users, nullOnDelete), rename `assigned_user_id`→`pelaksana_user_id`, `bidang` string(10) index, `sla_due_at` date nullable, `overdue_reason` string(30) nullable, `overdue_note` text nullable, `is_on_hold` bool default false, `chapters_done` bool default false, `archived_at` timestamp nullable index, `cancelled_at` timestamp nullable, `cancelled_by` FK users nullable, `cancel_reason` string nullable. *(Catatan implementasi: rename via raw `ALTER TABLE … CHANGE` — MariaDB 10.4 XAMPP belum dukung RENAME COLUMN & doctrine/dbal tak terpasang; FK+index dilepas lalu dipasang ulang. Semua referensi kode lama `assigned_user_id` milik tb_title_progress ikut diganti supaya modul lama tetap berfungsi; nama field form HTTP & kolom ChapterProgress lama TIDAK diubah.)*
- [x] **Step 2:** `tb_chapter_progress`: tambah `pelaksana_user_id` FK nullable, `sla_due_at` date nullable.
- [x] **Step 3:** `user_profiles`: tambah `bidang` string(10) nullable (`artikel`|`buku`).
- [x] **Step 4:** Jalankan `php artisan migrate` di dev + `php artisan test` → hijau (belum ada perilaku berubah). *(2026-08-09: dev ter-migrate; suite 815 passed, 1 skipped.)*

## Task 2: Model — konstanta & relasi

**Files:** `TitleProgress.php`, `ChapterProgress.php`.

- [x] **Step 1:** Ganti konstanta sesuai spec §3 (ARTICLE_STAGES tanpa `templating` + `pembuatan`; BOOK_STAGES dengan `pembuatan`,`editing`; CHAPTER_STAGES baru). Simpan `LEGACY_STAGE_MAP = ['templating'=>'editing', ...]` untuk migrasi & guard data lama. *(CHAPTER_STAGES + LEGACY_CHAPTER map di ChapterProgress; test lama pemakai `templating` diadaptasi ke `pembuatan`; 4 view peta warna diberi kunci `pembuatan`.)*
- [x] **Step 2:** Relasi `pj()` dan `pelaksana()`; scope `active()` (`archived_at` null & `cancelled_at` null), `overdue()`, `mine($userId)`, `bidang($b)`; helper `daysInStage()` (dari `started_at`), `isOverdue()`, `nextStage()`, `stageLabelId()` (label Indonesia). *(+ `createForDetail` kini mengisi `bidang` & mewarisi `pj_user_id` grup; alias deprecated `assignedUser()` dipertahankan utk modul lama.)*
- [x] **Step 3:** Update `Title::stageLabel()` untuk stage baru (`pembuatan` → "Pembuatan Naskah").
- [x] **Step 4:** `STAGE_HANDLER` disesuaikan: `pembuatan`→production, `editing`..`isbn`→admin, `cetak`/`terbit`/`loa`/`publish`→admin (superadmin hanya via koreksi); hapus handler marketing kecuali `menunggu_proses`. *(DIKERJAKAN PARSIAL DI SINI: `pembuatan`→production ditambah & `templating` dihapus. Pemetaan penuh editing..terbit→admin SENGAJA digeser ke Task 5 — mengubahnya sekarang mengunci modul distribusi lama (authorizeChange berbasis handler==='production') sehingga tak ada role non-super yang bisa maju tahap; remap final satu paket dengan refactor advance()/correct().)*

## Task 3: AccessMatrixSeeder — permission `naskah.*`

- [ ] **Step 1:** Tambah action per spec §4 dengan komentar penjelasan pola yang sama seperti action lain di file itu. Permission lama `manuscript.*`/`distribution.*` DIBIARKAN sampai Task 14.
- [ ] **Step 2:** Feature test `AccessParityTest` diperluas: route `naskah.*` tercakup matrix.

## Task 4: AssignmentService (BARU) + unit test

**Rules yang di-enforce (semua melempar `ValidationException`/`AuthorizationException` dgn pesan Indonesia):**

- [ ] **Step 1:** `distribute($progressOrChapter, int $pelaksanaId, User $actor)` — actor harus `naskah.assign` + bidang cocok; target HARUS role `production` (akun admin ditolak: "Pelaksana harus akun Produksi"); bab tanpa author ditolak ("Petakan author bab terlebih dahulu"); set `sla_due_at = 7 hari kerja` (helper `addWorkdays`); log `distribusi`; notifikasi pelaksana.
- [ ] **Step 2:** `claim($progressOrChapter, User $actor)` — actor role production; hanya jika pelaksana masih NULL; log `claim`.
- [ ] **Step 3:** `transferPj($progress, int $adminId, User $actor)` — penerima harus admin dengan `bidang` SAMA (superadmin bebas lintas bidang); log `oper_pj`; notifikasi penerima.
- [ ] **Step 4:** `withdraw`, `hold`/`unhold` (alasan opsional), `cancel` (alasan WAJIB, set `cancelled_*`) — semua log + aksi grup (fan-out via `group_key` seperti `assignGroup` lama).
- [ ] **Step 5:** `tests/Unit/AssignmentServiceTest.php` — kasus: admin assign ke produksi ✓; assign ke admin ✗; produksi claim ✓; claim yang sudah berpelaksana ✗; oper PJ sebidang ✓; lintas bidang ✗ (superadmin ✓); bab tanpa author ✗; SLA terhitung benar (lewati akhir pekan); cancel tanpa alasan ✗.

## Task 5: TitleProgressService — advance/correct

- [ ] **Step 1:** `advance($progress, User $actor, ?string $note)` — target SELALU `nextStage()`; gate `naskah.advance` + bidang; catatan opsional; fan-out grup (pakai `changeGroupStatus` yang ada, dipanggil dengan target = next); kembalikan `affected_count` untuk flash "Tahap diperbarui — berlaku untuk N order".
- [ ] **Step 2:** `correct($progress, string $target, User $actor, string $note)` — HANYA superadmin (`naskah.correct`); catatan wajib; boleh menyentuh FINAL_STAGES (ubah `authorizeChange`: hapus kunci final untuk superadmin); log `status_corrected`.
- [ ] **Step 3:** Fix no-op: bila target == status sekarang → return flash info "Tidak ada perubahan." tanpa exception.
- [ ] **Step 4:** Auto-skip `pembuatan`: order tanpa jasa penulisan (deteksi: tidak pernah didistribusikan pelaksana & file `masuk` diupload marketing/admin saat `menunggu_proses`) → langsung `editing` (dipanggil dari ManuscriptFileService, lihat Task 6).
- [ ] **Step 5:** Update `tests/Unit/TitleProgressServiceTest.php`: advance satu langkah ✓; lompat via advance ✗; correct tanpa note ✗; correct final oleh superadmin ✓ / admin ✗; no-op ramah; grup fan-out N order.

## Task 6: ChapterRollupService + auto-advance upload

- [ ] **Step 1:** `ChapterRollupService::recalc(Title $title)` — hitung status buku per spec §3; set `chapters_done`; log `chapters_done` saat pertama true; dipanggil setiap chapter_progress berubah (panggil eksplisit dari service, bukan observer magic, agar mudah dites).
- [ ] **Step 2:** "Mulai Layout": `advance()` dari `editing`→`layout` untuk buku kolaborasi DITOLAK selama `chapters_done=false` ("Semua bab harus Selesai dulu").
- [ ] **Step 3:** `ManuscriptFileService::upload()` — slot baru (`hasil_editing`,`hasil_layout`,`hasil_proofread`,`cover`); bila uploader = pelaksana & status `pembuatan` → auto-advance ke `editing` + log `auto_advance_upload` + notifikasi PJ; bila status `menunggu_proses` & slot `masuk` → auto ke `editing` (skip pembuatan, Task 5 Step 4).
- [ ] **Step 4:** `tests/Unit/ChapterRollupServiceTest.php`: bottleneck benar; semua selesai → chapters_done; layout terkunci/terbuka; auto-advance bab & buku mandiri & artikel.

## Task 7: Notifier

- [ ] **Step 1:** Method baru: `naskahDistribusi`, `naskahClaimed`, `naskahPjTransferred`, `naskahOverdue`, `naskahPublished` (ke marketing pemilik TIAP order grup). Pakai pola method existing (`naskahStageChanged`). Hapus pemakaian `distribusiChanged` di alur baru.
- [ ] **Step 2:** Penerima per matrix spec §7. Superadmin & (kelak) manager di-resolve via role, bukan hardcode id.

## Task 8: Routes + Controllers

- [ ] **Step 1:** Group `Route::prefix('naskah')->name('naskah.')` dalam middleware `['auth','access']`:
  `GET meja-kerja`, `GET pelacakan`, `GET arsip`, `GET {orderDetail}` (whereNumber),
  `POST {id}/selesaikan|koreksi|revisi|distribusi|claim|oper-pj|tarik|prioritas|target|hold|batal|file`,
  `POST bab/{cp}/distribusi|claim|selesaikan|file|author`.
- [ ] **Step 2:** `MejaKerjaController@index` — query: progress aktif milik user (pelaksana ATAU pj) + antrian claim (produksi); statistik 4 angka; sort overdue→deadline→prioritas.
- [ ] **Step 3:** `PelacakanNaskahController@index` — filter tipe/PJ/pelaksana/prioritas/cari; view papan/daftar/riwayat; data zona per spec §6; `@arsip` — DataTable archived+cancelled.
- [ ] **Step 4:** `DetailNaskahController@show` — eager load lengkap; blok aksi dirakit per permission actor (view TIDAK memeriksa role sendiri, terima flag dari controller); semua POST delegasi ke service, flash pesan Indonesia, pattern `run()` try/catch seperti controller lama.
- [ ] **Step 5:** Route lama `management/distribusi/*` & `management/manuscript` → `Route::redirect()` permanen ke `naskah.pelacakan` (hapus di Task 14).
- [ ] **Step 6:** Feature test rute: role matrix akses (marketing GET ✓ POST ✗ kecuali target/file-masuk; produksi claim/upload ✓ advance ✗; admin bidang lain ✗).

## Task 9: View Layar 1 — Meja Kerja Saya

- [ ] **Step 1:** Bangun `naskah/meja-kerja.blade.php` PERSIS layout wireframe "LAYAR 1" (stat cards, daftar tugas, antrian claim, badge & warna overdue). Komponen kartu tugas = partial agar dipakai ulang.
- [ ] **Step 2:** Tombol kontekstual: produksi+`pembuatan` → form upload; admin+tahap admin → tombol "✓ Selesaikan {label} →"; antrian → "✋ Ambil Tugas Ini".
- [ ] **Step 3:** `tests/Feature/NaskahMejaKerjaTest.php`: urutan sort benar; antrian hanya tampil utk produksi; angka statistik benar.

## Task 10: View Layar 2/2B — Pelacakan + Arsip

- [ ] **Step 1:** `pelacakan.blade.php` + partials sesuai wireframe LAYAR 2 & 2B: zona artikel (3) & buku (4, termasuk "Produksi per Bab" vs "Produksi Level Buku"), kartu dengan aging/target/grup, kartu buku dgn ringkasan bab + progress bar, kartu duduk di kolom bottleneck.
- [ ] **Step 2:** Kartu link → `naskah.show` (BUKAN halaman order). Papan read-only; aksi hanya di detail.
- [ ] **Step 3:** `daftar.blade.php` (DataTable), `riwayat.blade.php` (log per tipe), `arsip.blade.php`.
- [ ] **Step 4:** `tests/Feature/NaskahPelacakanTest.php`: kolom & zona per tipe; kartu grup tampil 1x dgn badge N order; arsip menampilkan published; papan TIDAK menampilkan archived/cancelled.

## Task 11: View Layar 3/3B — Detail Naskah

- [ ] **Step 1:** `detail.blade.php` + partials per wireframe LAYAR 3: header + banner grup, stepper (done/aktif+durasi/upcoming, target di step akhir), kartu info/brief/file, kartu aksi per role, riwayat.
- [ ] **Step 2:** 3B: pintasan level buku, tabel bab (kolom Author wajib tampil; baris tanpa author kuning + "Petakan Author", tombol distribusi disabled), file level buku vs bab, riwayat gabungan.
- [ ] **Step 3:** Tombol maju TUNGGAL (`nextStage()`); koreksi = modal terpisah dgn dropdown tahap + textarea wajib (hanya render utk superadmin); "Perlu Revisi" = advance khusus `editing`→`revisi` dgn dropdown alasan baku.
- [ ] **Step 4:** `tests/Feature/NaskahDetailTest.php`: marketing tak melihat blok aksi; admin melihat satu tombol maju dgn label benar; superadmin melihat koreksi; alur upload→auto-advance end-to-end; bab tanpa author tidak bisa didistribusikan (HTTP + pesan).

## Task 12: Sidebar, istilah, prioritas

- [ ] **Step 1:** Sidebar seksi NASKAH: "Meja Kerja Saya" (`naskah.workdesk`), "Pelacakan Naskah" (`naskah.view`), "Arsip Naskah" (`naskah.view`) — label SAMA untuk semua role. Hapus menu lama dari sidebar (route redirect tetap hidup).
- [ ] **Step 2:** Form prioritas ada di Detail (kartu aksi) — memastikan fitur prioritas hidup kembali.
- [ ] **Step 3:** Sweep istilah: `grep -rn "editor\|Editor" resources/views/naskah app/Http/Controllers/Pages/Naskah app/Services/AssignmentService.php` → 0 hasil di UI string.

## Task 13: Commands — migrasi data & overdue

- [ ] **Step 1:** `naskah:migrate-v2` per spec §8 (idempotent, `--dry-run` flag, ringkasan jumlah per langkah). Feature test `NaskahMigrasiTest` dgn data lama sintetis (templating, assigned admin, published, chapter lama).
- [ ] **Step 2:** `naskah:check-overdue` — tandai & notifikasi (SLA pembuatan + target publish/terbit); daftarkan di `routes/console.php`/Kernel schedule daily.
- [ ] **Step 3:** Uji manual di dev dgn snapshot DB produksi.

## Task 14: Cutover & cleanup (setelah 1 bulan stabil / keputusan owner)

- [ ] **Step 1:** Hapus controller/view/route/test modul lama (`ArticleDistributionController`, `BookDistributionController`, `ManuscriptTrackerController`, `resources/views/distribusi`, `resources/views/manuscript`, test terkait) + permission `distribution.*`, `manuscript.view` dari seeder (migrasikan `manuscript.detail` bila masih dipakai halaman judul).
- [ ] **Step 2:** Hapus redirect route lama. `php artisan test` hijau penuh.
- [ ] **Step 3:** Update `docs/` : tandai spec lama distribusi-naskah-split sebagai superseded.

---

## Verifikasi Akhir (harus lulus SEMUA — dari spec §10)

- [ ] 11 kriteria penerimaan spec §10 diverifikasi manual di dev dengan 4 akun uji (marketing, produksi, admin artikel, admin buku + superadmin).
- [ ] Bandingkan tiap layar berdampingan dengan `docs/wireframe-penugasan-naskah.html` — struktur, label, penempatan tombol, dan bahasa harus cocok.
- [ ] `php artisan test` hijau; `AccessParityTest` mencakup semua route `naskah.*`.
