# Archive Grouped Titles — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group the "Archive" list by normalized title (one row per book/article instead of one per author-order), add a group-detail page with per-order Progress/Order actions, reorganize the sidebar, and restore the standard DataTables behavior.

**Architecture:** A new `TitleArchiveService` holds all grouping logic (title normalization, pipeline class, group key, and per-group aggregate summary). `OrderBookController@indexJudul` and `@detailJudul` delegate to it; a new `@progressDetail` method serves the existing per-order timeline view. No database/schema changes — grouping is computed at runtime in PHP collections.

**Tech Stack:** Laravel 10, Blade, Spatie Permission (roles), DataTables (jQuery), PHPUnit feature/unit tests with `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-06-14-archive-grouped-titles-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Services/TitleArchiveService.php` | Create | Pure grouping logic: normalize title, pipeline class, group key, summarize a group |
| `app/Http/Controllers/Pages/OrderBookController.php` | Modify | `indexJudul` (grouped list), `detailJudul` (group detail), new `progressDetail` (per-order timeline) |
| `routes/web.php` | Modify | Add `order.indexJudul.progress` route |
| `resources/views/orders/index-title.blade.php` | Rewrite | Grouped table + restored DataTables |
| `resources/views/orders/detail-title-group.blade.php` | Create | Group detail: aggregate header + author/order table with Progress/Order actions |
| `resources/views/orders/detail-title.blade.php` | Unchanged | Per-order timeline (now reached via `progressDetail`) |
| `resources/views/layouts/sidebar.blade.php` | Rewrite | Reorganized, role-guarded, renamed menu |
| `tests/Unit/TitleArchiveServiceTest.php` | Create | Unit tests for `normalizeTitle` / `pipelineClass` |
| `tests/Feature/ArchiveGroupedTitlesTest.php` | Create | Feature tests: grouping, scope, detail, progress, sidebar |

**Conventions to follow (already in this codebase):**
- Tests use `Tests\TestCase` + `RefreshDatabase`, create Spatie roles in `setUp`, mock `GoogleDriveService` (the `OrderBookController` constructor injects it).
- Factories: `Order::factory()`, `OrderDetail::factory()` (default `type = bk_mandiri`). No `Author` factory — create via `Author::create([...])` and attach with pivot `position`.
- `OrderDetail` relations: `order()`, `authors()` (pivot `position`), `titleProgress()`.
- `TitleProgress::BOOK_STAGES`, `::ARTICLE_STAGES`, `::getHandlerForStatus($status)` already exist.

---

## Task 1: TitleArchiveService — normalize + pipeline class

**Files:**
- Create: `app/Services/TitleArchiveService.php`
- Test: `tests/Unit/TitleArchiveServiceTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/TitleArchiveServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\TitleArchiveService;

class TitleArchiveServiceTest extends TestCase
{
    private TitleArchiveService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TitleArchiveService();
    }

    /** @test */
    public function it_normalizes_spelling_variants_to_the_same_key(): void
    {
        $a = $this->svc->normalizeTitle('Manuscripts and Memory: Malay-Indonesian World');
        $b = $this->svc->normalizeTitle('manuscripts and memory  malay indonesian world');

        $this->assertSame($a, $b);
        $this->assertSame('manuscripts and memory malay indonesian world', $a);
    }

    /** @test */
    public function it_maps_types_to_pipeline_class(): void
    {
        $this->assertSame('buku', $this->svc->pipelineClass('bk_mandiri'));
        $this->assertSame('buku', $this->svc->pipelineClass('bk_kolab'));
        $this->assertSame('artikel', $this->svc->pipelineClass('at_kolab'));
        $this->assertSame('artikel', $this->svc->pipelineClass('at_mandiri'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TitleArchiveServiceTest`
Expected: FAIL — `Class "App\Services\TitleArchiveService" not found`.

- [ ] **Step 3: Create the service with minimal methods**

Create `app/Services/TitleArchiveService.php`:

```php
<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TitleArchiveService
{
    public function normalizeTitle(string $title): string
    {
        return Str::of($title)
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ') // strip punctuation
            ->replaceMatches('/\s+/u', ' ')              // collapse whitespace
            ->trim()
            ->value();
    }

    public function pipelineClass(string $type): string
    {
        return in_array($type, ['bk_mandiri', 'bk_kolab'], true) ? 'buku' : 'artikel';
    }

    public function groupKey(OrderDetail $detail): string
    {
        return $this->pipelineClass($detail->type) . '|' . $this->normalizeTitle($detail->title);
    }

    public function stagesFor(string $pipelineClass): array
    {
        return $pipelineClass === 'buku'
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TitleArchiveServiceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TitleArchiveService.php tests/Unit/TitleArchiveServiceTest.php
git commit -m "feat: add TitleArchiveService with title normalization and pipeline class"
```

---

## Task 2: TitleArchiveService — summarize + groupDetails

**Files:**
- Modify: `app/Services/TitleArchiveService.php`
- Create: `tests/Feature/ArchiveGroupedTitlesTest.php`

- [ ] **Step 1: Write the failing feature test (with shared helper)**

Create `tests/Feature/ArchiveGroupedTitlesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Author;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\TitleArchiveService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ArchiveGroupedTitlesTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        // OrderBookController constructor injects GoogleDriveService — avoid real API.
        $this->mock(GoogleDriveService::class);

        Role::create(['name' => 'marketing', 'guard_name' => 'web']);
        Role::create(['name' => 'manager',   'guard_name' => 'web']);
        Role::create(['name' => 'superadmin','guard_name' => 'web']);

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');
    }

    /**
     * Create one order-detail (title) with authors and an optional progress status.
     * Pass $status = null to simulate legacy data without a TitleProgress row.
     */
    private function makeDetail(
        string $title,
        string $type,
        ?string $status,
        array $authorNames,
        ?User $owner = null
    ): OrderDetail {
        $owner = $owner ?? $this->marketing;

        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id,
            'type'     => $type,
            'title'    => $title,
        ]);

        foreach ($authorNames as $i => $name) {
            $author = Author::create([
                'name'  => $name,
                'email' => Str::slug($name) . '@example.com',
            ]);
            $detail->authors()->attach($author->id, ['position' => $i + 1]);
        }

        if ($status !== null) {
            TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'updated_by'      => $owner->id,
                'started_at'      => now(),
            ]);
        }

        return $detail->load(['authors', 'titleProgress', 'order.user']);
    }

    /** @test */
    public function it_groups_normalized_title_variants_into_one_summary_row(): void
    {
        $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $svc     = app(TitleArchiveService::class);
        $details = OrderDetail::with(['authors', 'titleProgress'])->get();
        $rows    = $svc->groupDetails($details);

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame(2, $row->total_author);
        $this->assertSame('menunggu_proses', $row->bottleneck_status); // least-advanced wins
        $this->assertSame('marketing', $row->handler);                 // handler of bottleneck stage
        $this->assertTrue($row->is_mixed);
        $this->assertSame('Artikel', $row->type_label);
    }

    /** @test */
    public function it_keeps_same_title_in_different_pipelines_separate(): void
    {
        $this->makeDetail('Sejarah Nusantara', 'bk_mandiri', 'editing', ['A']);
        $this->makeDetail('Sejarah Nusantara', 'at_mandiri', 'editing', ['B']);

        $rows = app(TitleArchiveService::class)
            ->groupDetails(OrderDetail::with(['authors', 'titleProgress'])->get());

        $this->assertCount(2, $rows);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: FAIL — `Call to undefined method App\Services\TitleArchiveService::groupDetails()`.

- [ ] **Step 3: Add `summarize` + `groupDetails` to the service**

Append these methods inside the `TitleArchiveService` class (after `stagesFor`):

```php
    public function groupDetails(Collection $details): Collection
    {
        return $details
            ->groupBy(fn (OrderDetail $d) => $this->groupKey($d))
            ->map(fn (Collection $group) => $this->summarize($group))
            ->sortByDesc('last_update')
            ->values();
    }

    public function summarize(Collection $details): object
    {
        // Representative = most recently updated variant; tie-break by largest id.
        $repr = $details
            ->sort(fn (OrderDetail $a, OrderDetail $b) =>
                ($this->lastUpdateOf($b)->timestamp <=> $this->lastUpdateOf($a)->timestamp)
                    ?: ($b->id <=> $a->id))
            ->first();

        $pipeline = $this->pipelineClass($repr->type);
        $stages   = $this->stagesFor($pipeline);

        $statuses = $details->map(fn (OrderDetail $d) =>
            optional($d->titleProgress)->status ?? 'menunggu_proses');

        // Bottleneck = status with the smallest stage index.
        $bottleneck = $statuses
            ->sortBy(fn (string $s) =>
                ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();

        return (object) [
            'detail_id_repr'    => $repr->id,
            'title'             => $repr->title,
            'type'              => $repr->type,
            'type_label'        => $pipeline === 'buku' ? 'Buku' : 'Artikel',
            'total_author'      => $details
                                    ->flatMap(fn (OrderDetail $d) => $d->authors->pluck('id'))
                                    ->unique()
                                    ->count(),
            'bottleneck_status' => $bottleneck,
            'handler'           => TitleProgress::getHandlerForStatus($bottleneck),
            'last_update'       => $details->map(fn (OrderDetail $d) => $this->lastUpdateOf($d))->max(),
            'is_mixed'          => $statuses->unique()->count() > 1,
        ];
    }

    private function lastUpdateOf(OrderDetail $detail): Carbon
    {
        return optional($detail->titleProgress)->updated_at ?? $detail->updated_at;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TitleArchiveService.php tests/Feature/ArchiveGroupedTitlesTest.php
git commit -m "feat: add group summarization (bottleneck status, author count) to TitleArchiveService"
```

---

## Task 3: Archive grouped list — controller + view + DataTables

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php` (replace `indexJudul`, lines ~54-112; add import)
- Rewrite: `resources/views/orders/index-title.blade.php`
- Test: `tests/Feature/ArchiveGroupedTitlesTest.php` (add methods)

- [ ] **Step 1: Write the failing feature tests**

Add these methods to `ArchiveGroupedTitlesTest`:

```php
    /** @test */
    public function archive_index_groups_titles_into_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $resp = $this->actingAs($admin)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $rows = $resp->viewData('judulData');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()->total_author);
        $resp->assertSee('Arsip Judul');
    }

    /** @test */
    public function marketing_only_sees_own_orders_in_archive(): void
    {
        $other = User::factory()->create();
        $other->assignRole('marketing');

        $this->makeDetail('Buku Orang Lain', 'bk_mandiri', 'editing', ['X'], $other);
        $this->makeDetail('Buku Saya',       'bk_mandiri', 'editing', ['Y'], $this->marketing);

        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $rows = $resp->viewData('judulData');
        $this->assertCount(1, $rows);
        $this->assertSame('Buku Saya', $rows->first()->title);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: FAIL — old `indexJudul` returns rows keyed per-detail (count 2, not 1) and view has no `judulData` of summary objects / `total_author` property mismatch.

- [ ] **Step 3a: Add the service import to the controller**

In `app/Http/Controllers/Pages/OrderBookController.php`, add to the `use` block (near the other `App\Services` import):

```php
use App\Services\TitleArchiveService;
```

- [ ] **Step 3b: Replace the `indexJudul` method**

Replace the entire existing `indexJudul(Request $request)` method with:

```php
    public function indexJudul(TitleArchiveService $archive)
    {
        $details = OrderDetail::with(['order.user', 'authors', 'titleProgress'])
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())))
            ->get();

        $judulData = $archive->groupDetails($details);

        return view('orders.index-title', compact('judulData'));
    }
```

- [ ] **Step 3c: Rewrite the Archive view**

Replace the entire contents of `resources/views/orders/index-title.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')

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
                    <h6 class="card-title mb-0">Arsip Judul</h6>
                </div>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap"
                        style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul</th>
                                <th>Buku</th>
                                <th>Total Author</th>
                                <th>Status Progres</th>
                                <th>Handler</th>
                                <th>Update Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $statusColors = [
                                    'menunggu_proses' => 'secondary',
                                    'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                                    'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
                                    'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                                    'publish' => 'success', 'terbit' => 'success',
                                ];
                            @endphp
                            @foreach ($judulData as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td class="text-capitalize"><strong>{{ Str::limit($row->title, 40) }}</strong></td>
                                <td><span class="badge bg-info">{{ $row->type_label }}</span></td>
                                <td>{{ $row->total_author }}</td>
                                <td>
                                    <span class="badge bg-{{ $statusColors[$row->bottleneck_status] ?? 'secondary' }}">
                                        {{ Str::title(str_replace('_', ' ', $row->bottleneck_status)) }}
                                    </span>
                                    @if ($row->is_mixed)
                                        <span class="badge bg-light text-muted">beragam</span>
                                    @endif
                                </td>
                                <td><small class="text-muted text-capitalize">{{ $row->handler ?? '-' }}</small></td>
                                <td><small>{{ $row->last_update ? \Carbon\Carbon::parse($row->last_update)->diffForHumans() : '-' }}</small></td>
                                <td>
                                    <a href="{{ route('order.indexJudul.detail', $row->detail_id_repr) }}"
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
    $(function () {
        $(".datatable").DataTable({
            pageLength: 10,
            order: [[1, "asc"]],
        });
        $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
        $('.custom-select').select2();
    });
</script>
@endpush
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php resources/views/orders/index-title.blade.php tests/Feature/ArchiveGroupedTitlesTest.php
git commit -m "feat: group Archive list by normalized title, restore standard DataTables"
```

---

## Task 4: Group detail page + per-order progress route

**Files:**
- Modify: `routes/web.php` (add progress route after the existing `title/details/{id}` route, ~line 60)
- Modify: `app/Http/Controllers/Pages/OrderBookController.php` (replace `detailJudul`; add `progressDetail`)
- Create: `resources/views/orders/detail-title-group.blade.php`
- Test: `tests/Feature/ArchiveGroupedTitlesTest.php` (add methods)

- [ ] **Step 1: Write the failing feature tests**

Add these methods to `ArchiveGroupedTitlesTest`:

```php
    /** @test */
    public function group_detail_lists_all_orders_in_the_group(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $d1 = $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $resp = $this->actingAs($admin)->get(route('order.indexJudul.detail', $d1->id));

        $resp->assertOk();
        $details = $resp->viewData('details');
        $this->assertCount(2, $details);
        $resp->assertSee('Alice')->assertSee('Bob');
    }

    /** @test */
    public function progress_detail_autocreates_progress_for_legacy_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $detail = $this->makeDetail('Buku Lama Tanpa Progress', 'bk_mandiri', null, ['Z']);
        $this->assertDatabaseMissing('tb_title_progress', ['order_detail_id' => $detail->id]);

        $resp = $this->actingAs($admin)->get(route('order.indexJudul.progress', $detail->id));

        $resp->assertOk();
        $this->assertDatabaseHas('tb_title_progress', [
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
        ]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: FAIL — `Route [order.indexJudul.progress] not defined`, and `detailJudul` still renders the old per-order `orders.detail-title` view (no `details` view data).

- [ ] **Step 3a: Add the progress route**

In `routes/web.php`, inside the `Route::prefix('management')->name('order.')` group, add the new route immediately after the existing `title/details/{id}` line:

```php
        Route::get('title/order/{id}', [OrderBookController::class, 'progressDetail'])->name('indexJudul.progress');
```

- [ ] **Step 3b: Replace `detailJudul` and add `progressDetail`**

Replace the entire existing `detailJudul($id)` method in `OrderBookController` with these two methods:

```php
    public function detailJudul($id, TitleArchiveService $archive)
    {
        $base = OrderDetail::query()
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())));

        $clicked = (clone $base)->with('order')->findOrFail($id);
        $key     = $archive->groupKey($clicked);

        $details = (clone $base)
            ->with(['order.user', 'authors', 'titleProgress'])
            ->get()
            ->filter(fn ($d) => $archive->groupKey($d) === $key)
            ->values();

        $summary = $archive->summarize($details);

        return view('orders.detail-title-group', compact('summary', 'details'));
    }

    public function progressDetail($id)
    {
        $detail = OrderDetail::with([
                'authors',
                'scopes',
                'order.user',
                'titleProgress.logs.changedBy',
            ])
            ->where('id', $id)
            ->whereHas('order', function ($q) {
                $q->when(Auth::user()->hasRole('marketing'), fn ($query) =>
                    $query->where('tb_orders.user_id', Auth::id()));
            })
            ->firstOrFail();

        // Fallback: create TitleProgress for legacy data created before this feature.
        if (!$detail->titleProgress) {
            DB::transaction(function () use ($detail) {
                TitleProgress::create([
                    'order_detail_id' => $detail->id,
                    'status'          => 'menunggu_proses',
                    'assigned_role'   => 'marketing',
                    'updated_by'      => Auth::id(),
                    'started_at'      => now(),
                ]);
            });
            $detail->load('titleProgress.logs.changedBy');
        }

        return view('orders.detail-title', compact('detail'));
    }
```

- [ ] **Step 3c: Create the group detail view**

Create `resources/views/orders/detail-title-group.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
<div class="page-content">
    <div class="row">
        <div class="col-md-12">

            @php
                $statusColors = [
                    'menunggu_proses' => 'secondary',
                    'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                    'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
                    'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                    'publish' => 'success', 'terbit' => 'success',
                ];
            @endphp

            {{-- Header agregat --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <h3 class="mb-0 text-capitalize">{{ $summary->title }}</h3>
                        <span class="badge bg-info">{{ $summary->type_label }}</span>
                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Total Author</p>
                            <h5>{{ $summary->total_author }}</h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Status Progres</p>
                            <h5>
                                <span class="badge bg-{{ $statusColors[$summary->bottleneck_status] ?? 'secondary' }}">
                                    {{ Str::title(str_replace('_', ' ', $summary->bottleneck_status)) }}
                                </span>
                                @if ($summary->is_mixed)
                                    <span class="badge bg-light text-muted">beragam</span>
                                @endif
                            </h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Update Terakhir</p>
                            <h5><small>{{ $summary->last_update ? \Carbon\Carbon::parse($summary->last_update)->diffForHumans() : '-' }}</small></h5>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Daftar penulis / order --}}
            <div class="card">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Daftar Penulis &amp; Order</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Penulis</th>
                                    <th>Posisi</th>
                                    <th>Kode Order</th>
                                    <th>Marketing</th>
                                    <th>Status Order</th>
                                    <th>Update</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($details as $detail)
                                    @php
                                        $st = optional($detail->titleProgress)->status ?? 'menunggu_proses';
                                    @endphp
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>
                                            @forelse ($detail->authors as $author)
                                                <div><strong>{{ $author->name }}</strong></div>
                                            @empty
                                                <span class="text-muted">-</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            @foreach ($detail->authors as $author)
                                                <span class="badge bg-light text-primary">Ke-{{ $author->pivot->position }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $detail->order->code_order }}</td>
                                        <td>{{ $detail->order->user->name ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ $statusColors[$st] ?? 'secondary' }}">
                                                {{ Str::title(str_replace('_', ' ', $st)) }}
                                            </span>
                                        </td>
                                        <td><small>{{ optional($detail->titleProgress)->updated_at ? $detail->titleProgress->updated_at->diffForHumans() : '-' }}</small></td>
                                        <td>
                                            <a href="{{ route('order.indexJudul.progress', $detail->id) }}"
                                                class="btn btn-sm btn-outline-primary">Progress</a>
                                            <a href="{{ route('order.book.show', $detail->order->code_order) }}"
                                                class="btn btn-sm btn-outline-secondary">Order</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">Belum ada order.</td></tr>
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

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/Pages/OrderBookController.php resources/views/orders/detail-title-group.blade.php tests/Feature/ArchiveGroupedTitlesTest.php
git commit -m "feat: add grouped title detail page with per-order Progress/Order actions"
```

---

## Task 5: Sidebar reorganization

**Files:**
- Rewrite: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/ArchiveGroupedTitlesTest.php` (add methods)

- [ ] **Step 1: Write the failing feature tests**

Add these methods to `ArchiveGroupedTitlesTest` (the sidebar is rendered by `layouts.master`, which the Archive page extends):

```php
    /** @test */
    public function sidebar_shows_renamed_archive_label_for_marketing(): void
    {
        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertSee('Arsip Judul');
        $resp->assertSee('Daftar Order');
    }

    /** @test */
    public function sidebar_hides_user_management_from_marketing(): void
    {
        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertDontSee('Manajemen User');
    }

    /** @test */
    public function sidebar_shows_user_management_for_superadmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $resp = $this->actingAs($admin)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertSee('Manajemen User');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: FAIL — current sidebar uses labels "Archive" / "User Management", so `assertSee('Arsip Judul')` and `assertSee('Manajemen User')` fail.

- [ ] **Step 3: Rewrite the sidebar**

Replace the `<nav class="sidebar">...</nav>` block (lines 1-167) in `resources/views/layouts/sidebar.blade.php` with the following. **Leave the `<nav class="massege">` flash-message block below it unchanged.**

```blade
<nav class="sidebar">
    <div class="sidebar-header">
        <a href="#" class="sidebar-brand">Si<span>MAPA</span></a>
        <div class="sidebar-toggler not-active">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </div>
    <div class="sidebar-body">
        <ul class="nav">
            <li class="nav-item nav-category">Menu Utama</li>
            <li class="nav-item {{ active_class(['dashboard']) }}">
                <a href="{{ route('dashboard') }}" class="nav-link">
                    <i class="link-icon" data-feather="box"></i>
                    <span class="link-title">Dashboard</span>
                </a>
            </li>

            @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Order &amp; Naskah</li>
                <li class="nav-item {{ active_class(['order/*']) }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuOrder" role="button"
                        aria-expanded="{{ is_active_route(['order/*']) }}" aria-controls="menuOrder">
                        <i class="link-icon" data-feather="shopping-cart"></i>
                        <span class="link-title">Buat Order</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ show_class(['order/*']) }}" id="menuOrder">
                        <ul class="nav sub-menu">
                            <li class="nav-item">
                                <a href="{{ route('order.book.create') }}" class="nav-link {{ active_class(['order/buku/*']) }}">Buku</a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('order.journal.create') }}" class="nav-link {{ active_class(['order/jurnal/*']) }}">Jurnal</a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item {{ active_class(['management/order']) }}">
                    <a href="{{ route('order.book.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="list"></i>
                        <span class="link-title">Daftar Order</span>
                    </a>
                </li>
                <li class="nav-item {{ active_class(['management/title']) }}">
                    <a href="{{ route('order.book.indexJudul') }}" class="nav-link">
                        <i class="link-icon" data-feather="archive"></i>
                        <span class="link-title">Arsip Judul</span>
                    </a>
                </li>
            @endrole

            <li class="nav-item nav-category">Pembayaran</li>
            <li class="nav-item {{ active_class(['payments/*']) }}">
                <a class="nav-link" data-bs-toggle="collapse" href="#menuPayment" role="button"
                    aria-expanded="{{ is_active_route(['payments/*']) }}" aria-controls="menuPayment">
                    <i class="link-icon" data-feather="credit-card"></i>
                    <span class="link-title">Pembayaran</span>
                    <i class="link-arrow" data-feather="chevron-down"></i>
                </a>
                <div class="collapse {{ show_class(['payments/*']) }}" id="menuPayment">
                    <ul class="nav sub-menu">
                        <li class="nav-item">
                            <a href="{{ route('payment.dp.index') }}" class="nav-link">DP / Tagihan</a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('payment.fp.index') }}" class="nav-link">Pelunasan</a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('payment.index') }}" class="nav-link">Disetujui</a>
                        </li>
                    </ul>
                </div>
            </li>
            <li class="nav-item {{ request()->routeIs('invoice.*') ? 'active' : '' }}">
                <a href="{{ route('invoice.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="file-text"></i>
                    <span class="link-title">Invoice</span>
                </a>
            </li>

            <li class="nav-item nav-category">Laporan</li>
            <li class="nav-item {{ active_class(['income/*']) }}">
                <a class="nav-link" data-bs-toggle="collapse" href="#menuIncome" role="button"
                    aria-expanded="{{ is_active_route(['income/*']) }}" aria-controls="menuIncome">
                    <i class="link-icon" data-feather="bar-chart-2"></i>
                    <span class="link-title">Pendapatan</span>
                    <i class="link-arrow" data-feather="chevron-down"></i>
                </a>
                <div class="collapse {{ show_class(['income/*']) }}" id="menuIncome">
                    <ul class="nav sub-menu">
                        <li class="nav-item"><a href="{{ route('income.order') }}" class="nav-link">Order</a></li>
                        <li class="nav-item"><a href="{{ route('income.payment') }}" class="nav-link">Payment</a></li>
                        <li class="nav-item"><a href="{{ route('income.pending') }}" class="nav-link">Pending</a></li>
                        <li class="nav-item"><a href="{{ route('income.lunas') }}" class="nav-link">Lunas</a></li>
                    </ul>
                </div>
            </li>

            <li class="nav-item nav-category">Akun</li>
            @role(['superadmin', 'manager'])
                <li class="nav-item {{ active_class(['user-management']) }}">
                    <a href="{{ route('user.management') }}" class="nav-link">
                        <i class="link-icon" data-feather="users"></i>
                        <span class="link-title">Manajemen User</span>
                    </a>
                </li>
            @endrole
            <li class="nav-item {{ active_class(['profile']) }}">
                <a href="{{ route('profile') }}" class="nav-link">
                    <i class="link-icon" data-feather="user"></i>
                    <span class="link-title">Profil</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Run the full suite (no regressions)**

Run: `php artisan test`
Expected: PASS — existing `TitleProgressTest`, `InvoiceLifecycleTest`, auth tests, etc. still green. (The old per-detail `detailJudul` behavior is replaced; confirm no other test referenced it.)

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php tests/Feature/ArchiveGroupedTitlesTest.php
git commit -m "feat: reorganize sidebar into role-guarded Indonesian menu groups"
```

---

## Manual Verification (after all tasks)

Run the app and confirm:
- [ ] **Archive grouping:** Seed/create two orders with the same (slightly mis-spelled) title + different authors → Archive shows **one** row, Total Author = 2, status = least-advanced + `beragam` badge.
- [ ] **DataTables:** search box, column sorting, and pagination work on the Archive table.
- [ ] **Group detail:** clicking **Detail** lists all authors/orders; **Progress** opens the per-order timeline (with status-update form for manager/superadmin); **Order** opens the order page.
- [ ] **Sidebar:** renders the new groups; collapsible submenus open; `Manajemen User` is hidden for marketing; active item highlights on each page.

---

## Self-Review Notes (author)

- **Spec coverage:** §1 Archive grouping → Tasks 1-3; §2 group detail + progress split → Task 4; §3 sidebar → Task 5; §4 DataTables restore → Task 3 (Step 3c). Edge cases (legacy no-progress, distinct authors, mixed pipeline, marketing scope, canonical tie-break) covered by service logic + tests in Tasks 2-4.
- **Type consistency:** summary object shape (`detail_id_repr`, `title`, `type`, `type_label`, `total_author`, `bottleneck_status`, `handler`, `last_update`, `is_mixed`) is defined in Task 2 and consumed identically in Task 3 (view) and Task 4 (view/test). Route names `order.indexJudul.detail` (group) and `order.indexJudul.progress` (per-order) are used consistently.
- **No placeholders:** every step contains full code/commands.
