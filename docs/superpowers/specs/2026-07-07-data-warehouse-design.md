# Spec — Gudang Data (link Google Sheets + upload Excel/CSV)

- **Tanggal:** 2026-07-07
- **Branch:** `data-warehouse`
- **Scope:** **Hapus** fitur "Lembar Kerja" (custom_sheets) → **ganti** dengan **Gudang Data**: repositori terpusat entri data, tiap entri = **link Google Sheets/eksternal** ATAU **file Excel/CSV yang di-upload**. Bisa dibagikan (private/shared+role). Semua user login.
- **Di luar scope:** parsing/preview isi Excel, edit isi sel, versi.
- **Keputusan user:** hapus & bangun ulang · berbagi private/shared+role · upload **Excel/CSV saja** (.xlsx/.xls/.csv).

## 0. Penghapusan Lembar Kerja
Hapus: `app/Models/CustomSheet.php`, `app/Http/Controllers/Pages/CustomSheetController.php`, `resources/views/sheets/index.blade.php`, `resources/views/sheets/show.blade.php`, `tests/Feature/CustomSheetTest.php`, `tests/Feature/CustomSheetModelTest.php`. Hapus 6 rute `sheet.*` + import di `routes/web.php`. Hapus blok sidebar "Alat / Lembar Kerja". Migrasi **drop** `2026_07_07_000002_drop_custom_sheets_table` (up: dropIfExists; down: recreate struktur lama utk reversibilitas).

## 1. Data Model — migrasi `2026_07_07_000003_create_data_assets_table`
```php
$table->id();
$table->string('name');
$table->text('description')->nullable();
$table->string('type');                 // link | file
$table->string('url', 1000)->nullable();
$table->string('file_path')->nullable();
$table->string('file_name')->nullable();
$table->unsignedBigInteger('file_size')->nullable();
$table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
$table->string('visibility')->default('private');   // private | shared
$table->json('shared_roles')->nullable();           // null/kosong = semua
$table->unsignedBigInteger('updated_by')->nullable();
$table->timestamps();
```
**Model `DataAsset`**: fillable semua kolom di atas; casts `shared_roles=>array`, `file_size=>integer`; `const VISIBILITIES=['private'=>'Pribadi','shared'=>'Dibagikan']`; `const TYPES=['link'=>'Link','file'=>'File']`; `owner()` belongsTo User; `isOwner(User)`; `canView(User)` = owner ATAU (visibility=='shared' dan (shared_roles kosong atau `getRoleNames()->intersect(shared_roles)->isNotEmpty()`)). (Edit/hapus = pemilik saja → pakai `isOwner`.)

## 2. Kontroler & Rute — `DataAssetController` (auth; TANPA role gate)
- `index()`: `DataAsset::with('owner')->where('owner_id',$uid)->orWhere('visibility','shared')->latest()->get()->filter(canView)`. View `data-assets.index`.
- `create()`: view `data-assets.create` (form, `$asset=null`).
- `store(Request)`: validasi `name required max:150`, `description nullable`, `type required in:link,file`, `url required_if:type,link|nullable|url|max:1000`, `file required_if:type,file|nullable|file|mimes:xlsx,xls,csv|max:10240`, `visibility required in:private,shared`, `shared_roles nullable array`. Bila type=file → `$path=$request->file('file')->store('data-assets')` (disk default/local), simpan file_name(original)/file_size/file_path; type=link → simpan url. owner_id/updated_by=uid; shared_roles = (visibility shared? array:null). Redirect `data.index` sukses.
- `edit($id)`: `abort_unless(isOwner,403)`; view `data-assets.edit`.
- `update(Request,$id)`: `abort_unless(isOwner,403)`; validasi sama (url/file optional saat edit — hanya diganti bila diisi). Bila file baru → hapus file lama + simpan baru. Update. Redirect back.
- `download($id)`: `abort_unless(canView,403)`; `abort_if(type!='file' || !file_path,404)`; `Storage::download($asset->file_path, $asset->file_name)`.
- `destroy($id)`: `abort_unless(isOwner,403)`; bila file → `Storage::delete(file_path)`; delete record; redirect `data.index`.

**Rute** (grup `auth`, tanpa role):
```php
Route::get('gudang', [DataAssetController::class,'index'])->name('data.index');
Route::get('gudang/tambah', [DataAssetController::class,'create'])->name('data.create');
Route::post('gudang', [DataAssetController::class,'store'])->name('data.store');
Route::get('gudang/{id}/edit', [DataAssetController::class,'edit'])->name('data.edit')->whereNumber('id');
Route::put('gudang/{id}', [DataAssetController::class,'update'])->name('data.update')->whereNumber('id');
Route::get('gudang/{id}/download', [DataAssetController::class,'download'])->name('data.download')->whereNumber('id');
Route::delete('gudang/{id}', [DataAssetController::class,'destroy'])->name('data.destroy')->whereNumber('id');
```

## 3. Views
**`data-assets/index.blade.php`** (DataTable): header + tombol **+ Tambah Data** (→ `data.create`). Kolom: Nama · Jenis (badge Link/File) · **Sumber** (link → `<a target=_blank>Buka ↗</a>`; file → `<a href=data.download>Unduh ⬇</a>` + ukuran) · Deskripsi · Pemilik · Visibilitas · Diperbarui · Aksi (pemilik: Edit, Hapus).

**`data-assets/create.blade.php` & `edit.blade.php`** (extends master; `@include('data-assets._form')`): partial **`_form.blade.php`** — Nama · Deskripsi · **Jenis** (radio Link/File; JS toggle tampilkan input URL atau input file) · Visibilitas (select) · Dibagikan ke role (checkbox `\Spatie\Permission\Models\Role::pluck('name')`; kosong=semua) · tombol Simpan. Saat edit: nilai terisi, url/file opsional (petunjuk "kosongkan bila tak ganti"), tampil file/link saat ini.

## 4. Sidebar
Kategori **"Alat"** (sebelum "Akun & Sistem") — item **"Gudang Data"** [ikon `database`] → `data.index`, `nav_active('data.*')`. **Semua user login**.

## 5. Testing — `DataAssetTest` (+ `DataAssetModelTest`)
- **Model**: canView owner/shared/role, isOwner (mirip CustomSheet).
- `create_link_asset`: POST store type=link, url → tersimpan; index see nama.
- `upload_excel_file` (`Storage::fake()`, `UploadedFile::fake()->create('data.xlsx',100,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')`): store type=file → file_path terisi, `Storage::assertExists`.
- `download_requires_view_and_file`: owner GET download → 200; non-viewer (private) → 403.
- `private_hidden_shared_visible`: A private tak terlihat B; A shared terlihat B (index + download).
- `only_owner_edits_deletes`: non-owner edit/update/destroy → 403; owner → sukses; destroy hapus file (`Storage::assertMissing`).
- `validation`: type=link tanpa url → error; type=file tanpa file → error.
- Regresi: suite hijau; `view:cache` bersih; `php artisan migrate` dev (drop custom_sheets + create data_assets).

## 6. Komponen
- **Hapus:** file Lembar Kerja (§0) + rute + menu.
- **Baru:** migrasi `2026_07_07_000002` (drop) & `2026_07_07_000003` (data_assets); model `DataAsset`; `DataAssetController`; views `data-assets/index|create|edit|_form.blade.php`; test `DataAssetModelTest`+`DataAssetTest`.
- **Diubah:** `routes/web.php`; `sidebar.blade.php`.

## 7. Risiko
- Upload disimpan di disk default (`storage/app/data-assets`) — unduh lewat rute ber-izin (bukan public). Pastikan `storage` dapat ditulis.
- Validasi `mimes:xlsx,xls,csv` — di test pakai fake dgn mime eksplisit.
