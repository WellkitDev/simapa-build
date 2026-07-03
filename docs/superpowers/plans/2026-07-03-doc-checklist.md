# Cek Kelengkapan Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kartu "Cek Kelengkapan Data" di detail judul buku: checklist dokumen ISBN & HKI dari template global (superadmin CRUD), admin menandai status + unggah file (Google Drive) + catatan, lalu Submit (catat saja), dengan progress per kategori.

**Architecture:** 3 tabel — `tb_doc_requirements` (template global, di-seed), `tb_title_doc_marks` (penanda per buku×item), `tb_title_doc_checklists` (header submit per buku). `DocChecklistService` memusatkan progress/saveMarks(+upload)/submit. `DocRequirementController` (superadmin) kelola template; `TitleDocCheckController` (superadmin|admin) simpan+submit. Kartu di `titles/show.blade.php`.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5, GoogleDriveService (upload), Spatie roles. Test: PHPUnit feature/unit via `.env.testing`, `GoogleDriveService` di-mock, `UploadedFile::fake()`.

---

## File Structure

- `database/migrations/2026_07_03_000004_create_tb_doc_requirements_table.php` (**create**) — template + seed 9 item.
- `database/migrations/2026_07_03_000005_create_tb_title_doc_marks_table.php` (**create**) — penanda per buku×item.
- `database/migrations/2026_07_03_000006_create_tb_title_doc_checklists_table.php` (**create**) — header submit.
- `app/Models/DocRequirement.php`, `app/Models/TitleDocMark.php`, `app/Models/TitleDocChecklist.php` (**create**).
- `app/Models/Title.php` (**modify**) — `docMarks()` + `docChecklist()`.
- `app/Services/DocChecklistService.php` (**create**) — progress/saveMarks/submit.
- `app/Http/Controllers/Pages/DocRequirementController.php`, `app/Http/Controllers/Pages/TitleDocCheckController.php` (**create**).
- `routes/web.php` (**modify**) — rute `doc-req.*` + `title.doc.*` + import.
- `app/Http/Controllers/Pages/TitleController.php` (**modify**) — `show()` eager-load + vars.
- `resources/views/titles/show.blade.php` (**modify**) — kartu "Cek Kelengkapan Data".
- `tests/Unit/DocChecklistServiceTest.php`, `tests/Feature/DocChecklistTest.php` (**create**).

---

## Konteks untuk implementer

- **GoogleDriveService::uploadFile($file, ?folderId=null, bool $makePublic=true): ?array** → `['id','name','url']` atau null. Pola: `$this->drive->uploadFile($file, null, false)['url'] ?? null`.
- Migrasi terakhir: `2026_07_03_000003_create_tb_book_isbns_table`. Nomor baru 000004/000005/000006.
- `TitleController@show` sudah eager-load `[... , 'bookIsbn']` dan mengirim `canViewInfo` (superadmin/manager/admin/production), `canManageIsbn`, dll. Kartu baru disisipkan SEBELUM `@endsection` (setelah kartu Registrasi ISBN).
- Test role setup pola: `foreach (['marketing','manager','superadmin','production','admin'] as $r) Role::create(['name'=>$r,'guard_name'=>'web']);` + `$u->assignRole($role)`. `$this->mock(GoogleDriveService::class)` (untuk yang tak butuh return) atau dgn closure Mockery untuk upload.

---

### Task 1: Migrasi (3) + seed + Model + relasi Title

**Files:**
- Create: 3 migrasi; `app/Models/DocRequirement.php`, `TitleDocMark.php`, `TitleDocChecklist.php`
- Modify: `app/Models/Title.php`

- [ ] **Step 1: Migrasi `2026_07_03_000004_create_tb_doc_requirements_table.php` (+ seed)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_doc_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // penerbit | hki
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $items = [
            ['penerbit', 'Naskah Lengkap (Final Draft)', 'Buku sudah melalui penyuntingan dan siap cetak.', 1],
            ['penerbit', 'Surat Pernyataan Keaslian Karya', 'Menyatakan naskah orisinal dan bukan plagiasi.', 2],
            ['penerbit', 'Kelengkapan Naskah Awal', 'Halaman judul, balik halaman judul, kata pengantar, dan daftar isi.', 3],
            ['penerbit', 'Sinopsis/Ringkasan Buku', 'Penjelasan singkat isi buku untuk keperluan katalog.', 4],
            ['penerbit', 'Data Penulis', 'NIK serta biodata singkat penulis.', 5],
            ['hki', 'Surat Pernyataan Kepemilikan', 'Template terisi, bermaterai, ditandatangani semua pencipta.', 1],
            ['hki', 'Contoh Ciptaan', 'File naskah buku lengkap dalam format PDF.', 2],
            ['hki', 'Identitas Pemohon', 'Scan KTP atau Paspor semua penulis/pencipta.', 3],
            ['hki', 'Surat Pengalihan Hak', 'Bila pemegang hak cipta bukan pencipta asli (mis. dialihkan ke penerbit/kampus).', 4],
        ];
        $rows = [];
        foreach ($items as [$cat, $label, $desc, $pos]) {
            $rows[] = ['category' => $cat, 'label' => $label, 'description' => $desc, 'position' => $pos, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tb_doc_requirements')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_doc_requirements');
    }
};
```

- [ ] **Step 2: Migrasi `2026_07_03_000005_create_tb_title_doc_marks_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_doc_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->foreignId('doc_requirement_id')->constrained('tb_doc_requirements')->cascadeOnDelete();
            $table->string('status')->default('belum'); // ada | belum | tidak_perlu
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['title_id', 'doc_requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_doc_marks');
    }
};
```

- [ ] **Step 3: Migrasi `2026_07_03_000006_create_tb_title_doc_checklists_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_doc_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft | diajukan
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_doc_checklists');
    }
};
```

- [ ] **Step 4: Model `app/Models/DocRequirement.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocRequirement extends Model
{
    protected $table = 'tb_doc_requirements';

    protected $fillable = ['category', 'label', 'description', 'position', 'active'];

    protected $casts = ['active' => 'boolean'];

    const CATEGORIES = [
        'penerbit' => 'Dokumen Penerbit (ISBN)',
        'hki'      => 'Dokumen HKI (Hak Cipta)',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function marks()
    {
        return $this->hasMany(TitleDocMark::class);
    }
}
```

- [ ] **Step 5: Model `app/Models/TitleDocMark.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleDocMark extends Model
{
    protected $table = 'tb_title_doc_marks';

    protected $fillable = ['title_id', 'doc_requirement_id', 'status', 'file_url', 'file_name', 'catatan', 'updated_by'];

    const STATUSES = ['ada' => 'Ada', 'belum' => 'Belum', 'tidak_perlu' => 'Tidak perlu'];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function requirement()
    {
        return $this->belongsTo(DocRequirement::class, 'doc_requirement_id');
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
```

- [ ] **Step 6: Model `app/Models/TitleDocChecklist.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleDocChecklist extends Model
{
    protected $table = 'tb_title_doc_checklists';

    protected $fillable = ['title_id', 'status', 'submitted_at', 'submitted_by'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
```

- [ ] **Step 7: Relasi di `app/Models/Title.php`** — setelah method `bookIsbn()` (yang ditambah fitur ISBN), tambahkan:

```php
    public function docMarks()
    {
        return $this->hasMany(TitleDocMark::class);
    }

    public function docChecklist()
    {
        return $this->hasOne(TitleDocChecklist::class);
    }
```

- [ ] **Step 8: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: 3 migrasi `... DONE`.

```bash
git add database/migrations/2026_07_03_000004_create_tb_doc_requirements_table.php database/migrations/2026_07_03_000005_create_tb_title_doc_marks_table.php database/migrations/2026_07_03_000006_create_tb_title_doc_checklists_table.php app/Models/DocRequirement.php app/Models/TitleDocMark.php app/Models/TitleDocChecklist.php app/Models/Title.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(doc-checklist): tabel template/penanda/header + seed 9 item + model & relasi

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: DocChecklistService + unit test

**Files:**
- Create: `app/Services/DocChecklistService.php`, `tests/Unit/DocChecklistServiceTest.php`

- [ ] **Step 1: Tulis unit test (gagal dulu)**

`tests/Unit/DocChecklistServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\DocRequirement;
use App\Models\TitleDocMark;
use App\Models\TitleDocChecklist;
use App\Services\DocChecklistService;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class DocChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Doc ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    private function service(): DocChecklistService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        return new DocChecklistService($drive);
    }

    /** @test */
    public function progress_counts_ada_over_active_total_per_category(): void
    {
        $book = $this->book();
        $penerbit = DocRequirement::where('category', 'penerbit')->orderBy('position')->get();
        // tandai 2 item penerbit 'ada'
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[0]->id, 'status' => 'ada']);
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[1]->id, 'status' => 'ada']);

        $prog = $this->service()->progress($book, 'penerbit');
        $this->assertSame(5, $prog['total']); // 5 item penerbit ter-seed
        $this->assertSame(2, $prog['done']);
    }

    /** @test */
    public function save_marks_upserts_status_and_note(): void
    {
        $book = $this->book();
        $rid = DocRequirement::where('category', 'penerbit')->first()->id;
        $actor = User::factory()->create();

        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'ada', 'catatan' => 'lengkap', 'file' => null],
        ], $actor);

        $mark = TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('ada', $mark->status);
        $this->assertSame('lengkap', $mark->catatan);
        $this->assertSame($actor->id, $mark->updated_by);
    }

    /** @test */
    public function save_marks_uploads_file_then_preserves_on_next_save_without_file(): void
    {
        $book = $this->book();
        $rid = DocRequirement::where('category', 'hki')->first()->id;
        $actor = User::factory()->create();

        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'ada', 'catatan' => null, 'file' => UploadedFile::fake()->create('ktp.pdf', 10)],
        ], $actor);
        $mark = TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('http://drive/f.pdf', $mark->file_url);
        $this->assertSame('ktp.pdf', $mark->file_name);

        // simpan lagi tanpa file → file_url dipertahankan
        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'belum', 'catatan' => null, 'file' => null],
        ], $actor);
        $this->assertSame('http://drive/f.pdf', $mark->fresh()->file_url);
    }

    /** @test */
    public function submit_sets_status_diajukan(): void
    {
        $book = $this->book();
        $actor = User::factory()->create();
        $this->service()->submit($book, $actor);
        $cl = TitleDocChecklist::where('title_id', $book->id)->first();
        $this->assertSame('diajukan', $cl->status);
        $this->assertSame($actor->id, $cl->submitted_by);
        $this->assertNotNull($cl->submitted_at);
    }
}
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (service belum ada)**

Run: `php artisan test --env=testing tests/Unit/DocChecklistServiceTest.php`
Expected: FAIL — class `App\Services\DocChecklistService` tidak ada.

- [ ] **Step 3: Buat `app/Services/DocChecklistService.php`**

```php
<?php

namespace App\Services;

use App\Models\DocRequirement;
use App\Models\Title;
use App\Models\TitleDocChecklist;
use App\Models\TitleDocMark;
use App\Models\User;

class DocChecklistService
{
    public function __construct(private GoogleDriveService $drive) {}

    /** @param array $items list of ['requirement_id'=>int,'status'=>string,'catatan'=>?string,'file'=>?\Illuminate\Http\UploadedFile] */
    public function saveMarks(Title $title, array $items, User $actor): void
    {
        $activeIds = DocRequirement::active()->pluck('id')->all();

        foreach ($items as $item) {
            $rid = (int) ($item['requirement_id'] ?? 0);
            if (! in_array($rid, $activeIds, true)) {
                continue;
            }
            $status = in_array($item['status'] ?? '', array_keys(TitleDocMark::STATUSES), true) ? $item['status'] : 'belum';
            $attrs = [
                'status'     => $status,
                'catatan'    => $item['catatan'] ?? null,
                'updated_by' => $actor->id,
            ];
            $file = $item['file'] ?? null;
            if ($file) {
                $attrs['file_url']  = $this->drive->uploadFile($file, null, false)['url'] ?? null;
                $attrs['file_name'] = $file->getClientOriginalName();
            }
            // Tanpa file baru: file_url/file_name tak diikutkan → nilai lama dipertahankan.
            TitleDocMark::updateOrCreate(
                ['title_id' => $title->id, 'doc_requirement_id' => $rid],
                $attrs
            );
        }
    }

    public function submit(Title $title, User $actor): TitleDocChecklist
    {
        return TitleDocChecklist::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'diajukan', 'submitted_at' => now(), 'submitted_by' => $actor->id]
        );
    }

    /** @return array{done:int,total:int} */
    public function progress(Title $title, string $category): array
    {
        $reqIds = DocRequirement::active()->where('category', $category)->pluck('id');
        $done = TitleDocMark::where('title_id', $title->id)
            ->whereIn('doc_requirement_id', $reqIds)
            ->where('status', 'ada')
            ->count();

        return ['done' => $done, 'total' => $reqIds->count()];
    }
}
```

- [ ] **Step 4: Jalankan — diharapkan PASS**

Run: `php artisan test --env=testing tests/Unit/DocChecklistServiceTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DocChecklistService.php tests/Unit/DocChecklistServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(doc-checklist): DocChecklistService (progress/saveMarks+upload/submit)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: DocRequirementController (template CRUD) + rute + test

**Files:**
- Create: `app/Http/Controllers/Pages/DocRequirementController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/DocChecklistTest.php`

- [ ] **Step 1: Tulis feature test (bagian template) — gagal dulu**

`tests/Feature/DocChecklistTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\DocRequirement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DocChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Doc ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function superadmin_crud_requirement(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'Item Baru'])->assertRedirect();
        $req = DocRequirement::where('label', 'Item Baru')->first();
        $this->assertNotNull($req);

        $this->actingAs($sa)->put(route('doc-req.update', $req->id), ['category' => 'penerbit', 'label' => 'Item Ubah'])->assertRedirect();
        $this->assertSame('Item Ubah', $req->fresh()->label);

        $this->actingAs($sa)->delete(route('doc-req.destroy', $req->id))->assertRedirect();
        $this->assertNull(DocRequirement::find($req->id));
    }

    /** @test */
    public function non_superadmin_cannot_crud_template(): void
    {
        $this->actingAs($this->user('admin'))->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'X'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (route belum ada)**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php`
Expected: FAIL — `Route [doc-req.store] not defined`.

- [ ] **Step 3: Buat `app/Http/Controllers/Pages/DocRequirementController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\DocRequirement;
use Illuminate\Http\Request;

class DocRequirementController extends Controller
{
    private function data(Request $request): array
    {
        return $request->validate([
            'category'    => 'required|in:penerbit,hki',
            'label'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'position'    => 'nullable|integer',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['active'] = true;
        DocRequirement::create($data);

        return back()->with('success', 'Item checklist ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $req = DocRequirement::findOrFail($id);
        $data = $this->data($request);
        $data['active'] = $request->boolean('active', true);
        $req->update($data);

        return back()->with('success', 'Item checklist diperbarui.');
    }

    public function destroy(int $id)
    {
        DocRequirement::findOrFail($id)->delete();

        return back()->with('success', 'Item checklist dihapus.');
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`**

Import (dekat baris `use App\Http\Controllers\Pages\BookIsbnController;`):

```php
use App\Http\Controllers\Pages\DocRequirementController;
use App\Http\Controllers\Pages\TitleDocCheckController;
```

Sisipkan SETELAH blok rute `isbn.*` (sekitar baris 276, masih dalam grup auth):

```php
    // Template checklist dokumen — CRUD superadmin
    Route::middleware('role:superadmin')->group(function () {
        Route::post('doc-requirements', [DocRequirementController::class, 'store'])->name('doc-req.store');
        Route::put('doc-requirements/{id}', [DocRequirementController::class, 'update'])->name('doc-req.update')->whereNumber('id');
        Route::delete('doc-requirements/{id}', [DocRequirementController::class, 'destroy'])->name('doc-req.destroy')->whereNumber('id');
    });
    // Cek kelengkapan dokumen per judul — superadmin/admin
    Route::middleware('role:superadmin|admin')->group(function () {
        Route::put('titles/{id}/doc-check', [TitleDocCheckController::class, 'save'])->name('title.doc.save')->whereNumber('id');
        Route::post('titles/{id}/doc-check/submit', [TitleDocCheckController::class, 'submit'])->name('title.doc.submit')->whereNumber('id');
    });
```

> Rute `title.doc.*` menunjuk `TitleDocCheckController` yang dibuat Task 4. Karena file belum ada saat Task 3, **buat stub kosong dulu** agar route file bisa dikompilasi:

Create `app/Http/Controllers/Pages/TitleDocCheckController.php` (stub minimal, dilengkapi Task 4):

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;

class TitleDocCheckController extends Controller
{
    public function save(int $id) {}
    public function submit(int $id) {}
}
```

- [ ] **Step 5: Jalankan test template — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php`
Expected: 2 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/DocRequirementController.php app/Http/Controllers/Pages/TitleDocCheckController.php routes/web.php tests/Feature/DocChecklistTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(doc-checklist): DocRequirementController CRUD template + rute doc-req/title.doc

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: TitleDocCheckController (save + submit) + test

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleDocCheckController.php` (lengkapi stub)
- Modify: `tests/Feature/DocChecklistTest.php` (tambah test)

- [ ] **Step 1: Tambah test (gagal dulu)**

Tambahkan di `DocChecklistTest` (sebelum kurung tutup kelas):

```php
    /** @test */
    public function admin_saves_marks_and_uploads(): void
    {
        // mock drive kembalikan url
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        });

        $book = $this->book();
        $rid = DocRequirement::where('category', 'penerbit')->first()->id;

        $this->actingAs($this->user('admin'))->put(route('title.doc.save', $book->id), [
            'marks' => [
                $rid => ['status' => 'ada', 'catatan' => 'ok', 'file' => \Illuminate\Http\UploadedFile::fake()->create('naskah.pdf', 20)],
            ],
        ])->assertRedirect(route('title.show', $book->id));

        $mark = \App\Models\TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('ada', $mark->status);
        $this->assertSame('http://drive/f.pdf', $mark->file_url);
    }

    /** @test */
    public function admin_submits_checklist(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('admin'))->post(route('title.doc.submit', $book->id))
            ->assertRedirect(route('title.show', $book->id));
        $cl = \App\Models\TitleDocChecklist::where('title_id', $book->id)->first();
        $this->assertSame('diajukan', $cl->status);
    }

    /** @test */
    public function manager_and_marketing_cannot_mark(): void
    {
        $book = $this->book();
        foreach (['manager', 'marketing'] as $role) {
            $this->actingAs($this->user($role))->put(route('title.doc.save', $book->id), ['marks' => []])
                ->assertForbidden();
        }
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (stub tak menyimpan)**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php`
Expected: FAIL pada `admin_saves_marks_and_uploads` / `admin_submits_checklist` (stub tak berbuat apa-apa; tak ada redirect / mark).

- [ ] **Step 3: Lengkapi `app/Http/Controllers/Pages/TitleDocCheckController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Title;
use App\Services\DocChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TitleDocCheckController extends Controller
{
    public function __construct(private DocChecklistService $service) {}

    public function save(Request $request, int $id)
    {
        $title = Title::findOrFail($id);
        abort_unless($title->jenis === 'buku', 403);

        $marks = (array) $request->input('marks', []);
        $items = [];
        foreach ($marks as $rid => $m) {
            $items[] = [
                'requirement_id' => (int) $rid,
                'status'         => $m['status'] ?? 'belum',
                'catatan'        => $m['catatan'] ?? null,
                'file'           => $request->file("marks.$rid.file"),
            ];
        }
        $this->service->saveMarks($title, $items, Auth::user());

        return redirect()->route('title.show', $id)->with('success', 'Kelengkapan dokumen disimpan.');
    }

    public function submit(int $id)
    {
        $title = Title::findOrFail($id);
        abort_unless($title->jenis === 'buku', 403);
        $this->service->submit($title, Auth::user());

        return redirect()->route('title.show', $id)->with('success', 'Kelengkapan dokumen diajukan.');
    }
}
```

- [ ] **Step 4: Jalankan — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php`
Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/TitleDocCheckController.php tests/Feature/DocChecklistTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(doc-checklist): TitleDocCheckController simpan penanda+unggah & submit

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 5: Kartu di panel judul + show() vars + test render

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (`show`)
- Modify: `resources/views/titles/show.blade.php` (kartu sebelum `@endsection`)
- Modify: `tests/Feature/DocChecklistTest.php` (test render)

- [ ] **Step 1: Tambah test render (gagal dulu)**

Tambahkan di `DocChecklistTest`:

```php
    /** @test */
    public function card_renders_grouped_items_with_progress(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('admin'))->get(route('title.show', $book->id))
            ->assertOk()
            ->assertSee('Cek Kelengkapan Data')
            ->assertSee('Dokumen Penerbit (ISBN)')
            ->assertSee('Dokumen HKI (Hak Cipta)')
            ->assertSee('Surat Pernyataan Keaslian Karya');
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php::card_renders_grouped_items_with_progress`
Expected: FAIL — teks belum ada.

- [ ] **Step 3: `TitleController@show` — eager-load + vars**

Ubah baris eager-load `Title::with([...])` menambahkan `'docMarks', 'docChecklist'` di akhir daftar `with`:

```php
        $title = Title::with(['chapters.authors', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'orderDetails.authors', 'journalOptions.journal', 'logs.changedBy', 'bookIsbn', 'docMarks', 'docChecklist'])->findOrFail($id);
```

Di array `return view('titles.show', [ … ])`, setelah `'canManageIsbn' => …,` tambahkan:

```php
            'docRequirements' => \App\Models\DocRequirement::active()->orderBy('position')->get()->groupBy('category'),
            'docMarks'        => $title->docMarks->keyBy('doc_requirement_id'),
            'docChecklist'    => $title->docChecklist,
            'docProgress'     => [
                'penerbit' => app(\App\Services\DocChecklistService::class)->progress($title, 'penerbit'),
                'hki'      => app(\App\Services\DocChecklistService::class)->progress($title, 'hki'),
            ],
            'canMarkDocs'     => Auth::user()->hasAnyRole(['superadmin', 'admin']),
            'canManageDocReq' => Auth::user()->hasRole('superadmin'),
```

- [ ] **Step 4: Kartu di `resources/views/titles/show.blade.php`** — sisip SEBELUM `@endsection`:

```blade
@if($title->jenis === 'buku' && $canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Cek Kelengkapan Data</h6>
        <span class="badge {{ optional($docChecklist)->status === 'diajukan' ? 'bg-success' : 'bg-secondary' }}">
            {{ optional($docChecklist)->status === 'diajukan' ? 'Diajukan ' . optional($docChecklist->submitted_at)->format('d M Y') : 'Draft' }}
        </span>
    </div>

    @if($canMarkDocs)
    <form method="POST" action="{{ route('title.doc.save', $title->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
    @endif

    @foreach(\App\Models\DocRequirement::CATEGORIES as $catKey => $catLabel)
        @php $items = $docRequirements[$catKey] ?? collect(); $prog = $docProgress[$catKey]; @endphp
        <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
            <span class="text-muted small fw-semibold">{{ $catLabel }}</span>
            <span class="badge bg-light text-dark border">{{ $prog['done'] }}/{{ $prog['total'] }} ada</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                @forelse($items as $i => $req)
                    @php $mark = $docMarks[$req->id] ?? null; $st = optional($mark)->status ?? 'belum'; @endphp
                    <tr>
                        <td style="width:28px" class="text-muted">{{ $i + 1 }}.</td>
                        <td>
                            <div class="fw-semibold" style="font-size:13px">{{ $req->label }}</div>
                            @if($req->description)<div class="text-muted" style="font-size:11px">{{ $req->description }}</div>@endif
                            @if($canMarkDocs)
                                <input type="text" name="marks[{{ $req->id }}][catatan]" value="{{ optional($mark)->catatan }}" class="form-control form-control-sm mt-1" placeholder="Catatan (opsional)">
                            @elseif(optional($mark)->catatan)
                                <div class="text-muted" style="font-size:11px">Catatan: {{ $mark->catatan }}</div>
                            @endif
                        </td>
                        <td style="width:130px">
                            @if($canMarkDocs)
                                <select name="marks[{{ $req->id }}][status]" class="form-select form-select-sm">
                                    @foreach(\App\Models\TitleDocMark::STATUSES as $sv => $sl)
                                        <option value="{{ $sv }}" {{ $st === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="badge {{ $st === 'ada' ? 'bg-success' : ($st === 'tidak_perlu' ? 'bg-light text-dark border' : 'bg-secondary') }}">{{ \App\Models\TitleDocMark::STATUSES[$st] ?? $st }}</span>
                            @endif
                        </td>
                        <td style="width:150px">
                            @if(optional($mark)->file_url)
                                <a href="{{ $mark->file_url }}" target="_blank" rel="noopener" class="d-block text-truncate" style="max-width:140px; font-size:11px">📎 {{ $mark->file_name ?: 'file' }}</a>
                            @endif
                            @if($canMarkDocs)
                                <input type="file" name="marks[{{ $req->id }}][file]" class="form-control form-control-sm mt-1">
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">Belum ada item.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    @if($canMarkDocs)
        <button type="submit" class="btn btn-sm btn-primary mt-2">Simpan Kelengkapan</button>
    </form>
        <form method="POST" action="{{ route('title.doc.submit', $title->id) }}" class="mt-2">@csrf
            <button type="submit" class="btn btn-sm btn-success">Submit</button>
        </form>
    @endif

    @if($canManageDocReq)
        <div class="mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#docTplForm">Kelola Template Dokumen</button>
            <div class="collapse mt-2" id="docTplForm">
                <p class="text-muted small mb-2">Item berlaku untuk semua buku.</p>
                @foreach(\App\Models\DocRequirement::CATEGORIES as $catKey => $catLabel)
                    <div class="text-muted small fw-semibold mt-2">{{ $catLabel }}</div>
                    @foreach(($docRequirements[$catKey] ?? collect()) as $req)
                        <div class="d-flex gap-1 mb-1 align-items-center">
                            <form method="POST" action="{{ route('doc-req.update', $req->id) }}" class="d-flex gap-1 flex-grow-1 m-0">
                                @csrf @method('PUT')
                                <input type="hidden" name="category" value="{{ $req->category }}">
                                <input name="label" value="{{ $req->label }}" class="form-control form-control-sm">
                                <input name="position" value="{{ $req->position }}" class="form-control form-control-sm" style="max-width:64px" title="Urutan">
                                <button class="btn btn-sm btn-outline-primary">Simpan</button>
                            </form>
                            <form method="POST" action="{{ route('doc-req.destroy', $req->id) }}" class="m-0" data-confirm="Hapus item ini?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">×</button></form>
                        </div>
                    @endforeach
                    <form method="POST" action="{{ route('doc-req.store') }}" class="d-flex gap-1 mt-1">
                        @csrf
                        <input type="hidden" name="category" value="{{ $catKey }}">
                        <input name="label" placeholder="Item baru untuk {{ $catLabel }}…" class="form-control form-control-sm">
                        <button class="btn btn-sm btn-outline-success">+ Tambah</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif
</div></div></div></div>
@endif
```

- [ ] **Step 5: Jalankan test render + view:cache**

Run: `php artisan test --env=testing tests/Feature/DocChecklistTest.php`
Expected: 6 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/TitleController.php resources/views/titles/show.blade.php tests/Feature/DocChecklistTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(doc-checklist): kartu Cek Kelengkapan Data di panel judul + kelola template

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 6: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**

Run: `php artisan migrate`
Expected: `2026_07_03_000004…`, `…000005…`, `…000006…` `DONE` (seed 9 item ikut di 000004).

- [ ] **Step 2: Seluruh suite**

Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 398 + 10 test baru = 408 passed).

- [ ] **Step 3: Kompilasi view bersih**

Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §1.1 superadmin CRUD template → Task 3 controller + Task 5 kartu kelola; test `superadmin_crud_requirement`. ✓
- §1.2 seed 9 item → Task 1 migrasi seed; dipakai test render & progress. ✓
- §1.3 admin tandai+upload+submit → Task 2 service + Task 4 controller; test `admin_saves_marks_and_uploads`, `admin_submits_checklist`. ✓
- §1.4 kartu grup+progress+file+badge → Task 5 blade; test `card_renders_grouped_items_with_progress`. ✓
- §1.5 manager/marketing/production tak bisa mark/CRUD → rute role group; test `manager_and_marketing_cannot_mark`, `non_superadmin_cannot_crud_template`. ✓
- §2 model/migrasi → Task 1. §3 service → Task 2. §4 controller/rute → Task 3/4. §5 view/show → Task 5. §6 test → semua task. ✓

**2. Placeholder scan:** tak ada TBD/TODO; semua langkah berisi kode nyata. (Stub `TitleDocCheckController` di Task 3 sengaja minimal, dilengkapi Task 4 — bukan placeholder plan, melainkan langkah TDD bertahap.)

**3. Type/nama konsistensi:** tabel `tb_doc_requirements`/`tb_title_doc_marks`/`tb_title_doc_checklists`; model `DocRequirement`(CATEGORIES penerbit/hki, scope active)/`TitleDocMark`(STATUSES ada/belum/tidak_perlu)/`TitleDocChecklist`(status draft/diajukan) konsisten migrasi↔model↔service↔view↔test. `DocChecklistService::saveMarks/submit/progress` dipakai konsisten controller+view+test. Rute `doc-req.store/update/destroy` + `title.doc.save/submit` konsisten controller↔view↔test. Relasi `Title::docMarks/docChecklist` dipakai show()+service. `$canMarkDocs`/`$canManageDocReq`/`$docRequirements`/`$docMarks`/`$docChecklist`/`$docProgress` dikirim show()↔dipakai blade.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 6 Step 1) + `--env=testing` (Task 1 Step 8). Test via `.env.testing`, Drive di-mock.
