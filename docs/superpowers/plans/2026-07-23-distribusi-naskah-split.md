# Distribusi Naskah Split — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pisahkan Pelacakan Naskah (jadi monitor read-only) dari Distribusi Naskah (dua menu baru Artikel & Buku), buang alur `needs_review`, dan tambahkan upload file naskah 2-slot ber-versi via Google Drive; admin naik jadi pendistribusi penuh + editor.

**Architecture:** Laravel 10 + Blade + Spatie Permission. Logika status/editor sudah ada di `TitleProgressService` & `ChapterManuscriptService` — dipakai ulang oleh dua controller distribusi tipis. File naskah = tabel baru `tb_manuscript_files` (append-only, versi naik) di-handle `ManuscriptFileService` via `GoogleDriveService`. Papan Kanban disusutkan jadi tampilan read-only. Akses ditegakkan `EnforcePermission` (fail-closed) lewat `config/permissions.php`.

**Tech Stack:** PHP 8.2, Laravel, Spatie Permission, PHPUnit, DomPDF (tak dipakai di sini), Google Drive API (`GoogleDriveService`).

**Spec:** `docs/superpowers/specs/2026-07-23-distribusi-naskah-split-design.md`

---

## Catatan lintas-task (baca sekali)

- **Test DB:** seluruh test jalan lewat `.env.testing` → DB `avidpedi_simapa_test`. Perintah: `php artisan test --filter=<Class>` atau path spesifik. Jangan sentuh DB nyata.
- **Commit:** author = `WellkitDev`. Tambahkan trailer `Co-authored-by: Mira <admin@avidpedia.com>` pada tiap commit. **Jangan** sebut "Claude"/Anthropic di pesan commit.
- **Migrasi DB dev:** setelah Task 1, jalankan `php artisan migrate` juga di DB dev `avidpedi_simapa` (bukan hanya test) agar app live tak 500.
- **Permission fail-closed:** setiap route baru WAJIB terpetakan di `config/permissions.php`, jika tidak → 403 + test merah. Setelah mengubah peta/hibah, jalankan `php artisan db:seed --class=AccessMatrixSeeder` di dev, dan pastikan `TestCase` men-seed matriks untuk test (sudah otomatis).
- **`GoogleDriveService::uploadFile($file, ?folderId, bool makePublic)`** mengembalikan array `['id'=>..., 'name'=>..., 'url'=>...]` (lihat pemakaian di `DocChecklistService`). Di test, di-mock.
- **Baseline hijau:** sebelum mulai, jalankan `php artisan test` dan pastikan lulus (memory: 593 passed).

---

## File Structure

**Dibuat:**
- `database/migrations/2026_07_23_000001_create_manuscript_files_table.php` — tabel `tb_manuscript_files`.
- `app/Models/ManuscriptFile.php` — model file naskah (append-only, `UPDATED_AT=null`).
- `app/Services/ManuscriptFileService.php` — upload Drive + versioning + query versi.
- `app/Http/Controllers/Pages/ArticleDistributionController.php` — index + detail + aksi artikel.
- `app/Http/Controllers/Pages/BookDistributionController.php` — index + detail + aksi buku (per bab + pintasan).
- `resources/views/distribusi/artikel/index.blade.php`, `.../artikel/show.blade.php`
- `resources/views/distribusi/buku/index.blade.php`, `.../buku/show.blade.php`
- `resources/views/distribusi/partials/file-slot.blade.php` — komponen 2-slot file (dipakai artikel & buku).
- `tests/Unit/ManuscriptFileServiceTest.php`, `tests/Feature/ArticleDistributionTest.php`, `tests/Feature/BookDistributionTest.php`, `tests/Feature/DistributionAccessTest.php`.

**Dimodifikasi:**
- `app/Services/TitleProgressService.php` — `authorizeChange` (+admin), `assignEditor` eligibility (+admin), buang set `needs_review`, panggil `distribusiChanged`.
- `app/Services/ChapterManuscriptService.php` — `authorize` (+admin), `assignEditor` eligibility (+admin), buang set `needs_review`, tambah `assignEditorAll`.
- `app/Services/Notifier.php` — tambah `distribusiChanged`, hapus `naskahNeedsReview`.
- `app/Http/Controllers/Pages/ManuscriptTrackerController.php` — sisakan `index()` + helper; hapus mutasi.
- `app/Http/Controllers/Pages/OrderBookController.php` — hapus `progressDetail`.
- `app/Http/Controllers/Pages/ChapterProgressController.php` — hapus (dipindah ke BookDistributionController) **atau** kosongkan route lama.
- `config/permissions.php` — tambah modul `distribution`; susutkan `manuscript`; hapus modul `chapter`.
- `database/seeders/AccessMatrixSeeder.php` — hibah `distribution.*`; `manuscript.view` utk admin.
- `routes/web.php` — tambah grup `management/distribusi`; hapus route mutasi papan, `chapter.*`, `order.indexJudul.progress`.
- `resources/views/manuscript/partials/card.blade.php`, `board.blade.php`, `list.blade.php`, `partials/toolbar.blade.php` — jadikan read-only.
- `resources/views/layouts/sidebar.blade.php` — tambah menu Distribusi Artikel/Buku.

**Dihapus:**
- `resources/views/orders/detail-title.blade.php` (Kontrol Naskah lama).

**Test yang harus disesuaikan (referensi perilaku yang dipensiunkan):**
`tests/Feature/ManuscriptTrackerTest.php`, `tests/Unit/TitleProgressServiceTest.php`, `tests/Feature/ChapterStageJumpTest.php`, `tests/Feature/ChapterBoardTest.php`, `tests/Feature/TitleProgressTest.php`, `tests/Feature/ManuscriptFinalizeTest.php`, `tests/Feature/MarketingAccessTest.php`, `tests/Feature/ProductionWorkspaceTest.php`, `tests/Feature/MarketingDashboardTest.php`, `tests/Feature/ArchiveGroupedTitlesTest.php`.

---

## Task 1: Model + migrasi `tb_manuscript_files`

**Files:**
- Create: `database/migrations/2026_07_23_000001_create_manuscript_files_table.php`
- Create: `app/Models/ManuscriptFile.php`
- Test: `tests/Unit/ManuscriptFileServiceTest.php` (dibuat penuh di Task 2; di sini hanya smoke lewat model)

- [ ] **Step 1: Tulis migrasi**

`database/migrations/2026_07_23_000001_create_manuscript_files_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_manuscript_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $t->foreignId('title_chapter_id')->nullable()->constrained('tb_title_chapters')->nullOnDelete();
            $t->string('slot', 20);              // 'masuk' | 'final'
            $t->unsignedInteger('version')->default(1);
            $t->string('original_name');
            $t->string('drive_file_id')->nullable();
            $t->string('drive_url')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['title_id', 'title_chapter_id', 'slot', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_manuscript_files');
    }
};
```

- [ ] **Step 2: Tulis model**

`app/Models/ManuscriptFile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManuscriptFile extends Model
{
    protected $table = 'tb_manuscript_files';

    public const UPDATED_AT = null; // append-only: hanya created_at

    public const SLOTS = ['masuk' => 'Naskah Masuk', 'final' => 'Naskah Final'];

    protected $fillable = [
        'title_id', 'title_chapter_id', 'slot', 'version',
        'original_name', 'drive_file_id', 'drive_url', 'file_size', 'uploaded_by',
    ];

    public function title() { return $this->belongsTo(Title::class); }
    public function chapter() { return $this->belongsTo(TitleChapter::class, 'title_chapter_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function slotLabel(): string { return self::SLOTS[$this->slot] ?? $this->slot; }
}
```

- [ ] **Step 3: Jalankan migrasi di test DB & verifikasi**

Run: `php artisan migrate --env=testing`
Expected: migrasi `create_manuscript_files_table` sukses (`DONE`).

- [ ] **Step 4: Jalankan migrasi di DB dev**

Run: `php artisan migrate`
Expected: `DONE`. (Memory: app live 500 bila tabel belum ada di dev.)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_23_000001_create_manuscript_files_table.php app/Models/ManuscriptFile.php
git commit -m "feat(distribusi): tabel & model manuscript_files"
```

---

## Task 2: `ManuscriptFileService` (upload + versioning)

**Files:**
- Create: `app/Services/ManuscriptFileService.php`
- Test: `tests/Unit/ManuscriptFileServiceTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/ManuscriptFileServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\ManuscriptFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ManuscriptFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ManuscriptFileService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'drv1', 'name' => 'n', 'url' => 'http://drive/n.pdf']);
        return new ManuscriptFileService($drive);
    }

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Naskah ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function upload_creates_version_1_then_increments(): void
    {
        $title = $this->book();
        $actor = User::factory()->create();
        $svc = $this->service();

        $f1 = $svc->upload($title, null, 'final', UploadedFile::fake()->create('a.pdf', 5), $actor);
        $f2 = $svc->upload($title, null, 'final', UploadedFile::fake()->create('b.pdf', 5), $actor);

        $this->assertSame(1, $f1->version);
        $this->assertSame(2, $f2->version);
        $this->assertSame('http://drive/n.pdf', $f2->drive_url);
        $this->assertSame($actor->id, $f2->uploaded_by);
    }

    /** @test */
    public function versions_are_isolated_per_slot_and_chapter(): void
    {
        $title = $this->book();
        $chapter = TitleChapter::create(['title_id' => $title->id, 'judul' => 'Bab 1', 'urutan' => 1]);
        $actor = User::factory()->create();
        $svc = $this->service();

        $svc->upload($title, null, 'masuk', UploadedFile::fake()->create('m.pdf', 5), $actor);      // title/masuk v1
        $svc->upload($title, null, 'final', UploadedFile::fake()->create('f.pdf', 5), $actor);      // title/final v1
        $chFile = $svc->upload($title, $chapter, 'final', UploadedFile::fake()->create('c.pdf', 5), $actor); // chapter/final v1

        $this->assertSame(1, $chFile->version);
        $this->assertSame(1, $svc->latest($title, null, 'masuk')->version);
        $this->assertNull($svc->latest($title, $chapter->id, 'masuk'));
    }

    /** @test */
    public function invalid_slot_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->upload($this->book(), null, 'draft', UploadedFile::fake()->create('x.pdf', 5), User::factory()->create());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=ManuscriptFileServiceTest`
Expected: FAIL — `Class "App\Services\ManuscriptFileService" not found`.

- [ ] **Step 3: Tulis service**

`app/Services/ManuscriptFileService.php`:

```php
<?php

namespace App\Services;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ManuscriptFileService
{
    public function __construct(private GoogleDriveService $drive) {}

    public function upload(Title $title, ?TitleChapter $chapter, string $slot, UploadedFile $file, User $actor): ManuscriptFile
    {
        if (! array_key_exists($slot, ManuscriptFile::SLOTS)) {
            throw ValidationException::withMessages(['slot' => 'Slot naskah tidak valid.']);
        }

        $chapterId = $chapter?->id;
        $next = (int) ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->max('version') + 1;

        $uploaded = $this->drive->uploadFile($file, null, false);
        if (! $uploaded) {
            throw ValidationException::withMessages(['file' => 'Gagal mengunggah file ke Drive.']);
        }

        return ManuscriptFile::create([
            'title_id'         => $title->id,
            'title_chapter_id' => $chapterId,
            'slot'             => $slot,
            'version'          => $next,
            'original_name'    => $file->getClientOriginalName(),
            'drive_file_id'    => $uploaded['id'] ?? null,
            'drive_url'        => $uploaded['url'] ?? null,
            'file_size'        => $file->getSize(),
            'uploaded_by'      => $actor->id,
            'created_at'       => now(),
        ]);
    }

    public function latest(Title $title, ?int $chapterId, string $slot): ?ManuscriptFile
    {
        return ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->orderByDesc('version')->first();
    }

    /** @return Collection<int,ManuscriptFile> */
    public function versions(Title $title, ?int $chapterId, string $slot): Collection
    {
        return ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->orderByDesc('version')->get();
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ManuscriptFileServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ManuscriptFileService.php tests/Unit/ManuscriptFileServiceTest.php
git commit -m "feat(distribusi): ManuscriptFileService upload & versioning"
```

---

## Task 3: Admin sebagai editor & pendistribusi (services)

**Files:**
- Modify: `app/Services/TitleProgressService.php` (`assignEditor` ~118-141, `authorizeChange` ~101-116)
- Modify: `app/Services/ChapterManuscriptService.php` (`assignEditor` ~89-103, `authorize` ~192-207)
- Test: `tests/Unit/TitleProgressServiceTest.php` (tambah), `tests/Unit/ChapterManuscriptServiceTest.php` (tambah)

- [ ] **Step 1: Tulis test yang gagal (TitleProgressService)**

Tambahkan ke `tests/Unit/TitleProgressServiceTest.php` (di dalam kelas). Gunakan helper pembuat progress/role yang sudah ada di file itu; jika belum ada helper role, buat user via `User::factory()->create()` lalu `assignRole('admin')` (pastikan `Role::firstOrCreate(['name'=>'admin','guard_name'=>'web'])` di setup — tambahkan 'admin' ke daftar role setup file ini):

```php
/** @test */
public function admin_can_be_assigned_as_editor(): void
{
    $svc = new \App\Services\TitleProgressService();
    $progress = $this->makeProgress('editing'); // helper existing di file ini
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('admin');
    $actor = \App\Models\User::factory()->create();
    $actor->assignRole('manager');

    $svc->assignEditor($progress, $admin->id, $actor);

    $this->assertDatabaseHas('tb_title_progress', ['id' => $progress->id, 'assigned_user_id' => $admin->id]);
}

/** @test */
public function admin_can_move_production_stage(): void
{
    $svc = new \App\Services\TitleProgressService();
    $progress = $this->makeProgress('editing'); // handler production
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('admin');

    $svc->changeStatus($progress, 'layout', $admin);

    $this->assertDatabaseHas('tb_title_progress', ['id' => $progress->id, 'status' => 'layout']);
}
```

> Catatan: buka `tests/Unit/TitleProgressServiceTest.php` untuk nama helper sebenarnya (mis. `makeProgress`/`progress`) dan daftar role di `setUp()`. Tambahkan `'admin'` ke role yang di-`create` pada `setUp()`.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=TitleProgressServiceTest`
Expected: FAIL — `admin_can_be_assigned_as_editor` gagal validasi "Editor harus user dengan role production atau manager"; `admin_can_move_production_stage` lempar `AuthorizationException`.

- [ ] **Step 3: Ubah `TitleProgressService`**

Di `assignEditor`, ganti pemeriksaan eligibility editor:

```php
// SEBELUM
if (!$assignee || !$assignee->hasAnyRole(['production', 'manager'])) {
// SESUDAH
if (!$assignee || !$assignee->hasAnyRole(['production', 'manager', 'admin'])) {
```

Dan pesan error di baris berikutnya:

```php
'assigned_user_id' => 'Editor harus user dengan role production, manager, atau admin.',
```

Di `authorizeChange`, tambahkan admin setara production (sebelum baris production):

```php
private function authorizeChange(User $actor, string $current): void
{
    if ($actor->hasRole('superadmin')) {
        return;
    }
    if (TitleProgress::isFinal($current)) {
        throw new AuthorizationException('Naskah sudah final dan terkunci.');
    }
    if ($actor->hasRole('manager')) {
        return;
    }
    if ($actor->hasAnyRole(['production', 'admin']) && TitleProgress::getHandlerForStatus($current) === 'production') {
        return;
    }
    throw new AuthorizationException('Anda tidak berhak memindahkan naskah pada tahap ini.');
}
```

Juga di `assignEditor`/`setPriority`/`setTargetDate` guard aktor: ganti `hasAnyRole(['production', 'manager', 'superadmin'])` → tambahkan `'admin'`. Cari ketiga tempat itu dan sisipkan `'admin'`.

- [ ] **Step 4: Tulis test gagal (ChapterManuscriptService)**

Tambahkan ke `tests/Unit/ChapterManuscriptServiceTest.php`:

```php
/** @test */
public function admin_can_be_chapter_editor_and_move_chapter(): void
{
    $svc = app(\App\Services\ChapterManuscriptService::class);
    $book = $this->makeBookWithChapter(); // helper existing; jika beda nama, sesuaikan
    $cp = $book->chapters()->first()->progress;
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('admin');

    $svc->assignEditor($cp, $admin->id, $admin);
    $svc->changeStatus($cp, 'layout', $admin);

    $this->assertDatabaseHas('tb_chapter_progress', ['id' => $cp->id, 'assigned_user_id' => $admin->id, 'status' => 'layout']);
}
```

> Buka file untuk nama helper sebenarnya (pembuat buku + bab + progress) dan daftar role di `setUp()`; tambahkan `'admin'`.

- [ ] **Step 5: Ubah `ChapterManuscriptService`**

Di `assignEditor`:

```php
// aktor
if (! $actor->hasAnyRole(['production', 'manager', 'superadmin'])) { throw new AuthorizationException(); }
// →
if (! $actor->hasAnyRole(['production', 'manager', 'superadmin', 'admin'])) { throw new AuthorizationException(); }

// assignee eligibility
if (! $u || ! $u->hasAnyRole(['production', 'manager'])) {
// →
if (! $u || ! $u->hasAnyRole(['production', 'manager', 'admin'])) {
```

Dan pesan: `'Editor harus role production, manager, atau admin.'`

Di `authorize` (private), tambahkan admin setara production:

```php
if ($actor->hasAnyRole(['production', 'admin']) && TitleProgress::getHandlerForStatus($current) === 'production') {
    return;
}
```
(Ganti baris `if ($actor->hasRole('production') && ...)`.)

- [ ] **Step 6: Jalankan kedua test, pastikan lulus**

Run: `php artisan test --filter=TitleProgressServiceTest`
Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/TitleProgressService.php app/Services/ChapterManuscriptService.php tests/Unit/TitleProgressServiceTest.php tests/Unit/ChapterManuscriptServiceTest.php
git commit -m "feat(distribusi): admin sebagai editor & pendistribusi setara production"
```

---

## Task 4: Notifier `distribusiChanged` + `assignEditorAll`

**Files:**
- Modify: `app/Services/Notifier.php`
- Modify: `app/Services/ChapterManuscriptService.php` (tambah method)
- Test: `tests/Unit/NotifierTest.php` (tambah)

- [ ] **Step 1: Tulis test gagal (Notifier)**

Tambahkan ke `tests/Unit/NotifierTest.php` (pola: `Notification::fake()` lalu assert dikirim ke role tim). Sesuaikan dengan gaya file:

```php
/** @test */
public function distribusi_changed_notifies_production_roles_except_actor(): void
{
    \Illuminate\Support\Facades\Notification::fake();

    foreach (['superadmin','manager','admin','production'] as $r) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $actor = \App\Models\User::factory()->create(); $actor->assignRole('production');
    $mgr   = \App\Models\User::factory()->create(); $mgr->assignRole('manager');
    $adm   = \App\Models\User::factory()->create(); $adm->assignRole('admin');

    $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
    $progress = \App\Models\TitleProgress::create([
        'order_detail_id' => $detail->id, 'status' => 'editing',
        'assigned_role' => 'production', 'started_at' => now(),
    ]);

    app(\App\Services\Notifier::class)->distribusiChanged($progress, $actor, 'Editor diperbarui');

    \Illuminate\Support\Facades\Notification::assertSentTo([$mgr, $adm], \App\Notifications\DatabaseNotification::class);
    \Illuminate\Support\Facades\Notification::assertNotSentTo([$actor], \App\Notifications\DatabaseNotification::class);
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=NotifierTest`
Expected: FAIL — `Call to undefined method ...::distribusiChanged()`.

- [ ] **Step 3: Tambah `distribusiChanged` ke `Notifier`**

Sisipkan method (mis. setelah `naskahStageChanged`). `roleUsers`/`send` sudah ada (private):

```php
public function distribusiChanged(TitleProgress $progress, User $actor, string $summary): void
{
    $progress->loadMissing('orderDetail');
    $judul = optional($progress->orderDetail)->title ?? '—';
    $this->send($this->roleUsers(['superadmin', 'manager', 'admin', 'production'], $actor), [
        'category' => 'manuscript',
        'title'    => 'Distribusi naskah diperbarui',
        'message'  => $summary . ' — ' . $judul,
        'url'      => route('manuscript.board'),
        'icon'     => 'layers',
    ]);
}
```

- [ ] **Step 4: Hapus `naskahNeedsReview` dari `Notifier`**

Hapus seluruh method `public function naskahNeedsReview(TitleProgress $progress, User $actor): void { ... }`. (Pemanggilnya dihapus di Task 8; urutan aman karena Task 8 menghapus panggilan sebelum test suite penuh dijalankan — namun untuk menjaga hijau, lakukan penghapusan pemanggil di Step 5 berikut.)

- [ ] **Step 5: Hapus sementara pemanggil `naskahNeedsReview`**

Di `TitleProgressService::changeStatus` dan `changeGroupStatus`, hapus dua blok:
```php
if ($result->needs_review) { app(Notifier::class)->naskahNeedsReview($result, $actor); }
```
dan
```php
if ($p->needs_review) { app(Notifier::class)->naskahNeedsReview($p, $actor); }
```
(Set `needs_review` masih ada sampai Task 8; menghapus notifikasinya lebih dulu tidak mengubah kolom, hanya berhenti mengirim notif tinjau — aman.)

- [ ] **Step 6: Tambah `assignEditorAll` ke `ChapterManuscriptService`**

```php
/** Terapkan satu editor ke semua bab buku (pintasan distribusi). */
public function assignEditorAll(Title $book, ?int $userId, User $actor): void
{
    foreach ($book->chapters()->with('progress')->get() as $ch) {
        if ($ch->progress) {
            $this->assignEditor($ch->progress, $userId, $actor);
        }
    }
}
```

- [ ] **Step 7: Jalankan test + suite notifier & service**

Run: `php artisan test --filter=NotifierTest`
Run: `php artisan test --filter=TitleProgressServiceTest`
Expected: PASS. (Jika ada test lama yang meng-assert `naskahNeedsReview` terkirim, catat untuk Task 8.)

- [ ] **Step 8: Commit**

```bash
git add app/Services/Notifier.php app/Services/ChapterManuscriptService.php tests/Unit/NotifierTest.php
git commit -m "feat(distribusi): notif tim distribusiChanged + assignEditorAll; hapus notif tinjau"
```

---

## Task 5: Peta izin `distribution` + hibah seeder

**Files:**
- Modify: `config/permissions.php`
- Modify: `database/seeders/AccessMatrixSeeder.php`
- Test: `tests/Feature/DistributionAccessTest.php` (dibuat sebagian; dilengkapi Task 6-7)

> Route belum ada; peta hanya menyimpan nama route sebagai string (aman). Route fisik ditambah Task 6-7.

- [ ] **Step 1: Tambah modul `distribution` di `config/permissions.php`**

Sisipkan di dalam `'modules' => [ ... ]`:

```php
'distribution' => [
    'label'   => 'Distribusi Naskah',
    'actions' => [
        'view'     => ['distribusi.artikel.index', 'distribusi.artikel.show',
                       'distribusi.buku.index', 'distribusi.buku.show'],
        'assign'   => ['distribusi.artikel.editor', 'distribusi.buku.editorSemua',
                       'distribusi.buku.chapter.editor'],
        'move'     => ['distribusi.artikel.tahap', 'distribusi.buku.chapter.tahap'],
        'priority' => ['distribusi.artikel.prioritas', 'distribusi.buku.prioritas'],
        'target'   => ['distribusi.artikel.target', 'distribusi.buku.target'],
        'upload'   => ['distribusi.artikel.file', 'distribusi.buku.file',
                       'distribusi.buku.chapter.file'],
    ],
],
```

- [ ] **Step 2: Hibah di `AccessMatrixSeeder`**

- Ke array `production`: tambahkan `'distribution.*'`.
- Ke array `admin`: tambahkan `'distribution.*'` dan `'manuscript.view'`.
- (`manager` sudah `'*'` → otomatis dapat.)

Contoh potongan `admin`:
```php
'admin' => [
    'announcement.*',
    'title.view', 'title.create', 'title.edit', 'title.delete', 'title.submit', 'title.info',
    'title.doc.*',
    'journal.*', 'isbn.*', 'author.view',
    'archive.view', 'archive.artifacts', 'archive.submit',
    'manuscript.detail', 'manuscript.view',   // + view papan read-only
    'distribution.*',                          // + distribusi
    'data.*',
],
```

- [ ] **Step 3: Re-seed matriks di test & dev**

Run: `php artisan db:seed --class=AccessMatrixSeeder --env=testing`
Run: `php artisan db:seed --class=AccessMatrixSeeder`
Expected: sukses tanpa error (permission `distribution.*` dibuat).

- [ ] **Step 4: Verifikasi peta**

Run: `php artisan tinker --execute="echo in_array('distribution.view', App\Support\PermissionMap::allPermissions()) ? 'ok' : 'missing';"`
Expected: `ok`.

- [ ] **Step 5: Commit**

```bash
git add config/permissions.php database/seeders/AccessMatrixSeeder.php
git commit -m "feat(distribusi): peta izin modul distribution + hibah admin/production"
```

---

## Task 6: Distribusi Artikel — controller, route, view, test

**Files:**
- Create: `app/Http/Controllers/Pages/ArticleDistributionController.php`
- Create: `resources/views/distribusi/artikel/index.blade.php`, `resources/views/distribusi/artikel/show.blade.php`
- Create: `resources/views/distribusi/partials/file-slot.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ArticleDistributionTest.php`

- [ ] **Step 1: Tambah route (di dalam grup `middleware(['auth','access'])`)**

```php
Route::prefix('management/distribusi')->name('distribusi.')->group(function () {
    Route::get('artikel',              [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'index'])->name('artikel.index');
    Route::get('artikel/{id}',         [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'show'])->name('artikel.show')->whereNumber('id');
    Route::post('artikel/{id}/editor',    [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'assignEditor'])->name('artikel.editor')->whereNumber('id');
    Route::post('artikel/{id}/tahap',     [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'moveStage'])->name('artikel.tahap')->whereNumber('id');
    Route::post('artikel/{id}/prioritas', [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'setPriority'])->name('artikel.prioritas')->whereNumber('id');
    Route::post('artikel/{id}/target',    [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'setTarget'])->name('artikel.target')->whereNumber('id');
    Route::post('artikel/{id}/file',      [\App\Http\Controllers\Pages\ArticleDistributionController::class, 'uploadFile'])->name('artikel.file')->whereNumber('id');
});
```

- [ ] **Step 2: Tulis test gagal**

`tests/Feature/ArticleDistributionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArticleDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd', 'name' => 'n', 'url' => 'http://drive/n.pdf']);
        });
        foreach (['marketing','manager','superadmin','production','admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->seed(\Database\Seeders\AccessMatrixSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(); $u->assignRole($role); return $u;
    }

    private function articleTitle(string $status = 'templating'): Title
    {
        $title = Title::create(['title' => 'Artikel ' . uniqid(), 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create(['type' => 'at_mandiri', 'title_id' => $title->id, 'title' => $title->title]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => TitleProgress::getHandlerForStatus($status), 'started_at' => now()]);
        return $title;
    }

    /** @test */
    public function index_lists_only_article_titles_for_production(): void
    {
        $art = $this->articleTitle();
        $book = Title::create(['title' => 'BUKU BUKAN ARTIKEL', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title_id' => $book->id, 'title' => $book->title]);

        $this->actingAs($this->user('production'))
            ->get(route('distribusi.artikel.index'))
            ->assertOk()
            ->assertSee($art->title)
            ->assertDontSee('BUKU BUKAN ARTIKEL');
    }

    /** @test */
    public function assign_editor_sets_all_variants_and_admin_is_eligible(): void
    {
        $title = $this->articleTitle('editing');
        $editorAdmin = $this->user('admin');

        $this->actingAs($this->user('manager'))
            ->post(route('distribusi.artikel.editor', $title->id), ['assigned_user_id' => $editorAdmin->id])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', [
            'order_detail_id' => $title->orderDetails()->first()->id,
            'assigned_user_id' => $editorAdmin->id,
        ]);
    }

    /** @test */
    public function move_stage_advances_progress(): void
    {
        $title = $this->articleTitle('editing');

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.artikel.tahap', $title->id), ['status' => 'revisi'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', ['order_detail_id' => $title->orderDetails()->first()->id, 'status' => 'revisi']);
    }

    /** @test */
    public function upload_file_creates_versioned_row(): void
    {
        $title = $this->articleTitle();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.artikel.file', $title->id), [
                'slot' => 'final',
                'file' => UploadedFile::fake()->create('naskah.pdf', 20),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', ['title_id' => $title->id, 'slot' => 'final', 'version' => 1]);
    }

    /** @test */
    public function marketing_cannot_access_distribution(): void
    {
        $title = $this->articleTitle();
        $this->actingAs($this->user('marketing'))
            ->get(route('distribusi.artikel.index'))->assertStatus(403);
    }
}
```

- [ ] **Step 3: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ArticleDistributionTest`
Expected: FAIL — controller/view belum ada (500/target class not found).

- [ ] **Step 4: Tulis controller**

`app/Http/Controllers/Pages/ArticleDistributionController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ManuscriptFileService;
use App\Services\Notifier;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ArticleDistributionController extends Controller
{
    public function index()
    {
        $titles = Title::where('jenis', 'artikel')
            ->whereHas('orderDetails')
            ->with(['orderDetails.titleProgress.assignedUser'])
            ->orderBy('title')->get();

        return view('distribusi.artikel.index', compact('titles'));
    }

    public function show(int $id)
    {
        $title = Title::where('jenis', 'artikel')
            ->with(['orderDetails.order.user', 'orderDetails.authors', 'orderDetails.titleProgress.assignedUser'])
            ->findOrFail($id);

        $progresses = $this->progresses($title);
        $editors    = $this->editors();
        $files      = $this->fileVersions($title, null);

        return view('distribusi.artikel.show', compact('title', 'progresses', 'editors', 'files'));
    }

    public function assignEditor(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($svc, $progresses, $userId, $request) {
            $svc->assignGroup($progresses, $userId, $request->user());
            $this->notify($progresses, $request->user(), 'Editor diperbarui');
        }, 'Editor diperbarui.');
    }

    public function moveStage(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->changeGroupStatus($progresses, (string) $request->input('status'), $request->user(), $request->input('note'));
        }, 'Tahap diperbarui.');
    }

    public function setPriority(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupPriority($progresses, (string) $request->input('priority'), $request->user());
            $this->notify($progresses, $request->user(), 'Prioritas diperbarui');
        }, 'Prioritas diperbarui.');
    }

    public function setTarget(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupTargetDate($progresses, $request->input('target_date'), $request->user());
            $this->notify($progresses, $request->user(), 'Target diperbarui');
        }, 'Target diperbarui.');
    }

    public function uploadFile(Request $request, int $id, ManuscriptFileService $files)
    {
        $request->validate([
            'slot' => 'required|in:masuk,final',
            'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480',
        ]);
        $title = Title::where('jenis', 'artikel')->findOrFail($id);

        return $this->run($request, function () use ($files, $title, $request) {
            $files->upload($title, null, $request->input('slot'), $request->file('file'), $request->user());
            $this->notify($this->progresses($title), $request->user(), 'File naskah diunggah');
        }, 'File naskah diunggah.');
    }

    // ── helpers ──

    /** @return \Illuminate\Support\Collection<int,TitleProgress> */
    private function progresses(Title $title)
    {
        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))
            ->get();
    }

    private function editors()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager', 'admin']))
            ->orderBy('name')->get(['id', 'name']);
    }

    private function fileVersions(Title $title, ?int $chapterId): array
    {
        $svc = app(ManuscriptFileService::class);
        return [
            'masuk' => $svc->versions($title, $chapterId, 'masuk'),
            'final' => $svc->versions($title, $chapterId, 'final'),
        ];
    }

    private function notify($progresses, User $actor, string $summary): void
    {
        $first = collect($progresses)->first();
        if ($first) {
            app(Notifier::class)->distribusiChanged($first, $actor, $summary);
        }
    }

    private function run(Request $request, \Closure $action, string $success)
    {
        try {
            $action();
            return back()->with('success', $success);
        } catch (AuthorizationException | ValidationException $e) {
            $msg = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');
            return back()->with('error', $msg);
        }
    }
}
```

- [ ] **Step 5: Tulis partial file-slot**

`resources/views/distribusi/partials/file-slot.blade.php` (dipakai artikel & buku; variabel: `$uploadRoute`, `$routeParam`, `$files` = ['masuk'=>Collection,'final'=>Collection]):

```blade
@foreach (\App\Models\ManuscriptFile::SLOTS as $slot => $label)
    <div class="mb-2">
        <div class="d-flex justify-content-between align-items-center">
            <strong style="font-size:12px">{{ $label }}</strong>
            @php $latest = optional($files[$slot] ?? collect())->first(); @endphp
            @if ($latest)
                <a href="{{ $latest->drive_url }}" target="_blank" class="badge bg-success text-white text-decoration-none">
                    v{{ $latest->version }} · {{ Str::limit($latest->original_name, 24) }}
                </a>
            @else
                <span class="text-muted" style="font-size:11px">belum ada</span>
            @endif
        </div>
        <form method="POST" action="{{ $uploadRoute }}" enctype="multipart/form-data" class="d-flex gap-1 mt-1">
            @csrf
            <input type="hidden" name="slot" value="{{ $slot }}">
            <input type="file" name="file" class="form-control form-control-sm" required>
            <button class="btn btn-sm btn-outline-primary">Unggah</button>
        </form>
        @if (($files[$slot] ?? collect())->count() > 1)
            <details class="mt-1"><summary style="font-size:11px" class="text-muted">Riwayat versi</summary>
                <ul class="mb-0 ps-3">
                    @foreach ($files[$slot] as $f)
                        <li style="font-size:11px"><a href="{{ $f->drive_url }}" target="_blank">v{{ $f->version }}</a> — {{ $f->original_name }} ({{ optional($f->created_at)->format('d/m/Y H:i') }})</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endforeach
```

- [ ] **Step 6: Tulis view index artikel**

`resources/views/distribusi/artikel/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Distribusi Artikel - SiMAPA')
@section('content')
<div class="card">
    <div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi Naskah — Artikel</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="tblDistArtikel">
                <thead><tr><th>Judul</th><th>Status</th><th>Editor</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($titles as $t)
                    @php $p = optional($t->orderDetails->map->titleProgress->filter()->first()); @endphp
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ \App\Models\Title::stageLabel(optional($p)->status) ?? '—' }}</td>
                        <td>{{ optional(optional($p)->assignedUser)->name ?? 'Belum' }}</td>
                        <td><a href="{{ route('distribusi.artikel.show', $t->id) }}" class="btn btn-sm btn-outline-primary">Distribusi</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">Belum ada artikel.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 7: Tulis view show artikel**

`resources/views/distribusi/artikel/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Distribusi Artikel - SiMAPA')
@section('content')
@php
    $p = $progresses->first();
    $stages = $p ? $p->getStages() : [];
@endphp
<div class="card mb-3"><div class="card-body">
    <h3 class="mb-1">{{ $title->title }}</h3>
    <span class="badge bg-info">Artikel</span>
    <span class="badge bg-secondary">{{ \App\Models\Title::stageLabel(optional($p)->status) ?? '—' }}</span>
</div></div>

<div class="card mb-3"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Kontrol Naskah</h5></div>
<div class="card-body"><div class="row g-3">
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.editor', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Editor / PIC</label>
            <div class="d-flex gap-1">
                <select name="assigned_user_id" class="form-select form-select-sm">
                    <option value="">— Belum —</option>
                    @foreach ($editors as $ed)
                        <option value="{{ $ed->id }}" {{ optional(optional($p)->assignedUser)->id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.tahap', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Ubah Tahap</label>
            <div class="d-flex gap-1">
                <select name="status" class="form-select form-select-sm">
                    @foreach ($stages as $s)
                        <option value="{{ $s }}" {{ $s === optional($p)->status ? 'selected' : '' }}>{{ \App\Models\Title::stageLabel($s) }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
            <input type="text" name="note" class="form-control form-control-sm mt-1" placeholder="Catatan (opsional)">
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.target', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Target Publish</label>
            <div class="d-flex gap-1">
                <input type="date" name="target_date" class="form-control form-control-sm" value="{{ optional(optional($p)->target_date)->format('Y-m-d') }}">
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-6">
        <label class="form-label form-label-sm">File Naskah</label>
        @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.artikel.file', $title->id), 'files' => $files])
    </div>
</div></div></div>
@endsection
```

- [ ] **Step 8: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ArticleDistributionTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/ArticleDistributionController.php resources/views/distribusi routes/web.php tests/Feature/ArticleDistributionTest.php
git commit -m "feat(distribusi): halaman & aksi Distribusi Artikel"
```

---

## Task 7: Distribusi Buku — controller, route, view, test

**Files:**
- Create: `app/Http/Controllers/Pages/BookDistributionController.php`
- Create: `resources/views/distribusi/buku/index.blade.php`, `resources/views/distribusi/buku/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BookDistributionTest.php`

- [ ] **Step 1: Tambah route buku (di grup `distribusi.`)**

```php
Route::get('buku',                    [\App\Http\Controllers\Pages\BookDistributionController::class, 'index'])->name('buku.index');
Route::get('buku/{id}',               [\App\Http\Controllers\Pages\BookDistributionController::class, 'show'])->name('buku.show')->whereNumber('id');
Route::post('buku/{id}/editor-semua', [\App\Http\Controllers\Pages\BookDistributionController::class, 'assignEditorAll'])->name('buku.editorSemua')->whereNumber('id');
Route::post('buku/{id}/prioritas',    [\App\Http\Controllers\Pages\BookDistributionController::class, 'setPriority'])->name('buku.prioritas')->whereNumber('id');
Route::post('buku/{id}/target',       [\App\Http\Controllers\Pages\BookDistributionController::class, 'setTarget'])->name('buku.target')->whereNumber('id');
Route::post('buku/{id}/file',         [\App\Http\Controllers\Pages\BookDistributionController::class, 'uploadFile'])->name('buku.file')->whereNumber('id');
Route::post('buku/chapter/{cp}/editor', [\App\Http\Controllers\Pages\BookDistributionController::class, 'assignChapterEditor'])->name('buku.chapter.editor')->whereNumber('cp');
Route::post('buku/chapter/{cp}/tahap',  [\App\Http\Controllers\Pages\BookDistributionController::class, 'moveChapter'])->name('buku.chapter.tahap')->whereNumber('cp');
Route::post('buku/chapter/{cp}/file',   [\App\Http\Controllers\Pages\BookDistributionController::class, 'uploadChapterFile'])->name('buku.chapter.file')->whereNumber('cp');
```

- [ ] **Step 2: Tulis test gagal**

`tests/Feature/BookDistributionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ChapterProgress;
use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd', 'name' => 'n', 'url' => 'http://drive/n.pdf']);
        });
        foreach (['marketing','manager','superadmin','production','admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->seed(\Database\Seeders\AccessMatrixSeeder::class);
    }

    private function user(string $role): User { $u = User::factory()->create(); $u->assignRole($role); return $u; }

    /** Buku 2 bab + progress bab. */
    private function bookWithChapters(string $status = 'editing'): Title
    {
        $title = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title_id' => $title->id, 'title' => $title->title, 'chapters' => 2]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => 'production', 'started_at' => now()]);
        foreach ([1, 2] as $i) {
            $ch = TitleChapter::create(['title_id' => $title->id, 'judul' => 'Bab ' . $i, 'urutan' => $i]);
            ChapterProgress::create(['title_chapter_id' => $ch->id, 'status' => $status, 'started_at' => now()]);
        }
        return $title;
    }

    /** @test */
    public function index_lists_only_book_titles(): void
    {
        $book = $this->bookWithChapters();
        $this->actingAs($this->user('production'))
            ->get(route('distribusi.buku.index'))->assertOk()->assertSee($book->title);
    }

    /** @test */
    public function assign_editor_all_sets_every_chapter(): void
    {
        $book = $this->bookWithChapters();
        $editor = $this->user('production');

        $this->actingAs($this->user('manager'))
            ->post(route('distribusi.buku.editorSemua', $book->id), ['assigned_user_id' => $editor->id])
            ->assertRedirect();

        foreach ($book->chapters as $ch) {
            $this->assertDatabaseHas('tb_chapter_progress', ['title_chapter_id' => $ch->id, 'assigned_user_id' => $editor->id]);
        }
    }

    /** @test */
    public function move_chapter_advances_single_chapter(): void
    {
        $book = $this->bookWithChapters('editing');
        $cp = $book->chapters()->first()->progress;

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.chapter.tahap', $cp->id), ['status' => 'layout'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_chapter_progress', ['id' => $cp->id, 'status' => 'layout']);
    }

    /** @test */
    public function upload_chapter_file_is_versioned_per_chapter(): void
    {
        $book = $this->bookWithChapters();
        $ch = $book->chapters()->first();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.chapter.file', $ch->progress->id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('bab1.pdf', 10),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', [
            'title_id' => $book->id, 'title_chapter_id' => $ch->id, 'slot' => 'masuk', 'version' => 1,
        ]);
    }

    /** @test */
    public function upload_book_level_file_has_null_chapter(): void
    {
        $book = $this->bookWithChapters();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.file', $book->id), [
                'slot' => 'final',
                'file' => UploadedFile::fake()->create('buku.pdf', 10),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', ['title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'final']);
    }

    /** @test */
    public function marketing_cannot_access_book_distribution(): void
    {
        $book = $this->bookWithChapters();
        $this->actingAs($this->user('marketing'))->get(route('distribusi.buku.index'))->assertStatus(403);
    }
}
```

- [ ] **Step 3: Jalankan, pastikan gagal**

Run: `php artisan test --filter=BookDistributionTest`
Expected: FAIL — controller belum ada.

- [ ] **Step 4: Tulis controller**

`app/Http/Controllers/Pages/BookDistributionController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ChapterProgress;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterManuscriptService;
use App\Services\ManuscriptFileService;
use App\Services\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookDistributionController extends Controller
{
    public function __construct(private ChapterManuscriptService $chapters) {}

    public function index()
    {
        $titles = Title::where('jenis', 'buku')
            ->whereHas('orderDetails')
            ->with(['orderDetails.titleProgress'])
            ->orderBy('title')->get();

        return view('distribusi.buku.index', compact('titles'));
    }

    public function show(int $id)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $this->chapters->ensureChapters($title);
        $title->load(['chapters.progress.assignedUser', 'chapters.authors', 'orderDetails.order.user']);

        $editors = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager', 'admin']))
            ->orderBy('name')->get(['id', 'name']);

        $filesFor = function (?int $chapterId) use ($title) {
            $svc = app(ManuscriptFileService::class);
            return ['masuk' => $svc->versions($title, $chapterId, 'masuk'), 'final' => $svc->versions($title, $chapterId, 'final')];
        };

        return view('distribusi.buku.show', compact('title', 'editors', 'filesFor'));
    }

    public function assignEditorAll(Request $request, int $id)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($title, $userId, $request) {
            $this->chapters->assignEditorAll($title, $userId, $request->user());
            $this->notify($title, $request->user(), 'Editor semua bab diperbarui');
        }, 'Editor semua bab diperbarui.');
    }

    public function assignChapterEditor(Request $request, int $cp)
    {
        $progress = ChapterProgress::findOrFail($cp);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($progress, $userId, $request) {
            $this->chapters->assignEditor($progress, $userId, $request->user());
            $this->notifyChapter($progress, $request->user(), 'Editor bab diperbarui');
        }, 'Editor bab diperbarui.');
    }

    public function moveChapter(Request $request, int $cp)
    {
        $progress = ChapterProgress::findOrFail($cp);

        return $this->run($request, function () use ($progress, $request) {
            $this->chapters->changeStatus($progress, (string) $request->input('status'), $request->user(), $request->input('note'));
            $this->notifyChapter($progress, $request->user(), 'Tahap bab diperbarui');
        }, 'Tahap bab diperbarui.');
    }

    public function setPriority(Request $request, int $id, \App\Services\TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $progresses = $this->titleProgresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupPriority($progresses, (string) $request->input('priority'), $request->user());
        }, 'Prioritas diperbarui.');
    }

    public function setTarget(Request $request, int $id, \App\Services\TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $progresses = $this->titleProgresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupTargetDate($progresses, $request->input('target_date'), $request->user());
        }, 'Target diperbarui.');
    }

    public function uploadFile(Request $request, int $id, ManuscriptFileService $files)
    {
        $request->validate(['slot' => 'required|in:masuk,final', 'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480']);
        $title = Title::where('jenis', 'buku')->findOrFail($id);

        return $this->run($request, function () use ($files, $title, $request) {
            $files->upload($title, null, $request->input('slot'), $request->file('file'), $request->user());
            $this->notify($title, $request->user(), 'File buku diunggah');
        }, 'File buku diunggah.');
    }

    public function uploadChapterFile(Request $request, int $cp, ManuscriptFileService $files)
    {
        $request->validate(['slot' => 'required|in:masuk,final', 'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480']);
        $progress = ChapterProgress::with('chapter.title')->findOrFail($cp);
        $chapter = $progress->chapter;

        return $this->run($request, function () use ($files, $chapter, $request, $progress) {
            $files->upload($chapter->title, $chapter, $request->input('slot'), $request->file('file'), $request->user());
            $this->notifyChapter($progress, $request->user(), 'File bab diunggah');
        }, 'File bab diunggah.');
    }

    // ── helpers ──

    private function titleProgresses(Title $title)
    {
        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))->get();
    }

    private function notify(Title $title, User $actor, string $summary): void
    {
        $p = $this->titleProgresses($title)->first();
        if ($p) { app(Notifier::class)->distribusiChanged($p, $actor, $summary); }
    }

    private function notifyChapter(ChapterProgress $progress, User $actor, string $summary): void
    {
        $title = $progress->chapter->title ?? null;
        if ($title) { $this->notify($title, $actor, $summary); }
    }

    private function run(Request $request, \Closure $action, string $success)
    {
        try {
            $action();
            return back()->with('success', $success);
        } catch (AuthorizationException | ValidationException $e) {
            $msg = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');
            return back()->with('error', $msg);
        }
    }
}
```

- [ ] **Step 5: Tulis view index buku**

`resources/views/distribusi/buku/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Distribusi Buku - SiMAPA')
@section('content')
<div class="card">
    <div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi Naskah — Buku</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="tblDistBuku">
                <thead><tr><th>Judul</th><th>Status (bab paling lambat)</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($titles as $t)
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ $t->manuscriptStatusLabel() ?? '—' }}</td>
                        <td><a href="{{ route('distribusi.buku.show', $t->id) }}" class="btn btn-sm btn-outline-primary">Distribusi</a></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">Belum ada buku.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Tulis view show buku**

`resources/views/distribusi/buku/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Distribusi Buku - SiMAPA')
@section('content')
<div class="card mb-3"><div class="card-body">
    <h3 class="mb-1">{{ $title->title }}</h3>
    <span class="badge bg-info">Buku</span>
    <span class="badge bg-secondary">{{ $title->manuscriptStatusLabel() ?? '—' }}</span>
</div></div>

{{-- Pintasan level buku --}}
<div class="card mb-3"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Pintasan Seluruh Buku</h5></div>
<div class="card-body"><div class="row g-3">
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.buku.editorSemua', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Terapkan 1 Editor ke Semua Bab</label>
            <div class="d-flex gap-1">
                <select name="assigned_user_id" class="form-select form-select-sm">
                    <option value="">— Belum —</option>
                    @foreach ($editors as $ed)<option value="{{ $ed->id }}">{{ $ed->name }}</option>@endforeach
                </select>
                <button class="btn btn-sm btn-primary">Terapkan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.buku.target', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Target Terbit</label>
            <div class="d-flex gap-1">
                <input type="date" name="target_date" class="form-control form-control-sm">
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <label class="form-label form-label-sm">File Naskah (level buku)</label>
        @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.buku.file', $title->id), 'files' => $filesFor(null)])
    </div>
</div></div></div>

{{-- Grid per bab --}}
<div class="card"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi per Bab</h5></div>
<div class="card-body">
    @foreach ($title->chapters as $ch)
        @php $cp = $ch->progress; @endphp
        <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>{{ $ch->urutan }}. {{ $ch->judul }}</strong>
                <span class="badge bg-info">{{ \App\Models\Title::stageLabel(optional($cp)->status) }}</span>
            </div>
            <div class="text-muted mb-2" style="font-size:12px">Author: {{ $ch->authors->pluck('name')->join(', ') ?: '—' }}</div>
            @if ($cp)
            <div class="row g-2">
                <div class="col-md-4">
                    <form method="POST" action="{{ route('distribusi.buku.chapter.editor', $cp->id) }}">@csrf
                        <label class="form-label form-label-sm">Editor bab</label>
                        <div class="d-flex gap-1">
                            <select name="assigned_user_id" class="form-select form-select-sm">
                                <option value="">— Belum —</option>
                                @foreach ($editors as $ed)<option value="{{ $ed->id }}" {{ $cp->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-sm btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" action="{{ route('distribusi.buku.chapter.tahap', $cp->id) }}">@csrf
                        <label class="form-label form-label-sm">Tahap bab</label>
                        <div class="d-flex gap-1">
                            <select name="status" class="form-select form-select-sm">
                                @foreach (\App\Models\TitleProgress::BOOK_STAGES as $s)
                                    <option value="{{ $s }}" {{ $s === $cp->status ? 'selected' : '' }}>{{ \App\Models\Title::stageLabel($s) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-primary">Simpan</button>
                        </div>
                        <input type="text" name="note" class="form-control form-control-sm mt-1" placeholder="Catatan (opsional)">
                    </form>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">File bab</label>
                    @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.buku.chapter.file', $cp->id), 'files' => $filesFor($ch->id)])
                </div>
            </div>
            @endif
        </div>
    @endforeach
</div></div>
@endsection
```

- [ ] **Step 7: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=BookDistributionTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/BookDistributionController.php resources/views/distribusi/buku routes/web.php tests/Feature/BookDistributionTest.php
git commit -m "feat(distribusi): halaman & aksi Distribusi Buku (per bab + pintasan)"
```

---

## Task 8: Buang `needs_review` (services + tests lama)

**Files:**
- Modify: `app/Services/TitleProgressService.php` (`applyStatus` ~54-78, `changeGroupStatus` ~266-283, `markReviewed` ~314-328)
- Modify: `app/Services/ChapterManuscriptService.php` (`changeStatus` ~72-83)
- Modify: `tests/Unit/TitleProgressServiceTest.php`, `tests/Feature/ChapterStageJumpTest.php`

- [ ] **Step 1: Hentikan set `needs_review` di `TitleProgressService::applyStatus`**

Ubah array update — hapus baris `needs_review`:

```php
$progress->update([
    'status'        => $target,
    'assigned_role' => TitleProgress::getHandlerForStatus($target),
    'note'          => $note,
    'updated_by'    => $actor->id,
    'started_at'    => now(),
    // baris 'needs_review' => ... DIHAPUS
]);
```

Di `changeStatus` & `changeGroupStatus`, blok `if (...needs_review) naskahNeedsReview` sudah dihapus di Task 4. Tambahkan panggilan `distribusiChanged` untuk grup di `changeGroupStatus` (setelah loop `foreach ($changed ...)`):

```php
foreach ($changed as [$p, $from]) {
    app(Notifier::class)->naskahStageChanged($p, $actor, $from, $target);
}
if (! empty($changed)) {
    app(Notifier::class)->distribusiChanged($changed[0][0], $actor, 'Tahap naskah diperbarui');
}
```

Hapus method `markReviewed(...)` seluruhnya (aksi tinjau dihentikan).

- [ ] **Step 2: Hentikan set `needs_review` di `ChapterManuscriptService::changeStatus`**

Hapus baris `'needs_review' => $isCorrection && ! $actor->hasRole('superadmin'),` di array `$cp->update([...])`.

- [ ] **Step 3: Perbaiki test unit yang meng-assert `needs_review`**

Di `tests/Unit/TitleProgressServiceTest.php`, hapus/ubah test yang meng-assert `needs_review = true` setelah lompat. Cari string `needs_review`. Untuk test lompat, ganti asersinya menjadi memverifikasi log koreksi tercatat, mis.:

```php
// ganti assert 'needs_review' => true dengan:
$this->assertDatabaseHas('tb_title_progress_logs', [
    'title_progress_id' => $progress->id, 'is_correction' => true,
]);
```

Hapus test yang khusus menguji `markReviewed` (method sudah dihapus).

- [ ] **Step 4: Perbaiki `ChapterStageJumpTest`**

Cari `needs_review` di `tests/Feature/ChapterStageJumpTest.php`; ganti asersi flag menjadi asersi log `is_correction` seperti Step 3, atau hapus asersi flag bila test tetap valid tanpanya. (Catatan: route bab masih `chapter.advance` sampai Task 9 — jangan ubah route di sini.)

- [ ] **Step 5: Jalankan test terkait**

Run: `php artisan test --filter=TitleProgressServiceTest`
Run: `php artisan test --filter=ChapterStageJumpTest`
Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: PASS (setelah penyesuaian).

- [ ] **Step 6: Commit**

```bash
git add app/Services/TitleProgressService.php app/Services/ChapterManuscriptService.php tests/Unit/TitleProgressServiceTest.php tests/Feature/ChapterStageJumpTest.php
git commit -m "refactor(distribusi): matikan alur needs_review; log koreksi tetap"
```

---

## Task 9: Papan Pelacakan jadi read-only + susutkan controller/route/izin

**Files:**
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`
- Modify: `routes/web.php`
- Modify: `config/permissions.php`
- Modify: `resources/views/manuscript/partials/card.blade.php`, `board.blade.php`, `list.blade.php`, `partials/toolbar.blade.php`
- Delete: `app/Http/Controllers/Pages/ChapterProgressController.php`
- Modify: `tests/Feature/ManuscriptTrackerTest.php`, `tests/Feature/ChapterBoardTest.php`

- [ ] **Step 1: Susutkan `ManuscriptTrackerController`**

Hapus method mutasi: `move`, `assign`, `priority`, `reviewed`, `target`, `clearLog`, dan helper `runOrFlash`, `groupFor`. Sisakan: `index`, `buildGroupCards`, `buildZones`. Di `index`, hapus perhitungan `$reviewCount` (kolom needs_review tak dipakai) dan variabel `review` di filter (hapus blok `->when($request->boolean('review') ...)`). Hapus `$reviewCount` dari `compact(...)`.

- [ ] **Step 2: Hapus route mutasi papan + chapter di `routes/web.php`**

Hapus baris route: `manuscript.move`, `manuscript.assign`, `manuscript.priority`, `manuscript.reviewed`, `manuscript.target`, `manuscript.clearLog`, `chapter.advance`, `chapter.assign`, dan `title.progress.update` (perpindahan tahap kini via Distribusi). Sisakan `manuscript.board`, `title.progress.logs`, `order.indexJudul.detail`.

- [ ] **Step 3: Hapus `ChapterProgressController`**

Run: `git rm app/Http/Controllers/Pages/ChapterProgressController.php`
Dan hapus `use App\Http\Controllers\Pages\ChapterProgressController;` bila ada di `routes/web.php`.

- [ ] **Step 4: Susutkan modul `manuscript` di `config/permissions.php`**

Ganti blok modul `manuscript` menjadi hanya `view` + `detail`, dan hapus modul `chapter`:

```php
'manuscript' => [
    'label'   => 'Papan Manuskrip',
    'actions' => [
        'view'   => ['manuscript.board'],
        'detail' => ['order.indexJudul.detail', 'title.progress.logs'],
    ],
],
// modul 'chapter' => [...] DIHAPUS seluruhnya
```

Di `AccessMatrixSeeder`, hapus referensi eksplisit yang kini tak ada (`'manuscript.move'`, `'manuscript.assign'`, `'manuscript.priority'`, `'manuscript.target'`, `'chapter.*'`, `'manuscript.clear-log'` di `superadminOnly`). `expand()` mengabaikan nama yang tak ada, tapi rapikan agar jelas. Pastikan `production` tetap punya `'manuscript.view'`, `'manuscript.detail'`.

- [ ] **Step 5: Jadikan `card.blade.php` read-only**

Ganti seluruh isi `resources/views/manuscript/partials/card.blade.php` dengan versi tanpa form/aksi:

```blade
{{-- resources/views/manuscript/partials/card.blade.php — READ ONLY --}}
@php
    $p          = $detail->titleProgress;
    $primary    = $detail->authors->sortBy('pivot.position')->first();
    $service    = optional($detail->scopes->first())->name ?? strtoupper($detail->type);
    $orderCount = $detail->group_order_count ?? 1;
    $isBook     = in_array($detail->type, ['bk_mandiri', 'bk_kolab'], true);
    $targetWord = $isBook ? 'terbit' : 'publish';
    $overdue    = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['terbit', 'publish'], true);
    $chapters   = $isBook ? (optional($detail->titleRef)->chapters ?? collect()) : collect();
@endphp
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-primary fw-bold" style="font-size:11px">{{ $detail->order->code_order ?? '—' }}</span>
            <div class="d-flex gap-1">
                @if(\App\Models\TitleProgress::isFinal($p->status))<span class="badge bg-success">🔒 Final</span>@endif
                @if($orderCount > 1)<span class="badge bg-secondary">{{ $orderCount }} order</span>@endif
                @if($p->priority === 'high')<span class="badge bg-danger">High</span>@endif
            </div>
        </div>

        <a href="{{ route('order.indexJudul.detail', $detail->id) }}"
           class="d-block fw-semibold text-dark text-decoration-none mt-1" style="font-size:13px; line-height:1.3">
            {{ Str::limit($detail->title, 60) }}
        </a>

        <div class="d-flex align-items-center gap-2 mt-2">
            <span class="badge bg-light text-secondary">{{ strtoupper(Str::substr(optional($primary)->name ?? '?', 0, 1)) }}</span>
            <div class="flex-grow-1" style="min-width:0">
                <div class="text-truncate" style="font-size:11px">{{ optional($primary)->name ?? '—' }}</div>
                <div class="text-muted text-truncate" style="font-size:10px">{{ optional($primary)->affiliation ?? '' }}</div>
            </div>
        </div>

        <div class="mt-1" style="font-size:10px">
            @if($p->target_date)
                <span class="badge {{ $overdue ? 'bg-danger' : 'bg-light text-dark border' }}">🎯 {{ $targetWord }}: {{ $p->target_date->format('d M Y') }}{{ $overdue ? ' · lewat!' : '' }}</span>
            @else
                <span class="text-muted">🎯 {{ $targetWord }}: —</span>
            @endif
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="badge bg-info">{{ Str::limit($service, 18) }}</span>
            <small class="text-muted">Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong></small>
        </div>

        @if($isBook && $chapters->isNotEmpty())
            <div class="mt-2 pt-2 border-top">
                <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#chapters-{{ $p->id }}" style="font-size:11px">📖 Bab ({{ $chapters->count() }})</button>
                <div class="collapse mt-1" id="chapters-{{ $p->id }}">
                    @foreach($chapters as $ch)
                        @php $cp = $ch->progress; $cstatus = optional($cp)->status ?? 'menunggu_proses'; @endphp
                        <div class="border rounded p-2 mb-1" style="font-size:11px">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold text-truncate" style="max-width:150px">{{ $ch->urutan }}. {{ $ch->judul }}</span>
                                <span class="badge {{ in_array($cstatus, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ \App\Models\Title::stageLabel($cstatus) }}</span>
                            </div>
                            <div class="text-muted mt-1">Editor: {{ optional(optional($cp)->assignedUser)->name ?? 'Belum' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 6: Bersihkan `toolbar.blade.php`, `board.blade.php`, `list.blade.php`**

- Di `toolbar.blade.php`: hapus filter/tautan `review` (mis. tombol/param `review=1`) dan referensi `$reviewCount`. Pertahankan filter tipe/scope/editor/priority/view.
- Di `board.blade.php` & `list.blade.php`: hapus atribut drag & handler JS yang mem-POST ke `manuscript.move`/`assign` (jika ada `<script>` inline yang memanggil route tersebut, hapus). Pertahankan render kolom/kartu. Hapus penggunaan variabel `$reviewCount` bila ada.

> Cari cepat: `grep -rn "manuscript.move\|manuscript.assign\|manuscript.priority\|manuscript.target\|manuscript.reviewed\|manuscript.clearLog\|reviewCount\|chapter.advance\|chapter.assign\|data-chapter" resources/views/manuscript` dan bersihkan semua kemunculannya.

- [ ] **Step 7: Perbaiki `ManuscriptTrackerTest`**

Di `tests/Feature/ManuscriptTrackerTest.php`, **hapus** test yang menguji endpoint mutasi & tinjau yang sudah tiada:
`production_moves_card_via_ajax`, `rejected_move_keeps_status`, `assign_endpoint_sets_editor`, `priority_endpoint_sets_priority`, `marketing_cannot_use_move_endpoint`, `rejected_web_move_redirects_back_with_flash_error`, `group_move_advances_all_orders_of_the_title`, `group_assign_sets_editor_on_all_orders`, `production_jump_with_note_sets_review_flag`, `production_jump_without_note_is_rejected`, `board_shows_review_badge_when_flagged`, `review_filter_shows_only_flagged_titles`, `manager_can_mark_group_reviewed`, `production_cannot_mark_reviewed`, `target_date_endpoint_sets_all_orders_of_the_title`, `marketing_can_set_target_date`, `superadmin_can_clear_log_manager_cannot`, `title_detail_shows_activity_log_and_clear_button_for_superadmin` (route progress dipensiunkan — lihat Task 10).

**Pertahankan** test tampilan papan: `board_renders_for_production`, `marketing_cannot_access_board`, `guest_is_redirected_from_board`, `list_view_renders`, `priority_filter_narrows_results`, `board_card_shows_author_editor_priority_and_next_action` (hapus baris `assertDontSee('Majukan ke Layout')` karena tombol sudah tak ada — atau ganti jadi `assertDontSee('Majukan')`), `board_groups_columns_into_role_zones`, `board_shows_one_card_per_title_group`, `log_view_lists_activity_in_a_table`, `board_card_shows_target_date`.

Tambahkan satu test baru: papan tak lagi memuat form aksi:

```php
/** @test */
public function board_is_read_only_no_action_forms(): void
{
    $p = $this->progress('editing');
    $this->actingAs($this->user('production'));
    $html = $this->get(route('manuscript.board', ['tipe' => 'buku']))->assertOk()->getContent();
    $this->assertStringNotContainsString('manuscript.move', $html);
    $this->assertStringNotContainsString('Ambil naskah ini', $html);
}
```

- [ ] **Step 8: Perbaiki `ChapterBoardTest`**

`tests/Feature/ChapterBoardTest.php` menguji `chapter.advance`/`chapter.assign` (dihapus). Pindahkan asersinya ke route baru `distribusi.buku.chapter.tahap`/`distribusi.buku.chapter.editor`, atau bila cakupannya sudah diliput `BookDistributionTest`, hapus test yang redundan. Minimal: ganti pemanggilan route lama → route distribusi baru dan pastikan lulus.

- [ ] **Step 9: Jalankan test terkait**

Run: `php artisan test --filter=ManuscriptTrackerTest`
Run: `php artisan test --filter=ChapterBoardTest`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php resources/views/manuscript tests/Feature/ManuscriptTrackerTest.php tests/Feature/ChapterBoardTest.php
git rm app/Http/Controllers/Pages/ChapterProgressController.php
git commit -m "refactor(distribusi): papan Pelacakan jadi read-only; susutkan route/izin manuscript"
```

---

## Task 10: Pensiunkan `progressDetail` + view lama

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php` (hapus `progressDetail` ~88-118)
- Modify: `routes/web.php` (hapus `order.indexJudul.progress`)
- Delete: `resources/views/orders/detail-title.blade.php`
- Modify: tests yang memakai `order.indexJudul.progress`

- [ ] **Step 1: Cari pemakaian route progress**

Run: `grep -rn "indexJudul.progress\|order.indexJudul.progress\|detail-title')" app resources tests routes`
Catat semua kemunculan (link blade & test).

- [ ] **Step 2: Hapus method + route + view**

- Hapus method `progressDetail` di `OrderBookController`.
- Hapus route `Route::get('title/order/{id}', ...)->name('indexJudul.progress')` di `routes/web.php`.
- Run: `git rm resources/views/orders/detail-title.blade.php`
- Di `config/permissions.php`, pastikan `manuscript.detail` tidak lagi menyebut `order.indexJudul.progress` (sudah dibersihkan di Task 9 Step 4 — verifikasi).

- [ ] **Step 3: Alihkan link di `detail-title-group.blade.php`**

Di `resources/views/orders/detail-title-group.blade.php`, tombol "Progress" menaut `order.indexJudul.progress`. Ganti: untuk tipe buku → `route('distribusi.buku.show', $detail->title_id)`, artikel → `route('distribusi.artikel.show', $detail->title_id)`; tampilkan hanya bila `@can('distribution.view')` dan `$detail->title_id` ada. Bila tidak, sembunyikan tombol. Contoh:

```blade
@can('distribution.view')
    @if($detail->title_id)
        @php $distRoute = in_array($detail->type, ['bk_mandiri','bk_kolab'], true)
            ? route('distribusi.buku.show', $detail->title_id)
            : route('distribusi.artikel.show', $detail->title_id); @endphp
        <a href="{{ $distRoute }}" class="btn btn-sm btn-outline-primary">Distribusi</a>
    @endif
@endcan
```

- [ ] **Step 4: Perbaiki test yang memakai route progress**

Untuk tiap test dari Step 1 (mis. di `ManuscriptTrackerTest` sudah dihapus di Task 9; periksa `ProductionWorkspaceTest`, `TitleProgressTest`, `MarketingAccessTest`, `ArchiveGroupedTitlesTest`, `ManuscriptFinalizeTest`, `MarketingDashboardTest`): ganti pemanggilan `route('order.indexJudul.progress', ...)` menjadi `route('order.indexJudul.detail', ...)` (halaman read-only grup) atau hapus asersi yang khusus menguji "Kontrol Naskah"/form update di halaman lama. Jalankan tiap file setelah diubah.

- [ ] **Step 5: Jalankan test terdampak**

Run: `php artisan test --filter=ProductionWorkspaceTest`
Run: `php artisan test --filter=TitleProgressTest`
Run: `php artisan test --filter=MarketingAccessTest`
Run: `php artisan test --filter=ManuscriptFinalizeTest`
Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Run: `php artisan test --filter=MarketingDashboardTest`
Expected: PASS (setelah penyesuaian).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php routes/web.php resources/views/orders/detail-title-group.blade.php tests
git rm resources/views/orders/detail-title.blade.php
git commit -m "refactor(distribusi): pensiunkan progressDetail; alihkan ke halaman Distribusi"
```

---

## Task 11: Menu sidebar Distribusi + verifikasi visibilitas

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/SidebarTest.php` (tambah)

- [ ] **Step 1: Tulis test gagal**

Tambahkan ke `tests/Feature/SidebarTest.php` (ikuti pola file — cek helper user/role di dalamnya):

```php
/** @test */
public function production_sees_distribution_menus(): void
{
    $u = User::factory()->create(); $u->assignRole('production');
    $this->actingAs($u)->get(route('dashboard'))
        ->assertSee('Distribusi Artikel')
        ->assertSee('Distribusi Buku');
}

/** @test */
public function marketing_does_not_see_distribution_menus(): void
{
    $u = User::factory()->create(); $u->assignRole('marketing');
    $this->actingAs($u)->get(route('dashboard'))
        ->assertDontSee('Distribusi Artikel');
}
```

> Pastikan `setUp()` file ini men-seed `AccessMatrixSeeder` (banyak test sidebar sudah). Jika belum, tambah `$this->seed(\Database\Seeders\AccessMatrixSeeder::class);` + buat role.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=SidebarTest`
Expected: FAIL — menu belum ada.

- [ ] **Step 3: Tambah menu di section "Produksi" `sidebar.blade.php`**

Sisipkan setelah item papan manuskrip (`@can('manuscript.view') ... @endcan`):

```blade
@can('distribution.view')
    <li class="nav-item {{ nav_active('distribusi.artikel.*') }}">
        <a href="{{ route('distribusi.artikel.index') }}" class="nav-link">
            <i class="link-icon" data-feather="file-text"></i>
            <span class="link-title">Distribusi Artikel</span>
        </a>
    </li>
    <li class="nav-item {{ nav_active('distribusi.buku.*') }}">
        <a href="{{ route('distribusi.buku.index') }}" class="nav-link">
            <i class="link-icon" data-feather="book"></i>
            <span class="link-title">Distribusi Buku</span>
        </a>
    </li>
@endcan
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=SidebarTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php tests/Feature/SidebarTest.php
git commit -m "feat(distribusi): menu sidebar Distribusi Artikel & Buku"
```

---

## Task 12: Regresi penuh + seed dev

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua. Bila ada sisa kegagalan yang menyebut `needs_review`, `manuscript.move/assign/priority/reviewed/target/clearLog`, `chapter.advance/assign`, atau `order.indexJudul.progress`, perbaiki file test terkait dengan pola dari Task 8-10 (ganti ke route/halaman baru atau hapus asersi usang), lalu jalankan ulang.

- [ ] **Step 2: Re-seed matriks + migrate di DB dev**

Run: `php artisan migrate`
Run: `php artisan db:seed --class=AccessMatrixSeeder`
Expected: sukses. Login sebagai admin & production → menu Distribusi Artikel/Buku tampil; papan Pelacakan read-only; marketing tak melihat menu Distribusi.

- [ ] **Step 3: Commit (bila ada perbaikan test sisa)**

```bash
git add tests
git commit -m "test(distribusi): rapikan regresi suite untuk alur distribusi baru"
```

---

## Self-Review (sudah dijalankan penulis plan)

- **Cakupan spec:** §1 peta halaman → Task 6,7,9,10,11 · §2 peran/izin/approval → Task 3,5,8 · §2.4 notifikasi → Task 4,8 · §3 model artikel/buku → Task 6,7 · §4 file 2-slot versi → Task 1,2,6,7 · §5 struktur/pensiun → Task 9,10 · §8 testing → tersebar · §9 ops → Task 1,5,12. Semua tercakup.
- **Placeholder:** tak ada TBD/TODO; setiap step berkode nyata atau instruksi konkret (nama method/route yang dihapus disebut eksplisit).
- **Konsistensi tipe:** `ManuscriptFile::SLOTS`, `ManuscriptFileService::upload/latest/versions`, `Notifier::distribusiChanged`, `ChapterManuscriptService::assignEditorAll`, nama route `distribusi.*` konsisten di controller, view, test, dan peta izin.
- **Catatan realistis:** beberapa Task edit test lama mengandalkan pembacaan file untuk nama helper `setUp()` (mis. `makeProgress`); ini disebut eksplisit sebagai langkah, bukan asumsi tersembunyi.
