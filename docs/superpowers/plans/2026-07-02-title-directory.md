# Title Directory (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entitas Judul berdiri sendiri + menu Direktori Judul + CRUD (artikel/buku + bab) + alur approval (admin/production → menunggu → superadmin/manager setujui/tolak; superadmin/manager auto-disetujui).

**Architecture:** Dua tabel baru (`tb_titles`, `tb_title_chapters`) + `TitleService` (create/update+bab, submit, approve, reject) + `TitleController` (CRUD + aksi) + 3 view. Tidak menyentuh order/manuskrip (Fase 2 lewat field `asal`).

**Tech Stack:** Laravel 11, Spatie roles, Blade + Bootstrap 5 (NobleUI), DataTables + select2 (ter-bundle), SweetAlert2 (helper `data-confirm` global sudah ada di master).

**Spec:** `docs/superpowers/specs/2026-07-02-title-directory-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`); mock `GoogleDriveService`. DB error → MySQL/XAMPP mati: `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Setelah selesai: `php artisan migrate` di dev (2 tabel). Migrasi terakhir yang ada: `2026_06_27_000001`; yang baru `2026_07_02_000001/000002`.

---

## Task 1: Migrations + Models

**Files:**
- Create: `database/migrations/2026_07_02_000001_create_tb_titles_table.php`, `database/migrations/2026_07_02_000002_create_tb_title_chapters_table.php`
- Create: `app/Models/Title.php`, `app/Models/TitleChapter.php`

- [ ] **Step 1: Migration `tb_titles`**

Create `database/migrations/2026_07_02_000001_create_tb_titles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_titles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('jenis', 16);              // artikel | buku
            $table->string('indeksasi', 64)->nullable();
            $table->string('tipe_naskah', 16);        // mandiri | kolaborasi
            $table->string('status', 16)->default('draft'); // draft|menunggu|disetujui|ditolak
            $table->string('asal', 16)->default('distribusi'); // distribusi|order (Fase 2)
            $table->string('slug')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_titles');
    }
};
```

- [ ] **Step 2: Migration `tb_title_chapters`**

Create `database/migrations/2026_07_02_000002_create_tb_title_chapters_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('judul');
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_chapters');
    }
};
```

- [ ] **Step 3: Model `Title`**

Create `app/Models/Title.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    use HasFactory;

    protected $table = 'tb_titles';

    public const JENIS = ['artikel', 'buku'];
    public const TIPE = ['mandiri', 'kolaborasi'];
    public const STATUSES = ['draft', 'menunggu', 'disetujui', 'ditolak'];
    public const INDEKSASI = [
        'none', 'SINTA 1', 'SINTA 2', 'SINTA 3', 'SINTA 4', 'SINTA 5', 'SINTA 6',
        'Scopus Q1', 'Scopus Q2', 'Scopus Q3', 'Scopus Q4', 'Copernicus', 'WoS', 'DOAJ', 'Garuda',
    ];

    protected $fillable = [
        'title', 'jenis', 'indeksasi', 'tipe_naskah', 'status', 'asal', 'slug',
        'created_by', 'approved_by', 'approved_at', 'reject_note',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function chapters()
    {
        return $this->hasMany(TitleChapter::class)->orderBy('urutan');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ditolak'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'disetujui';
    }
}
```

- [ ] **Step 4: Model `TitleChapter`**

Create `app/Models/TitleChapter.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleChapter extends Model
{
    use HasFactory;

    protected $table = 'tb_title_chapters';

    protected $fillable = ['title_id', 'judul', 'urutan'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
```

- [ ] **Step 5: Verify migration healthy**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS (RefreshDatabase migrates both new tables cleanly).

- [ ] **Step 6: Commit**

```
git add database/migrations/2026_07_02_000001_create_tb_titles_table.php database/migrations/2026_07_02_000002_create_tb_title_chapters_table.php app/Models/Title.php app/Models/TitleChapter.php
git commit -m "feat(title): titles + chapters tables and models

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `TitleService` (TDD)

**Files:**
- Create: `app/Services/TitleService.php`
- Test: `tests/Unit/TitleServiceTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/TitleServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new TitleService();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function create_buku_stores_ordered_chapters(): void
    {
        $title = $this->svc->create(
            ['title' => 'Buku A', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'indeksasi' => 'none'],
            [['judul' => 'Bab 1'], ['judul' => 'Bab 2']],
            $this->user('production'),
        );

        $this->assertSame('draft', $title->status);
        $this->assertNotNull($title->slug);
        $this->assertSame(2, $title->chapters()->count());
        $this->assertSame('Bab 1', $title->chapters()->first()->judul);
    }

    /** @test */
    public function submit_by_production_goes_to_menunggu(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'Art', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $prod);

        $this->svc->submit($title, $prod);

        $this->assertSame('menunggu', $title->fresh()->status);
    }

    /** @test */
    public function submit_by_superadmin_auto_approves(): void
    {
        $sa = $this->user('superadmin');
        $title = $this->svc->create(['title' => 'Art', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $sa);

        $this->svc->submit($title, $sa);

        $title->refresh();
        $this->assertSame('disetujui', $title->status);
        $this->assertSame($sa->id, $title->approved_by);
    }

    /** @test */
    public function reject_then_resubmit_then_approve(): void
    {
        $prod = $this->user('production');
        $mgr  = $this->user('manager');
        $title = $this->svc->create(['title' => 'X', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $prod);

        $this->svc->submit($title, $prod);                    // menunggu
        $this->svc->reject($title->fresh(), $mgr, 'kurang lengkap');
        $this->assertSame('ditolak', $title->fresh()->status);
        $this->assertSame('kurang lengkap', $title->fresh()->reject_note);

        $this->svc->submit($title->fresh(), $prod);           // menunggu lagi
        $this->svc->approve($title->fresh(), $mgr);
        $this->assertSame('disetujui', $title->fresh()->status);
    }

    /** @test */
    public function update_to_artikel_removes_chapters(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'B', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'], [['judul' => 'Bab 1']], $prod);

        $this->svc->update($title, ['title' => 'B', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], []);

        $this->assertSame(0, $title->chapters()->count());
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleServiceTest`
Expected: FAIL — `Class "App\Services\TitleService" not found`.

- [ ] **Step 3: Implement the service**

Create `app/Services/TitleService.php`:

```php
<?php

namespace App\Services;

use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Str;

class TitleService
{
    /** Buat judul (status draft) + bab bila buku. */
    public function create(array $data, array $chapters, User $actor): Title
    {
        $title = Title::create([
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
            'status'      => 'draft',
            'asal'        => 'distribusi',
            'created_by'  => $actor->id,
        ]);
        $title->update(['slug' => Str::slug($title->title) . '-' . $title->id]);

        if ($title->jenis === 'buku') {
            $this->syncChapters($title, $chapters);
        }

        return $title;
    }

    /** Perbarui judul + bab (dipanggil hanya saat editable). */
    public function update(Title $title, array $data, array $chapters): void
    {
        $title->update([
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
        ]);

        if ($title->jenis === 'buku') {
            $this->syncChapters($title, $chapters);
        } else {
            $title->chapters()->delete();
        }
    }

    private function syncChapters(Title $title, array $chapters): void
    {
        $title->chapters()->delete();
        $i = 0;
        foreach ($chapters as $ch) {
            $judul = is_array($ch) ? ($ch['judul'] ?? '') : $ch;
            if (trim((string) $judul) === '') {
                continue;
            }
            $title->chapters()->create(['judul' => $judul, 'urutan' => $i++]);
        }
    }

    /** Ajukan: admin/production → menunggu; superadmin/manager → langsung disetujui. */
    public function submit(Title $title, User $actor): void
    {
        if (! in_array($title->status, ['draft', 'ditolak'], true)) {
            return;
        }
        if ($actor->hasAnyRole(['superadmin', 'manager'])) {
            $title->update(['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now(), 'reject_note' => null]);
        } else {
            $title->update(['status' => 'menunggu', 'reject_note' => null]);
        }
    }

    public function approve(Title $title, User $actor): void
    {
        if ($title->status !== 'menunggu') {
            return;
        }
        $title->update(['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now()]);
    }

    public function reject(Title $title, User $actor, string $note): void
    {
        if ($title->status !== 'menunggu') {
            return;
        }
        $title->update(['status' => 'ditolak', 'reject_note' => $note, 'approved_by' => null, 'approved_at' => null]);
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/TitleService.php tests/Unit/TitleServiceTest.php
git commit -m "feat(title): TitleService (create+chapters, submit, approve, reject)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Controller + routes + feature tests (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/TitleController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TitleControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/TitleControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleControllerTest extends TestCase
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

    private function title(User $creator, string $status, string $name = 'X'): Title
    {
        return Title::create(['title' => $name, 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => $status, 'created_by' => $creator->id]);
    }

    /** @test */
    public function production_creates_draft_then_submits_to_menunggu(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('title.store'), ['title' => 'Judul A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'])->assertRedirect();
        $title = Title::where('title', 'Judul A')->first();
        $this->assertNotNull($title);
        $this->assertSame('draft', $title->status);

        $this->actingAs($u)->post(route('title.submit', $title->id))->assertRedirect();
        $this->assertSame('menunggu', $title->fresh()->status);
    }

    /** @test */
    public function manager_approves_but_production_cannot(): void
    {
        $prod = $this->user('production');
        $mgr  = $this->user('manager');
        $title = $this->title($prod, 'menunggu');

        $this->actingAs($prod)->post(route('title.approve', $title->id))->assertForbidden();
        $this->actingAs($mgr)->post(route('title.approve', $title->id))->assertRedirect();
        $this->assertSame('disetujui', $title->fresh()->status);
    }

    /** @test */
    public function reject_records_note(): void
    {
        $prod = $this->user('production');
        $title = $this->title($prod, 'menunggu');

        $this->actingAs($this->user('superadmin'))->post(route('title.reject', $title->id), ['reject_note' => 'perbaiki judul'])->assertRedirect();
        $this->assertSame('ditolak', $title->fresh()->status);
        $this->assertSame('perbaiki judul', $title->fresh()->reject_note);
    }

    /** @test */
    public function marketing_index_sees_only_approved(): void
    {
        $prod = $this->user('production');
        $this->title($prod, 'draft', 'DRAF-RAHASIA');
        $this->title($prod, 'disetujui', 'JUDUL-SIAP');

        $this->actingAs($this->user('marketing'))->get(route('title.index'))
            ->assertOk()->assertSee('JUDUL-SIAP')->assertDontSee('DRAF-RAHASIA');
    }

    /** @test */
    public function edit_blocked_when_approved(): void
    {
        $prod = $this->user('production');
        $title = $this->title($prod, 'disetujui');
        $this->actingAs($prod)->get(route('title.edit', $title->id))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleControllerTest`
Expected: FAIL — route `title.store` not defined.

- [ ] **Step 3: Controller**

Create `app/Http/Controllers/Pages/TitleController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Title;
use App\Services\TitleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TitleController extends Controller
{
    public function __construct(private TitleService $service) {}

    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']);
    }

    private function isApprover(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager']);
    }

    public function index()
    {
        $query = Title::with('creator')->latest();
        if (! $this->canManage()) {
            $query->where('status', 'disetujui'); // marketing hanya lihat yang disetujui
        }

        return view('titles.index', [
            'titles' => $query->get(),
            'canManage' => $this->canManage(),
            'isApprover' => $this->isApprover(),
        ]);
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);
        return view('titles.form', ['title' => new Title(['jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'draft'])]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validateData($request);
        $this->service->create($data, $request->input('chapters', []), Auth::user());

        return redirect()->route('title.index')->with('success', 'Judul dibuat (draf).');
    }

    public function show(int $id)
    {
        $title = Title::with(['chapters', 'creator', 'approver'])->findOrFail($id);
        abort_if(! $this->canManage() && ! $title->isApproved(), 403);

        return view('titles.show', ['title' => $title, 'canManage' => $this->canManage(), 'isApprover' => $this->isApprover()]);
    }

    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with('chapters')->findOrFail($id);
        abort_unless($title->isEditable(), 403);

        return view('titles.form', ['title' => $title]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        abort_unless($title->isEditable(), 403);
        $data = $this->validateData($request);
        $this->service->update($title, $data, $request->input('chapters', []));

        return redirect()->route('title.show', $title->id)->with('success', 'Judul diperbarui.');
    }

    public function destroy(int $id)
    {
        $title = Title::findOrFail($id);
        $ownDraft = $title->created_by === Auth::id() && $title->status === 'draft';
        abort_unless($this->canManage() && ($ownDraft || Auth::user()->hasRole('superadmin')), 403);
        $title->delete();

        return redirect()->route('title.index')->with('success', 'Judul dihapus.');
    }

    public function submit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $this->service->submit(Title::findOrFail($id), Auth::user());

        return back()->with('success', 'Judul diajukan.');
    }

    public function approve(int $id)
    {
        abort_unless($this->isApprover(), 403);
        $this->service->approve(Title::findOrFail($id), Auth::user());

        return back()->with('success', 'Judul disetujui.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless($this->isApprover(), 403);
        $data = $request->validate(['reject_note' => 'required|string']);
        $this->service->reject(Title::findOrFail($id), Auth::user(), $data['reject_note']);

        return back()->with('success', 'Judul ditolak.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'           => 'required|string|max:255',
            'jenis'           => 'required|in:artikel,buku',
            'indeksasi'       => 'nullable|string|max:64',
            'tipe_naskah'     => 'required|in:mandiri,kolaborasi',
            'chapters'        => 'nullable|array',
            'chapters.*.judul' => 'nullable|string|max:255',
        ]);
    }
}
```

- [ ] **Step 4: Routes**

In `routes/web.php`, add the import near the other `use App\Http\Controllers\Pages\...;` lines:

```php
use App\Http\Controllers\Pages\TitleController;
```

Inside the `Route::middleware('auth')->group(function () {` block, add:

```php
    Route::get('titles', [TitleController::class, 'index'])->name('title.index');
    Route::middleware('role:superadmin|manager|admin|production')->group(function () {
        Route::get('titles/create', [TitleController::class, 'create'])->name('title.create');
        Route::post('titles', [TitleController::class, 'store'])->name('title.store');
        Route::get('titles/{id}/edit', [TitleController::class, 'edit'])->name('title.edit')->whereNumber('id');
        Route::put('titles/{id}', [TitleController::class, 'update'])->name('title.update')->whereNumber('id');
        Route::delete('titles/{id}', [TitleController::class, 'destroy'])->name('title.destroy')->whereNumber('id');
        Route::post('titles/{id}/submit', [TitleController::class, 'submit'])->name('title.submit')->whereNumber('id');
    });
    Route::middleware('role:superadmin|manager')->group(function () {
        Route::post('titles/{id}/approve', [TitleController::class, 'approve'])->name('title.approve')->whereNumber('id');
        Route::post('titles/{id}/reject', [TitleController::class, 'reject'])->name('title.reject')->whereNumber('id');
    });
    Route::get('titles/{id}', [TitleController::class, 'show'])->name('title.show')->whereNumber('id');
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=TitleControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/TitleController.php routes/web.php tests/Feature/TitleControllerTest.php
git commit -m "feat(title): controller + routes (CRUD + submit/approve/reject)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Views + sidebar + page smoke tests (TDD)

**Files:**
- Create: `resources/views/titles/index.blade.php`, `form.blade.php`, `show.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/TitlePagesTest.php`

- [ ] **Step 1: Write the failing page test**

Create `tests/Feature/TitlePagesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitlePagesTest extends TestCase
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

    /** @test */
    public function manager_can_open_index_and_create_pages(): void
    {
        $u = $this->user('manager');
        Title::create(['title' => 'JudulKu', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'draft', 'created_by' => $u->id]);

        $this->actingAs($u)->get(route('title.index'))->assertOk()->assertSee('JudulKu');
        $this->actingAs($u)->get(route('title.create'))->assertOk();
    }

    /** @test */
    public function marketing_can_open_index_but_not_create(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('title.index'))->assertOk();
        $this->actingAs($this->user('marketing'))->get(route('title.create'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitlePagesTest`
Expected: FAIL — `View [titles.index] not found`.

- [ ] **Step 3: Index view**

Create `resources/views/titles/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Direktori Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Judul</h5>
    @if($canManage)
        <a href="{{ route('title.create') }}" class="btn btn-sm btn-primary">Buat Judul</a>
    @endif
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Judul</th><th>Jenis</th><th>Indeksasi</th><th>Tipe</th><th>Status</th><th>Pembuat</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($titles as $t)
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ $t->indeksasi ?: '—' }}</td>
                        <td>{{ ucfirst($t->tipe_naskah) }}</td>
                        <td><span class="badge {{ $sb[$t->status] ?? 'bg-secondary' }}">{{ $sl[$t->status] ?? $t->status }}</span></td>
                        <td><small>{{ $t->creator?->name ?? '—' }}</small></td>
                        <td>
                            <a href="{{ route('title.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                            @if($canManage && $t->isEditable())
                                <a href="{{ route('title.edit', $t->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                <form action="{{ route('title.submit', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-info">Ajukan</button></form>
                            @endif
                            @if($isApprover && $t->status === 'menunggu')
                                <form action="{{ route('title.approve', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-success">Setujui</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada judul.' } }); });</script>
@endpush
```

- [ ] **Step 4: Form view (create + edit)**

Create `resources/views/titles/form.blade.php`:

```blade
@extends('layouts.master')
@section('title', ($title->exists ? 'Edit' : 'Buat') . ' Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">{{ $title->exists ? 'Edit' : 'Buat' }} Judul</h5>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <form method="POST" action="{{ $title->exists ? route('title.update', $title->id) : route('title.store') }}">
        @csrf
        @if($title->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Judul <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $title->title) }}" required>
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Jenis <span class="text-danger">*</span></label>
                <select name="jenis" id="jenis" class="form-select">
                    <option value="artikel" {{ old('jenis', $title->jenis) === 'artikel' ? 'selected' : '' }}>Artikel</option>
                    <option value="buku" {{ old('jenis', $title->jenis) === 'buku' ? 'selected' : '' }}>Buku</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Tipe Naskah <span class="text-danger">*</span></label>
                <select name="tipe_naskah" class="form-select">
                    <option value="mandiri" {{ old('tipe_naskah', $title->tipe_naskah) === 'mandiri' ? 'selected' : '' }}>Mandiri</option>
                    <option value="kolaborasi" {{ old('tipe_naskah', $title->tipe_naskah) === 'kolaborasi' ? 'selected' : '' }}>Kolaborasi</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Indeksasi</label>
                <select name="indeksasi" class="form-select select2-indeks">
                    <option value="">— pilih / ketik —</option>
                    @foreach(\App\Models\Title::INDEKSASI as $ix)
                        <option value="{{ $ix }}" {{ old('indeksasi', $title->indeksasi) === $ix ? 'selected' : '' }}>{{ $ix }}</option>
                    @endforeach
                    @if($title->indeksasi && ! in_array($title->indeksasi, \App\Models\Title::INDEKSASI, true))
                        <option value="{{ $title->indeksasi }}" selected>{{ $title->indeksasi }}</option>
                    @endif
                </select>
            </div>
        </div>

        <div id="chaptersWrap" class="mb-3 {{ old('jenis', $title->jenis) === 'buku' ? '' : 'd-none' }}">
            <label class="form-label">Bab (judul per bab)</label>
            <div id="chaptersList">
                @php $existing = old('chapters', $title->exists ? $title->chapters->map(fn($c) => ['judul' => $c->judul])->all() : []); @endphp
                @forelse($existing as $i => $ch)
                    <div class="input-group mb-2" data-chapter-row>
                        <input type="text" name="chapters[{{ $i }}][judul]" class="form-control" value="{{ $ch['judul'] ?? '' }}" placeholder="Judul bab">
                        <button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>
                    </div>
                @empty
                @endforelse
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addChapter">+ Tambah Bab</button>
        </div>

        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (window.jQuery && jQuery.fn.select2) { jQuery('.select2-indeks').select2({ tags: true, width: '100%' }); }

    var jenis = document.getElementById('jenis');
    var wrap = document.getElementById('chaptersWrap');
    if (jenis) jenis.addEventListener('change', function () { wrap.classList.toggle('d-none', this.value !== 'buku'); });

    var list = document.getElementById('chaptersList');
    var idx = list ? list.querySelectorAll('[data-chapter-row]').length : 0;
    var addBtn = document.getElementById('addChapter');
    if (addBtn) addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'input-group mb-2';
        row.setAttribute('data-chapter-row', '');
        row.innerHTML = '<input type="text" name="chapters[' + (idx++) + '][judul]" class="form-control" placeholder="Judul bab">'
            + '<button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>';
        list.appendChild(row);
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-chapter]');
        if (b) b.closest('[data-chapter-row]').remove();
    });
});
</script>
@endpush
```

- [ ] **Step 5: Show view**

Create `resources/views/titles/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">{{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->indeksasi ?: 'Tanpa indeksasi' }}</small>
    </div>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Status: <span class="badge {{ $sb[$title->status] ?? 'bg-secondary' }}">{{ $sl[$title->status] ?? $title->status }}</span></p>
    <p class="mb-2"><small class="text-muted">Dibuat oleh {{ $title->creator?->name ?? '—' }}@if($title->approver) · disetujui {{ $title->approver->name }}@endif</small></p>
    @if($title->status === 'ditolak' && $title->reject_note)
        <div class="alert alert-danger py-2"><strong>Ditolak:</strong> {{ $title->reject_note }}</div>
    @endif

    @if($title->jenis === 'buku')
        <h6 class="card-title mt-3">Bab</h6>
        <ol class="mb-3">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
    @endif

    <div class="d-flex gap-2 flex-wrap">
        @if($canManage && $title->isEditable())
            <a href="{{ route('title.edit', $title->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
            <form action="{{ route('title.submit', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-info">Ajukan</button></form>
        @endif
        @if($isApprover && $title->status === 'menunggu')
            <form action="{{ route('title.approve', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-success">Setujui</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectForm">Tolak</button>
        @endif
    </div>

    @if($isApprover && $title->status === 'menunggu')
        <div class="collapse mt-2" id="rejectForm">
            <form action="{{ route('title.reject', $title->id) }}" method="POST">@csrf
                <textarea name="reject_note" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
                <button class="btn btn-sm btn-danger">Kirim Penolakan</button>
            </form>
        </div>
    @endif
</div></div></div></div>
@endsection
```

- [ ] **Step 6: Sidebar menu**

In `resources/views/layouts/sidebar.blade.php`, inside the `@role(['superadmin', 'manager', 'marketing'])` "Order & Naskah" block, after the "Arsip Judul" nav item (the one linking to `order.book.indexJudul`), the block is currently gated to superadmin/manager/marketing — but Direktori Judul also needs admin+production. Add this as a SEPARATE block AFTER the `@endrole` that closes the "Order & Naskah" group (so it has its own role gate):

```blade
            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['titles']) }}">
                    <a href="{{ route('title.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="book"></i>
                        <span class="link-title">Direktori Judul</span>
                    </a>
                </li>
            @endrole
```

Place it right after the closing `@endrole` of the "Order & Naskah" `@role(['superadmin', 'manager', 'marketing'])` group and before the "Produksi" `@role` block.

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=TitlePagesTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```
git add resources/views/titles/index.blade.php resources/views/titles/form.blade.php resources/views/titles/show.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/TitlePagesTest.php
git commit -m "feat(title): index/form/show views + sidebar

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS semua (283 sebelumnya + TitleServiceTest 5 + TitleControllerTest 5 + TitlePagesTest 2 = 295).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Smoke manual (opsional)**

Login `pia` (production): Direktori Judul → Buat Judul (buku, tambah bab) → simpan (draf) → Ajukan (→ menunggu). Login manager/superadmin → Setujui/Tolak (dengan catatan). Login marketing → hanya melihat judul disetujui, tak ada tombol buat.

---

## Catatan & Risiko

- **Dev/prod: `php artisan migrate`** untuk `tb_titles` + `tb_title_chapters`. Lihat [[migrate-dev-db-after-new-migration]].
- Judul berdiri sendiri, belum terhubung ke order/manuskrip (Fase 2 via `asal`).
- Approval hanya untuk buatan admin/production; superadmin/manager auto-disetujui saat Ajukan.
- Indeksasi = string + select2 tags (SINTA/Scopus/dll + kustom) tanpa enum.
- Tidak menyentuh alur order/manuskrip existing → suite tetap hijau.
