# Direktori Jurnal (A: CRUD) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Entitas Jurnal + halaman admin (DataTables index, create/edit/detail, hapus) + menu sidebar. CRUD superadmin/manager/admin; lihat semua staf.

**Architecture:** `Journal` (tb_journals) reuse `Scope`/`tb_scopes` (bidang) + `Title::INDEKSASI` (akreditasi). `JournalController` (Pages) CRUD dgn gate `canManage`. View pola Direktori Judul (DataTables + select2 + SweetAlert2).

**Tech Stack:** Laravel 11, Eloquent, Spatie roles, Blade + DataTables + select2 (bundled).

**Spec:** `docs/superpowers/specs/2026-07-02-journal-directory-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell). Commit: `git add <path>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic". Migrasi terakhir `2026_07_02_000011`; baru = `2026_07_02_000012`. Setelah selesai: `php artisan migrate` di dev.

**Fakta:** `App\Models\Scope` (tb_scopes, kolom `scope`). `Title::INDEKSASI` = ['none','SINTA 1'..'SINTA 6','Scopus Q1'..'Scopus Q4','Copernicus','WoS','DOAJ','Garuda']. Sidebar "Direktori Judul" item (`title.index`, role superadmin/manager/admin/production/marketing) ada — tambah "Direktori Jurnal" tepat setelahnya. Assets: `assets/libs/datatables.net*`, `assets/plugins/select2/*`. Master punya SweetAlert2 global (`form[data-confirm]`).

---

## Task 1: Migration + `Journal` model

**Files:**
- Create: `database/migrations/2026_07_02_000012_create_tb_journals_table.php`, `app/Models/Journal.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_02_000012_create_tb_journals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_journals', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('akreditasi', 64)->nullable();
            $table->foreignId('scope_id')->nullable()->constrained('tb_scopes')->nullOnDelete();
            $table->string('apc_reguler')->nullable();
            $table->string('apc_fastrack')->nullable();
            $table->string('link')->nullable();
            $table->string('kontak_wa')->nullable();
            $table->string('kontak_email')->nullable();
            $table->json('terbitan_bulan')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_journals');
    }
};
```

- [ ] **Step 2: Model `Journal`**

Create `app/Models/Journal.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use HasFactory;

    protected $table = 'tb_journals';

    public const MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    protected $fillable = [
        'nama', 'akreditasi', 'scope_id', 'apc_reguler', 'apc_fastrack',
        'link', 'kontak_wa', 'kontak_email', 'terbitan_bulan', 'catatan', 'created_by',
    ];

    protected $casts = ['terbitan_bulan' => 'array'];

    public function scope()
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nama bulan terbitan, terurut. */
    public function terbitanLabels(): array
    {
        return collect($this->terbitan_bulan ?? [])
            ->map(fn ($m) => (int) $m)
            ->sort()
            ->map(fn ($m) => self::MONTHS[$m] ?? $m)
            ->values()
            ->all();
    }
}
```

- [ ] **Step 3: Verify migration healthy**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS (RefreshDatabase applies the new migration cleanly).

- [ ] **Step 4: Commit**

```
git add database/migrations/2026_07_02_000012_create_tb_journals_table.php app/Models/Journal.php
git commit -m "feat(journal): tb_journals table + Journal model

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `JournalController` + routes + feature tests (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/JournalController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/JournalControllerTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Feature/JournalControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Scope;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalControllerTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'nama' => 'Jurnal Riset Nusantara',
            'akreditasi' => 'SINTA 2',
            'scope_id' => 'Teknik Informatika',
            'apc_reguler' => 'Rp 3.000.000',
            'apc_fastrack' => 'Rp 5.000.000',
            'link' => 'https://jrn.test',
            'kontak_wa' => '0812',
            'kontak_email' => 'editor@jrn.test',
            'terbitan_bulan' => [1, 6],
            'catatan' => 'catatan',
        ], $over);
    }

    /** @test */
    public function manager_creates_journal_with_new_scope_and_months(): void
    {
        $this->actingAs($this->user('manager'))->post(route('journal.store'), $this->payload())->assertRedirect();

        $j = Journal::where('nama', 'Jurnal Riset Nusantara')->first();
        $this->assertNotNull($j);
        $this->assertSame([1, 6], $j->terbitan_bulan);
        $this->assertSame('Teknik Informatika', $j->scope->scope); // scope firstOrCreate
        $this->assertSame(1, Scope::where('scope', 'Teknik Informatika')->count());
        $this->assertSame(['Jan', 'Jun'], $j->terbitanLabels());
    }

    /** @test */
    public function manager_updates_and_deletes_journal(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('journal.store'), $this->payload())->assertRedirect();
        $j = Journal::first();

        $this->actingAs($mgr)->put(route('journal.update', $j->id), $this->payload(['nama' => 'Jurnal Baru', 'terbitan_bulan' => [3]]))->assertRedirect();
        $this->assertSame('Jurnal Baru', $j->fresh()->nama);
        $this->assertSame([3], $j->fresh()->terbitan_bulan);

        $this->actingAs($mgr)->delete(route('journal.destroy', $j->id))->assertRedirect();
        $this->assertSame(0, Journal::count());
    }

    /** @test */
    public function marketing_can_view_but_not_manage(): void
    {
        $j = Journal::create(['nama' => 'Lihat Saja', 'created_by' => $this->user('manager')->id]);
        $mkt = $this->user('marketing');

        $this->actingAs($mkt)->get(route('journal.index'))->assertOk()->assertSee('Lihat Saja');
        $this->actingAs($mkt)->get(route('journal.show', $j->id))->assertOk();
        $this->actingAs($mkt)->get(route('journal.create'))->assertForbidden();
        $this->actingAs($mkt)->post(route('journal.store'), $this->payload())->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=JournalControllerTest`
Expected: FAIL — route `journal.store` undefined.

- [ ] **Step 3: Controller** — create `app/Http/Controllers/Pages/JournalController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JournalController extends Controller
{
    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }

    public function index()
    {
        return view('journals.index', [
            'journals' => Journal::with('scope')->latest()->get(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);
        return view('journals.form', ['journal' => new Journal(), 'scopes' => Scope::orderBy('scope')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validateData($request);
        $data['scope_id'] = $this->resolveScopeId($data['scope_id'] ?? null);
        $data['created_by'] = Auth::id();
        Journal::create($data);

        return redirect()->route('journal.index')->with('success', 'Jurnal ditambahkan.');
    }

    public function show(int $id)
    {
        return view('journals.show', [
            'journal' => Journal::with(['scope', 'creator'])->findOrFail($id),
            'canManage' => $this->canManage(),
        ]);
    }

    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        return view('journals.form', [
            'journal' => Journal::findOrFail($id),
            'scopes' => Scope::orderBy('scope')->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $journal = Journal::findOrFail($id);
        $data = $this->validateData($request);
        $data['scope_id'] = $this->resolveScopeId($data['scope_id'] ?? null);
        $journal->update($data);

        return redirect()->route('journal.show', $journal->id)->with('success', 'Jurnal diperbarui.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        Journal::findOrFail($id)->delete();

        return redirect()->route('journal.index')->with('success', 'Jurnal dihapus.');
    }

    private function resolveScopeId($value): ?int
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return Scope::firstOrCreate(['scope' => $value])->id;
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'nama'             => 'required|string|max:255',
            'akreditasi'       => 'nullable|string|max:64',
            'scope_id'         => 'nullable|string|max:255',
            'apc_reguler'      => 'nullable|string|max:255',
            'apc_fastrack'     => 'nullable|string|max:255',
            'link'             => 'nullable|string|max:255',
            'kontak_wa'        => 'nullable|string|max:255',
            'kontak_email'     => 'nullable|string|max:255',
            'terbitan_bulan'   => 'nullable|array',
            'terbitan_bulan.*' => 'integer|between:1,12',
            'catatan'          => 'nullable|string',
        ]);
    }
}
```

- [ ] **Step 4: Routes** — in `routes/web.php`, add `use App\Http\Controllers\Pages\JournalController;` near the other Pages imports. Inside an authenticated (`auth`) route group (near the `titles` routes), add:

```php
    Route::get('journals', [JournalController::class, 'index'])->name('journal.index');
    Route::get('journals/{id}', [JournalController::class, 'show'])->name('journal.show')->whereNumber('id');
    Route::middleware('role:superadmin|manager|admin')->group(function () {
        Route::get('journals/create', [JournalController::class, 'create'])->name('journal.create');
        Route::post('journals', [JournalController::class, 'store'])->name('journal.store');
        Route::get('journals/{id}/edit', [JournalController::class, 'edit'])->name('journal.edit')->whereNumber('id');
        Route::put('journals/{id}', [JournalController::class, 'update'])->name('journal.update')->whereNumber('id');
        Route::delete('journals/{id}', [JournalController::class, 'destroy'])->name('journal.destroy')->whereNumber('id');
    });
```
(`whereNumber('id')` on `journals/{id}` keeps `journals/create` unambiguous.)

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=JournalControllerTest`
Expected: PASS (3 tests). Also `php artisan route:list --name=journal` shows 7 routes.

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/JournalController.php routes/web.php tests/Feature/JournalControllerTest.php
git commit -m "feat(journal): controller + routes (CRUD, scope firstOrCreate, month array)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Views (index/form/show) + sidebar + smoke test

**Files:**
- Create: `resources/views/journals/index.blade.php`, `form.blade.php`, `show.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/JournalPagesTest.php`

- [ ] **Step 1: Write the failing page test** — create `tests/Feature/JournalPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalPagesTest extends TestCase
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
    public function manager_opens_index_create_and_show(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal X', 'terbitan_bulan' => [1, 6], 'created_by' => $mgr->id]);

        $this->actingAs($mgr)->get(route('journal.index'))->assertOk()->assertSee('Jurnal X');
        $this->actingAs($mgr)->get(route('journal.create'))->assertOk();
        $this->actingAs($mgr)->get(route('journal.show', $j->id))->assertOk()->assertSee('Jan')->assertSee('Jun');
    }

    /** @test */
    public function marketing_opens_index_but_not_create(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('journal.index'))->assertOk();
        $this->actingAs($this->user('marketing'))->get(route('journal.create'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=JournalPagesTest`
Expected: FAIL — `View [journals.index] not found`.

- [ ] **Step 3: Index view** — create `resources/views/journals/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Direktori Jurnal - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Jurnal</h5>
    @if($canManage)
        <a href="{{ route('journal.create') }}" class="btn btn-sm btn-primary">Tambah Jurnal</a>
    @endif
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Jurnal</th><th>Akreditasi</th><th>Terbitan</th><th>Scope</th><th>APC Reguler</th><th>Fastrack</th><th>Link</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($journals as $j)
                    <tr>
                        <td>{{ $j->nama }}</td>
                        <td>@if($j->akreditasi)<span class="badge bg-dark">{{ $j->akreditasi }}</span>@else<span class="text-muted">—</span>@endif</td>
                        <td>@forelse($j->terbitanLabels() as $m)<span class="badge bg-light text-dark border me-1">{{ $m }}</span>@empty<span class="text-muted">—</span>@endforelse</td>
                        <td>{{ $j->scope?->scope ?? '—' }}</td>
                        <td>{{ $j->apc_reguler ?: '—' }}</td>
                        <td>{{ $j->apc_fastrack ?: '—' }}</td>
                        <td>@if($j->link)<a href="{{ $j->link }}" target="_blank" rel="noopener">buka</a>@else—@endif</td>
                        <td>
                            <a href="{{ route('journal.show', $j->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                            @if($canManage)
                                <a href="{{ route('journal.edit', $j->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                <form action="{{ route('journal.destroy', $j->id) }}" method="POST" class="d-inline m-0" data-confirm="Hapus jurnal ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
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
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada jurnal.' } }); });</script>
@endpush
```

- [ ] **Step 4: Form view** — create `resources/views/journals/form.blade.php`:

```blade
@extends('layouts.master')
@section('title', ($journal->exists ? 'Edit' : 'Tambah') . ' Jurnal - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">{{ $journal->exists ? 'Edit' : 'Tambah' }} Jurnal</h5>
    <a href="{{ route('journal.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-lg-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <form method="POST" action="{{ $journal->exists ? route('journal.update', $journal->id) : route('journal.store') }}">
        @csrf
        @if($journal->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Nama Jurnal <span class="text-danger">*</span></label>
            <input type="text" name="nama" class="form-control @error('nama') is-invalid @enderror" value="{{ old('nama', $journal->nama) }}" required>
            @error('nama') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Akreditasi</label>
                <select name="akreditasi" class="form-select select2-tags">
                    <option value="">— pilih / ketik —</option>
                    @foreach(\App\Models\Title::INDEKSASI as $ix)
                        <option value="{{ $ix }}" {{ old('akreditasi', $journal->akreditasi) === $ix ? 'selected' : '' }}>{{ $ix }}</option>
                    @endforeach
                    @if($journal->akreditasi && ! in_array($journal->akreditasi, \App\Models\Title::INDEKSASI, true))
                        <option value="{{ $journal->akreditasi }}" selected>{{ $journal->akreditasi }}</option>
                    @endif
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Scope / Bidang</label>
                <select name="scope_id" class="form-select select2-tags">
                    <option value="">— pilih / ketik —</option>
                    @foreach($scopes as $scope)
                        <option value="{{ $scope->id }}" {{ (string) old('scope_id', $journal->scope_id) === (string) $scope->id ? 'selected' : '' }}>{{ $scope->scope }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">APC Reguler</label>
                <input type="text" name="apc_reguler" class="form-control" value="{{ old('apc_reguler', $journal->apc_reguler) }}" placeholder="Rp / USD / Gratis">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">APC Fastrack</label>
                <input type="text" name="apc_fastrack" class="form-control" value="{{ old('apc_fastrack', $journal->apc_fastrack) }}">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Link Jurnal</label>
            <input type="text" name="link" class="form-control" value="{{ old('link', $journal->link) }}" placeholder="https://…">
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Kontak Editor (WA)</label>
                <input type="text" name="kontak_wa" class="form-control" value="{{ old('kontak_wa', $journal->kontak_wa) }}">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Kontak Editor (Email)</label>
                <input type="text" name="kontak_email" class="form-control" value="{{ old('kontak_email', $journal->kontak_email) }}">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label d-block">Bulan Terbitan</label>
            @php $selMonths = collect(old('terbitan_bulan', $journal->terbitan_bulan ?? []))->map(fn($m) => (int) $m)->all(); @endphp
            <div class="d-flex flex-wrap gap-3">
                @foreach(\App\Models\Journal::MONTHS as $num => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="terbitan_bulan[]" value="{{ $num }}" id="bln{{ $num }}" {{ in_array($num, $selMonths, true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="bln{{ $num }}">{{ $label }}</label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea name="catatan" class="form-control" rows="2">{{ old('catatan', $journal->catatan) }}</textarea>
        </div>

        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { if (window.jQuery && jQuery.fn.select2) jQuery('.select2-tags').select2({ tags: true, width: '100%' }); });</script>
@endpush
```

- [ ] **Step 5: Show view** — create `resources/views/journals/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Detail Jurnal - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">{{ $journal->nama }}</h5>
        <small class="text-muted">{{ $journal->akreditasi ?: 'Tanpa akreditasi' }} · {{ $journal->scope?->scope ?? 'Tanpa scope' }}</small>
    </div>
    <div class="d-flex gap-2">
        @if($canManage)<a href="{{ route('journal.edit', $journal->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endif
        <a href="{{ route('journal.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
</div>

<div class="row"><div class="col-lg-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-4 text-muted small">Akreditasi</dt><dd class="col-sm-8">{{ $journal->akreditasi ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Scope</dt><dd class="col-sm-8">{{ $journal->scope?->scope ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Bulan Terbitan</dt><dd class="col-sm-8">@forelse($journal->terbitanLabels() as $m)<span class="badge bg-light text-dark border me-1">{{ $m }}</span>@empty—@endforelse</dd>
        <dt class="col-sm-4 text-muted small">APC Reguler</dt><dd class="col-sm-8">{{ $journal->apc_reguler ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">APC Fastrack</dt><dd class="col-sm-8">{{ $journal->apc_fastrack ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Link</dt><dd class="col-sm-8">@if($journal->link)<a href="{{ $journal->link }}" target="_blank" rel="noopener">{{ $journal->link }}</a>@else—@endif</dd>
        <dt class="col-sm-4 text-muted small">Kontak Editor</dt><dd class="col-sm-8">WA: {{ $journal->kontak_wa ?: '—' }} · Email: {{ $journal->kontak_email ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $journal->catatan ?: '—' }}</dd>
    </dl>

    <hr class="my-3">
    <h6 class="card-title">Artikel di Jurnal Ini</h6>
    <p class="text-muted small mb-0">Daftar artikel yang di-submit/terbit ke jurnal ini akan hadir di fase berikutnya (tracking submit artikel).</p>
</div></div></div></div>
@endsection
```

- [ ] **Step 6: Sidebar** — in `resources/views/layouts/sidebar.blade.php`, find the "Direktori Judul" nav block:
```blade
            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['titles', 'titles/*']) }}">
                    <a href="{{ route('title.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="book"></i>
                        <span class="link-title">Direktori Judul</span>
                    </a>
                </li>
            @endrole
```
and add, immediately AFTER its closing `@endrole`:
```blade

            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['journals', 'journals/*']) }}">
                    <a href="{{ route('journal.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="book-open"></i>
                        <span class="link-title">Direktori Jurnal</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 7: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="JournalPagesTest|JournalControllerTest"`
Expected: PASS all.

- [ ] **Step 8: Commit**

```
git add resources/views/journals/index.blade.php resources/views/journals/form.blade.php resources/views/journals/show.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/JournalPagesTest.php
git commit -m "feat(journal): index/form/show views + sidebar menu

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Full verification + migrate dev

**Files:** none.

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (351 sebelumnya + JournalControllerTest (3) + JournalPagesTest (2) = ~356).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (buat tabel `tb_journals`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login manager → Direktori Jurnal → Tambah Jurnal (isi akreditasi/scope select2, centang bulan) → simpan → tampil di index dgn badge bulan; buka detail; edit; hapus (SweetAlert2). Login marketing → bisa lihat index/detail, tak ada tombol tambah/edit/hapus.

---

## Catatan & Risiko

- APC teks bebas; `terbitan_bulan` array int (JSON) → badge via `terbitanLabels()`.
- Reuse `Scope`/`tb_scopes` (firstOrCreate) & `Title::INDEKSASI` → kosakata konsisten.
- Seksi "Artikel di jurnal ini" placeholder → diisi Fase B (tracking submit artikel).
- Menu & lihat untuk semua staf; CRUD hanya superadmin/manager/admin (route group + `canManage`).
