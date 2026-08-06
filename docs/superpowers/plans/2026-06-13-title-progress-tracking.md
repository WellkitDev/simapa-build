# Order Management + Title Progress Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan sistem tracking progres per judul dengan role-based stage advancement, search & filter pada dashboard title, dan timeline visual di halaman detail.

**Architecture:** Module TitleProgress baru (2 model, 1 controller, 2 migration) yang hidup terpisah dari OrderDetail. TitleProgress di-create otomatis saat order baru di-store. Manager hanya bisa maju ke tahap berikutnya; superadmin bisa set ke tahap manapun (koreksi) dengan note wajib.

**Tech Stack:** Laravel 10, Spatie Permission (terpasang), Blade + Alpine.js + Bootstrap 5, DataTables (terpasang), PHPUnit

---

## File Map

| Aksi | Path |
|------|------|
| Create | `database/migrations/2026_06_13_000001_create_tb_title_progress_table.php` |
| Create | `database/migrations/2026_06_13_000002_create_tb_title_progress_logs_table.php` |
| Create | `app/Models/TitleProgress.php` |
| Create | `app/Models/TitleProgressLog.php` |
| Create | `app/Http/Controllers/Pages/TitleProgressController.php` |
| Create | `tests/Feature/TitleProgressTest.php` |
| Modify | `app/Models/OrderDetail.php` — tambah relasi `titleProgress()` |
| Modify | `app/Http/Controllers/Pages/OrderBookController.php` — `store()` + `indexJudul()` + `detailJudul()` |
| Modify | `routes/web.php` — tambah 2 route |
| Modify | `resources/views/orders/index-title.blade.php` — search/filter + status badge |
| Modify | `resources/views/orders/detail-title.blade.php` — timeline + form update + log |

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/2026_06_13_000001_create_tb_title_progress_table.php`
- Create: `database/migrations/2026_06_13_000002_create_tb_title_progress_logs_table.php`

- [ ] **Step 1: Buat file migration pertama**

```php
<?php
// database/migrations/2026_06_13_000001_create_tb_title_progress_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_title_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_detail_id')->unique()->constrained('tb_order_details')->cascadeOnDelete();
            $table->string('status', 50)->default('menunggu_proses');
            $table->string('assigned_role', 50)->default('marketing');
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_progress');
    }
};
```

- [ ] **Step 2: Buat file migration kedua**

```php
<?php
// database/migrations/2026_06_13_000002_create_tb_title_progress_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_title_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_progress_id')->constrained('tb_title_progress')->cascadeOnDelete();
            $table->string('from_status', 50);
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->constrained('users');
            $table->text('note')->nullable();
            $table->boolean('is_correction')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_progress_logs');
    }
};
```

- [ ] **Step 3: Jalankan migrasi**

```bash
php artisan migrate
```

Expected output: `Running migrations... 2026_06_13_000001... DONE  2026_06_13_000002... DONE`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_13_000001_create_tb_title_progress_table.php
git add database/migrations/2026_06_13_000002_create_tb_title_progress_logs_table.php
git commit -m "feat: add title progress and progress logs migrations"
```

---

## Task 2: Models

**Files:**
- Create: `app/Models/TitleProgress.php`
- Create: `app/Models/TitleProgressLog.php`
- Modify: `app/Models/OrderDetail.php`

- [ ] **Step 1: Buat TitleProgress model**

```php
<?php
// app/Models/TitleProgress.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TitleProgress extends Model
{
    use HasFactory;

    protected $table = 'tb_title_progress';

    protected $fillable = [
        'order_detail_id', 'status', 'assigned_role',
        'note', 'updated_by', 'started_at',
    ];

    protected $dates = ['started_at'];

    const ARTICLE_STAGES = [
        'menunggu_proses', 'templating', 'editing',
        'revisi', 'submit', 'loa', 'publish',
    ];

    const BOOK_STAGES = [
        'menunggu_proses', 'editing', 'layout',
        'proofreading', 'isbn', 'cetak', 'terbit',
    ];

    const STAGE_HANDLER = [
        'menunggu_proses' => 'marketing',
        'templating'      => 'manager',
        'editing'         => 'manager',
        'revisi'          => 'manager',
        'submit'          => 'manager',
        'loa'             => 'superadmin',
        'publish'         => 'superadmin',
        'layout'          => 'manager',
        'proofreading'    => 'manager',
        'isbn'            => 'manager',
        'cetak'           => 'superadmin',
        'terbit'          => 'superadmin',
    ];

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function logs()
    {
        return $this->hasMany(TitleProgressLog::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getStages(): array
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        return in_array($this->orderDetail->type, $bookTypes)
            ? self::BOOK_STAGES
            : self::ARTICLE_STAGES;
    }

    public function getNextStatus(): ?string
    {
        $stages = $this->getStages();
        $currentIndex = array_search($this->status, $stages);
        if ($currentIndex === false || !isset($stages[$currentIndex + 1])) {
            return null;
        }
        return $stages[$currentIndex + 1];
    }

    public function isValidStatus(string $status): bool
    {
        return in_array($status, $this->getStages());
    }

    public static function getHandlerForStatus(string $status): string
    {
        return self::STAGE_HANDLER[$status] ?? 'superadmin';
    }
}
```

- [ ] **Step 2: Buat TitleProgressLog model**

```php
<?php
// app/Models/TitleProgressLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TitleProgressLog extends Model
{
    use HasFactory;

    protected $table = 'tb_title_progress_logs';

    public $timestamps = false;

    protected $fillable = [
        'title_progress_id', 'from_status', 'to_status',
        'changed_by', 'note', 'is_correction',
    ];

    protected $casts = [
        'is_correction' => 'boolean',
        'created_at'    => 'datetime',
    ];

    public function titleProgress()
    {
        return $this->belongsTo(TitleProgress::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

- [ ] **Step 3: Tambah relasi `titleProgress()` ke OrderDetail model**

Buka `app/Models/OrderDetail.php`, tambah method di bawah method `authors()`:

```php
public function titleProgress()
{
    return $this->hasOne(TitleProgress::class);
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/TitleProgress.php app/Models/TitleProgressLog.php app/Models/OrderDetail.php
git commit -m "feat: add TitleProgress and TitleProgressLog models"
```

---

## Task 3: Test — Auto-create TitleProgress saat Order dibuat

**Files:**
- Create: `tests/Feature/TitleProgressTest.php`

- [ ] **Step 1: Tulis failing test**

```php
<?php
// tests/Feature/TitleProgressTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles yang dibutuhkan Spatie
        Role::create(['name' => 'marketing', 'guard_name' => 'web']);
        Role::create(['name' => 'manager',   'guard_name' => 'web']);
        Role::create(['name' => 'superadmin','guard_name' => 'web']);

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');
    }

    /** @test */
    public function title_progress_is_created_when_order_is_stored(): void
    {
        $this->actingAs($this->marketing);

        $payload = [
            'type'             => 'bk_mandiri',
            'title'            => 'Buku Tes Integrasi',
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'issued_at'        => now()->toDateString(),
            'cost_amount'      => 1000000,
            'contact_phone'    => '08123456789',
            'contact_email'    => 'test@example.com',
            'authors'          => [
                [
                    'name'        => 'Penulis Satu',
                    'email'       => 'penulis@example.com',
                    'phone'       => '0812',
                    'affiliation' => 'UI',
                    'position'    => 1,
                ],
            ],
        ];

        $this->post(route('order.book.store'), $payload);

        $this->assertDatabaseHas('tb_title_progress', [
            'status'        => 'menunggu_proses',
            'assigned_role' => 'marketing',
        ]);

        $this->assertEquals(1, TitleProgress::count());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan FAIL**

```bash
php artisan test --filter=title_progress_is_created_when_order_is_stored
```

Expected: FAIL — `TitleProgress` belum dibuat di `store()`.

---

## Task 4: Modifikasi OrderBookController

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php`

- [ ] **Step 1: Tambah import TitleProgress di bagian atas file**

Tambahkan setelah baris `use App\Models\OrderContact;`:

```php
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
```

- [ ] **Step 2: Tambah auto-create TitleProgress di method `store()`**

Dalam `DB::transaction()` di `store()`, tambahkan setelah baris `$detail->authors()->attach($authorPivots);`:

```php
// Auto-create TitleProgress
TitleProgress::create([
    'order_detail_id' => $detail->id,
    'status'          => 'menunggu_proses',
    'assigned_role'   => 'marketing',
    'updated_by'      => Auth::id(),
    'started_at'      => now(),
]);
```

- [ ] **Step 3: Jalankan test, pastikan PASS**

```bash
php artisan test --filter=title_progress_is_created_when_order_is_stored
```

Expected: PASS

- [ ] **Step 4: Update `indexJudul()` — tambah join title_progress + terima query params search/filter**

Ganti seluruh method `indexJudul()` dengan:

```php
public function indexJudul(Request $request)
{
    $query = OrderDetail::select(
            'tb_order_details.title',
            'tb_order_details.type',
            'tb_order_details.order_id',
            'tb_order_details.id as detail_id',
            DB::raw('COUNT(DISTINCT tb_author_orders.author_id) as total_author'),
            'tb_title_progress.status as progress_status',
            'tb_title_progress.assigned_role as progress_role',
            'tb_title_progress.updated_at as progress_updated_at'
        )
        ->join('tb_orders', 'tb_order_details.order_id', '=', 'tb_orders.id')
        ->leftJoin('tb_author_orders', 'tb_order_details.id', '=', 'tb_author_orders.order_detail_id')
        ->leftJoin('tb_title_progress', 'tb_order_details.id', '=', 'tb_title_progress.order_detail_id')
        ->when(Auth::user()->hasRole('marketing'), fn($q) =>
            $q->where('tb_orders.user_id', Auth::id())
        )
        ->when($request->filled('search'), function ($q) use ($request) {
            $search = '%' . $request->search . '%';
            $q->where(function ($inner) use ($search) {
                $inner->where('tb_order_details.title', 'like', $search)
                      ->orWhereExists(function ($sub) use ($search) {
                          $sub->select(DB::raw(1))
                              ->from('tb_authors')
                              ->join('tb_author_orders', 'tb_authors.id', '=', 'tb_author_orders.author_id')
                              ->whereColumn('tb_author_orders.order_detail_id', 'tb_order_details.id')
                              ->where('tb_authors.name', 'like', $search);
                      });
            });
        })
        ->when($request->filled('tipe'), fn($q) =>
            $q->where('tb_order_details.type', 'like', $request->tipe . '%')
        )
        ->when($request->filled('status'), fn($q) =>
            $q->where('tb_title_progress.status', $request->status)
        )
        ->when($request->filled('tahun'), fn($q) =>
            $q->whereYear('tb_orders.ordered_at', $request->tahun)
        )
        ->groupBy(
            'tb_order_details.title',
            'tb_order_details.type',
            'tb_order_details.order_id',
            'tb_order_details.id',
            'tb_title_progress.status',
            'tb_title_progress.assigned_role',
            'tb_title_progress.updated_at'
        )
        ->get();

    $tahunList = Order::selectRaw('YEAR(ordered_at) as tahun')
        ->distinct()->orderByDesc('tahun')->pluck('tahun');

    return view('orders.index-title', [
        'judulData' => $query,
        'tahunList' => $tahunList,
    ]);
}
```

- [ ] **Step 5: Update `detailJudul()` — eager-load titleProgress dan logs**

Ganti seluruh method `detailJudul()` dengan:

```php
public function detailJudul($id)
{
    $detail = OrderDetail::with([
            'authors',
            'scopes',
            'order.user',
            'titleProgress.logs.changedBy',
        ])
        ->where('id', $id)
        ->whereHas('order', function($q) {
            $q->when(Auth::user()->hasRole('marketing'), fn($query) =>
                $query->where('tb_orders.user_id', Auth::id())
            );
        })
        ->firstOrFail();

    // Fallback: buat TitleProgress jika belum ada (data lama sebelum fitur ini)
    if (!$detail->titleProgress) {
        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => Auth::id(),
            'started_at'      => now(),
        ]);
        $detail->load('titleProgress.logs.changedBy');
    }

    return view('orders.detail-title', compact('detail'));
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php app/Models/OrderDetail.php
git commit -m "feat: auto-create TitleProgress on order store, add search/filter to indexJudul"
```

---

## Task 5: TitleProgressController

**Files:**
- Create: `app/Http/Controllers/Pages/TitleProgressController.php`

- [ ] **Step 1: Tulis failing tests untuk controller**

Tambahkan ke `tests/Feature/TitleProgressTest.php`:

```php
/** @test */
public function manager_can_advance_status_to_next_stage(): void
{
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    // Buat order dengan TitleProgress (menunggu_proses)
    $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
    $progress = TitleProgress::create([
        'order_detail_id' => $detail->id,
        'status'          => 'menunggu_proses',
        'assigned_role'   => 'marketing',
        'updated_by'      => $manager->id,
        'started_at'      => now(),
    ]);

    $this->actingAs($manager);

    $this->post(route('title.progress.update', $progress->id), [
        'status' => 'editing',
        'note'   => '',
    ])->assertRedirect();

    $this->assertDatabaseHas('tb_title_progress', [
        'id'     => $progress->id,
        'status' => 'editing',
    ]);

    $this->assertDatabaseHas('tb_title_progress_logs', [
        'title_progress_id' => $progress->id,
        'from_status'       => 'menunggu_proses',
        'to_status'         => 'editing',
        'is_correction'     => false,
    ]);
}

/** @test */
public function manager_cannot_make_correction_jump(): void
{
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
    $progress = TitleProgress::create([
        'order_detail_id' => $detail->id,
        'status'          => 'menunggu_proses',
        'assigned_role'   => 'marketing',
        'updated_by'      => $manager->id,
        'started_at'      => now(),
    ]);

    $this->actingAs($manager);

    $this->post(route('title.progress.update', $progress->id), [
        'status' => 'terbit', // Loncat jauh
        'note'   => '',
    ])->assertStatus(403);

    $this->assertDatabaseHas('tb_title_progress', ['id' => $progress->id, 'status' => 'menunggu_proses']);
}

/** @test */
public function superadmin_can_make_correction_with_note(): void
{
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
    $progress = TitleProgress::create([
        'order_detail_id' => $detail->id,
        'status'          => 'isbn',
        'assigned_role'   => 'manager',
        'updated_by'      => $superadmin->id,
        'started_at'      => now(),
    ]);

    $this->actingAs($superadmin);

    $this->post(route('title.progress.update', $progress->id), [
        'status' => 'editing', // Mundur
        'note'   => 'Koreksi karena ada revisi mendasar',
    ])->assertRedirect();

    $this->assertDatabaseHas('tb_title_progress_logs', [
        'to_status'     => 'editing',
        'is_correction' => true,
    ]);
}
```

- [ ] **Step 2: Jalankan tests, pastikan FAIL**

```bash
php artisan test --filter=TitleProgressTest
```

Expected: semua test yang baru FAIL — controller belum ada.

- [ ] **Step 3: Buat TitleProgressController**

```php
<?php
// app/Http/Controllers/Pages/TitleProgressController.php

namespace App\Http\Controllers\Pages;

use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TitleProgressController extends Controller
{
    public function update(Request $request, int $id)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $user     = Auth::user();
        $target   = $request->input('status');

        // Marketing tidak boleh mengubah status apapun
        if ($user->hasRole('marketing')) {
            abort(403);
        }

        // Validasi: status target harus valid untuk tipe order ini
        if (!$progress->isValidStatus($target)) {
            return back()->with('error', 'Status tidak valid untuk tipe naskah ini.');
        }

        $nextStatus   = $progress->getNextStatus();
        $isCorrection = ($target !== $nextStatus);

        // Manager hanya boleh advance (maju ke tahap berikutnya)
        if ($user->hasRole('manager') && $isCorrection) {
            abort(403);
        }

        // Koreksi oleh superadmin wajib menyertakan note
        if ($isCorrection && empty(trim($request->input('note', '')))) {
            return back()->withErrors(['note' => 'Catatan wajib diisi untuk koreksi status.'])->withInput();
        }

        $fromStatus = $progress->status;

        $progress->update([
            'status'        => $target,
            'assigned_role' => TitleProgress::getHandlerForStatus($target),
            'note'          => $request->input('note'),
            'updated_by'    => $user->id,
            'started_at'    => now(),
        ]);

        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'from_status'       => $fromStatus,
            'to_status'         => $target,
            'changed_by'        => $user->id,
            'note'              => $request->input('note'),
            'is_correction'     => $isCorrection,
        ]);

        return back()->with('success', 'Status berhasil diperbarui.');
    }

    public function logs(int $id)
    {
        $progress = TitleProgress::with('logs.changedBy')->findOrFail($id);
        return response()->json($progress->logs);
    }
}
```

- [ ] **Step 4: Jalankan tests, pastikan PASS**

```bash
php artisan test --filter=TitleProgressTest
```

Expected: semua tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/TitleProgressController.php tests/Feature/TitleProgressTest.php
git commit -m "feat: add TitleProgressController with role-based status advancement"
```

---

## Task 6: Tambah Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Tambah import controller di atas file**

Setelah baris `use App\Http\Controllers\Pages\OrderJournalController;`, tambahkan:

```php
use App\Http\Controllers\Pages\TitleProgressController;
```

- [ ] **Step 2: Tambah routes di dalam block `Route::prefix('management')`**

Setelah baris `Route::get('title/details/{id}', ...)`, tambahkan:

```php
Route::post('title/{id}/update-status', [TitleProgressController::class, 'update'])
    ->name('title.progress.update')
    ->middleware('role:manager|superadmin');
Route::get('title/{id}/logs', [TitleProgressController::class, 'logs'])
    ->name('title.progress.logs');
```

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat: add routes for title progress update and logs"
```

---

## Task 7: Update View — index-title (Search/Filter + Status Badge)

**Files:**
- Modify: `resources/views/orders/index-title.blade.php`

- [ ] **Step 1: Ganti seluruh isi file**

```blade
@extends('layouts.master')
@section('title', 'Data Judul - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Data Judul</h6>
                </div>

                {{-- Search & Filter Form --}}
                <form method="GET" action="{{ route('order.book.indexJudul') }}" class="row g-2 mb-4">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Cari judul atau nama author..."
                            value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <select name="tipe" class="form-select form-select-sm">
                            <option value="">Semua Tipe</option>
                            <option value="bk" {{ request('tipe') === 'bk' ? 'selected' : '' }}>Buku</option>
                            <option value="at" {{ request('tipe') === 'at' ? 'selected' : '' }}>Artikel</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua Status</option>
                            @foreach(['menunggu_proses','templating','editing','revisi','submit','loa','publish','layout','proofreading','isbn','cetak','terbit'] as $s)
                                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                                    {{ Str::title(str_replace('_', ' ', $s)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="tahun" class="form-select form-select-sm">
                            <option value="">Semua Tahun</option>
                            @foreach($tahunList as $tahun)
                                <option value="{{ $tahun }}" {{ request('tahun') == $tahun ? 'selected' : '' }}>{{ $tahun }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap"
                        style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul Naskah</th>
                                <th>Tipe</th>
                                <th>Status Progres</th>
                                <th>Handler</th>
                                <th>Update Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($judulData as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td class="text-capitalize"><strong>{{ Str::limit($row->title, 40) }}</strong></td>
                                <td>
                                    <span class="badge bg-info">
                                        {{ in_array($row->type, ['bk_mandiri','bk_kolab']) ? 'Buku' : 'Artikel' }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'menunggu_proses' => 'secondary',
                                            'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                                            'revisi' => 'orange', 'proofreading' => 'warning', 'isbn' => 'warning',
                                            'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                                            'publish' => 'success', 'terbit' => 'success',
                                        ];
                                        $color = $statusColors[$row->progress_status] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $color }}">
                                        {{ Str::title(str_replace('_', ' ', $row->progress_status ?? 'menunggu_proses')) }}
                                    </span>
                                </td>
                                <td><small class="text-muted text-capitalize">{{ $row->progress_role ?? '-' }}</small></td>
                                <td><small>{{ $row->progress_updated_at ? \Carbon\Carbon::parse($row->progress_updated_at)->diffForHumans() : '-' }}</small></td>
                                <td>
                                    <a href="{{ route('order.indexJudul.detail', $row->detail_id) }}"
                                        class="btn btn-sm btn-primary">Detail</a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function() {
        $(".datatable").DataTable({ pageLength: 25, searching: false, ordering: false });
    });
</script>
@endpush
```

> **Catatan:** Link di tombol Detail sekarang menggunakan `$row->detail_id` (bukan `$row->order_id` seperti sebelumnya — itu adalah bug lama yang ikut diperbaiki di sini).

- [ ] **Step 2: Commit**

```bash
git add resources/views/orders/index-title.blade.php
git commit -m "feat: update index-title view with search/filter and status badges"
```

---

## Task 8: Update View — detail-title (Timeline + Form Update + Log History)

**Files:**
- Modify: `resources/views/orders/detail-title.blade.php`

- [ ] **Step 1: Ganti seluruh isi file**

```blade
@extends('layouts.master')
@section('title', 'Detail Title - SiMAPA')

@section('content')
<div class="page-content">
    <div class="row">
        <div class="col-md-12">

            {{-- Info Order --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <h3 class="mb-0">{{ $detail->title }}</h3>
                        <div class="d-flex gap-2">
                            <span class="badge bg-info text-uppercase">{{ $detail->type }}</span>
                            @role('superadmin')
                                <a href="{{ route('order.book.edit', $detail->order->code_order) }}"
                                   class="btn btn-sm btn-outline-warning">Edit Order</a>
                            @endrole
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Kode Order</p>
                            <h5>{{ $detail->order->code_order }}</h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Marketing</p>
                            <h5>{{ $detail->order->user->name ?? '-' }}</h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Total Biaya</p>
                            <h5 class="text-success">Rp {{ number_format($detail->cost_amount, 0, ',', '.') }}</h5>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Timeline Progres --}}
            @php
                $progress  = $detail->titleProgress;
                $stages    = $progress->getStages();
                $currentIdx = array_search($progress->status, $stages);
            @endphp
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Progress Naskah</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        @foreach ($stages as $idx => $stage)
                            @php
                                if ($idx < $currentIdx)      $cls = 'bg-success text-white';
                                elseif ($idx === $currentIdx) $cls = 'bg-primary text-white';
                                else                         $cls = 'bg-light text-muted';
                            @endphp
                            <div class="text-center" style="min-width:90px;">
                                <div class="badge {{ $cls }} p-2 mb-1 w-100">
                                    @if($idx < $currentIdx) ✓ @elseif($idx === $currentIdx) ● @else ○ @endif
                                </div>
                                <small class="{{ $idx === $currentIdx ? 'fw-bold' : '' }}">
                                    {{ Str::title(str_replace('_',' ',$stage)) }}
                                </small>
                            </div>
                            @if(!$loop->last)
                                <div class="d-flex align-items-center"><small class="text-muted">→</small></div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Form Update Status (manager & superadmin) --}}
                    @hasanyrole('manager|superadmin')
                        <form method="POST" action="{{ route('title.progress.update', $progress->id) }}" class="row g-2 mt-2">
                            @csrf
                            <div class="col-md-4">
                                <label class="form-label form-label-sm">Ubah Status</label>
                                <select name="status" class="form-select form-select-sm" required>
                                    @role('superadmin')
                                        @foreach($stages as $stage)
                                            <option value="{{ $stage }}" {{ $stage === $progress->status ? 'selected' : '' }}>
                                                {{ Str::title(str_replace('_',' ',$stage)) }}
                                            </option>
                                        @endforeach
                                    @else
                                        @if($progress->getNextStatus())
                                            <option value="{{ $progress->getNextStatus() }}">
                                                {{ Str::title(str_replace('_',' ',$progress->getNextStatus())) }}
                                            </option>
                                        @else
                                            <option disabled>Sudah di tahap akhir</option>
                                        @endif
                                    @endrole
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label form-label-sm">Catatan</label>
                                <input type="text" name="note" class="form-control form-control-sm"
                                    placeholder="Catatan (wajib jika koreksi)"
                                    value="{{ old('note') }}">
                                @error('note') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Update Status</button>
                            </div>
                        </form>
                    @endhasanyrole
                </div>
            </div>

            {{-- Daftar Penulis --}}
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Daftar Penulis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr><th>No</th><th>Nama</th><th>Email / WA</th><th>Posisi</th></tr>
                            </thead>
                            <tbody>
                                @forelse($detail->authors as $author)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><strong>{{ $author->name }}</strong></td>
                                    <td>{{ $author->email }}<br><small class="text-muted">{{ $author->phone }}</small></td>
                                    <td><span class="badge bg-light text-primary">Ke-{{ $author->pivot->position }}</span></td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center">Belum ada penulis.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Log History --}}
            <div class="card">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Riwayat Perubahan Status</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Dari</th><th>Ke</th><th>Diubah Oleh</th>
                                    <th>Tanggal</th><th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($progress->logs->sortByDesc('created_at') as $log)
                                <tr>
                                    <td><span class="badge bg-secondary">{{ Str::title(str_replace('_',' ',$log->from_status)) }}</span></td>
                                    <td><span class="badge bg-primary">{{ Str::title(str_replace('_',' ',$log->to_status)) }}</span></td>
                                    <td>{{ $log->changedBy->name ?? '-' }}</td>
                                    <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                                    <td>
                                        {{ $log->note ?? '-' }}
                                        @if($log->is_correction)
                                            <span class="badge bg-danger ms-1">Koreksi</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted">Belum ada riwayat.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/orders/detail-title.blade.php
git commit -m "feat: update detail-title view with progress timeline, update form, and log history"
```

---

## Task 9: Verifikasi End-to-End

- [ ] **Step 1: Jalankan semua tests**

```bash
php artisan test --filter=TitleProgressTest
```

Expected: semua PASS

- [ ] **Step 2: Buka browser, login sebagai marketing**

Buat order buku baru. Periksa: setelah redirect, buka `/management/title` — judul baru harus muncul dengan status badge "Menunggu Proses".

- [ ] **Step 3: Login sebagai manager**

Buka detail title, periksa: form update status hanya menampilkan satu pilihan (tahap berikutnya). Update status. Periksa timeline berubah dan log muncul.

- [ ] **Step 4: Login sebagai superadmin**

Buka detail title, periksa: form update status menampilkan semua tahap. Coba koreksi mundur tanpa note → harus muncul error validasi. Koreksi dengan note → berhasil, log bertanda "Koreksi" merah.

- [ ] **Step 5: Final commit**

```bash
git add .
git commit -m "feat: complete title progress tracking feature"
```
