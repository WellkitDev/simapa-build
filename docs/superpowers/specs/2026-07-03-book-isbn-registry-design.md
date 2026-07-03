# Spec — Registri ISBN Buku (Book ISBN Registry)

- **Tanggal:** 2026-07-03
- **Branch:** `book-isbn`
- **Scope:** Registri **satu record ISBN per buku** (`tb_titles` `jenis=buku`) berisi lifecycle nomor (pendaftaran → ber-ISBN → cetak) + tanggal + penerbit. Dikelola via menu **"Direktori ISBN"** (worklist buku yang manuskripnya sudah mencapai tahap `isbn`) dan kartu **"Registrasi ISBN"** di panel detail judul. CRUD: superadmin/manager/admin/production; lihat: semua staf.
- **Di luar scope (sengaja):** Registri **HKI** (cycle berikutnya, pola sama); aturan papan manuskrip "kunci mundur butuh persetujuan / kunci final / retensi 30 hari" (cycle terpisah setelahnya); alokasi/stok blok ISBN; sinkronisasi otomatis dua-arah dgn tahap manuskrip.

> Melengkapi Fase 2 direktori. Panel Informasi Publikasi judul sudah menaut opsi jurnal ke `tb_journals` (epik jurnal C). ISBN unik per buku (bukan master pakai-ulang seperti jurnal), jadi dimodelkan sebagai registri per-judul dengan gate kelayakan berbasis tahap manuskrip.

---

## 1. Tujuan & Kriteria Sukses

1. Buku yang manuskripnya **sudah mencapai tahap `isbn`** muncul di Direktori ISBN; buku yang belum **tidak** muncul dan **tidak dapat** didaftarkan (ditegakkan server).
2. superadmin/manager/admin/production dapat membuat/mengubah satu registrasi ISBN per buku: status + 3 nomor (pendaftaran/ISBN/buku cetak) + penerbit + tanggal + catatan.
3. Tepat satu record per buku (`title_id` unik) — percobaan membuat record kedua ditolak.
4. Panel detail judul (buku) menampilkan registrasi ISBN + form kelola bila layak; catatan penjelas bila belum layak.
5. Marketing (dan non-pengelola) tak bisa mengubah (403); semua staf boleh melihat direktori.
6. Perilaku tertutup test; suite tetap hijau.

## 2. Kelayakan (Gate A) — `Title::isbnEligible()`

Tambah method di `App\Models\Title`:

```php
/** Buku yang manuskripnya sudah mencapai tahap 'isbn' (bottleneck ≥ index 'isbn'). */
public function isbnEligible(): bool
{
    if ($this->jenis !== 'buku') {
        return false;
    }
    $status = $this->manuscriptStatus(); // bottleneck stage atau null
    if ($status === null) {
        return false;
    }
    $stages   = TitleProgress::BOOK_STAGES;
    $reached  = array_search($status, $stages, true);
    $isbnIdx  = array_search('isbn', $stages, true);
    return $reached !== false && $reached >= $isbnIdx;
}
```

> `manuscriptStatus()` mengembalikan tahap **bottleneck** (paling awal antar order/bab). Buku "mencapai `isbn`" berarti seluruh order/bab sudah di `isbn`/`cetak`/`terbit`. Butuh relasi `orderDetails.titleProgress` ter-load (eager-load di controller).

## 3. Data Model

**Migrasi** `2026_07_03_000003_create_tb_book_isbns_table.php`:

```php
Schema::create('tb_book_isbns', function (Blueprint $table) {
    $table->id();
    $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
    $table->string('status')->default('pendaftaran'); // pendaftaran | ber_isbn | cetak
    $table->string('no_pendaftaran')->nullable();
    $table->string('no_isbn')->nullable();
    $table->string('no_buku_cetak')->nullable();
    $table->string('penerbit')->nullable();
    $table->date('tgl_daftar')->nullable();
    $table->date('tgl_isbn')->nullable();
    $table->date('tgl_terbit')->nullable();
    $table->text('catatan')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

**Model** `App\Models\BookIsbn`:
- `$fillable`: `title_id, status, no_pendaftaran, no_isbn, no_buku_cetak, penerbit, tgl_daftar, tgl_isbn, tgl_terbit, catatan, created_by`.
- `$casts`: `tgl_daftar, tgl_isbn, tgl_terbit` → `date`.
- `const STATUSES = ['pendaftaran' => 'Pendaftaran', 'ber_isbn' => 'Ber-ISBN', 'cetak' => 'Cetak/Terbit']`.
- `statusLabel(): string` → `self::STATUSES[$this->status] ?? $this->status`.
- Relasi `title()` belongsTo `Title`; `creator()` belongsTo `User` (`created_by`).

**`Title`**: relasi `bookIsbn()` `hasOne(BookIsbn::class)` + method `isbnEligible()` (§2).

## 4. Rute & Kontroler — `BookIsbnController`

Route (di dalam grup auth; middleware role pada mutasi):

```php
Route::get('management/isbn', [BookIsbnController::class, 'index'])->name('isbn.index'); // semua staf
Route::middleware('role:superadmin|manager|admin|production')->group(function () {
    Route::post('management/isbn', [BookIsbnController::class, 'store'])->name('isbn.store');
    Route::put('management/isbn/{id}', [BookIsbnController::class, 'update'])->name('isbn.update')->whereNumber('id');
    Route::delete('management/isbn/{id}', [BookIsbnController::class, 'destroy'])->name('isbn.destroy')->whereNumber('id');
});
```

- **`index()`** — ambil buku layak: `Title::where('jenis','buku')->with(['orderDetails.titleProgress','bookIsbn'])->get()->filter->isbnEligible()->values()`; kirim ke view direktori (DataTables). Tiap baris tampil `bookIsbn` (record atau null).
- **`store(Request)`** — validasi (`title_id` required exists, `status` in keys STATUSES, nomor/penerbit nullable string, tgl_* nullable date, catatan nullable string). Ambil `Title::findOrFail(title_id)`; **abort 403 bila `! $title->isbnEligible()`**; **abort 422/redirect-error bila `$title->bookIsbn` sudah ada** (satu per buku); set `created_by = auth id`; `BookIsbn::create($data)`; redirect `title.show` + flash.
- **`update(Request,$id)`** — `BookIsbn::findOrFail`; validasi sama (tanpa title_id/atau abaikan); update; redirect `title.show` + flash. (Kelayakan tak dicek ulang saat update — record sudah ada berarti pernah layak; buku tak "turun" tahap dalam alur normal.)
- **`destroy($id)`** — hapus; redirect back + flash.
- Pola date-clear: tanggal kosong `('' → null)` pakai `($data['x'] ?? '') ?: null` (hindari Carbon parse '' = hari ini — pelajaran dari panel publikasi).

> Edit UI **hanya** di panel judul (DRY). Direktori = worklist read + tombol "Kelola" → `route('title.show', $book->id)`.

## 5. View

- **`resources/views/isbn/index.blade.php`** — kartu + tabel DataTables (pola `journals/index`): kolom **Kode/Judul**, **No. ISBN** (`bookIsbn?->no_isbn ?? '—'`), **Status** (badge dari `bookIsbn?->statusLabel()` atau "Belum didaftarkan"), **Penerbit**, **Tgl ISBN**, **Aksi** ("Kelola" → `title.show`). Menu sidebar "Direktori ISBN" dekat "Direktori Jurnal" (semua staf).
- **`resources/views/titles/show.blade.php`** — kartu baru **"Registrasi ISBN"** (bila `$title->jenis==='buku'`, di bawah Informasi Publikasi, tampil utk `canViewInfo`):
  - Bila **belum layak** (`! $title->isbnEligible()`): catatan muted "Registrasi ISBN tersedia setelah manuskrip mencapai tahap ISBN."
  - Bila **layak**: tampil `dl` status + 3 nomor + penerbit + tanggal (dari `$title->bookIsbn`, atau "Belum didaftarkan"). Bila `canEditInfo`-equiv (superadmin/manager/admin/production) → tombol "Edit Registrasi ISBN" buka collapse form:
    - Bila `bookIsbn` ada → `PUT route('isbn.update', $title->bookIsbn->id)`; else → `POST route('isbn.store')` + `<input hidden name=title_id>`.
    - Field: `status` (select STATUSES), `no_pendaftaran`, `no_isbn`, `no_buku_cetak`, `penerbit` (text), `tgl_daftar`/`tgl_isbn`/`tgl_terbit` (flatpickr date), `catatan` (textarea).
  - `TitleController@show` tambah eager-load `bookIsbn` + kirim flag/objek yang perlu (mis. `$title->bookIsbn`). `isbnEligible()` dihitung dari `orderDetails.titleProgress` yang sudah di-load di `show()`.

> Panel kelola ISBN pakai role gate `superadmin/manager/admin/production` (production ikut). Ini SEDIKIT lebih luas dari `canEditInfo` publikasi (superadmin/manager/admin) → kirim flag terpisah `$canManageIsbn = hasAnyRole([...,'production'])`.

## 6. Rencana Test

- **Feature `BookIsbnTest`** (via `.env.testing`, `GoogleDriveService` di-mock):
  - `eligible_book_appears_in_directory_ineligible_hidden`: buku manuskrip di `isbn` muncul di `isbn.index`; buku di `editing` tak muncul.
  - `production_registers_isbn_for_eligible_book`: production `POST isbn.store` (buku layak) → record tersimpan (status/nomor), `created_by` terisi; redirect.
  - `store_rejected_for_ineligible_book`: buku di `editing` → `POST isbn.store` (production) → 403; tak ada record.
  - `duplicate_isbn_per_book_rejected`: buku sudah punya record → `POST isbn.store` lagi → error (tak buat record kedua; `title_id` tetap unik/1 record).
  - `marketing_cannot_register`: marketing `POST isbn.store` → 403.
  - `panel_shows_form_when_eligible_and_note_when_not`: `GET title.show` buku layak → `assertSee('Registrasi ISBN')` + form; buku belum layak → `assertSee('setelah manuskrip mencapai tahap ISBN')`.
  - `production_updates_and_deletes`: `PUT isbn.update` ubah status ke `ber_isbn`; `DELETE isbn.destroy` hapus.
- **Unit `TitleIsbnEligibleTest`**: `isbnEligible()` true saat bottleneck `isbn`/`cetak`/`terbit`; false saat `editing`/`layout`/`proofreading`/null/artikel.
- **Regresi**: `TitlePublicationInfoTest`, `TitleManuscriptStatusTest` tetap hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` untuk `tb_book_isbns`. Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `create_tb_book_isbns`; `app/Models/BookIsbn.php`; `app/Http/Controllers/Pages/BookIsbnController.php`; `resources/views/isbn/index.blade.php`; test `BookIsbnTest` + `TitleIsbnEligibleTest`; item menu sidebar.
- **Diubah:** `app/Models/Title.php` (+`bookIsbn()`, +`isbnEligible()`); `app/Http/Controllers/Pages/TitleController.php` (`show` eager-load `bookIsbn` + `$canManageIsbn`); `resources/views/titles/show.blade.php` (kartu Registrasi ISBN); `routes/web.php` (rute `isbn.*`); layout sidebar (menu).
- **Tak diubah:** service/manuscript/journal existing.

## 8. Asumsi & Risiko

- Satu record ISBN per buku (`title_id` unik); format cetak vs digital tak dipisah record (bila perlu, tambah `format` di cycle lanjutan) — sesuai keputusan "satu record/buku, 3 nomor".
- Status diisi manual oleh pengelola (tak auto-derive dari nomor terisi) → kontrol eksplisit, hindari kejutan.
- Kelayakan dihitung PHP dari `orderDetails.titleProgress` (perlu eager-load); jumlah buku modest → tanpa query kompleks.
- Registri ISBN independen dari tahap manuskrip `isbn` (tanpa auto-sync); aturan kunci papan (B/C/D) ditangani cycle berikutnya.
- Tanggal kosong → null (pola `($x ?? '') ?: null`) untuk hindari Carbon parse '' = hari ini.
