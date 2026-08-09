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

- [x] **Step 1:** Tambah action per spec §4 dengan komentar penjelasan pola yang sama seperti action lain di file itu. Permission lama `manuscript.*`/`distribution.*` DIBIARKAN sampai Task 14.
- [x] **Step 2:** ~~Feature test `AccessParityTest` diperluas: route `naskah.*` tercakup matrix.~~ *(Digeser sebagian: `AccessParityTest` memanggil `route($name)`, jadi baru bisa memuat `naskah.*` setelah route-nya lahir di Task 8 — ditambahkan di sana. Sebagai gantinya matriks dikunci SEKARANG di tingkat permission lewat `PermissionMapTest::matriks_naskah_sesuai_keputusan_bisnis` (12 permission × 5 role).)*

## Task 4: AssignmentService (BARU) + unit test

**Rules yang di-enforce (semua melempar `ValidationException`/`AuthorizationException` dgn pesan Indonesia):**

- [x] **Step 1:** `distribute($progressOrChapter, int $pelaksanaId, User $actor)` — actor harus `naskah.assign` + bidang cocok; target HARUS role `production` (akun admin ditolak: "Pelaksana harus akun Produksi"); bab tanpa author ditolak ("Petakan author bab terlebih dahulu"); set `sla_due_at = 7 hari kerja` (helper `addWorkdays`); log `distribusi`; notifikasi pelaksana. *(Tambahan dari wireframe: distribusi dari antrian SEKALIGUS memasukkan naskah/bab ke tahap Pembuatan — riwayat wireframe "Bab 3 masuk Pembuatan (distribusi ke Fitri, SLA 7 hari)" & stepper "Pembuatan 29 Jul–4 Agu". Ganti pelaksana di tahap lanjut hanya menukar orang, tak menyentuh tahap.)*
- [x] **Step 2:** `claim($progressOrChapter, User $actor)` — actor role production; hanya jika pelaksana masih NULL; log `claim`.
- [x] **Step 3:** `transferPj($progress, int $adminId, User $actor)` — penerima harus admin dengan `bidang` SAMA (superadmin bebas lintas bidang); log `oper_pj`; notifikasi penerima.
- [x] **Step 4:** `withdraw`, `hold`/`unhold` (alasan opsional), `cancel` (alasan WAJIB, set `cancelled_*`) — semua log + aksi grup (fan-out via `group_key` seperti `assignGroup` lama). *(`withdraw` sengaja TIDAK memundurkan tahap — mundur = wilayah koreksi; naskah kembali ke antrian lewat definisi antrian "pelaksana NULL & tahap ≤ pembuatan".)*
- [x] **Step 5:** `tests/Unit/AssignmentServiceTest.php` — kasus: admin assign ke produksi ✓; assign ke admin ✗; produksi claim ✓; claim yang sudah berpelaksana ✗; oper PJ sebidang ✓; lintas bidang ✗ (superadmin ✓); bab tanpa author ✗; SLA terhitung benar (lewati akhir pekan); cancel tanpa alasan ✗. *(17 test.)*

> **Keputusan implementasi (butuh konfirmasi owner bila tak setuju):** `user_profiles.bidang` kosong = **belum di-scope → boleh semua bidang**, bukan terkunci. Kolom itu baru ada dan belum punya layar pengisian (Manajemen User tak menyentuh tabel profil), jadi tafsir "terkunci" akan mematikan modul untuk SEMUA admin sejak hari pertama. Perbandingan bidang tetap ketat begitu nilainya terisi. Ubah di `AssignmentService::assertBidang()` bila owner mau versi ketat.
>
> **Notifier:** tiga method yang dipakai service ini (`naskahDistribusi`, `naskahClaimed`, `naskahPjTransferred`) ditulis di task ini juga supaya suite tetap hijau per-task; Task 7 melengkapi sisanya (`naskahOverdue`, `naskahPublished`) + matriks penerima.

## Task 5: TitleProgressService — advance/correct

- [x] **Step 1:** `advance($progress, User $actor, ?string $note)` — target SELALU `nextStage()`; gate `naskah.advance` + bidang; catatan opsional; fan-out grup; kembalikan `affected_count` untuk flash "Tahap diperbarui — berlaku untuk N order". *(Fan-out ditulis sendiri (`applyGroup`), TIDAK lewat `changeGroupStatus` — method itu memakai gerbang lama `authorizeChange` berbasis STAGE_HANDLER dan masih melayani modul distribusi. Tambahan: maju ke tahap final menulis `archived_at` + log `diarsipkan`; koreksi mundur membatalkannya.)*
- [x] **Step 2:** `correct($progress, string $target, User $actor, string $note)` — HANYA superadmin (`naskah.correct`); catatan wajib; boleh menyentuh FINAL_STAGES; log `status_corrected`. *(`authorizeChange` lama TIDAK diubah — jalur baru tak memakainya sama sekali, jadi kunci final modul lama tetap utuh.)*
- [x] **Step 3:** Fix no-op: bila target == status sekarang → `correct()` mengembalikan 0 tanpa exception; controller menampilkan flash info "Tidak ada perubahan."
- [x] **Step 4:** Auto-skip `pembuatan` + auto-advance upload: `autoAdvanceOnUpload($progress, $uploader, $slot)` — slot `masuk` saja; `pembuatan` + diunggah pelaksananya → `editing`; `menunggu_proses` → langsung `editing` (naskah dari klien). Disambungkan ke `ManuscriptFileService` di Task 6.
- [x] **Step 5:** Update `tests/Unit/TitleProgressServiceTest.php`: advance satu langkah ✓; advance tanpa izin ✗; advance di tahap akhir ✗; arsip otomatis ✓; correct tanpa note ✗; correct final oleh superadmin ✓ / admin ✗; no-op ramah; grup fan-out N order; 4 kasus auto-advance upload. *(14 test baru.)*

> **Remap `STAGE_HANDLER` (sisa Task 2 Step 4) DIGESER KE TASK 14.** Memetakan `editing`..`terbit`→`admin` sekarang akan (a) mematikan `authorizeChange` untuk modul Distribusi lama — production/admin tak bisa lagi memindahkan tahap apa pun selain `pembuatan`, dan (b) mengubah `productionStages()` yang dipakai ProductionDashboardService & ManuscriptTrackerController, sehingga "Antrian Saya"/"belum diambil" salah hitung. Modul baru TIDAK bergantung pada peta ini sama sekali (otorisasinya permission `naskah.*` + bidang), jadi remap murni pekerjaan pembersihan yang aman dilakukan bersamaan penghapusan modul lama.
>
> **Trait `App\Services\Concerns\AuthorizesNaskah`** menampung `requirePermission()` + `requireBidang()` supaya aturan bidang tidak bercabang antara AssignmentService & TitleProgressService.

## Task 6: ChapterRollupService + auto-advance upload

- [x] **Step 1:** `ChapterRollupService::recalc(Title $title)` — hitung status buku per spec §3; set `chapters_done`; log `chapters_done` saat pertama true; dipanggil eksplisit dari `TitleProgressService::applyChapterStatus()` setiap bab berpindah. *(Roll-up hanya menggerakkan buku selama masih di wilayah bab (≤ `editing`) — begitu admin menekan "Mulai Layout", perubahan bab tak bisa menariknya mundur. Buku mandiri dikecualikan lewat `isCollaborative()` berbasis tipe order `bk_kolab`. Bonus: `summary()` untuk ringkasan bab + progress bar kartu papan.)*
- [x] **Step 2:** "Mulai Layout": `advance()` dari `editing`→`layout` untuk buku kolaborasi DITOLAK selama `chapters_done=false` ("Semua bab harus Selesai dulu sebelum masuk tahap Layout.").
- [x] **Step 3:** `ManuscriptFileService::upload()` — slot baru (`hasil_editing`,`hasil_layout`,`hasil_proofread`,`cover`); bila uploader = pelaksana & status `pembuatan` → auto-advance ke `editing` + log `auto_advance_upload` + notifikasi PJ (`Notifier::naskahAutoAdvanced`); bila status `menunggu_proses` & slot `masuk` → auto ke `editing`. *(Kolom `slot` sudah `string(20)` — tak perlu migrasi. `advanceChapter()`/`autoAdvanceChapterOnUpload()` untuk alur bab ditaruh di TitleProgressService bersama `advance()` karena sama-sama mesin tahap.)*
- [x] **Step 4:** `tests/Unit/ChapterRollupServiceTest.php`: bottleneck benar; semua selesai → chapters_done; layout terkunci/terbuka; auto-advance bab & buku mandiri & artikel. *(12 test; alur upload diuji end-to-end lewat ManuscriptFileService, bukan memanggil service tahap langsung.)*

## Task 7: Notifier

- [x] **Step 1:** Method baru: `naskahDistribusi`, `naskahClaimed`, `naskahPjTransferred` (Task 4), `naskahAutoAdvanced` (Task 6), `naskahWithdrawn`, `naskahTahapBerubah`, `naskahOverdue`, `naskahPublished` (ke marketing pemilik TIAP order grup). Pakai pola method existing. `distribusiChanged` TIDAK dipakai alur baru sama sekali (tinggal melayani controller distribusi lama).
- [x] **Step 2:** Penerima per matrix spec §7. Superadmin di-resolve via role (`roleUsers`/`User::role()`), bukan hardcode id. *(Perubahan semantik yang disengaja: di alur v2, maju/koreksi tahap mengabari **PJ + superadmin**, BUKAN marketing tiap tahap — marketing dapat kabar saat publish/terbit lewat `naskahPublished`, persis catatan wireframe LAYAR 3. `naskahStageChanged` lama dibiarkan utuh supaya modul distribusi lama tetap berperilaku sama.)*
- [x] **Step 3 (tambahan):** `tests/Feature/NaskahNotifikasiTest.php` mengunci matriks penerima — 8 test, satu per baris tabel spec §7, termasuk publish yang mengabari pemilik order BERBEDA dalam satu grup judul.

## Task 8: Routes + Controllers

- [x] **Step 1:** Group `Route::prefix('naskah')->name('naskah.')` dalam middleware `['auth','access']` — semua route sesuai daftar. `{id}` SELALU `order_detail_id` (identitas = kode order); route `bab/*` dideklarasikan lebih dulu agar segmen "bab" tak tertelan pola `{id}`.
- [x] **Step 2:** `MejaKerjaController@index` — tugas aktif milik user (pelaksana ATAU pj) **digabung dengan tugas bab** (wireframe LAYAR 1 baris ke-2 memang baris bab); antrian claim; statistik 4 angka; sort terlambat→deadline→prioritas. Bab tanpa author sengaja tak masuk antrian karena memang belum boleh didistribusikan.
- [x] **Step 3:** `PelacakanNaskahController@index` — filter tipe/PJ/pelaksana/prioritas/cari; tampilan papan/daftar/riwayat; zona per spec §6; `@arsip` = DataTable selesai + filter dibatalkan.
- [x] **Step 4:** `DetailNaskahController@show` — eager load lengkap; flag izin dirakit di controller (view tidak pernah memeriksa role); semua POST delegasi ke service dengan pola `run()` + flash Bahasa Indonesia.
- [ ] **Step 5:** ~~Route lama → `Route::redirect()` ke `naskah.pelacakan`~~ **DIGESER KE TASK 14.** Mengganti route lama dengan redirect = mematikan modul Distribusi/Pelacak lama, padahal instruksi eksekusi mewajibkannya tetap berfungsi selama masa transisi (dan Task 14 menunggu keputusan owner). Redirect adalah langkah cutover, bukan langkah pembangunan.
- [x] **Step 6:** Feature test rute: `AccessParityTest` diperluas 27 kasus `naskah.*` (menutup utang Task 3 Step 2) + `NaskahLayarTest` 11 test yang benar-benar me-render keempat layar dengan data nyata.

> **Bug lama yang tersingkap & diperbaiki di sini:** `TitleProgress` memakai `protected $dates = ['started_at']` — properti itu **sudah tidak berlaku sejak Laravel 10**, jadi `started_at` selalu kembali sebagai string dan `->copy()` di `daysInStage()` fatal. Tak pernah terlihat karena tak ada kode lama yang memperlakukannya sebagai Carbon. Diperbaiki dengan memindahkannya ke `$casts`.

## Task 9: View Layar 1 — Meja Kerja Saya

- [x] **Step 1:** Bangun `naskah/meja-kerja.blade.php` sesuai wireframe "LAYAR 1" (stat cards, daftar tugas, antrian claim, badge & warna overdue). Kartu tugas = partial `tugas-baris` + `identitas`, dipakai ulang untuk baris judul MAUPUN baris bab.
- [x] **Step 2:** Tombol kontekstual: pelaksana+`pembuatan` → "⬆ Upload Naskah" (menuju kartu file di Detail, tempat form unggahnya); yang berwenang maju → "✓ Selesaikan Tahap →" (POST langsung); antrian → "✋ Ambil Tugas Ini" (POST langsung).
- [x] **Step 3:** `tests/Feature/NaskahMejaKerjaTest.php` — 8 test: urutan sort, statistik, tugas orang lain tak bocor, final/dibatalkan tak muncul, antrian & claim, tombol kontekstual, bahasa tanpa jargon.

## Task 10: View Layar 2/2B — Pelacakan + Arsip

- [x] **Step 1:** `pelacakan.blade.php` + partials sesuai wireframe LAYAR 2 & 2B: zona artikel (3) & buku (4, termasuk "Produksi per Bab" vs "Produksi Level Buku"), kartu dengan lama-di-tahap/target/grup, kartu buku dgn ringkasan bab + progress bar, kartu duduk di kolom bottleneck.
- [x] **Step 2:** Kartu link → `naskah.show` (BUKAN halaman order). Papan read-only; aksi hanya di detail — dijaga test yang memindai `<form action>` ke `/naskah/`.
- [x] **Step 3:** `partials/daftar.blade.php` (DataTable), `partials/riwayat-tabel.blade.php` (log per tipe), `arsip.blade.php` (selesai + filter dibatalkan). *(Daftar & riwayat jadi partial dari `pelacakan.blade.php` karena ketiganya berbagi toolbar & filter yang sama — bukan halaman terpisah.)*
- [x] **Step 4:** `tests/Feature/NaskahPelacakanTest.php` — 10 test: zona per tipe, kartu grup 1× + badge N order, kartu di kolom bottleneck, tautan ke Detail, filter, arsip memisahkan selesai/dibatalkan, papan menyembunyikan archived+cancelled, papan hanya-baca.

## Task 11: View Layar 3/3B — Detail Naskah

- [x] **Step 1:** `detail.blade.php` + partials per wireframe LAYAR 3: header + banner grup (dengan drill-down per order), stepper (done/aktif+durasi/upcoming, target di step akhir), kartu info/brief/file, kartu aksi per izin, riwayat.
- [x] **Step 2:** 3B: tabel bab (kolom Author selalu tampil; baris tanpa author kuning + "Petakan Author"; distribusi baru muncul setelah author dipetakan), file level buku vs bab, riwayat gabungan (log bab menempel pada linimasa naskah). *(Pintasan "terapkan 1 pelaksana ke semua bab" dan "ubah struktur bab" BELUM dibuat — lihat catatan di bawah.)*
- [x] **Step 3:** Tombol maju TUNGGAL (`nextStage()`); koreksi = form terpisah (collapse) dgn dropdown tahap + textarea wajib, hanya dirender untuk superadmin; "Perlu Revisi" = jalur khusus dgn dropdown alasan baku.
- [x] **Step 4:** `tests/Feature/NaskahDetailTest.php` — 14 test: satu tombol maju & tanpa dropdown semua-tahap, maju lewat HTTP, flash "N order", produksi ditolak, koreksi wajib catatan, no-op ramah, marketing tanpa blok aksi tapi tetap bisa set target, upload→auto-advance end-to-end, batal wajib alasan, tabel bab + author, bab tanpa author ditolak, pemetaan author membuka distribusi, gerbang Mulai Layout, file bab terpisah.

> **Belum dibuat (sadar, bukan terlewat):** dua pintasan level buku di wireframe 3B — "Terapkan 1 pelaksana ke semua bab" dan "+ Tambah / ubah struktur bab". Keduanya kenyamanan, bukan syarat alur: mendistribusikan bab satu per satu sudah berjalan penuh, dan struktur bab dibuat otomatis dari jumlah bab order (`ChapterManuscriptService::ensureChapters`) serta bisa diubah lewat Direktori Judul. Ditandai di sini supaya owner bisa memutuskan apakah perlu sebelum rilis.

## Task 12: Sidebar, istilah, prioritas

- [x] **Step 1:** Sidebar seksi NASKAH: "Meja Kerja Saya" (`naskah.workdesk`), "Pelacakan Naskah" (`naskah.view`), "Arsip Naskah" (`naskah.view`) — label SAMA untuk semua role. *(Menu lama TIDAK dihapus, hanya diberi judul seksi "Produksi (lama)" — modul lama masih harus berfungsi selama transisi; penghapusannya satu paket dengan Task 14.)*
- [x] **Step 2:** Form prioritas ada di Detail (kartu aksi) — fitur prioritas hidup kembali (dropdown high/normal/low, berlaku serempak untuk grup judul).
- [x] **Step 3:** Sweep istilah bersih (0 hasil). Diperkuat jadi penjaga permanen: `tests/Feature/NaskahIstilahTest.php` memindai seluruh view+controller+service modul untuk kata "editor"/"tracker"/"aging", plus menguji sidebar dan bahasa layar.

## Task 13: Commands — migrasi data & overdue

- [x] **Step 1:** `naskah:migrate-v2` per spec §8 (idempotent, `--dry-run`, ringkasan jumlah per langkah + peringatan naskah yang belum punya PJ). `NaskahMigrasiTest` dgn data lama sintetis — 8 test migrasi + 4 test overdue.
- [x] **Step 2:** `naskah:check-overdue` — daftar + notifikasi (SLA pembuatan & target publish/terbit), terjadwal `dailyAt('07:00')` di `app/Console/Kernel.php`. *(Naskah yang sedang ditahan sengaja dilewati: penundaannya sudah disepakati, mengabarinya tiap hari hanya jadi kebisingan. Command TIDAK mengisi `overdue_reason` — alasan wajib datang dari manusia lewat Detail Naskah.)*
- [x] **Step 3:** Uji manual di dev (DB `avidpedi_simapa`, data mirip produksi): `--dry-run` → 1 templating · 126 bidang · 1 PJ dipisahkan · 75 bab · 6 buku roll-up. Dijalankan sungguhan, lalu **diulang → semua nol** (idempotensi terbukti pada data nyata, bukan hanya sintetis). `naskah:check-overdue --dry-run` menemukan 1 naskah lewat target. `AccessMatrixSeeder` di-seed ulang di dev agar permission `naskah.*` aktif.

## Task 14: Cutover & cleanup (setelah 1 bulan stabil / keputusan owner)

- [ ] **Step 1:** Hapus controller/view/route/test modul lama (`ArticleDistributionController`, `BookDistributionController`, `ManuscriptTrackerController`, `resources/views/distribusi`, `resources/views/manuscript`, test terkait) + permission `distribution.*`, `manuscript.view` dari seeder (migrasikan `manuscript.detail` bila masih dipakai halaman judul).
- [ ] **Step 2:** Hapus redirect route lama. `php artisan test` hijau penuh.
- [ ] **Step 3:** Update `docs/` : tandai spec lama distribusi-naskah-split sebagai superseded.

---

## Verifikasi Akhir (harus lulus SEMUA — dari spec §10)

- [x] **11 kriteria penerimaan spec §10 — masing-masing dikunci test otomatis**, bukan hanya dicoba sekali:

| # | Kriteria | Dikunci oleh |
|---|----------|--------------|
| 1 | Meja Kerja langsung menunjukkan tugas, yang telat, dan satu aksi jelas | `NaskahMejaKerjaTest` — urutan sort, statistik, tombol kontekstual |
| 2 | Upload di tahap Pembuatan → otomatis Editing + notifikasi PJ + log `auto_advance_upload` | `NaskahDetailTest::upload_naskah_oleh_pelaksana_memajukan_tahap_end_to_end`, `NaskahNotifikasiTest::upload_naskah_mengabari_pj` |
| 3 | Detail hanya punya SATU tombol maju; submit tanpa perubahan bukan error | `NaskahDetailTest::hanya_ada_satu_tombol_maju_dan_tanpa_dropdown_semua_tahap`, `…submit_tanpa_perubahan_bukan_error_melainkan_info_ramah` |
| 4 | Tabel bab menampilkan author; bab tanpa author kuning & tak bisa didistribusikan | `NaskahDetailTest::tabel_bab_menampilkan_author…`, `…bab_tanpa_author_tidak_bisa_didistribusikan` |
| 5 | "Mulai Layout" terkunci sampai semua bab Selesai | `NaskahDetailTest::tombol_mulai_layout_terkunci_sampai_semua_bab_selesai`, `ChapterRollupServiceTest` |
| 6 | Marketing read-only; dapat notifikasi saat publish/terbit | `NaskahDetailTest::marketing_tidak_melihat_blok_aksi…`, `NaskahNotifikasiTest::publish_mengabari_marketing_pemilik_tiap_order_dalam_grup` |
| 7 | Naskah selesai ada di Arsip dan bisa dicari — tidak pernah hilang | `NaskahPelacakanTest::arsip_memisahkan_naskah_selesai_dari_yang_dibatalkan`, `TitleProgressServiceTest::advance_ke_tahap_final_memindahkan_naskah_ke_arsip` |
| 8 | Koreksi naskah final hanya superadmin + catatan wajib, tercatat | `TitleProgressServiceTest::superadmin_bisa_mengoreksi_naskah_yang_sudah_final`, `…correct_hanya_superadmin`, `…correct_wajib_catatan` |
| 9 | Aksi grup N order → banner + N progress berubah + N baris log | `NaskahDetailTest::flash_menyebut_jumlah_order_saat_judul_bergrup`, `NaskahLayarTest::banner_grup_muncul…`, `AssignmentServiceTest::aksi_berlaku_serempak…` |
| 10 | Tanpa kata "editor"/"tracker"/"aging"; kartu papan menaut ke Detail Naskah | `NaskahIstilahTest` (pemindai statis seluruh berkas modul), `NaskahPelacakanTest::kartu_menautkan_ke_detail_naskah_bukan_ke_halaman_order` |
| 11 | Suite hijau (`php artisan test`, DB `.env.testing`) | Dijalankan penuh di tiap task; hasil akhir tercatat di commit terakhir |

- [x] **Banding layar vs `docs/wireframe-penugasan-naskah.html`.** Lima selisih ditemukan dan ditutup: (a) **fungsional** — tombol unggah & daftar file per bab belum ada di tabel 3B padahal route-nya sudah jalan, sehingga pelaksana bab tak punya jalan mengunggah naskahnya dari layar; (b) baris "Pembayaran" di kartu Informasi; (c) catatan roll-up di header buku kolaborasi; (d) tombol "Riwayat Lengkap"; (e) chip jumlah author bab. Semua kini punya test.
- [x] `php artisan test` hijau; `AccessParityTest` mencakup route `naskah.*` (27 kasus).

### Sisa yang menunggu keputusan owner (bukan pekerjaan tertinggal)

1. **Task 14 (cutover) belum dikerjakan sesuai instruksi** — modul Distribusi Artikel/Buku + Papan Manuskrip lama masih hidup berdampingan. Termasuk di dalamnya: redirect route lama, penghapusan controller/view/test lama, remap `STAGE_HANDLER`, dan pembersihan permission `distribution.*`/`manuscript.*`.
2. **Dua pintasan level buku wireframe 3B** ("Terapkan 1 pelaksana ke semua bab", "Tambah/ubah struktur bab") — kenyamanan, bukan syarat alur.
3. **`user_profiles.bidang` belum punya layar pengisian.** Sampai diisi, admin tidak terbatas bidang (lihat catatan Task 4). Kalau scoping per bidang mau benar-benar berlaku, perlu field di Manajemen User atau Profil.
4. **Verifikasi browser end-to-end** dengan 4 akun uji belum dilakukan (tidak bisa headless di lingkungan ini) — data dev sudah dimigrasi & permission sudah di-seed, jadi tinggal dibuka.
