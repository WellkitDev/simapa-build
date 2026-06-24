# Spec — Report Harian & Bulanan Karyawan (Task Management Phase 2)

- **Tanggal:** 2026-06-24
- **Branch:** `task-reports`
- **Prinsip inti:** Report = **rekap otomatis** dari data tugas yang sudah ada (`tb_tasks`), BUKAN sistem input paralel. Hanya catatan harian + lampiran yang ditambah manual. Nol input ganda.
- **Scope:** (1) Report Harian = rekap aktivitas tugas per tanggal + catatan + **alur Kirim/Submit** + **lampiran file (Dropzone → Google Drive)**; (2) Report Bulanan = agregasi read-only; (3) Pemantauan Report untuk manager (siapa sudah/belum kirim).
- **Di luar scope (sengaja):** snapshot beku saat submit, approve/reject report oleh atasan, export PDF, notifikasi saat submit, edit/re-open report yang sudah submitted, report mingguan.

> **Syarat UI/UX profesional** mengikuti `template-web` (NobleUI BS5): Dropzone bergaya template, tabel pakai **DataTables**. Lihat [[ui-conventions]] (jangan commit folder `template-web`).

---

## 1. Latar Belakang

Phase 1 (Task Management) sudah menyimpan di `tb_tasks`: `created_at` (kapan tugas dibuat/ditugaskan), `completed_at` (kapan diselesaikan), `status`, `priority`, `due_date`, `user_id`, `created_by`. Itu cukup untuk merekap "apa yang dikerjakan karyawan pada suatu hari" tanpa menyimpan ulang. Project juga sudah punya `GoogleDriveService` (`uploadFile()`→`{id,name,url}`, `deleteFile()`, `getOrCreateFolderByPath()`) dan plugin **Dropzone** (`assets/plugins/dropzone/dropzone.min.{js,css}`) — semua di-reuse. Tanpa dependency baru.

## 2. Tujuan & Kriteria Sukses

1. Karyawan membuka Report Harian (default hari ini) → melihat **rekap otomatis** tugas hari itu (selesai / dibuat / sedang dikerjakan) tanpa mengisi apa pun.
2. Karyawan menambah **catatan harian** (opsional) + **lampiran file** (drag-drop, banyak file, progress bar, kompres gambar) yang tersimpan di Google Drive.
3. Karyawan **Kirim Report** → terkunci; atasan tahu siapa sudah/belum kirim.
4. Report Bulanan menampilkan agregasi (total selesai, tepat waktu vs telat, ringkasan per hari).
5. Karyawan tidak bisa melihat/mengubah report orang lain (403); manager/superadmin bisa melihat semua + Pemantauan Report.
6. Semua perilaku tertutup test; suite tetap hijau.

---

## 3. Data Model (2 tabel baru)

### 3.1 `tb_daily_reports`
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users, cascadeOnDelete | pemilik report |
| `report_date` | date | tanggal report |
| `note` | text, nullable | catatan harian manual |
| `status` | string(16), default `draft` | `draft` / `submitted` |
| `submitted_at` | timestamp, nullable | di-set saat Kirim |
| timestamps | | |

**Unik** `(user_id, report_date)`.

### 3.2 `tb_daily_report_files`
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `daily_report_id` | FK → tb_daily_reports, cascadeOnDelete | |
| `drive_file_id` | string | id file di Google Drive |
| `name` | string | nama file asli |
| `url` | string(1024) | link publik Drive |
| `mime` | string, nullable | |
| `size` | unsignedBigInteger, nullable | byte |
| `uploaded_by` | FK users, nullable nullOnDelete | |
| timestamps | | |

Model `App\Models\DailyReport` (`$table='tb_daily_reports'`): fillable user_id/report_date/note/status/submitted_at; casts report_date→date, submitted_at→datetime; relasi `user()`, `files()` hasMany; helper `isSubmitted()`. Model `App\Models\DailyReportFile` (`$table='tb_daily_report_files'`): fillable semua kolom; relasi `report()`.

## 4. `DailyReportService` (`app/Services/DailyReportService.php`)

- **`recapFor(User $user, \Illuminate\Support\Carbon $date): array`** → dari `tb_tasks` (scope `forUser`):
  - `selesai` = tugas `whereDate('completed_at', $date)` (fakta historis, akurat tanggal mana pun).
  - `dibuat` = tugas `whereDate('created_at', $date)`.
  - `dikerjakan` = HANYA bila `$date` = hari ini → tugas `status = in_progress` saat ini (status historis tidak dilog; untuk tanggal lampau key ini kosong).
  - Mengembalikan tiap bucket (Collection) + `counts`.
- **`monthlyRecap(User $user, int $year, int $month): array`** → KPI: `selesai` (completed_at dalam bulan), `tepat_waktu` (di antara yang ber-`due_date`, `DATE(completed_at) ≤ due_date`), `telat` (sisanya yang ber-due_date), `on_time_rate` (tepat ÷ ber-due_date × 100, `null` bila tak ada); `per_hari` = peta tanggal→{selesai_count, submitted bool, note snippet}; `dilaporkan` = jumlah hari ber-status submitted. Realisasi efisien (hindari N+1).
- **`submissionsForDate(\Illuminate\Support\Carbon $date): \Illuminate\Support\Collection`** → satu baris per user: `id`, `name`, `submitted` (ada baris submitted utk tanggal itu?), `selesai` (jml tugas selesai tanggal itu). Untuk Pemantauan.
- **`getOrCreateReport(User $user, \Illuminate\Support\Carbon $date): DailyReport`** → `firstOrCreate(['user_id','report_date'])`. Dipakai oleh endpoint note/submit/upload agar tak membuat baris kosong hanya karena membuka halaman.

## 5. Controller & Route — `App\Http\Controllers\Pages\DailyReportController`

Semua di grup `auth`. Kepemilikan ditegakkan di controller.

| Route | Nama | Aksi |
|---|---|---|
| `GET /reports/daily` | `report.daily` | rekap + catatan + lampiran (param `date`, manager: `user_id`) |
| `POST /reports/daily/note` | `report.note` | simpan catatan (pemilik, hanya saat draft) |
| `POST /reports/daily/submit` | `report.submit` | kirim report (pemilik) → submitted |
| `POST /reports/daily/files` | `report.files.store` | upload 1 file → Drive (pemilik, draft) → JSON |
| `DELETE /reports/daily/files/{id}` | `report.files.destroy` | hapus file dari Drive + baris (pemilik, draft) → JSON |
| `GET /reports/monthly` | `report.monthly` | agregasi (param `month`, manager: `user_id`) |
| `GET /reports/submissions` | `report.submissions` | manager/superadmin: siapa sudah/belum kirim (param `date`) |

`report.submissions` di grup `role:manager|superadmin`. Route statis (`reports/daily`, `reports/monthly`, `reports/submissions`, `reports/daily/...`) didefinisikan sebelum rute ber-`{id}`.

**Kepemilikan:** helper `ownerOrManager(Carbon $date, ?int $userId)` mengembalikan user yang dilihat — manager boleh `?user_id`, selain itu paksa `Auth::user()`. Mutasi (note/submit/upload/delete) **selalu** untuk `Auth::user()` sendiri (atasan tidak menulis report orang). Upload/note/delete menolak bila report sudah `submitted` (abort 422). Delete file mengecek file milik report milik Auth user.

## 6. Lampiran File (Dropzone → Google Drive)

- Di `reports/daily.blade.php`, area **Dropzone** (drag-drop): `maxFiles: 10`, `maxFilesize: 10` (MB), `acceptedFiles` gambar+pdf+doc/docx/xls/xlsx, `addRemoveLinks: true`, **progress bar bawaan Dropzone**, **kompres gambar** via `resizeWidth: 1600, resizeQuality: 0.8` (file non-gambar diupload apa adanya). `url` = `report.files.store`, kirim `date` + CSRF.
- **Upload**: `report.files.store` validasi `file` (mimes images,pdf,doc,docx,xls,xlsx; max 10240 KB) + `date`. `getOrCreateReport(Auth::user(), date)` (tolak bila submitted). `GoogleDriveService->uploadFile($file, getOrCreateFolderByPath("SiMAPA/Reports/{userId}/{Y-m}"))` → simpan baris `tb_daily_report_files` → balas JSON `{id, name, url}` (Dropzone simpan id utk hapus). Bila Drive gagal (null) → 500 JSON `{message}`.
- **Init dengan file existing**: halaman me-render daftar file report (id/name/url) → JS menambahkannya sebagai Dropzone "mock file" (thumbnail + tombol hapus).
- **Hapus**: Dropzone `removedfile` → `DELETE report.files.destroy/{id}` → `deleteFile(drive_file_id)` + hapus baris → JSON.
- **Terkunci setelah submit**: Dropzone disembunyikan/dinonaktifkan saat `submitted`; endpoint upload/delete menolak (422). Atasan melihat lampiran sebagai daftar link (read-only).

## 7. Tampilan

- **`reports/daily.blade.php`** — pemilih tanggal (flatpickr) + tombol Sebelumnya/Berikutnya; 3 kartu rekap (Selesai / Dibuat / Sedang dikerjakan) berisi tabel/daftar tugas (judul, prioritas, waktu); textarea **Catatan Harian** (read-only bila submitted); area **Dropzone**; tombol **Kirim Report** (atau badge "Terkirim {jam}" bila submitted). Header menampilkan nama pemilik (untuk manager yang membuka report orang lain).
- **`reports/monthly.blade.php`** — pemilih bulan; KPI cards (Selesai, Tepat waktu, Telat, On-time %, Hari dilaporkan); **DataTable per hari** (Tanggal, Selesai, Submit?, Catatan snippet, link "Buka"). 
- **`reports/submissions.blade.php`** (manager) — pemilih tanggal; KPI ringkas (jml sudah/belum kirim); **DataTable** (Karyawan, Sudah Kirim?, Jml Selesai, aksi "Buka report").

## 8. Hak Akses (ringkas)

- `report.daily` / `report.monthly`: semua role; data ter-scope ke `Auth::user()`; `?user_id=` hanya dihormati untuk manager/superadmin.
- `report.note` / `report.submit` / `report.files.store` / `report.files.destroy`: **pemilik saja** (selalu `Auth::user()`), ditolak saat submitted.
- `report.submissions`: manager/superadmin (lainnya 403).

## 9. Komponen yang Dibuat / Disentuh

- **Baru:** migrasi `tb_daily_reports` + `tb_daily_report_files`; `app/Models/DailyReport.php`, `DailyReportFile.php`; `app/Services/DailyReportService.php`; `app/Http/Controllers/Pages/DailyReportController.php`; `resources/views/reports/{daily,monthly,submissions}.blade.php`.
- **Diubah:** `routes/web.php` (grup route reports), `resources/views/layouts/sidebar.blade.php` (grup "Report": Harian / Bulanan / + Pemantauan utk manager).
- Reuse: `tb_tasks`, `GoogleDriveService`, Dropzone, flatpickr, DataTables.

## 10. Rencana Test

- **Unit `DailyReportServiceTest`**: `recapFor` bucket benar (selesai by completed_at date, dibuat by created_at date, ter-scope user; `dikerjakan` hanya untuk hari ini); `monthlyRecap` agregasi (selesai/tepat-waktu/telat/on_time_rate, tugas tanpa due_date dikecualikan dari rate); `submissionsForDate` (submitted vs belum, jml selesai); `getOrCreateReport` idempotent.
- **Feature `DailyReportControllerTest`** (mock `GoogleDriveService`): daily view 200 (self), manager `?user_id` melihat report orang lain, non-manager `?user_id` tetap data sendiri; `saveNote` membuat/meng-update catatan; `submit` mengunci (status submitted, note/upload berikutnya 422); `report.files.store` (UploadedFile::fake, mock `uploadFile` → array) membuat baris file + JSON; tidak bisa upload saat submitted; `report.files.destroy` (mock `deleteFile`) menghapus baris; non-pemilik tak bisa hapus file orang lain (403); `report.submissions` manager 200 / non-manager 403.

Suite pakai DB test via `.env.testing` (`RefreshDatabase`). **Dev/prod: `php artisan migrate`** untuk 2 tabel ini (lihat [[migrate-dev-db-after-new-migration]]).

## 11. Asumsi & Risiko

- Rekap **live** dari `tb_tasks` (anchor `report_date`); angka historis (completed_at/created_at) stabil. Risiko kecil: menghapus tugas lama mengubah rekap masa lalu — diterima untuk v1 (bukan snapshot).
- `dikerjakan` hanya bermakna untuk hari ini (status historis tak dilog) — sengaja dibatasi agar tidak menyesatkan.
- Upload ke Google Drive memakai service & kredensial yang sudah ada; kegagalan Drive ditangani (JSON 500, file tidak tercatat). Folder dibuat lewat `getOrCreateFolderByPath`.
- Kompres hanya untuk gambar (Dropzone client-side resize); file dokumen apa adanya.
- Test memock `GoogleDriveService` (tak menyentuh Drive asli) — konsisten dgn test lain.
- Kepemilikan ditegakkan di controller (manager bypass untuk **view** saja; mutasi selalu milik sendiri).
