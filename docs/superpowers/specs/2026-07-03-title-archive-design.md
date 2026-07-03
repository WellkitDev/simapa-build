# Spec — Arsip Judul (Judul Selesai) — Fase 1

- **Tanggal:** 2026-07-03
- **Branch:** `title-archive`
- **Scope (Fase 1):** Ubah menu **"Arsip Judul"** menjadi arsip judul **selesai**. Record penyelesaian per judul + artefak deliverable (nilai file/link/teks + **PIC** + catatan), cek **kelayakan** (lunas + manuskrip final), alur **Ajukan → notif superadmin/manager → Approve/Tolak**, halaman **Arsip** (daftar disetujui + antrean diajukan) & **detail konsolidasi** (judul + order + manuskrip + artefak).
- **Di luar scope:** **export/print PDF** (Fase 2); membuang judul dari Direktori/board saat diarsipkan (judul tetap di Direktori); template artefak yang bisa di-custom (Fase 1 pakai daftar baku + baris "lainnya").

> "Arsip Judul" sekarang = `OrderBookController::indexJudul` (mirip Direktori Judul) — menu di-repurpose ke `TitleArchiveController`. Nama service baru **`TitleArchivalService`** agar tak bentrok `TitleArchiveService` existing (mengurus `group_key` manuskrip).

---

## 1. Tujuan & Kriteria Sukses

1. Judul yang **selesai** (lunas + manuskrip final + disetujui) terkumpul di menu Arsip Judul; detailnya konsolidasi info judul + order + manuskrip + artefak penyelesaian (+ PIC).
2. Pengelola mengisi artefak deliverable per judul (buku/artikel) — nilai (file→Google Drive / link / teks) + PIC + catatan; nilai yang sudah ada (ISBN/LoA/publish/bukti APC) **di-prefill** dari data existing.
3. **Ajukan ke Arsip** hanya bila **eligible** (semua order lunas **dan** manuskrip final `terbit`/`publish`); jika belum → ditolak dengan pesan. Artefak tak wajib 100% saat submit.
4. Submit → status `diajukan` + **notifikasi** ke superadmin/manager. Approve (superadmin/manager) + catatan → `disetujui` (masuk arsip); Tolak + catatan → `ditolak`.
5. Approve/Tolak hanya superadmin/manager (403 untuk lainnya); isi artefak + Ajukan oleh superadmin/manager/admin/production; lihat arsip oleh keempat role tsb.
6. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model (2 tabel)

**`tb_title_archives`** (1 per judul) — migrasi `2026_07_03_000007`:
```php
$table->id();
$table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
$table->string('status')->default('draft'); // draft | diajukan | disetujui | ditolak
$table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('submitted_at')->nullable();
$table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('approved_at')->nullable();
$table->text('approval_note')->nullable();
$table->text('reject_note')->nullable();
$table->timestamps();
```

**`tb_title_archive_artifacts`** (baris artefak per judul) — migrasi `2026_07_03_000008`:
```php
$table->id();
$table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
$table->string('key')->nullable();       // key baku (isbn, barcode_file, …) atau null untuk "lainnya"
$table->string('label');
$table->string('type')->default('text'); // file | link | text
$table->text('value')->nullable();       // url (file/link) atau teks
$table->string('file_name')->nullable();
$table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
$table->text('note')->nullable();
$table->boolean('is_custom')->default(false); // true = baris "lainnya"
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```

**Model `TitleArchive`** (`tb_title_archives`): fillable status/submitted_by/submitted_at/approved_by/approved_at/approval_note/reject_note (+title_id); casts submitted_at/approved_at datetime; `const STATUSES = ['draft'=>'Draft','diajukan'=>'Diajukan','disetujui'=>'Disetujui','ditolak'=>'Ditolak']`; `statusLabel()`. **Konstanta artefak baku:**
```php
const BOOK_ARTIFACTS = [
    'isbn'            => ['label' => 'No. ISBN',            'type' => 'text'],
    'barcode_file'    => ['label' => 'File Barcode',        'type' => 'file'],
    'publish_link'    => ['label' => 'Link Buku Publish',   'type' => 'link'],
    'scholar_link'    => ['label' => 'Link Scholar',        'type' => 'link'],
    'hki_file'        => ['label' => 'File HKI',            'type' => 'file'],
    'final_book_file' => ['label' => 'File Buku Final (ber-ISBN)', 'type' => 'file'],
];
const ARTICLE_ARTIFACTS = [
    'loa'          => ['label' => 'LoA',              'type' => 'file'],
    'publish_link' => ['label' => 'Link Publish',     'type' => 'link'],
    'final_naskah' => ['label' => 'Naskah Final',     'type' => 'file'],
    'apc_bukti'    => ['label' => 'Bukti Bayar APC',  'type' => 'file'],
];
public static function artifactsFor(string $jenis): array { return $jenis === 'buku' ? self::BOOK_ARTIFACTS : self::ARTICLE_ARTIFACTS; }
```
Relasi `title()`, `submitter()` (submitted_by), `approver()` (approved_by).

**Model `TitleArchiveArtifact`** (`tb_title_archive_artifacts`): fillable title_id/key/label/type/value/file_name/pic_user_id/note/is_custom/position; casts is_custom bool; relasi `title()`, `pic()` (belongsTo User `pic_user_id`).

**`Title`**: `archive()` hasOne `TitleArchive`; `archiveArtifacts()` hasMany `TitleArchiveArtifact` (orderBy position). Plus helper kelayakan (§3).

## 3. Kelayakan (server)

- **`Order::isLunas(): bool`** (di `App\Models\Order`):
  ```php
  public function isLunas(): bool {
      if ($this->invoices()->where('status', 'lunas')->exists()) return true;
      $paid = (int) $this->payments()->where('status', 'paid')->sum('amount');
      $cost = (int) optional($this->orderDetail)->cost_amount;
      return $paid >= $cost; // cost 0 → lunas
  }
  ```
- **`Title::isPaidOff(): bool`**: kumpulkan order unik dari `orderDetails.order`; kosong → `false`; else `->every(fn ($o) => $o->isLunas())`.
- **`Title::manuscriptIsFinal(): bool`**: `TitleProgress::isFinal((string) $this->manuscriptStatus())` (null-safe → false).
- **`Title::archiveEligible(): bool`**: `isPaidOff() && manuscriptIsFinal()`.
- Butuh eager-load `orderDetails.order.invoices`, `orderDetails.order.payments`, `orderDetails.order.orderDetail`, `orderDetails.titleProgress` di controller.

## 4. Logika — `TitleArchivalService` (inject `GoogleDriveService $drive`)

- **`defaultArtifacts(Title $title): array`** — untuk tiap key baku `artifactsFor($jenis)`: ambil row existing (`archiveArtifacts` by key) bila ada; else buat struktur default dengan **prefill** value: `isbn`←`bookIsbn->no_isbn`; `loa`←submission `loa_url`; `publish_link`←(artikel) submission `link_publish`; `apc_bukti`←submission `bukti_bayar_url` (submission = `JournalSubmission::where('title_id',id)->latest()->first()`). Return daftar terurut untuk render.
- **`saveArtifacts(Title $title, array $fixed, array $custom, User $actor): void`** — 
  - Fixed: untuk tiap `$key => $item` (`value`,`pic_user_id`,`note`, `file`?UploadedFile): resolve label/type dari konstanta; `updateOrCreate(['title_id'=>id,'key'=>$key], [...])`; bila `type=file` & ada file baru → `value = drive->uploadFile($file,null,false)['url']` + `file_name`; tanpa file baru pertahankan value lama.
  - Custom (lainnya): hapus semua row `is_custom=true` milik judul, lalu buat ulang dari `$custom` (tiap: `label` wajib non-kosong, `type` in link/text, `value`,`pic_user_id`,`note`, `is_custom=true`).
- **`submit(Title $title, User $actor): TitleArchive`** — `abort_unless($title->archiveEligible(), 422/…)` (dilempar sbg ValidationException agar controller flash); `updateOrCreate(['title_id'=>id], ['status'=>'diajukan','submitted_by'=>$actor->id,'submitted_at'=>now()])`; `app(Notifier::class)->titleArchiveSubmitted($archive, $actor)`.
- **`approve(Title $title, User $actor, ?string $note): TitleArchive`** — `updateOrCreate(['title_id'=>id], ['status'=>'disetujui','approved_by'=>$actor->id,'approved_at'=>now(),'approval_note'=>$note])`.
- **`reject(Title $title, User $actor, string $note): TitleArchive`** — `updateOrCreate([...], ['status'=>'ditolak','reject_note'=>$note])`.

**`Notifier::titleArchiveSubmitted(TitleArchive $archive, User $actor)`** — kirim ke `roleUsers(['superadmin','manager'], $actor)`, `category='title'`, judul "Judul diajukan ke arsip", `url = route('archive.show', $archive->title_id)`, icon `archive`.

## 5. Kontroler & Rute — `TitleArchiveController`

Rute (grup auth):
```php
Route::get('management/archive', [TitleArchiveController::class, 'index'])->name('archive.index'); // lihat: 4 role
Route::get('management/archive/{id}', [TitleArchiveController::class, 'show'])->name('archive.show')->whereNumber('id');
Route::middleware('role:superadmin|manager|admin|production')->group(function () {
    Route::put('management/archive/{id}/artifacts', [TitleArchiveController::class, 'saveArtifacts'])->name('archive.artifacts')->whereNumber('id');
    Route::post('management/archive/{id}/submit', [TitleArchiveController::class, 'submit'])->name('archive.submit')->whereNumber('id');
});
Route::middleware('role:superadmin|manager')->group(function () {
    Route::post('management/archive/{id}/approve', [TitleArchiveController::class, 'approve'])->name('archive.approve')->whereNumber('id');
    Route::post('management/archive/{id}/reject', [TitleArchiveController::class, 'reject'])->name('archive.reject')->whereNumber('id');
});
```
Akses `index`/`show`: `abort_unless hasAnyRole([superadmin,manager,admin,production])`.

- **`index()`** — judul dengan archive `disetujui` (arsip) + (untuk approver) hitung/antrean `diajukan`. Kirim dua koleksi (arsip + menunggu) untuk DataTables (tab). Eager-load `archive.approver`, `archive.submitter`.
- **`show($id)`** — `Title::with([...kelayakan + chapters + orderDetails.* + bookIsbn + journalOptions + archive + archiveArtifacts.pic])->findOrFail`; abort role; bangun `$artifacts = service->defaultArtifacts($title)` + `$customArtifacts = archiveArtifacts where is_custom`; kirim `$eligible`, `$isPaidOff`, `$isFinal`, `$canManage`(4 role), `$canApprove`(superadmin/manager), `$staff` (User staf untuk PIC).
- **`saveArtifacts(Request,$id)`** — validasi `fixed.*`/`custom.*`; `service->saveArtifacts`; redirect `archive.show` + flash.
- **`submit($id)`** — try `service->submit`; bila `ValidationException` (belum eligible) → back with error; else redirect + flash.
- **`approve(Request,$id)`** — validasi `approval_note` nullable; `service->approve`; redirect + flash.
- **`reject(Request,$id)`** — validasi `reject_note` required; `service->reject`; redirect + flash.

## 6. View

- **`resources/views/archive/index.blade.php`** — dua tabel DataTables: **Arsip (disetujui)** (Kode/Judul, Jenis, Tgl disetujui, Approver, aksi "Lihat"→`archive.show`) dan (bila `$canApprove`) **Menunggu Persetujuan (diajukan)** (Kode/Judul, Jenis, Diajukan oleh/tgl, aksi "Tinjau"). Menu sidebar "Arsip Judul" diarahkan ke `archive.index`.
- **`resources/views/archive/show.blade.php`** — konsolidasi (kartu-kartu):
  1. **Ringkas Judul** (kode, judul, jenis, tipe, bidang, indeksasi, status arsip badge).
  2. **Info Order** (tabel order tertaut + marketing + tanggal + biaya + badge **Lunas/Belum** per order).
  3. **Info Manuskrip** (status final badge; bab bila buku).
  4. **Kelayakan** (badge Lunas ✓/✗, Final ✓/✗) + tombol **Ajukan ke Arsip** (aktif hanya bila `$eligible` & belum diajukan/disetujui; `$canManage`).
  5. **Artefak Penyelesaian** — form (`$canManage`) `PUT archive.artifacts` multipart: tiap artefak baku (label + input sesuai type: file/link/teks) + **PIC** (select `$staff`) + catatan; + repeater **"Lainnya"** (label + type link/teks + value + PIC + catatan). Non-pengelola: read (nilai + PIC + catatan, link/file klik).
  6. **Persetujuan** (bila `$canApprove` & status `diajukan`): form Approve (`approval_note`) / Tolak (`reject_note`). Tampilkan approver + approval_note bila `disetujui`; reject_note bila `ditolak`.

## 7. Rencana Test

- **Unit `TitleArchiveEligibleTest`**: `Order::isLunas` (invoice lunas → true; payments paid ≥ cost → true; kurang → false). `Title::isPaidOff` (semua order lunas → true; salah satu belum → false; tanpa order → false). `archiveEligible` (paidoff & final → true; final tapi belum lunas → false; lunas tapi belum final → false).
- **Feature `TitleArchiveTest`** (`GoogleDriveService` di-mock):
  - `save_artifacts_upserts_with_pic_and_prefill`: admin `PUT archive.artifacts` (buku, isbn text + file barcode `UploadedFile::fake` + PIC) → row tersimpan, file_url terisi, pic_user_id terisi.
  - `submit_rejected_when_not_eligible`: judul belum lunas/final → `POST archive.submit` → redirect back + error; tak ada archive `diajukan`.
  - `submit_sets_diajukan_and_notifies`: judul eligible (order lunas + manuskrip final) → submit → archive `diajukan`, submitted_by terisi; ada notifikasi ke superadmin (assert `tb_notifications`/pola existing).
  - `superadmin_approves` / `rejects`: `POST archive.approve` (+note) → `disetujui`+approved_by; `POST archive.reject` (+note) → `ditolak`.
  - `admin_cannot_approve`: admin `POST archive.approve` → 403.
  - `index_lists_approved`: judul `disetujui` tampil di `archive.index`.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` untuk 2 tabel. Lihat [[migrate-dev-db-after-new-migration]].

## 8. Komponen

- **Baru:** 2 migrasi (`000007`/`000008`); model `TitleArchive`, `TitleArchiveArtifact`; `TitleArchivalService`; `TitleArchiveController`; view `archive/index.blade.php`, `archive/show.blade.php`; `Notifier::titleArchiveSubmitted`; test unit+feature.
- **Diubah:** `Title` (+archive/archiveArtifacts + isPaidOff/manuscriptIsFinal/archiveEligible); `Order` (+isLunas); `routes/web.php` (`archive.*`); `resources/views/layouts/sidebar.blade.php` (menu Arsip Judul → `archive.index`).
- **Tak diubah:** `TitleArchiveService` (group_key), Direktori Judul, board.

## 9. Asumsi & Risiko

- Judul diarsipkan **tetap** ada di Direktori Judul; Arsip = view koleksi selesai (status archive `disetujui`).
- "Lunas" = invoice order `lunas` ATAU total Payment `paid` ≥ `cost_amount`; judul multi-order → semua ordernya lunas.
- Artefak baku fixed (konstanta); "lainnya" = link/teks + PIC + catatan (tanpa upload file di Fase 1) untuk membatasi kompleksitas; upload file hanya artefak baku bertipe `file`.
- Prefill best-effort saat render (tak menimpa nilai yang sudah disimpan).
- PIC = User staf (dropdown). Approve tak mengunci artefak (bisa diperbarui pasca-approve; v1).
- PDF & (opsional) custom template artefak = Fase 2.
