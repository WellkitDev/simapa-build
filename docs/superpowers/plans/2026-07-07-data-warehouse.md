# Gudang Data — Implementation Plan (ganti Lembar Kerja)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Checkbox steps.

**Goal:** Hapus fitur Lembar Kerja; bangun **Gudang Data** = repositori entri link Google Sheets / upload Excel-CSV, bisa dibagikan (private/shared+role), semua user login.

**Spec:** `docs/superpowers/specs/2026-07-07-data-warehouse-design.md`

## Konvensi
- Test: `php artisan test` (test DB). TDD. Commit: author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`. `git add` eksplisit. Heredoc via Bash. Migrate dev hanya di Task 4.

---

## Task 1: Hapus Lembar Kerja + skema & model DataAsset

**Files:** Delete `app/Models/CustomSheet.php`, `app/Http/Controllers/Pages/CustomSheetController.php`, `resources/views/sheets/index.blade.php`, `resources/views/sheets/show.blade.php`, `tests/Feature/CustomSheetTest.php`, `tests/Feature/CustomSheetModelTest.php`; Modify `routes/web.php`, `resources/views/layouts/sidebar.blade.php`; Create `database/migrations/2026_07_07_000002_drop_custom_sheets_table.php`, `database/migrations/2026_07_07_000003_create_data_assets_table.php`, `app/Models/DataAsset.php`, `tests/Feature/DataAssetModelTest.php`.

- [ ] **Step 1: Hapus file Lembar Kerja** — hapus keenam file di atas (gunakan `git rm` atau hapus lalu stage). Di `routes/web.php`: hapus import `use App\Http\Controllers\Pages\CustomSheetController;` dan 6 rute `sheet.*`. Di `resources/views/layouts/sidebar.blade.php`: hapus blok kategori "Alat" + item "Lembar Kerja" (nanti diganti Gudang Data di Task 3).

- [ ] **Step 2: Migrasi drop** — `database/migrations/2026_07_07_000002_drop_custom_sheets_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('custom_sheets');
    }

    public function down(): void
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
};
```

- [ ] **Step 3: Test gagal** — `tests/Feature/DataAssetModelTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DataAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DataAssetModelTest extends TestCase
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

        $private = DataAsset::create(['name' => 'P', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'private']);
        $this->assertTrue($private->canView($owner));
        $this->assertFalse($private->canView($other));
        $this->assertTrue($private->isOwner($owner));

        $sharedAll = DataAsset::create(['name' => 'S', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $this->assertTrue($sharedAll->canView($other));

        $sharedMgr = DataAsset::create(['name' => 'SM', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $owner->id, 'visibility' => 'shared', 'shared_roles' => ['manager']]);
        $this->assertFalse($sharedMgr->canView($other));
        $this->assertTrue($sharedMgr->canView($manager));
    }
}
```

- [ ] **Step 4: Run — gagal** — `php artisan test --filter=DataAssetModelTest` → FAIL (class/tabel belum ada).

- [ ] **Step 5: Migrasi data_assets** — `database/migrations/2026_07_07_000003_create_data_assets_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('url', 1000)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('visibility')->default('private');
            $table->json('shared_roles')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_assets');
    }
};
```

- [ ] **Step 6: Model** — `app/Models/DataAsset.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataAsset extends Model
{
    protected $table = 'data_assets';

    protected $fillable = ['name', 'description', 'type', 'url', 'file_path', 'file_name', 'file_size', 'owner_id', 'visibility', 'shared_roles', 'updated_by'];

    protected $casts = ['shared_roles' => 'array', 'file_size' => 'integer'];

    const VISIBILITIES = ['private' => 'Pribadi', 'shared' => 'Dibagikan'];
    const TYPES = ['link' => 'Link', 'file' => 'File'];

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
}
```

- [ ] **Step 7: Run — lulus** — `php artisan test --filter=DataAssetModelTest` → PASS. Juga `php artisan test --filter=CustomSheet` → **0 test** (file terhapus) & tak error.

- [ ] **Step 8: Commit**
```bash
git add -u routes/web.php resources/views/layouts/sidebar.blade.php app/Models/CustomSheet.php app/Http/Controllers/Pages/CustomSheetController.php resources/views/sheets tests/Feature/CustomSheetTest.php tests/Feature/CustomSheetModelTest.php
git add database/migrations/2026_07_07_000002_drop_custom_sheets_table.php database/migrations/2026_07_07_000003_create_data_assets_table.php app/Models/DataAsset.php tests/Feature/DataAssetModelTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
refactor(data): hapus Lembar Kerja, mulai Gudang Data (skema+model)

Drop custom_sheets; tambah data_assets (link/file, owner, visibilitas)
+ model DataAsset (izin view/owner). Rute & menu Lembar Kerja dihapus.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```
> Catatan: `git rm` file yang dihapus bila `git add -u` tak menstage penghapusan — pastikan `git status` bersih untuk path tsb.

---

## Task 2: DataAssetController + rute + test

**Files:** Create `app/Http/Controllers/Pages/DataAssetController.php`, `tests/Feature/DataAssetTest.php`; Modify `routes/web.php`.

- [ ] **Step 1: Test gagal** — `tests/Feature/DataAssetTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\DataAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DataAssetTest extends TestCase
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
    public function creates_link_asset(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('data.store'), [
            'name' => 'Rekap Marketing', 'type' => 'link', 'url' => 'https://docs.google.com/spreadsheets/d/abc', 'visibility' => 'private',
        ])->assertRedirect();
        $a = DataAsset::where('name', 'Rekap Marketing')->first();
        $this->assertNotNull($a);
        $this->assertSame('link', $a->type);
        $this->assertSame($u->id, $a->owner_id);
        $this->actingAs($u)->get(route('data.index'))->assertOk()->assertSee('Rekap Marketing');
    }

    /** @test */
    public function uploads_excel_file(): void
    {
        Storage::fake();
        $u = $this->user('marketing');
        $file = UploadedFile::fake()->create('stok.xlsx', 120, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($u)->post(route('data.store'), [
            'name' => 'Stok', 'type' => 'file', 'file' => $file, 'visibility' => 'private',
        ])->assertRedirect();
        $a = DataAsset::where('name', 'Stok')->first();
        $this->assertSame('file', $a->type);
        $this->assertNotNull($a->file_path);
        $this->assertSame('stok.xlsx', $a->file_name);
        Storage::assertExists($a->file_path);

        $this->actingAs($u)->get(route('data.download', $a->id))->assertOk();
    }

    /** @test */
    public function validation_requires_url_or_file_per_type(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('data.store'), ['name' => 'X', 'type' => 'link', 'visibility' => 'private'])->assertSessionHasErrors('url');
        $this->actingAs($u)->post(route('data.store'), ['name' => 'Y', 'type' => 'file', 'visibility' => 'private'])->assertSessionHasErrors('file');
    }

    /** @test */
    public function private_hidden_shared_visible(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $priv = DataAsset::create(['name' => 'Rahasia', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'private']);
        $shar = DataAsset::create(['name' => 'Bareng', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);

        $this->actingAs($b)->get(route('data.index'))->assertOk()->assertDontSee('Rahasia')->assertSee('Bareng');
        $this->actingAs($b)->get(route('data.download', $priv->id))->assertForbidden();
    }

    /** @test */
    public function only_owner_edits_and_deletes(): void
    {
        Storage::fake();
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $file = UploadedFile::fake()->create('d.csv', 10, 'text/csv');
        $this->actingAs($a)->post(route('data.store'), ['name' => 'Milik A', 'type' => 'file', 'file' => $file, 'visibility' => 'shared', 'shared_roles' => []])->assertRedirect();
        $asset = DataAsset::where('name', 'Milik A')->first();

        $this->actingAs($b)->get(route('data.edit', $asset->id))->assertForbidden();
        $this->actingAs($b)->put(route('data.update', $asset->id), ['name' => 'Ubah', 'type' => 'link', 'url' => 'https://x', 'visibility' => 'private'])->assertForbidden();
        $this->actingAs($b)->delete(route('data.destroy', $asset->id))->assertForbidden();

        $path = $asset->file_path;
        $this->actingAs($a)->delete(route('data.destroy', $asset->id))->assertRedirect();
        $this->assertNull(DataAsset::find($asset->id));
        Storage::assertMissing($path);
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=DataAssetTest` → FAIL (`Route [data.store] not defined`).

- [ ] **Step 3: Controller** — `app/Http/Controllers/Pages/DataAssetController.php`:
```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\DataAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DataAssetController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $assets = DataAsset::with('owner')
            ->where('owner_id', $user->id)->orWhere('visibility', 'shared')
            ->latest()->get()
            ->filter(fn ($a) => $a->canView($user))->values();

        return view('data-assets.index', compact('assets'));
    }

    public function create()
    {
        $asset = null;
        return view('data-assets.create', compact('asset'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $payload = $this->payload($request, $data);
        $payload['owner_id'] = Auth::id();
        DataAsset::create($payload);

        return redirect()->route('data.index')->with('success', 'Data ditambahkan ke gudang.');
    }

    public function edit(int $id)
    {
        $asset = DataAsset::findOrFail($id);
        abort_unless($asset->isOwner(Auth::user()), 403);

        return view('data-assets.edit', compact('asset'));
    }

    public function update(Request $request, int $id)
    {
        $asset = DataAsset::findOrFail($id);
        abort_unless($asset->isOwner(Auth::user()), 403);

        $data = $this->validated($request, false);
        $payload = $this->payload($request, $data, $asset);
        $asset->update($payload);

        return redirect()->route('data.index')->with('success', 'Data diperbarui.');
    }

    public function download(int $id)
    {
        $asset = DataAsset::findOrFail($id);
        abort_unless($asset->canView(Auth::user()), 403);
        abort_if($asset->type !== 'file' || ! $asset->file_path, 404);

        return Storage::download($asset->file_path, $asset->file_name);
    }

    public function destroy(int $id)
    {
        $asset = DataAsset::findOrFail($id);
        abort_unless($asset->isOwner(Auth::user()), 403);
        if ($asset->type === 'file' && $asset->file_path) {
            Storage::delete($asset->file_path);
        }
        $asset->delete();

        return redirect()->route('data.index')->with('success', 'Data dihapus.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string',
            'type'           => 'required|in:link,file',
            'url'            => [$creating ? 'required_if:type,link' : 'nullable', 'nullable', 'url', 'max:1000'],
            'file'           => [$creating ? 'required_if:type,file' : 'nullable', 'nullable', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'visibility'     => 'required|in:private,shared',
            'shared_roles'   => 'nullable|array',
            'shared_roles.*' => 'string',
        ]);
    }

    private function payload(Request $request, array $data, ?DataAsset $asset = null): array
    {
        $payload = [
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'type'         => $data['type'],
            'visibility'   => $data['visibility'],
            'shared_roles' => $data['visibility'] === 'shared' ? ($data['shared_roles'] ?? []) : null,
            'updated_by'   => Auth::id(),
        ];

        if ($data['type'] === 'link') {
            $payload['url'] = $data['url'] ?? ($asset->url ?? null);
            $payload['file_path'] = null; $payload['file_name'] = null; $payload['file_size'] = null;
            if ($asset && $asset->file_path) { Storage::delete($asset->file_path); }
        } else { // file
            if ($request->hasFile('file')) {
                if ($asset && $asset->file_path) { Storage::delete($asset->file_path); }
                $f = $request->file('file');
                $payload['file_path'] = $f->store('data-assets');
                $payload['file_name'] = $f->getClientOriginalName();
                $payload['file_size'] = $f->getSize();
            }
            $payload['url'] = null;
        }

        return $payload;
    }
}
```

- [ ] **Step 4: Rute** — di `routes/web.php`: import `use App\Http\Controllers\Pages\DataAssetController;`; dalam grup `auth` (tanpa role):
```php
        Route::get('gudang', [DataAssetController::class, 'index'])->name('data.index');
        Route::get('gudang/tambah', [DataAssetController::class, 'create'])->name('data.create');
        Route::post('gudang', [DataAssetController::class, 'store'])->name('data.store');
        Route::get('gudang/{id}/edit', [DataAssetController::class, 'edit'])->name('data.edit')->whereNumber('id');
        Route::put('gudang/{id}', [DataAssetController::class, 'update'])->name('data.update')->whereNumber('id');
        Route::get('gudang/{id}/download', [DataAssetController::class, 'download'])->name('data.download')->whereNumber('id');
        Route::delete('gudang/{id}', [DataAssetController::class, 'destroy'])->name('data.destroy')->whereNumber('id');
```
> Rute `data.index` GET terdaftar SEBELUM `gudang/tambah`? Tidak masalah — `gudang` (index) & `gudang/tambah` (create) path beda. `gudang/{id}` PUT/DELETE pakai `whereNumber` sehingga `tambah` tak tertangkap sebagai id.

- [ ] **Step 5: Run — lulus** — `php artisan test --filter=DataAssetTest` → PASS (5 test).

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Pages/DataAssetController.php routes/web.php tests/Feature/DataAssetTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(data): DataAssetController + rute (link/upload, unduh, izin)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: Views + sidebar

**Files:** Create `resources/views/data-assets/index.blade.php`, `create.blade.php`, `edit.blade.php`, `_form.blade.php`; Modify `resources/views/layouts/sidebar.blade.php`.

- [ ] **Step 1: Partial form** — `resources/views/data-assets/_form.blade.php`:
```blade
@php $isFile = old('type', optional($asset)->type ?? 'link') === 'file'; @endphp
<div class="mb-3"><label class="form-label">Nama <span class="text-danger">*</span></label>
    <input name="name" value="{{ old('name', optional($asset)->name) }}" class="form-control @error('name') is-invalid @enderror" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="mb-3"><label class="form-label">Deskripsi</label>
    <textarea name="description" rows="2" class="form-control">{{ old('description', optional($asset)->description) }}</textarea>
</div>
<div class="mb-3"><label class="form-label d-block">Jenis</label>
    @foreach (\App\Models\DataAsset::TYPES as $k => $v)
        <label class="me-3"><input type="radio" name="type" value="{{ $k }}" {{ old('type', optional($asset)->type ?? 'link') === $k ? 'checked' : '' }} onclick="toggleSrc('{{ $k }}')"> {{ $v }}</label>
    @endforeach
</div>
<div class="mb-3 src-link" style="{{ $isFile ? 'display:none' : '' }}">
    <label class="form-label">Link Google Sheets / URL @if(!$asset)<span class="text-danger">*</span>@endif</label>
    <input name="url" value="{{ old('url', optional($asset)->url) }}" class="form-control @error('url') is-invalid @enderror" placeholder="https://docs.google.com/spreadsheets/...">
    @error('url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
<div class="mb-3 src-file" style="{{ $isFile ? '' : 'display:none' }}">
    <label class="form-label">File Excel/CSV (.xlsx, .xls, .csv) @if(!$asset)<span class="text-danger">*</span>@endif</label>
    <input type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror">
    @error('file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @if ($asset && $asset->file_name)<small class="text-muted">File saat ini: {{ $asset->file_name }} — kosongkan bila tak ingin ganti.</small>@endif
</div>
<div class="row g-2 mb-3">
    <div class="col-md-4"><label class="form-label">Visibilitas</label>
        <select name="visibility" class="form-select">
            @foreach (\App\Models\DataAsset::VISIBILITIES as $k => $v)<option value="{{ $k }}" {{ old('visibility', optional($asset)->visibility ?? 'private') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-8"><label class="form-label">Dibagikan ke role (kosong = semua)</label>
        <div class="d-flex gap-2 flex-wrap">
            @foreach (\Spatie\Permission\Models\Role::pluck('name') as $rn)
                <label class="small mb-0"><input type="checkbox" name="shared_roles[]" value="{{ $rn }}" {{ in_array($rn, old('shared_roles', (array) optional($asset)->shared_roles)) ? 'checked' : '' }}> {{ ucfirst($rn) }}</label>
            @endforeach
        </div>
    </div>
</div>
<script>
    function toggleSrc(t) {
        document.querySelectorAll('.src-link').forEach(e => e.style.display = t === 'link' ? '' : 'none');
        document.querySelectorAll('.src-file').forEach(e => e.style.display = t === 'file' ? '' : 'none');
    }
</script>
```

- [ ] **Step 2: Create view** — `resources/views/data-assets/create.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Tambah Data - Gudang Data - SiMAPA')
@section('content')
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Tambah Data</h5>
    <form method="POST" action="{{ route('data.store') }}" enctype="multipart/form-data">
        @csrf
        @include('data-assets._form')
        <button class="btn btn-primary">Simpan</button>
        <a href="{{ route('data.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
```

- [ ] **Step 3: Edit view** — `resources/views/data-assets/edit.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Edit Data - Gudang Data - SiMAPA')
@section('content')
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Edit Data</h5>
    <form method="POST" action="{{ route('data.update', $asset->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @include('data-assets._form')
        <button class="btn btn-primary">Simpan</button>
        <a href="{{ route('data.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
```

- [ ] **Step 4: Index view** — `resources/views/data-assets/index.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Gudang Data - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php $vis = \App\Models\DataAsset::VISIBILITIES; $kb = fn ($b) => $b ? number_format($b / 1024, 0) . ' KB' : ''; @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">Gudang Data</h5>
        <a href="{{ route('data.create') }}" class="btn btn-sm btn-primary">+ Tambah Data</a>
    </div>
    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover datatable" style="width:100%">
                <thead><tr><th>Nama</th><th>Jenis</th><th>Sumber</th><th>Deskripsi</th><th>Pemilik</th><th>Visibilitas</th><th>Diperbarui</th><th>Aksi</th></tr></thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr>
                            <td class="dt-judul">{{ $a->name }}</td>
                            <td><span class="badge {{ $a->type === 'file' ? 'bg-primary' : 'bg-info' }}">{{ \App\Models\DataAsset::TYPES[$a->type] ?? $a->type }}</span></td>
                            <td>
                                @if ($a->type === 'link' && $a->url)
                                    <a href="{{ $a->url }}" target="_blank" rel="noopener" class="btn btn-xs btn-outline-info">Buka ↗</a>
                                @elseif ($a->type === 'file' && $a->file_path)
                                    <a href="{{ route('data.download', $a->id) }}" class="btn btn-xs btn-outline-primary">Unduh ⬇</a>
                                    <small class="text-muted d-block">{{ $a->file_name }} · {{ $kb($a->file_size) }}</small>
                                @else — @endif
                            </td>
                            <td class="dt-judul">{{ $a->description ?: '-' }}</td>
                            <td>{{ optional($a->owner)->name ?? '-' }}</td>
                            <td><span class="badge {{ $a->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }}">{{ $vis[$a->visibility] ?? $a->visibility }}</span></td>
                            <td>{{ optional($a->updated_at)->format('d/m/Y H:i') }}</td>
                            <td>
                                @if ($a->owner_id === auth()->id())
                                    <a href="{{ route('data.edit', $a->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                    <form method="POST" action="{{ route('data.destroy', $a->id) }}" class="d-inline" data-confirm="Hapus data ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [] }); });</script>
@endpush
```

- [ ] **Step 5: Sidebar** — di `resources/views/layouts/sidebar.blade.php`, tepat SEBELUM `{{-- ===================== AKUN & SISTEM ===================== --}}` (baris `<li class="nav-item nav-category">Akun &amp; Sistem</li>`), sisipkan:
```blade
            {{-- ===================== ALAT ===================== --}}
            <li class="nav-item nav-category">Alat</li>
            <li class="nav-item {{ nav_active('data.*') }}">
                <a href="{{ route('data.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="database"></i>
                    <span class="link-title">Gudang Data</span>
                </a>
            </li>

```

- [ ] **Step 6: Run + view:cache** — `php artisan test --filter=DataAssetTest` → PASS. `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 7: Commit**
```bash
git add resources/views/data-assets/index.blade.php resources/views/data-assets/create.blade.php resources/views/data-assets/edit.blade.php resources/views/data-assets/_form.blade.php resources/views/layouts/sidebar.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(data): UI Gudang Data (daftar + form link/upload) + menu Alat

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: Regresi + migrasi dev

- [x] **Step 1** — `php artisan test` → PASS semua (506 passed, 1510 assertions, 2026-07-17).
- [x] **Step 2** — `php artisan view:cache` (tanpa error) lalu `php artisan view:clear`.
- [x] **Step 3** — `php artisan migrate` di dev: `000002` drop custom_sheets + `000003` create data_assets.
- [x] **Step 4 (manual)** — diverifikasi 2026-07-17 via HTTP sungguhan (`artisan serve` + sesi login + CSRF), dua user sementara (owner admin + viewer marketing), lalu dibersihkan. Hasil: Link Sheets tersimpan + "Buka" tampil · upload .xlsx → unduh **byte-identik** (md5 sama) + nama file asli via Content-Disposition · Dibagikan+role marketing → viewer lihat & unduh 200; link pribadi milik orang lain tak tampil & unduh langsung **403** · edit ganti role ke production → viewer langsung 403 (file lama tetap utuh saat tak unggah ulang) · non-pemilik edit/hapus **403** · hapus → baris hilang **dan file fisik terhapus** dari `storage/app/data-assets`.

## Self-Review
- Coverage: hapus Lembar Kerja + skema/model (T1), controller+rute+akses+upload/download (T2), view+sidebar (T3), regresi+migrate (T4).
- Konsistensi: `DataAsset` (isOwner/canView), rute `data.*`, `type link|file`, upload `store('data-assets')` + `Storage::download`, hapus file saat destroy/replace. Test pakai `Storage::fake` + `UploadedFile::fake` mime eksplisit.
