# Spec — Cek Kelengkapan Data (Dokumen ISBN & HKI)

- **Tanggal:** 2026-07-03
- **Branch:** `book-isbn` (lanjutan epik ISBN, sebelum merge)
- **Scope:** Kartu **"Cek Kelengkapan Data"** di detail judul buku: checklist dokumen yang diperlukan untuk **ISBN (penerbit)** & **HKI (hak cipta)**, dari **template global** yang di-CRUD superadmin. Admin menandai status tiap item (Ada/Belum/Tidak perlu) + unggah file (Google Drive) + catatan, lalu **Submit** (catat saja). Progress per kategori ditampilkan.
- **Di luar scope (sengaja):** notifikasi/verifikasi submit (submit hanya mencatat); alur pendaftaran HKI/ISBN otomatis; template per-buku (template bersifat global).

> Melengkapi kartu **Registrasi ISBN** (`tb_book_isbns`) di `titles/show.blade.php`. Fitur ini menambah checklist dokumen (persiapan berkas) yang independen dari registrasi nomor ISBN.

---

## 1. Tujuan & Kriteria Sukses

1. superadmin dapat mengelola (tambah/edit/hapus/urut) item template checklist, terkelompok **penerbit** & **hki**.
2. Template ter-seed dengan item default: **Penerbit** — Naskah Lengkap (Final Draft), Surat Pernyataan Keaslian Karya, Kelengkapan Naskah Awal, Sinopsis/Ringkasan Buku, Data Penulis (NIK+biodata). **HKI** — Surat Pernyataan Kepemilikan, Contoh Ciptaan (PDF), Identitas Pemohon (KTP/Paspor), Surat Pengalihan Hak.
3. admin/superadmin dapat menandai tiap item per buku: `status` (ada/belum/tidak_perlu) + unggah file (Google Drive) + catatan, dan **Submit** (checklist buku → `diajukan` + tanggal/oleh).
4. Kartu menampilkan item terkelompok + progress per kategori (mis. "3/5 ada"), file tertaut bila ada, badge status submit.
5. marketing & manager & production **tak bisa** menandai/submit (403) maupun CRUD template; production/manager boleh **melihat**.
6. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model (3 tabel)

**`tb_doc_requirements`** (template global) — migrasi `2026_07_03_000004`:
```php
$table->id();
$table->string('category');          // penerbit | hki
$table->string('label');
$table->text('description')->nullable();
$table->unsignedInteger('position')->default(0);
$table->boolean('active')->default(true);
$table->timestamps();
```
Seed 9 item default (lihat §1.2) di `up()` via `DB::table('tb_doc_requirements')->insert([...])` (position urut, active true).

**`tb_title_doc_marks`** (penanda per buku×item) — migrasi `2026_07_03_000005`:
```php
$table->id();
$table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
$table->foreignId('doc_requirement_id')->constrained('tb_doc_requirements')->cascadeOnDelete();
$table->string('status')->default('belum');   // ada | belum | tidak_perlu
$table->string('file_url')->nullable();
$table->string('file_name')->nullable();
$table->text('catatan')->nullable();
$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
$table->unique(['title_id', 'doc_requirement_id']);
```

**`tb_title_doc_checklists`** (header submit per buku) — migrasi `2026_07_03_000006`:
```php
$table->id();
$table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
$table->string('status')->default('draft');   // draft | diajukan
$table->timestamp('submitted_at')->nullable();
$table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
```

**Models:**
- `DocRequirement` (`tb_doc_requirements`): fillable category/label/description/position/active; casts active bool; `const CATEGORIES = ['penerbit'=>'Dokumen Penerbit (ISBN)', 'hki'=>'Dokumen HKI (Hak Cipta)']`; scope `active()`; ordered by position. Relasi `marks()` hasMany TitleDocMark.
- `TitleDocMark` (`tb_title_doc_marks`): fillable title_id/doc_requirement_id/status/file_url/file_name/catatan/updated_by; `const STATUSES = ['ada'=>'Ada','belum'=>'Belum','tidak_perlu'=>'Tidak perlu']`; `statusLabel()`; relasi `requirement()`, `title()`.
- `TitleDocChecklist` (`tb_title_doc_checklists`): fillable title_id/status/submitted_at/submitted_by; casts submitted_at datetime; relasi `title()`, `submitter()`.
- `Title`: `docMarks()` hasMany TitleDocMark; `docChecklist()` hasOne TitleDocChecklist.

## 3. Logika — `DocChecklistService`

Inject `GoogleDriveService $drive`.

- **`saveMarks(Title $title, array $items, User $actor): void`** — `$items` = list `['requirement_id'=>int, 'status'=>string, 'catatan'=>?string, 'file'=>?UploadedFile]`. Untuk tiap item: abaikan bila `requirement_id` bukan requirement aktif. `updateOrCreate(['title_id'=>$title->id, 'doc_requirement_id'=>$rid], [...])`: set `status` (validasi in STATUSES; else 'belum'), `catatan`, `updated_by`. Bila ada `file` → `$url = $this->drive->uploadFile($file, null, false)['url'] ?? null` + `file_name = $file->getClientOriginalName()`; bila tak ada file baru, **pertahankan** file_url/file_name lama (jangan ditimpa null).
- **`submit(Title $title, User $actor): TitleDocChecklist`** — `updateOrCreate(['title_id'=>$title->id], ['status'=>'diajukan','submitted_at'=>now(),'submitted_by'=>$actor->id])`.
- **`progress(Title $title, string $category): array`** — `total` = jumlah `DocRequirement::active()->where('category',$category)->count()`; `done` = jumlah mark buku dgn status `ada` untuk requirement kategori itu. Return `['done'=>int,'total'=>int]`.

> `makePublic=false` untuk dokumen sensitif (KTP/surat). Upload di-mock saat test.

## 4. Rute & Kontroler

**`DocRequirementController`** (template, `role:superadmin`):
- `POST doc-requirements` `doc-req.store`; `PUT doc-requirements/{id}` `doc-req.update`; `DELETE doc-requirements/{id}` `doc-req.destroy` (whereNumber). Validasi `category in penerbit,hki`, `label required`, `description nullable`, `position nullable int`, `active boolean`. Redirect back + flash.

**`TitleDocCheckController`** (`role:superadmin|admin`), inject `DocChecklistService`:
- `PUT titles/{id}/doc-check` `title.doc.save` — `Title::findOrFail`; abort_unless jenis buku; kumpulkan `$items` dari `marks` input (`marks[{rid}][status|catatan]` + file `marks[{rid}][file]`); `service->saveMarks`; redirect `title.show` + flash.
- `POST titles/{id}/doc-check/submit` `title.doc.submit` — `service->submit`; redirect `title.show` + flash.

## 5. View

- **`titles/show.blade.php`** — kartu **"Cek Kelengkapan Data"** (bila `jenis=buku` & `canViewInfo`, setelah kartu Registrasi ISBN):
  - Header: judul + badge status submit (`Draft` / `Diajukan {tgl}`).
  - Per kategori (`penerbit`, `hki`): sub-judul + badge progress `{done}/{total} ada`; daftar item bernomor: label (deskripsi = `title=`/muted small) + **status** (badge read; bila `$canMarkDocs`, dalam form: `<select>`), **file** (link "buka" bila ada; input file bila mark), **catatan**.
  - Bila `$canMarkDocs`: seluruh item dalam satu `<form PUT title.doc.save enctype=multipart/form-data>` (nama `marks[{rid}][status]`, `marks[{rid}][catatan]`, `marks[{rid}][file]`), tombol **Simpan**; + form terpisah **Submit** (`POST title.doc.submit`).
  - Bila `$canManageDocReq` (superadmin): collapse **"Kelola Template Dokumen (berlaku semua buku)"** — daftar item tiap kategori dgn form edit/hapus per baris + form tambah item per kategori (`doc-req.*`).
- **`TitleController@show`** kirim: `$docRequirements` (grouped by category, active, ordered), `$title->docMarks` keyed by requirement_id, `$title->docChecklist`, `$docProgress` (['penerbit'=>[done,total],'hki'=>[...]] via service), `$canMarkDocs` (superadmin/admin), `$canManageDocReq` (superadmin). Eager-load `docMarks`, `docChecklist`.

> UI profesional: gunakan kartu Bootstrap + `dl`/tabel ringkas, badge status berwarna (ada=success, belum=secondary, tidak_perlu=light), progress badge, ikon file. Selaras gaya kartu Registrasi ISBN.

## 6. Rencana Test

- **Unit `DocChecklistServiceTest`**: `progress` menghitung done/total per kategori benar; `saveMarks` upsert status+catatan; `saveMarks` dgn `UploadedFile` (drive mock → url) menyimpan file_url/file_name; `saveMarks` tanpa file baru mempertahankan file_url lama; `submit` set status diajukan + submitted_by.
- **Feature `DocChecklistTest`** (`GoogleDriveService` di-mock):
  - `superadmin_crud_requirement`: store/update/destroy item template.
  - `admin_saves_marks_and_uploads`: admin `PUT title.doc.save` (status ada + `UploadedFile::fake()`) → mark tersimpan + file_url terisi.
  - `admin_submits_checklist`: `POST title.doc.submit` → header `diajukan` + submitted_by.
  - `manager_and_marketing_cannot_mark`: manager & marketing `PUT title.doc.save` → 403.
  - `non_superadmin_cannot_crud_template`: admin `POST doc-req.store` → 403.
  - `card_renders_grouped_items_with_progress`: `GET title.show` (buku) → `assertSee('Cek Kelengkapan Data')` + label kategori + salah satu item default.
- **Regresi**: suite tetap hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` untuk 3 tabel (+ seed). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** 3 migrasi (`000004`/`000005`/`000006`, seed di 000004); model `DocRequirement`, `TitleDocMark`, `TitleDocChecklist`; `DocChecklistService`; `DocRequirementController`, `TitleDocCheckController`; test `DocChecklistServiceTest` + `DocChecklistTest`.
- **Diubah:** `Title` (+docMarks/docChecklist); `TitleController@show` (eager-load + variabel view); `titles/show.blade.php` (kartu); `routes/web.php` (rute `doc-req.*`, `title.doc.*`).
- **Tak diubah:** kartu Registrasi ISBN & sinkron manuskrip (fitur sebelumnya).

## 8. Asumsi & Risiko

- Template global (bukan per-buku); mengedit dari kartu buku memengaruhi semua buku (diberi label jelas).
- Penanda dibuat lazily (updateOrCreate saat Simpan); item tanpa penanda dianggap `belum`.
- Upload sensitif → `makePublic=false`; satu file per item (unggah baru menimpa lama).
- Submit hanya mencatat (`diajukan`); admin masih bisa memperbarui penanda setelah submit (v1).
- Kartu tampil untuk semua buku (persiapan dokumen bisa kapan pun), tak digating tahap manuskrip.
