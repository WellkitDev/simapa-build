# Spec — Direktori Jurnal Fase B: Tracking Submit Artikel

- **Tanggal:** 2026-07-02
- **Branch:** `journal-submissions`
- **Scope (B):** Entitas **JournalSubmission** yang mencatat artikel yang di-submit/terbit ke suatu jurnal (tgl submit/terbit, akun+password OJS terenkripsi, file LoA & bukti bayar di Google Drive, link publish, status). Popup modal "Tambah/Edit/Detail" + daftar submission di **halaman detail jurnal**. Kelola oleh superadmin/manager/admin; staf lain melihat daftar read-only.
- **Di luar scope (sengaja):** auto-sync ke pipeline manuskrip (`TitleProgress`); direktori ISBN/HKI; (**C**) panel publikasi judul memilih jurnal dari `tb_journals`.

> Melanjutkan Direktori Jurnal (A). Reuse `GoogleDriveService::uploadFile($file, $folderId, true)` (pola sudah dipakai `ManagementUserController`/`OrderBookController`) untuk LoA & bukti bayar. Submission menaut `Title` artikel sebagai **referensi**, tidak menggerakkan tahap manuskrip (submission = catatan hilir mandiri).

---

## 1. Tujuan & Kriteria Sukses

1. Pengelola (superadmin/manager/admin) menambah/edit/hapus catatan submission artikel pada detail jurnal via popup modal.
2. Password OJS **terenkripsi at-rest** (`encrypted` cast), ditampilkan kembali hanya di modal edit/detail untuk pengelola.
3. LoA & bukti pembayaran ter-upload ke Google Drive; url tersimpan; file lama dipertahankan bila tak ada unggahan baru.
4. Detail jurnal menampilkan daftar submission (Judul · Submit · Terbit · Status · Aksi); staf non-pengelola melihat read-only tanpa tombol kelola.
5. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model — `tb_journal_submissions`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `journal_id` | FK → tb_journals, cascadeOnDelete | |
| `title_id` | FK → tb_titles, nullable nullOnDelete | judul artikel (Title `jenis=artikel` disetujui) |
| `tgl_submit` | date, nullable | |
| `tgl_terbit` | date, nullable | tgl publish/terbit |
| `ojs_akun` | string, nullable | username OJS |
| `ojs_password` | text, nullable | **encrypted** (Laravel `encrypted` cast) |
| `loa_url` | string, nullable | LoA di Google Drive |
| `bukti_bayar_url` | string, nullable | bukti bayar di Drive |
| `link_publish` | string, nullable | link artikel terbit |
| `status` | string(16), default `submitted` | `submitted` / `loa` / `published` |
| `catatan` | text, nullable | |
| `created_by` | FK → users, nullable nullOnDelete | |
| timestamps | | |

Model `App\Models\JournalSubmission` (`$table='tb_journal_submissions'`): fillable semua kolom; `$casts`: `ojs_password='encrypted'`, `tgl_submit='date'`, `tgl_terbit='date'`; const `STATUSES = ['submitted','loa','published']` + `statusLabel()` (Submitted/LoA/Published); relasi `journal()` belongsTo(Journal), `title()` belongsTo(Title), `creator()` belongsTo(User). `Journal::submissions()` hasMany(JournalSubmission) (urut terbaru).

## 3. Controller & Route — `JournalSubmissionController`

| Route | Nama | Aksi | Akses |
|---|---|---|---|
| `POST /journals/{journal}/submissions` | `journal.submission.store` | tambah | superadmin/manager/admin |
| `PUT /journals/submissions/{id}` | `journal.submission.update` | edit | superadmin/manager/admin |
| `DELETE /journals/submissions/{id}` | `journal.submission.destroy` | hapus | superadmin/manager/admin |

Grup `role:superadmin|manager|admin`. Controller inject `GoogleDriveService`.
- **store**(`Journal $journal` via route model binding atau `{journal}` id): validasi (title_id nullable exists tb_titles; tgl_submit/tgl_terbit nullable date; ojs_akun/link_publish/catatan nullable string; ojs_password nullable string; status in STATUSES; `loa`,`bukti_bayar` nullable file mimes pdf/jpg/png max ~5MB). Upload file → `loa_url`/`bukti_bayar_url`. Simpan (created_by=Auth). Redirect ke `journal.show` + flash.
- **update**(`$id`): sama; hanya set `ojs_password` bila diisi (kosong = pertahankan); file baru → ganti url, else pertahankan.
- **destroy**(`$id`): hapus record. (File Drive dibiarkan — hapus Drive di luar scope.)
- Password/LoA/bukti hanya dirender untuk pengelola (view meng-guard).

`JournalController@show` (diubah): eager-load `submissions.title`; kirim daftar `Title` `jenis=artikel` `disetujui` (`$articles`) untuk select modal + `canManage`.

## 4. View — Detail Jurnal (ganti placeholder "Artikel di Jurnal Ini")

- **Tabel submission**: Judul (title?->title atau '—') · Submit (tgl) · Terbit (tgl) · Status (badge submitted=secondary/loa=info/published=success) · Aksi (Detail/Edit/Hapus) bila `canManage`.
- Tombol **"+ Tambah Artikel Submit"** (canManage) → modal **create**: select2 judul artikel (`$articles`), tgl_submit, tgl_terbit, ojs_akun, ojs_password, file LoA, file bukti bayar, link_publish, status, catatan. `enctype=multipart/form-data`, submit biasa → redirect+reload.
- Modal **Edit** (satu modal dipopulasi via JS dari `data-*` tombol Edit; password kosong = tak diubah; file baru opsional). Modal **Detail** (read-only; OJS akun+password, link LoA/bukti/publish) hanya untuk pengelola. **Hapus** via form `data-confirm` (SweetAlert2 global).
- Modal memuat plugin select2 (judul) + flatpickr (tanggal) bila tersedia; fallback `<input type="date">`.

## 5. Rencana Test

- **Feature `JournalSubmissionTest`**:
  - manager `POST journal.submission.store` (title_id, tgl_submit, status=submitted, ojs_password) → tersimpan; `ojs_password` **tak** tersimpan plaintext (kolom mentah ≠ nilai; `$sub->ojs_password` decrypt = nilai asli).
  - LoA file (UploadedFile fake) + `GoogleDriveService` di-mock mengembalikan url → `loa_url` terisi.
  - update mengubah status→published + tgl_terbit; password kosong tetap; destroy menghapus.
  - marketing `GET journal.show` (lihat daftar) OK, tapi `POST store` → 403.
- **Smoke**: detail jurnal render daftar + tombol tambah untuk pengelola, tanpa tombol untuk marketing.
- Suite via DB test (`.env.testing`); `GoogleDriveService` di-mock; `Storage`/`UploadedFile::fake()`. **Dev/prod: `php artisan migrate`** untuk `tb_journal_submissions`.

## 6. Komponen

- **Baru:** migrasi `create_tb_journal_submissions`; `app/Models/JournalSubmission.php`; `app/Http/Controllers/Pages/JournalSubmissionController.php`; test `JournalSubmissionTest`.
- **Diubah:** `app/Models/Journal.php` (relasi `submissions()`); `app/Http/Controllers/Pages/JournalController.php` (`show` load submissions + `$articles`); `resources/views/journals/show.blade.php` (tabel + modal create/edit/detail); `routes/web.php` (3 route submission).

## 7. Asumsi & Risiko

- Password OJS `encrypted` cast → aman dari dump DB langsung; masih dapat didekripsi dgn APP_KEY (dan ditampilkan ke pengelola) — sesuai kebutuhan tim. Jangan pernah render password ke non-pengelola.
- Submission menaut Title artikel sebagai referensi; **tak** menggerakkan `TitleProgress` (auto-sync = fase lanjutan bila diperlukan).
- File LoA/bukti via `GoogleDriveService::uploadFile`; kegagalan upload → tangani (flash error, jangan gagalkan seluruh simpan bila memungkinkan). Hapus file di Drive saat destroy di luar scope.
- Modal edit/detail dipopulasi via `data-*` di sisi klien; password TIDAK dimuat ke `data-*` (hindari kebocoran di DOM) — modal edit membiarkan field password kosong (isi hanya bila mengganti); modal detail menampilkan password lewat render server-side yang di-guard `canManage`.
- Reuse pola Drive & DataTables/SweetAlert2 yang sudah ada.
