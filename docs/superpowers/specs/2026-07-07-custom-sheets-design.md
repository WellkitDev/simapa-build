# Spec — Lembar Kerja (spreadsheet dalam app)

- **Tanggal:** 2026-07-07
- **Branch:** `custom-sheets`
- **Scope:** Fitur **spreadsheet** ala Excel dalam app: user bikin *lembar*, edit langsung di sel (inline, autosave), bisa **dibagikan** (semua / role tertentu). Untuk semua user login. Library grid **jSpreadsheet CE** (MIT) via CDN. Export CSV (bawaan library).
- **Di luar scope (v1):** formula lintas-sel kompleks, real-time collab lock (last-write-wins), import Excel, riwayat versi.
- **Keputusan user:** grid sel bebas (library) · inline edit · bisa dibagikan (semua/role tertentu).

> Tak ada library grid ter-bundle → pakai **jSpreadsheet CE** dari CDN (preseden: Alpine dimuat via CDN di master layout). Butuh internet; bisa self-host kelak.

## 1. Data Model — migrasi `2026_07_07_000001_create_custom_sheets_table`
```php
Schema::create('custom_sheets', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->string('visibility')->default('private');   // private | shared
    $table->json('shared_roles')->nullable();           // array nama role; null/kosong = semua
    $table->json('columns')->nullable();                // definisi kolom jSpreadsheet
    $table->json('data')->nullable();                   // matriks sel (2D array)
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();
});
```
Satu baris = satu lembar (grid diserialkan JSON). Tak ada tabel per-sel.

**Model `CustomSheet`**: fillable name/owner_id/visibility/shared_roles/columns/data/updated_by; casts shared_roles/columns/data => array; `const VISIBILITIES = ['private'=>'Pribadi','shared'=>'Dibagikan']`; `owner()` belongsTo User; helper:
- `canView(User $u): bool` = owner_id==u atau (visibility=='shared' dan (shared_roles kosong atau `$u->getRoleNames()->intersect($this->shared_roles)->isNotEmpty()`)).
- `canEdit(User $u): bool` = `canView($u)` (shared = kolaboratif).
- `isOwner(User $u): bool` = owner_id==u->id.

## 2. Kontroler & Rute — `CustomSheetController` (auth; TANPA role gate — semua user)
- `index()`: `CustomSheet::with('owner')->where('owner_id',$uid)->orWhere('visibility','shared')->latest()->get()->filter(fn($s)=>$s->canView($u))`. Pisah `mine` (owner) vs `shared`. View `sheets.index`.
- `store(Request)`: `name required string max:150`. Buat `['name','owner_id'=>uid,'visibility'=>'private','columns'=>[], 'data'=>array_fill(0,15,array_fill(0,6,'')), 'updated_by'=>uid]`. Redirect `sheet.show`.
- `show($id)`: findOrFail; `abort_unless($sheet->canView(u),403)`. Kirim `sheet`, `canEdit`. View `sheets.show`.
- `update(Request,$id)`: `abort_unless($sheet->isOwner(u),403)`. Validasi `name required`, `visibility in:private,shared`, `shared_roles nullable array`, `shared_roles.* string`. Update. Redirect back.
- `save(Request,$id)`: `abort_unless($sheet->canEdit(u),403)`. Validasi `data nullable array`, `columns nullable array`. `$sheet->update(['data'=>$data,'columns'=>$columns,'updated_by'=>uid])`. Return `response()->json(['ok'=>true,'saved_at'=>now()->format('H:i:s')])`.
- `destroy($id)`: `abort_unless(isOwner,403)`; delete; redirect `sheet.index`.

**Rute** (grup `auth`, tanpa role):
```php
Route::get('sheets', [CustomSheetController::class,'index'])->name('sheet.index');
Route::post('sheets', [CustomSheetController::class,'store'])->name('sheet.store');
Route::get('sheets/{id}', [CustomSheetController::class,'show'])->name('sheet.show')->whereNumber('id');
Route::put('sheets/{id}', [CustomSheetController::class,'update'])->name('sheet.update')->whereNumber('id');
Route::post('sheets/{id}/save', [CustomSheetController::class,'save'])->name('sheet.save')->whereNumber('id');
Route::delete('sheets/{id}', [CustomSheetController::class,'destroy'])->name('sheet.destroy')->whereNumber('id');
```

## 3. Views
**`sheets/index.blade.php`**: header + tombol **+ Lembar Baru** (collapse form: nama → POST `sheet.store`). Dua bagian: **Lembar Saya** & **Dibagikan ke Saya** — tabel (DataTables) Nama · Pemilik · Visibilitas · Diperbarui · Aksi (**Buka** → `sheet.show`; pemilik: **Hapus**).

**`sheets/show.blade.php`**:
- Toolbar: judul lembar · (pemilik) form setelan visibilitas (private/shared + centang role) · tombol **Export CSV** (`instance.download()`) · **Kembali**.
- `<div id="spreadsheet"></div>` + muat jSpreadsheet CE + jSuites (CSS+JS CDN, `@push('style')`/`@push('custom-scripts')`).
- Init: `jspreadsheet(el, { data: initData, columns: initCols||undefined, minDimensions:[6,15], tableOverflow:true, editable:canEdit, onchange:scheduleSave, oninsertrow/ondeleterow/oninsertcolumn/ondeletecolumn/onsort:scheduleSave })`. **Autosave** debounce 800ms → `fetch(sheet.save, {POST, X-CSRF-TOKEN, body: JSON {data: instance.getData(), columns: instance.getConfig().columns||[]}})`. Indikator "Tersimpan HH:MM:SS". Bila `!canEdit` → grid read-only, tanpa save.

## 4. Sidebar
Kategori baru **"Alat"** (sebelum "Akun & Sistem"), item **"Lembar Kerja"** [ikon `grid`] → `sheet.index`, `nav_active('sheet.*')`. **Semua user login** (tanpa @role).

## 5. Testing — `CustomSheetTest` (backend; grid JS diverifikasi manual)
- `user_creates_and_opens_sheet`: POST `sheet.store` (name) → redirect; sheet owned by user; GET `sheet.index` see name.
- `private_sheet_hidden_from_others`: A bikin private; B GET index tak lihat; B GET show → 403.
- `shared_sheet_visible_and_editable`: A shared (roles kosong); B lihat di index + show 200 + POST save 200.
- `shared_roles_restrict`: A shared_roles=['manager']; marketing→403 show; manager→200.
- `save_persists_grid`: owner POST save {data:[['a','b']]} → `custom_sheets.data` terupdate.
- `only_owner_updates_or_deletes`: non-owner PUT update →403; DELETE →403.
- Regresi: suite hijau; `view:cache` bersih. `php artisan migrate` dev.

## 6. Komponen
- **Baru:** migrasi `2026_07_07_000001`; model `CustomSheet`; `CustomSheetController`; views `sheets/index.blade.php`, `sheets/show.blade.php`; test `CustomSheetTest`.
- **Diubah:** `routes/web.php` (+6 rute + import); `sidebar.blade.php` (+kategori Alat / Lembar Kerja).

## 7. Risiko
- Library dari CDN → butuh internet (self-host kelak bila perlu).
- Autosave last-write-wins pada lembar shared (v1).
- API jSpreadsheet CE v4 (getData/getConfig/download/onchange) — verifikasi grid manual di browser.
