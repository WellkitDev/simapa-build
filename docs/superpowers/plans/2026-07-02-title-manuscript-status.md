# Title Directory Fase 2b-1 (Manuscript Status in Directory) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Direktori Judul menampilkan tahap manuskrip per judul (roll-up bottleneck + per-order) dibaca via `title_id → OrderDetail → TitleProgress`, plus tombol ke papan manuskrip. Aditif — tanpa migrasi, tanpa mengubah `group_key`.

**Architecture:** Dua helper turunan di `Title` (`manuscriptStatus`, `manuscriptStatusLabel` + static `stageLabel`) yang membaca relasi `orderDetails.titleProgress` (eager-loaded kontroler). View index/show menampilkan badge tahap. Bottleneck = stage paling awal (`BOOK_STAGES`/`ARTICLE_STAGES`), konsisten dengan papan manuskrip.

**Tech Stack:** Laravel 11, Eloquent, Blade, Spatie roles.

**Spec:** `docs/superpowers/specs/2026-07-02-title-manuscript-status-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell), tunggu ~6 dtk. Commit: `git add <path eksplisit>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic", jangan `git add .`. **Tanpa migrasi** — tak perlu `php artisan migrate`.

**Fakta:** `TitleProgress::BOOK_STAGES = ['menunggu_proses','editing','layout','proofreading','isbn','cetak','terbit']`, `ARTICLE_STAGES = ['menunggu_proses','templating','editing','revisi','submit','loa','publish']`, `FINAL_STAGES = ['terbit','publish']`. `Title::orderDetails()` hasMany(OrderDetail,'title_id'); `OrderDetail::titleProgress()` hasOne. Papan: `route('manuscript.board', ['tipe' => 'buku'|'artikel'])` (gated superadmin/manager/production).

---

## Task 1: `Title` manuscript-status helpers (TDD)

**Files:**
- Modify: `app/Models/Title.php`
- Test: `tests/Unit/TitleManuscriptStatusTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Unit/TitleManuscriptStatusTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleManuscriptStatusTest extends TestCase
{
    use RefreshDatabase;

    private function linkedTitle(string $jenis, array $statuses): Title
    {
        $user = User::factory()->create();
        $type = $jenis === 'buku' ? 'bk_mandiri' : 'at_mandiri';
        $title = Title::create(['title' => 'Judul Uji', 'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        foreach ($statuses as $i => $st) {
            $order = Order::create(['code_order' => 'ORD-T' . $i . '-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
            $detail = OrderDetail::create([
                'order_id' => $order->id, 'title_id' => $title->id, 'type' => $type,
                'title' => 'Judul Uji', 'slug' => 'judul-uji-' . $i, 'cost_amount' => 0,
            ]);
            TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $st, 'assigned_role' => 'production', 'started_at' => now()]);
        }

        return $title->load('orderDetails.titleProgress');
    }

    /** @test */
    public function manuscript_status_returns_earliest_stage_bottleneck(): void
    {
        // BOOK_STAGES: menunggu_proses, editing, layout, ... → editing lebih awal dari layout
        $title = $this->linkedTitle('buku', ['layout', 'editing']);
        $this->assertSame('editing', $title->manuscriptStatus());
    }

    /** @test */
    public function manuscript_status_null_without_orders(): void
    {
        $title = Title::create(['title' => 'Tanpa Order', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertNull($title->load('orderDetails.titleProgress')->manuscriptStatus());
    }

    /** @test */
    public function stage_label_formats_special_cases(): void
    {
        $this->assertSame('LoA', Title::stageLabel('loa'));
        $this->assertSame('ISBN', Title::stageLabel('isbn'));
        $this->assertSame('Menunggu Proses', Title::stageLabel('menunggu_proses'));
        $this->assertNull(Title::stageLabel(null));
    }

    /** @test */
    public function manuscript_status_label_uses_status(): void
    {
        $title = $this->linkedTitle('artikel', ['loa']);
        $this->assertSame('LoA', $title->manuscriptStatusLabel());
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleManuscriptStatusTest`
Expected: FAIL — `Call to undefined method ... manuscriptStatus()`.

- [ ] **Step 3: Implement helpers in `app/Models/Title.php`**

Add `use Illuminate\Support\Str;` to the imports at the top (below the existing `use` lines). Then add these methods after the `logs()` relation (or after `isApproved()`):

```php
    /** Tahap manuskrip judul = bottleneck (stage paling awal) di antara order tertaut. Null bila belum ada progress. */
    public function manuscriptStatus(): ?string
    {
        $stages = $this->jenis === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;

        $statuses = $this->orderDetails
            ->map(fn ($d) => optional($d->titleProgress)->status)
            ->filter();

        if ($statuses->isEmpty()) {
            return null;
        }

        return $statuses
            ->sortBy(fn ($s) => ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();
    }

    public function manuscriptStatusLabel(): ?string
    {
        return self::stageLabel($this->manuscriptStatus());
    }

    /** Label rapi untuk satu status tahap manuskrip. */
    public static function stageLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ($status) {
            'loa'  => 'LoA',
            'isbn' => 'ISBN',
            default => Str::title(str_replace('_', ' ', $status)),
        };
    }
```

(`TitleProgress` is in the same `App\Models` namespace — no import needed.)

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TitleManuscriptStatusTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```
git add app/Models/Title.php tests/Unit/TitleManuscriptStatusTest.php
git commit -m "feat(title-manuscript): Title manuscript status helpers (bottleneck + label)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Controller eager-load + directory views (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (index + show)
- Modify: `resources/views/titles/index.blade.php`, `resources/views/titles/show.blade.php`
- Test: `tests/Feature/TitleDirectoryManuscriptTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Feature/TitleDirectoryManuscriptTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleDirectoryManuscriptTest extends TestCase
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

    private function titleWithProgress(string $status = 'editing'): Title
    {
        $owner = $this->user('production');
        $title = Title::create(['title' => 'Naskah Uji', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-M1-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Naskah Uji', 'slug' => 'naskah-uji', 'cost_amount' => 0]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => 'production', 'started_at' => now()]);

        return $title;
    }

    /** @test */
    public function index_shows_manuscript_stage_label(): void
    {
        $this->titleWithProgress('editing');
        $this->actingAs($this->user('manager'))->get(route('title.index'))
            ->assertOk()->assertSee('Manuskrip')->assertSee('Editing');
    }

    /** @test */
    public function show_shows_board_link_for_production_not_marketing(): void
    {
        $title = $this->titleWithProgress('editing');

        $this->actingAs($this->user('production'))->get(route('title.show', $title->id))
            ->assertOk()->assertSee('Buka Papan Manuskrip');

        $this->actingAs($this->user('marketing'))->get(route('title.show', $title->id))
            ->assertOk()->assertDontSee('Buka Papan Manuskrip');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleDirectoryManuscriptTest`
Expected: FAIL (index lacks "Manuskrip" column / show lacks board link).

- [ ] **Step 3: Controller — eager-load + canOpenBoard**

In `app/Http/Controllers/Pages/TitleController.php`:

`index()` — add `orderDetails.titleProgress` to the eager-load. Change:
```php
        $query = Title::with(['creator', 'scope', 'assignedMarketing'])
```
to:
```php
        $query = Title::with(['creator', 'scope', 'assignedMarketing', 'orderDetails.titleProgress'])
```

`show()` — add `orderDetails.titleProgress` to the eager-load and a `canOpenBoard` flag. Change the load line:
```php
        $title = Title::with(['chapters', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'journalOptions', 'logs.changedBy'])->findOrFail($id);
```
to:
```php
        $title = Title::with(['chapters', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'journalOptions', 'logs.changedBy'])->findOrFail($id);
```
and add `'canOpenBoard' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'production'])` to the array passed to `view('titles.show', [...])`.

- [ ] **Step 4: index view — Manuskrip column**

In `resources/views/titles/index.blade.php`, in the `<thead>` row, insert after `<th>Jml Author</th>`:
```blade
<th>Manuskrip</th>
```
In the body row, insert after the `<td>{{ $t->authors_count ?? 0 }}</td>` cell:
```blade
                        @php $mstat = $t->manuscriptStatus(); @endphp
                        <td>@if($mstat)<span class="badge {{ in_array($mstat, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ $t->manuscriptStatusLabel() }}</span>@else<span class="text-muted">—</span>@endif</td>
```

- [ ] **Step 5: show view — roll-up + per-order column + board button**

In `resources/views/titles/show.blade.php`, right AFTER the line `<p class="mb-2">Order tertaut: <strong>Jml Order</strong> ...</p>`, insert:
```blade
    @php $mstat = $title->manuscriptStatus(); @endphp
    <p class="mb-2">Manuskrip:
        @if($mstat)<span class="badge {{ in_array($mstat, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ $title->manuscriptStatusLabel() }}</span>@else<span class="text-muted">Belum ada order</span>@endif
        @if($canOpenBoard)
            <a href="{{ route('manuscript.board', ['tipe' => $title->jenis === 'buku' ? 'buku' : 'artikel']) }}" class="btn btn-xs btn-outline-secondary ms-2">Buka Papan Manuskrip</a>
        @endif
    </p>
```

In the "Order Tertaut" table, add a header `<th>Manuskrip</th>` after `<th>Tanggal</th>`:
```blade
                <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Manuskrip</th></tr></thead>
```
and a cell after the tanggal `<td>` inside the `@foreach($title->orderDetails as $od)` row:
```blade
                            <td>{{ \App\Models\Title::stageLabel(optional($od->titleProgress)->status) ?? '—' }}</td>
```

- [ ] **Step 6: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="TitleDirectoryManuscriptTest|TitlePagesTest|TitleControllerTest|TitlePublicationInfoTest|TitleOrderLinkTest"`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/TitleController.php resources/views/titles/index.blade.php resources/views/titles/show.blade.php tests/Feature/TitleDirectoryManuscriptTest.php
git commit -m "feat(title-manuscript): show manuscript stage in directory index/show + board link

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (323 sebelumnya + TitleManuscriptStatusTest (4) + TitleDirectoryManuscriptTest (2) = ~329).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Smoke (opsional)**

Login manager → Direktori Judul: kolom Manuskrip menampilkan tahap untuk judul yang punya order (atau "—"). Buka detail judul → baris Manuskrip + kolom per order + tombol "Buka Papan Manuskrip". Login marketing → tombol papan tak muncul.

---

## Catatan & Risiko

- Murni pembacaan + tampilan; tanpa migrasi, tanpa perubahan `group_key`/papan/order → risiko regresi minimal.
- Order lama `title_id=null` tak tertaut → tak muncul di roll-up judul (wajar; backfill = 2b-2).
- Bottleneck konsisten dengan papan manuskrip & Arsip Judul.
