# Pengumuman Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pengumuman yang dibuat admin (superadmin/manager/admin) tampil sebagai slider kartu di dashboard SEMUA role, dengan badge "Baru" (per-user + recency ≤3 hari) dan durasi auto-slide menurut panjang teks.

**Architecture:** Dua tabel (`tb_announcements` + `tb_announcement_reads`). `AnnouncementService` menyiapkan data dashboard + menandai dibaca. Halaman admin CRUD (editor Summernote). Slider = carousel Bootstrap 5 di partial yang di-inject ke semua cabang dashboard via View Composer.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie roles, Blade + Bootstrap 5 carousel + DataTables, Summernote (CDN, admin only), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-23-announcement-dashboard-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing`. Bila ada error koneksi DB, MySQL (XAMPP) mungkin mati — jalankan `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Setelah selesai, jalankan `php artisan migrate` di dev/prod (dua tabel ini).

---

## File Structure

**Create:** migrasi `2026_06_24_000001_create_tb_announcements_table.php`, `2026_06_24_000002_create_tb_announcement_reads_table.php`; `app/Models/Announcement.php`, `app/Models/AnnouncementRead.php`; `app/Services/AnnouncementService.php`; `app/Http/Controllers/Pages/AnnouncementController.php`; `resources/views/announcements/index.blade.php`, `resources/views/announcements/form.blade.php`; `resources/views/dashboard/partials/announcements.blade.php`; tests `tests/Unit/AnnouncementServiceTest.php`, `tests/Feature/AnnouncementAdminTest.php`, `tests/Feature/AnnouncementDashboardTest.php`.

**Modify:** `routes/web.php`, `resources/views/layouts/sidebar.blade.php`, `app/Providers/AppServiceProvider.php`, `resources/views/dashboard.blade.php`.

---

## Task 1: Migrations + Models

**Files:**
- Create: `database/migrations/2026_06_24_000001_create_tb_announcements_table.php`
- Create: `database/migrations/2026_06_24_000002_create_tb_announcement_reads_table.php`
- Create: `app/Models/Announcement.php`, `app/Models/AnnouncementRead.php`

- [ ] **Step 1: Migrasi `tb_announcements`**

Create `database/migrations/2026_06_24_000001_create_tb_announcements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body');
            $table->string('status', 16)->default('draft');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_announcements');
    }
};
```

- [ ] **Step 2: Migrasi `tb_announcement_reads`**

Create `database/migrations/2026_06_24_000002_create_tb_announcement_reads_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('tb_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_announcement_reads');
    }
};
```

- [ ] **Step 3: Model `Announcement`**

Create `app/Models/Announcement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $table = 'tb_announcements';

    /** Ambang "Baru" (hari sejak published_at). */
    public const NEW_DAYS = 3;

    protected $fillable = [
        'title', 'body', 'status', 'is_pinned', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_pinned'    => 'boolean',
        'published_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads()
    {
        return $this->hasMany(AnnouncementRead::class);
    }
}
```

- [ ] **Step 4: Model `AnnouncementRead`**

Create `app/Models/AnnouncementRead.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementRead extends Model
{
    use HasFactory;

    protected $table = 'tb_announcement_reads';

    protected $fillable = ['announcement_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
```

- [ ] **Step 5: Verifikasi migrasi sehat**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS (RefreshDatabase memigrasi kedua tabel baru tanpa error).

- [ ] **Step 6: Commit**

```
git add database/migrations/2026_06_24_000001_create_tb_announcements_table.php database/migrations/2026_06_24_000002_create_tb_announcement_reads_table.php app/Models/Announcement.php app/Models/AnnouncementRead.php
git commit -m "feat(announce): announcements + reads tables and models

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `AnnouncementService` (TDD)

**Files:**
- Create: `app/Services/AnnouncementService.php`
- Test: `tests/Unit/AnnouncementServiceTest.php`

- [ ] **Step 1: Tulis unit test yang gagal**

Create `tests/Unit/AnnouncementServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AnnouncementServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AnnouncementService();
    }

    /** @test */
    public function for_dashboard_returns_published_ordered_pinned_then_recent(): void
    {
        $u = User::factory()->create();
        Announcement::create(['title' => 'Draf', 'body' => 'x', 'status' => 'draft']);
        Announcement::create(['title' => 'Lama', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(2)]);
        Announcement::create(['title' => 'Dipin', 'body' => 'x', 'status' => 'published', 'is_pinned' => true, 'published_at' => now()->subDay()]);

        $rows = $this->svc->forDashboard($u);

        $this->assertCount(2, $rows);           // draft dikecualikan
        $this->assertSame('Dipin', $rows[0]['title']); // pinned dulu
        $this->assertSame('Lama', $rows[1]['title']);
    }

    /** @test */
    public function is_new_reflects_recency_and_read_state(): void
    {
        $u = User::factory()->create();
        $fresh = Announcement::create(['title' => 'Baru', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'Lawas', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(5)]);

        $rows = $this->svc->forDashboard($u);
        $this->assertTrue($rows->firstWhere('title', 'Baru')['is_new']);
        $this->assertFalse($rows->firstWhere('title', 'Lawas')['is_new']);

        $this->svc->markSeen($u, [$fresh->id]);
        $rows = $this->svc->forDashboard($u);
        $this->assertFalse($rows->firstWhere('title', 'Baru')['is_new']);
    }

    /** @test */
    public function mark_seen_is_idempotent(): void
    {
        $u = User::factory()->create();
        $a = Announcement::create(['title' => 'A', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

        $this->svc->markSeen($u, [$a->id]);
        $this->svc->markSeen($u, [$a->id]);

        $this->assertSame(1, AnnouncementRead::where(['announcement_id' => $a->id, 'user_id' => $u->id])->count());
    }

    /** @test */
    public function publish_sets_published_at_once(): void
    {
        $a = Announcement::create(['title' => 'A', 'body' => 'x', 'status' => 'draft']);

        $this->svc->publish($a);
        $first = $a->fresh()->published_at;
        $this->assertNotNull($first);

        $this->svc->archive($a);
        $this->svc->publish($a);
        $this->assertSame($first->format('Y-m-d H:i:s'), $a->fresh()->published_at->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=AnnouncementServiceTest`
Expected: FAIL — `Class "App\Services\AnnouncementService" not found`.

- [ ] **Step 3: Implement service**

Create `app/Services/AnnouncementService.php`:

```php
<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Support\Collection;

class AnnouncementService
{
    /** Pengumuman published untuk dashboard: pinned dulu lalu terbaru, + flag is_new per user. */
    public function forDashboard(User $user): Collection
    {
        $announcements = Announcement::with('creator')
            ->where('status', 'published')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get();

        $readIds = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('announcement_id')->all();

        $cutoff = now()->subDays(Announcement::NEW_DAYS);

        return $announcements->map(function (Announcement $a) use ($readIds, $cutoff) {
            $isNew = $a->published_at && $a->published_at->gte($cutoff) && ! in_array($a->id, $readIds, true);

            return [
                'id'           => $a->id,
                'title'        => $a->title,
                'body'         => $a->body,
                'published_at' => optional($a->published_at)->format('d M Y'),
                'creator_name' => $a->creator?->name ?? '—',
                'is_new'       => $isNew,
            ];
        })->values();
    }

    /** Tandai pengumuman (published) sebagai dibaca oleh user; idempotent. */
    public function markSeen(User $user, array $ids): void
    {
        $valid = Announcement::where('status', 'published')->whereIn('id', $ids)->pluck('id');

        foreach ($valid as $id) {
            AnnouncementRead::firstOrCreate(
                ['announcement_id' => $id, 'user_id' => $user->id],
                ['read_at' => now()],
            );
        }
    }

    /** Set status published; published_at di-set SEKALI (tidak reset bila sudah pernah). */
    public function publish(Announcement $a): void
    {
        $a->update([
            'status'       => 'published',
            'published_at' => $a->published_at ?? now(),
        ]);
    }

    public function archive(Announcement $a): void
    {
        $a->update(['status' => 'archived']);
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=AnnouncementServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/AnnouncementService.php tests/Unit/AnnouncementServiceTest.php
git commit -m "feat(announce): AnnouncementService (forDashboard, markSeen, publish/archive)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Halaman admin CRUD (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/AnnouncementController.php`
- Create: `resources/views/announcements/index.blade.php`, `resources/views/announcements/form.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/AnnouncementAdminTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/AnnouncementAdminTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AnnouncementAdminTest extends TestCase
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
    public function admin_can_create_publish_archive_delete(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('announcement.index'))->assertOk();

        $this->actingAs($admin)->post(route('announcement.store'), [
            'title' => 'PENGUMUMAN UJI', 'body' => '<p>halo</p>', 'status' => 'published', 'is_pinned' => 1,
        ])->assertRedirect();

        $a = Announcement::where('title', 'PENGUMUMAN UJI')->first();
        $this->assertNotNull($a);
        $this->assertSame('published', $a->status);
        $this->assertNotNull($a->published_at);
        $this->assertTrue($a->is_pinned);

        $this->actingAs($admin)->post(route('announcement.status', $a->id), ['status' => 'archived'])->assertRedirect();
        $this->assertSame('archived', $a->fresh()->status);

        $this->actingAs($admin)->delete(route('announcement.destroy', $a->id))->assertRedirect();
        $this->assertDatabaseMissing('tb_announcements', ['id' => $a->id]);
    }

    /** @test */
    public function store_strips_script_tags(): void
    {
        $this->actingAs($this->user('superadmin'))->post(route('announcement.store'), [
            'title' => 'XSS', 'body' => '<p>ok</p><script>alert(1)</script>', 'status' => 'draft',
        ])->assertRedirect();

        $this->assertStringNotContainsString('<script>', Announcement::where('title', 'XSS')->first()->body);
    }

    /** @test */
    public function non_admin_roles_cannot_access(): void
    {
        $this->actingAs($this->user('production'))->get(route('announcement.index'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->get(route('announcement.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=AnnouncementAdminTest`
Expected: FAIL — route `announcement.index` not defined.

- [ ] **Step 3: Controller**

Create `app/Http/Controllers/Pages/AnnouncementController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::with('creator')->latest()->get();
        return view('announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('announcements.form', ['announcement' => new Announcement()]);
    }

    public function store(Request $request, AnnouncementService $service)
    {
        $data = $this->validateData($request);

        $a = Announcement::create([
            'title'      => $data['title'],
            'body'       => $this->cleanBody($data['body']),
            'is_pinned'  => $request->boolean('is_pinned'),
            'created_by' => Auth::id(),
            'status'     => 'draft',
        ]);

        if ($data['status'] === 'published') {
            $service->publish($a);
        }

        return redirect()->route('announcement.index')->with('success', 'Pengumuman disimpan.');
    }

    public function edit(int $id)
    {
        return view('announcements.form', ['announcement' => Announcement::findOrFail($id)]);
    }

    public function update(Request $request, int $id, AnnouncementService $service)
    {
        $a = Announcement::findOrFail($id);
        $data = $this->validateData($request);

        $a->update([
            'title'     => $data['title'],
            'body'      => $this->cleanBody($data['body']),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        $data['status'] === 'published' ? $service->publish($a) : $a->update(['status' => 'draft']);

        return redirect()->route('announcement.index')->with('success', 'Pengumuman diperbarui.');
    }

    public function destroy(int $id)
    {
        Announcement::findOrFail($id)->delete();
        return back()->with('success', 'Pengumuman dihapus.');
    }

    public function status(Request $request, int $id, AnnouncementService $service)
    {
        $request->validate(['status' => 'required|in:published,archived']);
        $a = Announcement::findOrFail($id);

        $request->input('status') === 'published' ? $service->publish($a) : $service->archive($a);

        return back()->with('success', 'Status pengumuman diperbarui.');
    }

    /** POST dari dashboard semua role: tandai pengumuman dibaca. */
    public function seen(Request $request, AnnouncementService $service)
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $service->markSeen(Auth::user(), $ids);

        return response()->noContent();
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'     => 'required|string|max:255',
            'body'      => 'required|string',
            'status'    => 'required|in:draft,published',
            'is_pinned' => 'sometimes|boolean',
        ]);
    }

    /** Pengaman ringan: buang <script>/<style> (penulis tepercaya). */
    private function cleanBody(string $html): string
    {
        return preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
    }
}
```

- [ ] **Step 4: Routes**

In `routes/web.php`, add the import near the other `use App\Http\Controllers\Pages\...` imports:

```php
use App\Http\Controllers\Pages\AnnouncementController;
```

Inside the `Route::middleware('auth')->group(function () {` block, add (the `seen` route is OUTSIDE the admin role group — all authenticated roles can mark-as-read):

```php
    Route::post('announcements/seen', [AnnouncementController::class, 'seen'])->name('announcement.seen');

    Route::middleware('role:superadmin|manager|admin')->group(function () {
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcement.index');
        Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcement.create');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcement.store');
        Route::get('announcements/{id}/edit', [AnnouncementController::class, 'edit'])->name('announcement.edit');
        Route::put('announcements/{id}', [AnnouncementController::class, 'update'])->name('announcement.update');
        Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy'])->name('announcement.destroy');
        Route::post('announcements/{id}/status', [AnnouncementController::class, 'status'])->name('announcement.status');
    });
```

- [ ] **Step 5: List view**

Create `resources/views/announcements/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Pengumuman - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $sb = ['draft' => 'bg-secondary', 'published' => 'bg-success', 'archived' => 'bg-dark']; @endphp
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="card-title mb-0">Pengumuman</h6>
        <a href="{{ route('announcement.create') }}" class="btn btn-primary btn-sm">Buat Pengumuman</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Judul</th><th>Status</th><th>Pin</th><th>Dibuat oleh</th><th>Tanggal</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($announcements as $a)
                    <tr>
                        <td>{{ $a->title }}</td>
                        <td><span class="badge {{ $sb[$a->status] ?? 'bg-secondary' }}">{{ ucfirst($a->status) }}</span></td>
                        <td>@if($a->is_pinned)<i data-feather="bookmark" class="text-warning icon-sm"></i>@endif</td>
                        <td>{{ $a->creator?->name ?? '—' }}</td>
                        <td><small>{{ $a->created_at->format('d/m/y') }}</small></td>
                        <td>
                            <a href="{{ route('announcement.edit', $a->id) }}" class="btn btn-xs btn-outline-primary">Edit</a>
                            @if($a->status !== 'published')
                                <form action="{{ route('announcement.status', $a->id) }}" method="POST" class="d-inline m-0">@csrf
                                    <input type="hidden" name="status" value="published">
                                    <button class="btn btn-xs btn-outline-success">Terbitkan</button>
                                </form>
                            @else
                                <form action="{{ route('announcement.status', $a->id) }}" method="POST" class="d-inline m-0">@csrf
                                    <input type="hidden" name="status" value="archived">
                                    <button class="btn btn-xs btn-outline-secondary">Arsipkan</button>
                                </form>
                            @endif
                            <form action="{{ route('announcement.destroy', $a->id) }}" method="POST" class="d-inline m-0" onsubmit="return confirm('Hapus pengumuman ini?');">@csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada pengumuman.' } });
    });
</script>
@endpush
```

- [ ] **Step 6: Form view (create + edit, Summernote)**

Create `resources/views/announcements/form.blade.php`:

```blade
@extends('layouts.master')
@section('title', ($announcement->exists ? 'Edit' : 'Buat') . ' Pengumuman - SiMAPA')

@push('plugin-styles')
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
@endpush

@section('content')
<div class="row"><div class="col-md-10 offset-md-1 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">{{ $announcement->exists ? 'Edit' : 'Buat' }} Pengumuman</h6>
    <form method="POST" action="{{ $announcement->exists ? route('announcement.update', $announcement->id) : route('announcement.store') }}">
        @csrf
        @if($announcement->exists) @method('PUT') @endif
        <div class="mb-3">
            <label class="form-label">Judul</label>
            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $announcement->title) }}" required>
            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Isi</label>
            <textarea name="body" id="summernote">{{ old('body', $announcement->body) }}</textarea>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" {{ old('status', $announcement->status) === 'draft' ? 'selected' : '' }}>Draf</option>
                    <option value="published" {{ old('status', $announcement->status) === 'published' ? 'selected' : '' }}>Terbit</option>
                </select>
            </div>
            <div class="col-md-4 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="is_pinned" value="1" class="form-check-input" id="pinChk" {{ old('is_pinned', $announcement->is_pinned) ? 'checked' : '' }}>
                    <label class="form-check-label" for="pinChk">Pin ke atas</label>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a href="{{ route('announcement.index') }}" class="btn btn-secondary">Batal</a>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('#summernote').summernote({ height: 250, placeholder: 'Tulis isi pengumuman...' });
    });
</script>
@endpush
```

- [ ] **Step 7: Sidebar menu**

In `resources/views/layouts/sidebar.blade.php`, find the "Akun" category + Manajemen User block:

```blade
            <li class="nav-item nav-category">Akun</li>
            @role(['superadmin', 'manager'])
                <li class="nav-item {{ active_class(['user-management']) }}">
                    <a href="{{ route('user.management') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">Manajemen User</span>
                    </a>
                </li>
            @endrole
```

Insert AFTER that `@endrole` (and before the Profil item) an admin-gated "Pengumuman" menu:

```blade
            @role(['superadmin', 'manager', 'admin'])
                <li class="nav-item {{ active_class(['announcements']) }}">
                    <a href="{{ route('announcement.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="volume-2"></i>
                        <span class="link-title">Pengumuman</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 8: Run, confirm PASS**

Run: `php artisan test --filter=AnnouncementAdminTest`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```
git add app/Http/Controllers/Pages/AnnouncementController.php routes/web.php resources/views/announcements/index.blade.php resources/views/announcements/form.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/AnnouncementAdminTest.php
git commit -m "feat(announce): admin CRUD page (Summernote) + routes + sidebar

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Slider di dashboard + tandai dibaca (TDD)

**Files:**
- Create: `resources/views/dashboard/partials/announcements.blade.php`
- Modify: `app/Providers/AppServiceProvider.php`, `resources/views/dashboard.blade.php`
- Test: `tests/Feature/AnnouncementDashboardTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/AnnouncementDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AnnouncementDashboardTest extends TestCase
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
    public function published_announcement_shows_for_all_roles_draft_hidden(): void
    {
        Announcement::create(['title' => 'INFO PENTING', 'body' => '<p>isi</p>', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'DRAF RAHASIA', 'body' => '<p>x</p>', 'status' => 'draft']);

        foreach (['marketing', 'production', 'manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard'))
                ->assertOk()
                ->assertSee('INFO PENTING')
                ->assertDontSee('DRAF RAHASIA');
        }
    }

    /** @test */
    public function seen_endpoint_marks_read(): void
    {
        $u = $this->user('marketing');
        $a = Announcement::create(['title' => 'A', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($u)->post(route('announcement.seen'), ['ids' => [$a->id]])->assertNoContent();

        $this->assertDatabaseHas('tb_announcement_reads', ['announcement_id' => $a->id, 'user_id' => $u->id]);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=AnnouncementDashboardTest`
Expected: FAIL — `published_announcement_shows...` fails (slider not rendered / "INFO PENTING" absent).

- [ ] **Step 3: View Composer in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php`, inside `boot()`, after the existing `View::composer('layouts.partials.notifications', ...)` block, add:

```php
        \Illuminate\Support\Facades\View::composer('dashboard.partials.announcements', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $items = collect();
            if ($user) {
                try {
                    $items = app(\App\Services\AnnouncementService::class)->forDashboard($user);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Gagal memuat pengumuman dashboard: ' . $e->getMessage());
                }
            }
            $view->with('dashAnnouncements', $items);
        });
```

- [ ] **Step 4: Slider partial**

Create `resources/views/dashboard/partials/announcements.blade.php`:

```blade
@php $items = $dashAnnouncements ?? collect(); @endphp
@if($items->isNotEmpty())
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div id="announceCarousel" class="carousel slide w-100" data-bs-ride="carousel" data-bs-pause="hover">
            <div class="carousel-inner">
                @foreach($items as $i => $a)
                    @php
                        $words = max(1, str_word_count(strip_tags($a['body'])));
                        $interval = max(7000, min(20000, (int) round($words / 3.5 * 1000)));
                    @endphp
                    <div class="carousel-item {{ $i === 0 ? 'active' : '' }}" data-bs-interval="{{ $interval }}">
                        <div class="card border-start border-4 border-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap">
                                    <h6 class="mb-0"><i data-feather="volume-2" class="icon-sm me-1"></i>{{ $a['title'] }}
                                        @if($a['is_new'])<span class="badge bg-danger ms-1">Baru</span>@endif
                                    </h6>
                                    <small class="text-muted">{{ $a['published_at'] }}</small>
                                </div>
                                <div class="announce-body text-muted">{!! $a['body'] !!}</div>
                                <small class="text-muted">— {{ $a['creator_name'] }}</small>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($items->count() > 1)
                <button class="carousel-control-prev" type="button" data-bs-target="#announceCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon bg-dark rounded" aria-hidden="true"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#announceCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon bg-dark rounded" aria-hidden="true"></span>
                </button>
                <div class="carousel-indicators position-static mt-2">
                    @foreach($items as $i => $a)
                        <button type="button" data-bs-target="#announceCarousel" data-bs-slide-to="{{ $i }}" class="{{ $i === 0 ? 'active' : '' }} bg-dark"></button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

@push('custom-scripts')
<script>
    setTimeout(function () {
        fetch('{{ route('announcement.seen') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ ids: @json($items->pluck('id')->all()) })
        }).catch(function () {});
    }, 2500);
</script>
@endpush
@endif
```

- [ ] **Step 5: Include partial at top of dashboard content**

In `resources/views/dashboard.blade.php`, change:

```blade
@section('content')
@if(($dashboardView ?? 'financial') === 'production')
```

to:

```blade
@section('content')
@include('dashboard.partials.announcements')
@if(($dashboardView ?? 'financial') === 'production')
```

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=AnnouncementDashboardTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```
git add resources/views/dashboard/partials/announcements.blade.php app/Providers/AppServiceProvider.php resources/views/dashboard.blade.php tests/Feature/AnnouncementDashboardTest.php
git commit -m "feat(announce): dashboard carousel + per-user seen tracking

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (213 sebelumnya + AnnouncementServiceTest 4 + AnnouncementAdminTest 3 + AnnouncementDashboardTest 2 = 222). Tidak ada yang merah — dashboard lama tetap hijau (partial pengumuman aman saat kosong: `@if($items->isNotEmpty())`).

- [ ] **Step 2: Smoke manual (opsional)**

Login `super`/`password` (superadmin) → menu "Pengumuman" → Buat (judul + isi Summernote + Terbit + Pin) → Simpan. Buka dashboard semua role (mis. `ika` marketing, `pia` produksi) → slider pengumuman tampil dengan badge "Baru", auto-geser sesuai panjang teks, jeda saat hover. Muat ulang → badge "Baru" hilang (sudah dibaca).

---

## Catatan & Risiko

- **Dev/prod: jalankan `php artisan migrate`** untuk `tb_announcements` + `tb_announcement_reads` (kalau tidak, slider/halaman pengumuman error). View Composer di-guard try/catch jadi dashboard tetap jalan walau migrasi belum ada. Lihat [[migrate-dev-db-after-new-migration]].
- `body` di-render `{!! !!}` (HTML); pembuat hanya role tepercaya + strip `<script>`/`<style>` saat simpan. Sanitizer penuh di luar scope.
- Summernote via CDN — halaman form admin butuh internet; bisa di-host lokal belakangan bila perlu offline.
- Durasi carousel pakai `data-bs-interval` per item (didukung BS5) — tanpa JS auto-slide khusus; hanya fetch tandai-dibaca yang pakai JS.
- `published_at` di-set sekali (publish ulang setelah arsip tidak mereset recency "Baru").
