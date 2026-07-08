# Lembar Kerja (Custom Sheets) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps pakai checkbox.

**Goal:** Spreadsheet ala Excel dalam app (jSpreadsheet CE), inline edit + autosave, bisa dibagikan (semua/role tertentu), semua user login.

**Tech:** Laravel 11, jSpreadsheet CE v4 + jSuites (CDN), PHPUnit. **Spec:** `docs/superpowers/specs/2026-07-07-custom-sheets-design.md`.

## Konvensi
- Test: `php artisan test` (test DB). Single `--filter=Nama`. TDD backend (grid JS diverifikasi manual).
- Commit: author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`. `git add` path eksplisit. Heredoc via Bash.
- Migrate dev hanya di Task 4.

---

## Task 1: Migrasi + model CustomSheet

**Files:** Create `database/migrations/2026_07_07_000001_create_custom_sheets_table.php`, `app/Models/CustomSheet.php`, `tests/Feature/CustomSheetModelTest.php`.

- [ ] **Step 1: Test gagal** — `tests/Feature/CustomSheetModelTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CustomSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class CustomSheetModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'marketing', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function view_permission_respects_owner_visibility_and_roles(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(); $other->assignRole('marketing');
        $manager = User::factory()->create(); $manager->assignRole('manager');

        $private = CustomSheet::create(['name' => 'P', 'owner_id' => $owner->id, 'visibility' => 'private']);
        $this->assertTrue($private->canView($owner));
        $this->assertFalse($private->canView($other));

        $sharedAll = CustomSheet::create(['name' => 'S', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $this->assertTrue($sharedAll->canView($other));
        $this->assertTrue($sharedAll->canEdit($other));

        $sharedMgr = CustomSheet::create(['name' => 'SM', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);
        $this->assertFalse($sharedMgr->canView($other));   // marketing
        $this->assertTrue($sharedMgr->canView($manager));
        $this->assertTrue($sharedMgr->isOwner($owner));
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=CustomSheetModelTest` → FAIL (class/tabel belum ada).

- [ ] **Step 3: Migrasi** — `database/migrations/2026_07_07_000001_create_custom_sheets_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('visibility')->default('private');
            $table->json('shared_roles')->nullable();
            $table->json('columns')->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_sheets');
    }
};
```

- [ ] **Step 4: Model** — `app/Models/CustomSheet.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomSheet extends Model
{
    protected $table = 'custom_sheets';

    protected $fillable = ['name', 'owner_id', 'visibility', 'shared_roles', 'columns', 'data', 'updated_by'];

    protected $casts = ['shared_roles' => 'array', 'columns' => 'array', 'data' => 'array'];

    const VISIBILITIES = ['private' => 'Pribadi', 'shared' => 'Dibagikan'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOwner(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id;
    }

    public function canView(User $user): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }
        if ($this->visibility !== 'shared') {
            return false;
        }
        if (empty($this->shared_roles)) {
            return true;
        }
        return $user->getRoleNames()->intersect($this->shared_roles)->isNotEmpty();
    }

    public function canEdit(User $user): bool
    {
        return $this->canView($user);
    }
}
```

- [ ] **Step 5: Run — lulus** — `php artisan test --filter=CustomSheetModelTest` → PASS.

- [ ] **Step 6: Commit**
```bash
git add database/migrations/2026_07_07_000001_create_custom_sheets_table.php app/Models/CustomSheet.php tests/Feature/CustomSheetModelTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(sheets): tabel & model CustomSheet (grid JSON + izin view/edit)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 2: Controller + rute + test backend

**Files:** Create `app/Http/Controllers/Pages/CustomSheetController.php`, `tests/Feature/CustomSheetTest.php`; Modify `routes/web.php`.

- [ ] **Step 1: Test gagal** — `tests/Feature/CustomSheetTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CustomSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class CustomSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'marketing', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(?string $role = null): User
    {
        $u = User::factory()->create();
        if ($role) { $u->assignRole($role); }
        return $u;
    }

    /** @test */
    public function user_creates_and_opens_sheet(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('sheet.store'), ['name' => 'Stok Buku'])->assertRedirect();
        $sheet = CustomSheet::where('name', 'Stok Buku')->first();
        $this->assertNotNull($sheet);
        $this->assertSame($u->id, $sheet->owner_id);
        $this->actingAs($u)->get(route('sheet.index'))->assertOk()->assertSee('Stok Buku');
        $this->actingAs($u)->get(route('sheet.show', $sheet->id))->assertOk();
    }

    /** @test */
    public function private_sheet_hidden_from_others(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Rahasia', 'owner_id' => $a->id, 'visibility' => 'private']);

        $this->actingAs($b)->get(route('sheet.index'))->assertOk()->assertDontSee('Rahasia');
        $this->actingAs($b)->get(route('sheet.show', $sheet->id))->assertForbidden();
    }

    /** @test */
    public function shared_sheet_visible_and_editable(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Bareng', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->get(route('sheet.index'))->assertOk()->assertSee('Bareng');
        $this->actingAs($b)->get(route('sheet.show', $sheet->id))->assertOk();
        $this->actingAs($b)->postJson(route('sheet.save', $sheet->id), ['data' => [['x', 'y']], 'columns' => []])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([['x', 'y']], $sheet->fresh()->data);
    }

    /** @test */
    public function shared_roles_restrict_access(): void
    {
        $a = $this->user('marketing');
        $mkt = $this->user('marketing');
        $mgr = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'KhususManajer', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);

        $this->actingAs($mkt)->get(route('sheet.show', $sheet->id))->assertForbidden();
        $this->actingAs($mgr)->get(route('sheet.show', $sheet->id))->assertOk();
    }

    /** @test */
    public function only_owner_updates_or_deletes(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $sheet = CustomSheet::create(['name' => 'Milik A', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->put(route('sheet.update', $sheet->id), ['name' => 'Ubah', 'visibility' => 'private'])->assertForbidden();
        $this->actingAs($b)->delete(route('sheet.destroy', $sheet->id))->assertForbidden();

        $this->actingAs($a)->put(route('sheet.update', $sheet->id), ['name' => 'Baru', 'visibility' => 'private'])->assertRedirect();
        $this->assertSame('Baru', $sheet->fresh()->name);
        $this->actingAs($a)->delete(route('sheet.destroy', $sheet->id))->assertRedirect();
        $this->assertNull(CustomSheet::find($sheet->id));
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=CustomSheetTest` → FAIL (`Route [sheet.store] not defined`).

- [ ] **Step 3: Controller** — `app/Http/Controllers/Pages/CustomSheetController.php`:
```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CustomSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomSheetController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $sheets = CustomSheet::with('owner')
            ->where('owner_id', $user->id)->orWhere('visibility', 'shared')
            ->latest()->get()
            ->filter(fn ($s) => $s->canView($user));

        $mine   = $sheets->where('owner_id', $user->id)->values();
        $shared = $sheets->where('owner_id', '!=', $user->id)->values();

        return view('sheets.index', compact('mine', 'shared'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $sheet = CustomSheet::create([
            'name'       => $data['name'],
            'owner_id'   => Auth::id(),
            'visibility' => 'private',
            'columns'    => [],
            'data'       => array_fill(0, 15, array_fill(0, 6, '')),
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('sheet.show', $sheet->id)->with('success', 'Lembar dibuat.');
    }

    public function show(int $id)
    {
        $sheet = CustomSheet::with('owner')->findOrFail($id);
        abort_unless($sheet->canView(Auth::user()), 403);

        $canEdit = $sheet->canEdit(Auth::user());

        return view('sheets.show', compact('sheet', 'canEdit'));
    }

    public function update(Request $request, int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->isOwner(Auth::user()), 403);

        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'visibility'    => 'required|in:private,shared',
            'shared_roles'  => 'nullable|array',
            'shared_roles.*' => 'string',
        ]);
        $sheet->update([
            'name'         => $data['name'],
            'visibility'   => $data['visibility'],
            'shared_roles' => $data['visibility'] === 'shared' ? ($data['shared_roles'] ?? []) : null,
        ]);

        return back()->with('success', 'Setelan lembar diperbarui.');
    }

    public function save(Request $request, int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->canEdit(Auth::user()), 403);

        $data = $request->validate([
            'data'    => 'nullable|array',
            'columns' => 'nullable|array',
        ]);
        $sheet->update([
            'data'       => $data['data'] ?? [],
            'columns'    => $data['columns'] ?? [],
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i:s')]);
    }

    public function destroy(int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->isOwner(Auth::user()), 403);
        $sheet->delete();

        return redirect()->route('sheet.index')->with('success', 'Lembar dihapus.');
    }
}
```

- [ ] **Step 4: Rute** — di `routes/web.php`, tambah import `use App\Http\Controllers\Pages\CustomSheetController;` (dekat import controller lain) dan dalam grup `Route::middleware('auth')->group(...)` (mis. dekat rute `tasks`/`profile`, TANPA role middleware):
```php
        Route::get('sheets', [CustomSheetController::class, 'index'])->name('sheet.index');
        Route::post('sheets', [CustomSheetController::class, 'store'])->name('sheet.store');
        Route::get('sheets/{id}', [CustomSheetController::class, 'show'])->name('sheet.show')->whereNumber('id');
        Route::put('sheets/{id}', [CustomSheetController::class, 'update'])->name('sheet.update')->whereNumber('id');
        Route::post('sheets/{id}/save', [CustomSheetController::class, 'save'])->name('sheet.save')->whereNumber('id');
        Route::delete('sheets/{id}', [CustomSheetController::class, 'destroy'])->name('sheet.destroy')->whereNumber('id');
```

- [ ] **Step 5: Run — lulus** — `php artisan test --filter=CustomSheetTest` → PASS (5 test).

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Pages/CustomSheetController.php routes/web.php tests/Feature/CustomSheetTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(sheets): CustomSheetController + rute (CRUD + save + izin akses)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: Views (daftar + grid jSpreadsheet) + sidebar

**Files:** Create `resources/views/sheets/index.blade.php`, `resources/views/sheets/show.blade.php`; Modify `resources/views/layouts/sidebar.blade.php`; Test `tests/Feature/CustomSheetTest.php` (tambah 1 smoke).

- [ ] **Step 1: View daftar** — `resources/views/sheets/index.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Lembar Kerja - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php $vis = \App\Models\CustomSheet::VISIBILITIES; @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">Lembar Kerja</h5>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formSheet">+ Lembar Baru</button>
    </div>
    <div class="collapse mb-3" id="formSheet">
        <form method="POST" action="{{ route('sheet.store') }}" class="border rounded p-3 d-flex gap-2 align-items-end flex-wrap">
            @csrf
            <div><label class="form-label small mb-1">Nama Lembar</label><input name="name" class="form-control form-control-sm" style="min-width:240px" required></div>
            <button class="btn btn-sm btn-primary">Buat</button>
        </form>
    </div>

    @foreach (['Lembar Saya' => $mine, 'Dibagikan ke Saya' => $shared] as $label => $list)
        <div class="card mb-3"><div class="card-body">
            <h6 class="card-title">{{ $label }} <span class="badge bg-secondary ms-1">{{ $list->count() }}</span></h6>
            <div class="table-responsive mt-2">
                <table class="table table-sm table-hover datatable" style="width:100%">
                    <thead><tr><th>Nama</th><th>Pemilik</th><th>Visibilitas</th><th>Diperbarui</th><th>Aksi</th></tr></thead>
                    <tbody>
                        @foreach ($list as $s)
                            <tr>
                                <td class="dt-judul">{{ $s->name }}</td>
                                <td>{{ optional($s->owner)->name ?? '-' }}</td>
                                <td><span class="badge {{ $s->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }}">{{ $vis[$s->visibility] ?? $s->visibility }}</span></td>
                                <td>{{ optional($s->updated_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('sheet.show', $s->id) }}" class="btn btn-xs btn-outline-primary">Buka</a>
                                    @if ($s->owner_id === auth()->id())
                                        <form method="POST" action="{{ route('sheet.destroy', $s->id) }}" class="d-inline" data-confirm="Hapus lembar ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
    @endforeach
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [] }); });</script>
@endpush
```

- [ ] **Step 2: View grid** — `resources/views/sheets/show.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Lembar: ' . $sheet->name . ' - SiMAPA')

@push('style')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/jspreadsheet.css" type="text/css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsuites@5/dist/jsuites.css" type="text/css" />
@endpush

@section('content')
    @php $vis = \App\Models\CustomSheet::VISIBILITIES; $isOwner = $sheet->owner_id === auth()->id(); @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">{{ $sheet->name }}
                <span class="badge {{ $sheet->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }} ms-1">{{ $vis[$sheet->visibility] ?? $sheet->visibility }}</span>
            </h5>
            <small class="text-muted">Pemilik: {{ optional($sheet->owner)->name ?? '-' }} · <span id="saveStatus">{{ $canEdit ? 'Perubahan tersimpan otomatis' : 'Hanya-baca' }}</span></small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-success" onclick="window.exportCsv && window.exportCsv()">Export CSV</button>
            @if ($isOwner)
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#formSetelan">Setelan</button>
            @endif
            <a href="{{ route('sheet.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    @if ($isOwner)
        <div class="collapse mb-3" id="formSetelan">
            <form method="POST" action="{{ route('sheet.update', $sheet->id) }}" class="border rounded p-3">
                @csrf @method('PUT')
                <div class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label small mb-1">Nama</label><input name="name" value="{{ $sheet->name }}" class="form-control form-control-sm" required></div>
                    <div class="col-md-3"><label class="form-label small mb-1">Visibilitas</label>
                        <select name="visibility" class="form-select form-select-sm" id="visSel">
                            @foreach ($vis as $k => $v)<option value="{{ $k }}" {{ $sheet->visibility === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-5"><label class="form-label small mb-1">Dibagikan ke role (kosong = semua)</label>
                        <div class="d-flex gap-2 flex-wrap">
                            @foreach (\Spatie\Permission\Models\Role::pluck('name') as $rn)
                                <label class="small mb-0"><input type="checkbox" name="shared_roles[]" value="{{ $rn }}" {{ in_array($rn, (array) $sheet->shared_roles) ? 'checked' : '' }}> {{ ucfirst($rn) }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <button class="btn btn-sm btn-primary mt-2">Simpan Setelan</button>
            </form>
        </div>
    @endif

    <div class="card"><div class="card-body">
        <div id="spreadsheet"></div>
    </div></div>
@endsection

@push('custom-scripts')
    <script src="https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsuites@5/dist/jsuites.js"></script>
    <script>
        (function () {
            const factory = window.jspreadsheet || window.jexcel;
            if (!factory) { document.getElementById('spreadsheet').innerHTML = '<div class="text-danger p-3">Gagal memuat grid (butuh internet untuk library).</div>'; return; }
            const initData = @json($sheet->data ?: []);
            const initColumns = @json($sheet->columns ?: []);
            const canEdit = @json($canEdit);
            const saveUrl = @json(route('sheet.save', $sheet->id));
            const csrf = @json(csrf_token());
            let saveTimer = null;

            const options = {
                data: (initData && initData.length) ? initData : [['', '', '', '', '', '']],
                minDimensions: [6, 15],
                tableOverflow: true,
                tableWidth: '100%',
                columnSorting: true,
                editable: canEdit,
                allowInsertRow: canEdit, allowInsertColumn: canEdit,
                allowDeleteRow: canEdit, allowDeleteColumn: canEdit,
                onchange: scheduleSave, oninsertrow: scheduleSave, ondeleterow: scheduleSave,
                oninsertcolumn: scheduleSave, ondeletecolumn: scheduleSave, onsort: scheduleSave, onmoverow: scheduleSave,
            };
            if (initColumns && initColumns.length) { options.columns = initColumns; }
            const instance = factory(document.getElementById('spreadsheet'), options);
            window.exportCsv = function () { try { instance.download(); } catch (e) {} };

            function scheduleSave() {
                if (!canEdit) return;
                setStatus('Menyimpan…');
                clearTimeout(saveTimer);
                saveTimer = setTimeout(doSave, 800);
            }
            function doSave() {
                let cols = [];
                try { cols = instance.getConfig().columns || []; } catch (e) {}
                fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ data: instance.getData(), columns: cols })
                }).then(r => r.json()).then(j => setStatus(j.ok ? ('Tersimpan ' + (j.saved_at || '')) : 'Gagal simpan'))
                  .catch(() => setStatus('Gagal simpan (jaringan)'));
            }
            function setStatus(t) { const el = document.getElementById('saveStatus'); if (el) el.textContent = t; }
        })();
    </script>
@endpush
```

- [ ] **Step 3: Sidebar** — di `resources/views/layouts/sidebar.blade.php`, tepat SEBELUM blok `{{-- ===================== AKUN & SISTEM ===================== --}}` (baris `<li class="nav-item nav-category">Akun &amp; Sistem</li>`), sisipkan:
```blade
            {{-- ===================== ALAT ===================== --}}
            <li class="nav-item nav-category">Alat</li>
            <li class="nav-item {{ nav_active('sheet.*') }}">
                <a href="{{ route('sheet.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="grid"></i>
                    <span class="link-title">Lembar Kerja</span>
                </a>
            </li>
```

- [ ] **Step 4: Smoke test** — di `tests/Feature/CustomSheetTest.php` tambahkan:
```php
    /** @test */
    public function index_and_show_render(): void
    {
        $u = $this->user('marketing');
        $sheet = \App\Models\CustomSheet::create(['name' => 'Render Uji', 'owner_id' => $u->id, 'visibility' => 'private', 'data' => [['a', 'b']]]);
        $this->actingAs($u)->get(route('sheet.index'))->assertOk()->assertSee('Lembar Kerja')->assertSee('Render Uji');
        $this->actingAs($u)->get(route('sheet.show', $sheet->id))->assertOk()->assertSee('spreadsheet');
    }
```

- [ ] **Step 5: Run + view:cache** — `php artisan test --filter=CustomSheetTest` → PASS (6 test). `php artisan view:cache` → sukses; `php artisan view:clear`.

- [ ] **Step 6: Commit**
```bash
git add resources/views/sheets/index.blade.php resources/views/sheets/show.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/CustomSheetTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(sheets): UI Lembar Kerja (daftar + grid jSpreadsheet) + menu Alat

Daftar lembar (saya/dibagikan), grid jSpreadsheet CE inline + autosave,
setelan visibilitas/role (pemilik), export CSV. Menu Alat > Lembar Kerja
untuk semua user login.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: Regresi + migrasi dev

- [ ] **Step 1** — `php artisan test` → PASS semua.
- [ ] **Step 2** — `php artisan view:cache` (tanpa error) lalu `php artisan view:clear`.
- [ ] **Step 3** — `php artisan migrate` di DB dev (`avidpedi_simapa`): tabel `custom_sheets`.
- [ ] **Step 4 (manual)** — buka `/sheets` (login): buat lembar → grid muncul, ketik di sel → status "Tersimpan HH:MM:SS"; refresh → data tetap; setel Dibagikan + role → user lain lihat; Export CSV unduh.

## Self-Review
- Spec coverage: model+izin (T1), controller+rute+akses+save (T2), view daftar+grid+sidebar (T3), regresi+migrate (T4).
- Type consistency: `CustomSheet` casts array; `canView/canEdit/isOwner(User)`; rute `sheet.index/store/show/update/save/destroy` konsisten controller/view/test; `save` JSON `{ok, saved_at}`; grid `factory(el, {data, columns})` + autosave POST `sheet.save`.
- Risiko: grid JS (CDN) diverifikasi manual (Step 4 manual); backend teruji penuh.

