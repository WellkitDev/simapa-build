# Production Workspace + Role-Aware Dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri tim production "Meja Kerja Saya" (tracker ter-scope) dan jadikan `/dashboard` role-aware berbasis progres + grafik, plus metrik performa on-time per editor.

**Architecture:** Tanpa tabel baru. Tambah helper stage di `TitleProgress`; dua service baru (`PerformanceService`, `ProductionDashboardService`) menampung query/agregasi; `ManuscriptTrackerController@index` dapat dimensi `scope`; `DashboardController` bercabang per-role dan mendelegasi ke service; Blade dashboard role-aware via partial. Reuse ApexCharts + DataTables yang sudah ada.

**Tech Stack:** Laravel 10, Spatie Permission, Blade + Bootstrap 5, ApexCharts, DataTables, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-15-production-workspace-dashboard-design.md`

> **Branch:** kerjakan di branch `Fitur` (sudah aktif). **Commit attribution:** author `WellkitDev <rahmatpurnomo808@gmail.com>` (sudah di git config), dan akhiri tiap pesan commit dengan `Co-Authored-By: Mira <admin@avidpedia.com>` (BUKAN "Claude").
>
> **Testing:** `php artisan test` (otomatis `.env.testing`, DB `avidpedi_simapa_test`). Suite saat ini 117 passed — harus tetap hijau. **Commit rule:** `git add` hanya path eksplisit per task; jangan pernah `git add .`/`-A`; jangan commit file lokal-only (`avidpedi_simapa.sql`, `database/seeders/*`, `.gitignore`, `public/error_log`, `template-web/`, design HTML).

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Modify | `app/Models/TitleProgress.php` | `FINAL_STAGES`, `productionStages()`, `isFinal()` |
| Create | `app/Services/PerformanceService.php` | on-time rate per editor |
| Create | `app/Services/ProductionDashboardService.php` | KPI + data chart (saya & global) |
| Modify | `app/Http/Controllers/Pages/ManuscriptTrackerController.php` | param `scope`, filter kerja-saya, sort prioritas/target |
| Modify | `resources/views/manuscript/partials/toolbar.blade.php` | toggle Meja Saya/Semua |
| Modify | `resources/views/manuscript/partials/card.blade.php` | tombol **Ambil** |
| Modify | `resources/views/layouts/sidebar.blade.php` | label "Meja Kerja Saya" untuk production |
| Modify | `app/Http/Controllers/DashboardController.php` | branch per-role |
| Modify | `resources/views/dashboard.blade.php` | role-aware: include partial / gate finansial |
| Create | `resources/views/dashboard/partials/production.blade.php` | KPI + chart produksi (diri) |
| Create | `resources/views/dashboard/partials/progress-global.blade.php` | KPI + chart + tabel performa global |
| Create | `tests/Unit/PerformanceServiceTest.php` | hitung on-time rate |
| Create | `tests/Unit/ProductionDashboardServiceTest.php` | hitung KPI kunci |
| Create | `tests/Feature/ProductionWorkspaceTest.php` | scope, Ambil, dashboard per-role |

---

## Task 1: Helper stage di TitleProgress

**Files:**
- Modify: `app/Models/TitleProgress.php`
- Test: `tests/Unit/ProductionDashboardServiceTest.php` (dibuat di Task 3; helper diuji tak langsung)

- [ ] **Step 1: Tambah konstanta & helper**

Di `app/Models/TitleProgress.php`, tepat setelah baris `const PRIORITIES = ['low', 'normal', 'high'];`, tambahkan:

```php
    const FINAL_STAGES = ['terbit', 'publish'];

    /** Daftar status yang handler-nya production (diturunkan dari STAGE_HANDLER). */
    public static function productionStages(): array
    {
        return array_keys(array_filter(self::STAGE_HANDLER, fn ($role) => $role === 'production'));
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STAGES, true);
    }
```

- [ ] **Step 2: Sanity check via tinker**

Run: `php artisan tinker --execute="print_r(App\Models\TitleProgress::productionStages());"`
Expected: array berisi `templating, editing, revisi, submit, layout, proofreading, isbn`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/TitleProgress.php
git commit -m "$(printf 'feat: add production-stage + final-stage helpers to TitleProgress\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 2: PerformanceService (on-time rate) — TDD

**Files:**
- Create: `tests/Unit/PerformanceServiceTest.php`
- Create: `app/Services/PerformanceService.php`

- [ ] **Step 1: Tulis unit test (failing)**

```php
<?php
// tests/Unit/PerformanceServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new PerformanceService();
    }

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $attrs['type'] ?? 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id,
            'status'          => 'editing',
            'assigned_role'   => 'production',
            'started_at'      => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    private function editor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('production');
        return $u;
    }

    /** @test */
    public function on_time_rate_counts_only_completed_with_target(): void
    {
        $ed = $this->editor();

        // 2 selesai tepat waktu (started_at <= target), 1 telat → rate 66.7%
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-10', 'target_date' => '2026-06-15']);
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-10', 'target_date' => '2026-06-12']);
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-20', 'target_date' => '2026-06-15']); // telat
        // selesai tanpa target → tidak masuk rate, masuk completed
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => now(), 'target_date' => null]);
        // milik editor lain → diabaikan
        $this->progress(['assigned_user_id' => $this->editor()->id, 'status' => 'terbit', 'started_at' => now(), 'target_date' => '2026-01-01']);

        $r = $this->svc->forEditor($ed, 3650); // periode besar agar semua masuk

        $this->assertEquals(4, $r['completed']);
        $this->assertEquals(3, $r['with_target']);
        $this->assertEquals(2, $r['on_time']);
        $this->assertEquals(66.7, $r['on_time_rate']);
    }

    /** @test */
    public function on_time_rate_is_null_when_no_completed_with_target(): void
    {
        $ed = $this->editor();
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit', 'target_date' => null]);

        $r = $this->svc->forEditor($ed, 3650);
        $this->assertNull($r['on_time_rate']);
        $this->assertEquals(1, $r['completed']);
    }

    /** @test */
    public function active_queue_counts_assigned_not_final(): void
    {
        $ed = $this->editor();
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'editing']);   // aktif
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'layout']);    // aktif
        $this->progress(['assigned_user_id' => $ed->id, 'status' => 'terbit']);    // final → bukan antrian

        $r = $this->svc->forEditor($ed, 30);
        $this->assertEquals(2, $r['active_queue']);
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=PerformanceServiceTest`
Expected: FAIL — `App\Services\PerformanceService` belum ada.

- [ ] **Step 3: Buat service**

```php
<?php
// app/Services/PerformanceService.php

namespace App\Services;

use App\Models\User;
use App\Models\TitleProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PerformanceService
{
    /**
     * Metrik on-time per editor. Atribusi ke assigned_user_id.
     * "Selesai" = status final & started_at dalam periode. "Tepat waktu" = started_at <= target_date.
     */
    public function forEditor(User $editor, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        $completed = TitleProgress::query()
            ->where('assigned_user_id', $editor->id)
            ->whereIn('status', TitleProgress::FINAL_STAGES)
            ->where('started_at', '>=', $since)
            ->get(['id', 'started_at', 'target_date']);

        $withTarget = $completed->filter(fn ($p) => $p->target_date !== null);
        $onTime = $withTarget->filter(fn ($p) =>
            $p->started_at->toDateString() <= $p->target_date->toDateString());

        $rate = $withTarget->count() > 0
            ? round($onTime->count() / $withTarget->count() * 100, 1)
            : null;

        $activeQueue = TitleProgress::query()
            ->where('assigned_user_id', $editor->id)
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->count();

        return [
            'completed'    => $completed->count(),
            'with_target'  => $withTarget->count(),
            'on_time'      => $onTime->count(),
            'on_time_rate' => $rate,
            'active_queue' => $activeQueue,
        ];
    }

    /** Statistik untuk semua editor (production/manager) — untuk tabel/bar global. */
    public function allEditors(int $days = 30): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager']))
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'stats' => $this->forEditor($u, $days),
            ]);
    }
}
```

- [ ] **Step 4: Jalankan — pastikan PASS**

Run: `php artisan test --filter=PerformanceServiceTest`
Expected: PASS (3 test).

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/PerformanceServiceTest.php app/Services/PerformanceService.php
git commit -m "$(printf 'feat: add PerformanceService (on-time rate per editor)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 3: ProductionDashboardService — TDD

**Files:**
- Create: `tests/Unit/ProductionDashboardServiceTest.php`
- Create: `app/Services/ProductionDashboardService.php`

- [ ] **Step 1: Tulis unit test (failing)**

```php
<?php
// tests/Unit/ProductionDashboardServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\ProductionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ProductionDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new ProductionDashboardService();
    }

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $attrs['type'] ?? 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'status'        => 'editing',
            'assigned_role' => 'production',
            'started_at'    => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function for_user_computes_queue_unclaimed_and_overdue(): void
    {
        $me = User::factory()->create();
        $me->assignRole('production');

        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing']); // antrian saya
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'layout', 'target_date' => now()->subDay()->toDateString()]); // antrian + lewat target
        $this->progress(['assigned_user_id' => null, 'status' => 'editing']);    // belum diambil
        $this->progress(['assigned_user_id' => null, 'status' => 'menunggu_proses']); // bukan stage produksi → bukan belum-diambil

        $d = $this->svc->forUser($me);

        $this->assertEquals(2, $d['antrian_saya']);
        $this->assertEquals(1, $d['belum_diambil']);
        $this->assertEquals(1, $d['lewat_target']);
    }

    /** @test */
    public function global_counts_all_in_production(): void
    {
        $this->progress(['assigned_user_id' => null, 'status' => 'editing']);
        $this->progress(['assigned_user_id' => null, 'status' => 'isbn']);
        $this->progress(['assigned_user_id' => null, 'status' => 'terbit']); // final → bukan in-production

        $g = $this->svc->global();
        $this->assertEquals(2, $g['total_in_production']);
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=ProductionDashboardServiceTest`
Expected: FAIL — service belum ada.

- [ ] **Step 3: Buat service**

```php
<?php
// app/Services/ProductionDashboardService.php

namespace App\Services;

use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Support\Carbon;

class ProductionDashboardService
{
    /** KPI + data chart untuk satu production user (kerja saya). */
    public function forUser(User $user): array
    {
        $prodStages = TitleProgress::productionStages();
        $today      = Carbon::today();

        $mineActive = TitleProgress::query()
            ->where('assigned_user_id', $user->id)
            ->whereNotIn('status', TitleProgress::FINAL_STAGES);

        $perStage = (clone $mineActive)->get(['status'])
            ->groupBy('status')->map->count();

        return [
            'antrian_saya'      => (clone $mineActive)->count(),
            'belum_diambil'     => TitleProgress::whereNull('assigned_user_id')->whereIn('status', $prodStages)->count(),
            'lewat_target'      => (clone $mineActive)->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'     => (clone $mineActive)->whereNotNull('target_date')
                                    ->whereDate('target_date', '>=', $today)
                                    ->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini' => TitleProgress::where('assigned_user_id', $user->id)
                                    ->whereIn('status', TitleProgress::FINAL_STAGES)
                                    ->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'per_stage'         => $this->stageChart($perStage),
            'activity_30d'      => $this->activitySeries($user->id),
        ];
    }

    /** KPI + data chart global (manager/superadmin). */
    public function global(): array
    {
        $prodStages = TitleProgress::productionStages();
        $today      = Carbon::today();

        $inProduction = TitleProgress::query()->whereIn('status', $prodStages);

        $perStage = (clone $inProduction)->get(['status'])->groupBy('status')->map->count();

        return [
            'total_in_production' => (clone $inProduction)->count(),
            'lewat_target'        => TitleProgress::whereNotIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'       => TitleProgress::whereNotIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereNotNull('target_date')
                                        ->whereDate('target_date', '>=', $today)
                                        ->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini'   => TitleProgress::whereIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'per_stage'           => $this->stageChart($perStage),
            'completion_trend'    => $this->completionTrend(),
        ];
    }

    /** {labels:[stage], series:[count]} untuk donut. */
    private function stageChart($perStage): array
    {
        return [
            'labels' => $perStage->keys()->map(fn ($s) => \Illuminate\Support\Str::title(str_replace('_', ' ', $s)))->values()->all(),
            'series' => $perStage->values()->all(),
        ];
    }

    /** Aktivitas harian user (log) 30 hari → {labels, series}. */
    private function activitySeries(int $userId): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::where('changed_by', $userId)
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian global per hari 30 hari (log to_value Terbit/Publish) → {labels, series}. */
    private function completionTrend(): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
}
```

- [ ] **Step 4: Jalankan — pastikan PASS**

Run: `php artisan test --filter=ProductionDashboardServiceTest`
Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/ProductionDashboardServiceTest.php app/Services/ProductionDashboardService.php
git commit -m "$(printf 'feat: add ProductionDashboardService (KPIs + chart data, mine + global)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 4: Tracker scope (Meja Kerja Saya) — TDD

**Files:**
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`
- Create: `tests/Feature/ProductionWorkspaceTest.php`

- [ ] **Step 1: Tulis feature test (failing)**

```php
<?php
// tests/Feature/ProductionWorkspaceTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ProductionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => $attrs['title'] ?? fake()->sentence(3)]);
        return TitleProgress::create(array_merge([
            'status'        => 'editing',
            'assigned_role' => 'production',
            'started_at'    => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function scope_mine_shows_only_my_work_and_unclaimed(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing', 'title' => 'MILIK SAYA']);
        $this->progress(['assigned_user_id' => null, 'status' => 'editing', 'title' => 'BELUM DIAMBIL']);
        $this->progress(['assigned_user_id' => $this->user('production')->id, 'status' => 'editing', 'title' => 'MILIK ORANG LAIN']);

        $this->actingAs($me);
        $this->get(route('manuscript.board', ['tipe' => 'buku', 'scope' => 'mine']))
            ->assertOk()
            ->assertSee('MILIK SAYA')
            ->assertSee('BELUM DIAMBIL')
            ->assertDontSee('MILIK ORANG LAIN');
    }

    /** @test */
    public function production_defaults_to_scope_mine(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $this->user('production')->id, 'status' => 'editing', 'title' => 'PUNYA EDITOR LAIN']);

        $this->actingAs($me);
        // tanpa param scope → production default 'mine' → tak melihat punya editor lain
        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertDontSee('PUNYA EDITOR LAIN');
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=ProductionWorkspaceTest`
Expected: FAIL — `scope` belum diterapkan (semua naskah tampil).

- [ ] **Step 3: Tambah scope di `index()`**

Buka `app/Http/Controllers/Pages/ManuscriptTrackerController.php`. Di method `index()`, tepat setelah baris `$view = in_array($request->query('view'), ['list', 'log'], true) ? $request->query('view') : 'board';`, tambahkan:

```php
        $isProductionOnly = Auth::user()->hasRole('production') && ! Auth::user()->hasAnyRole(['manager', 'superadmin']);
        $scope = $request->query('scope') === 'all' ? 'all'
               : ($request->query('scope') === 'mine' ? 'mine' : ($isProductionOnly ? 'mine' : 'all'));
        $prodStages = TitleProgress::productionStages();
```

Lalu pada query `$details = OrderDetail::query()...`, tambahkan filter scope **sebelum** `->get();` (setelah `->when($request->boolean('review'), ...)`):

```php
            ->when($scope === 'mine', fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) =>
                    $t->where('assigned_user_id', Auth::id())
                      ->orWhere(fn ($w) => $w->whereNull('assigned_user_id')->whereIn('status', $prodStages))))
```

- [ ] **Step 4: Sort kartu (prioritas lalu target) + kirim `$scope` ke view**

Ganti baris `$byStatus = $groups->groupBy(...)` dengan versi yang mengurutkan dalam tiap kolom:

```php
        $prioRank = ['high' => 0, 'normal' => 1, 'low' => 2];
        $groups   = $groups->sortBy(function ($g) use ($prioRank) {
            $p = $g->titleProgress;
            $overdue = $p->target_date && $p->target_date->isPast() && ! TitleProgress::isFinal($p->status) ? 0 : 1;
            $target  = optional($p->target_date)->timestamp ?? PHP_INT_MAX;
            return [$overdue, $prioRank[$p->priority] ?? 1, $target];
        })->values();
        $byStatus = $groups->groupBy(fn ($g) => optional($g->titleProgress)->status ?? 'menunggu_proses');
```

Lalu tambahkan `'scope'` ke `compact(...)` pada `return view('manuscript.' . $view, compact(...));`:

```php
        return view('manuscript.' . $view, compact('groups', 'stages', 'byStatus', 'tipe', 'view', 'editors', 'zones', 'reviewCount', 'logs', 'scope'));
```

- [ ] **Step 5: Jalankan — pastikan PASS**

Run: `php artisan test --filter=ProductionWorkspaceTest`
Expected: PASS (2 test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php tests/Feature/ProductionWorkspaceTest.php
git commit -m "$(printf 'feat: scope-aware tracker (mine/all) for production Meja Kerja Saya\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 5: Tombol Ambil + toggle scope + menu sidebar

**Files:**
- Modify: `resources/views/manuscript/partials/card.blade.php`
- Modify: `resources/views/manuscript/partials/toolbar.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Modify: `tests/Feature/ProductionWorkspaceTest.php`

- [ ] **Step 1: Tambah test "Ambil" (failing)**

Tambahkan ke `tests/Feature/ProductionWorkspaceTest.php`:

```php
    /** @test */
    public function ambil_self_assigns_unclaimed_naskah(): void
    {
        $me = $this->user('production');
        $p  = $this->progress(['assigned_user_id' => null, 'status' => 'editing']);

        $this->actingAs($me);
        $this->post(route('manuscript.assign', $p->id), ['assigned_user_id' => $me->id])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'assigned_user_id' => $me->id]);
    }
```

> Catatan: endpoint `manuscript.assign` sudah ada (group-wide) & production boleh self-assign. Test ini mengunci perilaku "Ambil".

- [ ] **Step 2: Jalankan — pastikan PASS** (perilaku sudah didukung endpoint existing)

Run: `php artisan test --filter=ambil_self_assigns_unclaimed_naskah`
Expected: PASS.

- [ ] **Step 3: Tambah tombol Ambil di kartu**

Di `resources/views/manuscript/partials/card.blade.php`, tepat setelah baris editor (`Editor: <strong>...`) di dalam blok `<small class="text-muted text-truncate" ...>`, **tidak**; lebih aman: sisipkan tombol di atas baris editor+dropdown. Cari baris:

```blade
        <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted text-truncate" style="max-width:140px">
                Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong>
            </small>
```

dan ganti menjadi:

```blade
        @if(is_null($p->assigned_user_id))
            <form method="POST" action="{{ route('manuscript.assign', $p->id) }}" class="mt-2">@csrf
                <input type="hidden" name="assigned_user_id" value="{{ auth()->id() }}">
                <button type="submit" class="btn btn-sm btn-success w-100 py-0" style="font-size:11px">+ Ambil naskah ini</button>
            </form>
        @endif
        <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted text-truncate" style="max-width:140px">
                Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong>
            </small>
```

- [ ] **Step 4: Tambah toggle scope di toolbar**

Di `resources/views/manuscript/partials/toolbar.blade.php` ada dua `btn-group` (tipe Buku/Artikel, dan view Papan/Daftar/Log). Sisipkan toggle scope tepat **sebelum** btn-group **view** — yaitu sebelum baris:
```blade
        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'board'])) }}"
```
Sisipkan:
```blade
        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['scope' => 'mine'])) }}"
               class="btn btn-{{ ($scope ?? 'all') === 'mine' ? 'success' : 'outline-success' }}">Meja Saya</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['scope' => 'all'])) }}"
               class="btn btn-{{ ($scope ?? 'all') === 'all' ? 'success' : 'outline-success' }}">Semua</a>
        </div>
```

- [ ] **Step 5: Label menu "Meja Kerja Saya" untuk production**

Di `resources/views/layouts/sidebar.blade.php`, di dalam blok `@role(['superadmin', 'manager', 'production'])`, ganti **hanya** baris label menu Manuscript Tracker:

Cari:
```blade
                        <span class="link-title">Manuscript Tracker</span>
```
Ganti dengan:
```blade
                        <span class="link-title">{{ (auth()->user()->hasRole('production') && ! auth()->user()->hasAnyRole(['manager','superadmin'])) ? 'Meja Kerja Saya' : 'Manuscript Tracker' }}</span>
```

- [ ] **Step 6: Verifikasi render & no-regресi**

Run: `php artisan view:clear && php artisan test --filter=ManuscriptTrackerTest`
Expected: PASS (semua test tracker tetap hijau).

- [ ] **Step 7: Commit**

```bash
git add resources/views/manuscript/partials/card.blade.php resources/views/manuscript/partials/toolbar.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/ProductionWorkspaceTest.php
git commit -m "$(printf 'feat: Ambil button, scope toggle, and Meja Kerja Saya menu label\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 6: DashboardController role-aware — TDD

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `tests/Feature/ProductionWorkspaceTest.php`

- [ ] **Step 1: Tambah test dashboard per-role (failing)**

Tambahkan ke `tests/Feature/ProductionWorkspaceTest.php`:

```php
    /** @test */
    public function production_dashboard_shows_production_kpis_not_financial(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing']);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Antrian Saya')          // KPI produksi
            ->assertSee('Performa Saya')          // widget performa
            ->assertDontSee('total payment');     // blok finansial tidak ada untuk production
    }

    /** @test */
    public function manager_dashboard_shows_global_progress_section(): void
    {
        $this->actingAs($this->user('manager'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Progres Naskah')        // seksi global
            ->assertSee('total payment');         // finansial tetap ada
    }
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter="production_dashboard_shows_production_kpis_not_financial|manager_dashboard_shows_global_progress_section"`
Expected: FAIL — dashboard belum role-aware.

- [ ] **Step 3: Branch di DashboardController**

Buka `app/Http/Controllers/DashboardController.php`. Tambahkan import di atas (setelah `use Illuminate\Support\Facades\Auth;`):

```php
use App\Services\ProductionDashboardService;
use App\Services\PerformanceService;
```

Di awal `index()`, tepat setelah `$userId = Auth::id();`, tambahkan cabang production (return lebih awal):

```php
        $user = Auth::user();
        $isProductionOnly = $user->hasRole('production') && ! $user->hasAnyRole(['manager', 'superadmin', 'marketing']);

        if ($isProductionOnly) {
            return view('dashboard', [
                'dashboardView' => 'production',
                'prod' => app(ProductionDashboardService::class)->forUser($user),
                'perf' => app(PerformanceService::class)->forEditor($user),
            ]);
        }
```

Lalu sebelum baris `return view('dashboard', compact('data'));` di akhir, tambahkan data global untuk manager/superadmin & set `dashboardView`:

```php
        $dashboardView = 'financial';
        $global  = null;
        $editors = collect();
        if ($user->hasAnyRole(['manager', 'superadmin'])) {
            $global  = app(ProductionDashboardService::class)->global();
            $editors = app(PerformanceService::class)->allEditors();
        }

        return view('dashboard', compact('data', 'dashboardView', 'global', 'editors'));
```

(Hapus `return view('dashboard', compact('data'));` yang lama — diganti baris di atas.)

- [ ] **Step 4: Jalankan — masih FAIL** (view belum punya markup) — lanjut Task 7 lalu kembali.

> Test render akan PASS setelah Task 7 menambah partial. Untuk sementara konfirmasi controller tidak error:
> Run: `php artisan test --filter=manager_dashboard_shows_global_progress_section` → boleh masih gagal pada `assertSee('Progres Naskah')` (markup di Task 7), tapi **tidak boleh** 500/error. Jika 500, perbaiki sebelum lanjut.

- [ ] **Step 5: Commit (gabung dengan Task 7)** — jangan commit terpisah; controller + view harus konsisten. Lanjut Task 7.

---

## Task 7: Dashboard views (production + global) dengan ApexCharts

**Files:**
- Modify: `resources/views/dashboard.blade.php`
- Create: `resources/views/dashboard/partials/production.blade.php`
- Create: `resources/views/dashboard/partials/progress-global.blade.php`

- [ ] **Step 1: Buat partial production**

```blade
{{-- resources/views/dashboard/partials/production.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Produksi</h4>
</div>

<div class="row">
    @php
        $cards = [
            ['Antrian Saya', $prod['antrian_saya'], 'list', 'primary'],
            ['Belum Diambil', $prod['belum_diambil'], 'inbox', 'secondary'],
            ['Lewat Target', $prod['lewat_target'], 'alert-triangle', 'danger'],
            ['Jatuh Tempo ≤7 hari', $prod['jatuh_tempo_7'], 'clock', 'warning'],
            ['Selesai Bulan Ini', $prod['selesai_bulan_ini'], 'check-circle', 'success'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $icon, $tone])
        <div class="col-md-4 col-xl grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <i data-feather="{{ $icon }}" class="icon-sm text-{{ $tone }}"></i>
                    </div>
                    <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa Saya</h6>
            <div id="prodPerfChart"></div>
            <div class="text-center text-muted" style="font-size:12px">
                Selesai {{ $perf['completed'] }} · Antrian {{ $perf['active_queue'] }}
            </div>
        </div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah Saya per Tahap</h6>
            <div id="prodStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Aktivitas Saya (30 hari)</h6>
            <div id="prodActivityChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var rate = @json($perf['on_time_rate']);
        new ApexCharts(document.querySelector("#prodPerfChart"), {
            chart: { type: 'radialBar', height: 220 },
            series: [rate === null ? 0 : rate],
            labels: ['On-time'],
            plotOptions: { radialBar: { dataLabels: { value: { formatter: function(){ return rate === null ? '—' : rate + '%'; } } } } },
            colors: ['#05a34a'],
        }).render();

        new ApexCharts(document.querySelector("#prodStageChart"), {
            chart: { type: 'donut', height: 240 },
            series: @json($prod['per_stage']['series']),
            labels: @json($prod['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();

        new ApexCharts(document.querySelector("#prodActivityChart"), {
            chart: { type: 'area', height: 240, toolbar: { show: false } },
            series: [{ name: 'Aktivitas', data: @json($prod['activity_30d']['series']) }],
            xaxis: { categories: @json($prod['activity_30d']['labels']), labels: { rotate: -45, style: { fontSize: '9px' } } },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#6571ff'],
        }).render();
    });
</script>
@endpush
```

- [ ] **Step 2: Buat partial progress-global**

```blade
{{-- resources/views/dashboard/partials/progress-global.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin mt-2">
    <h4 class="mb-0">Progres Naskah (Global)</h4>
</div>

<div class="row">
    @php
        $g = [
            ['Total dalam Produksi', $global['total_in_production'], 'primary'],
            ['Lewat Target', $global['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $global['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $global['selesai_bulan_ini'], 'success'],
        ];
    @endphp
    @foreach($g as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-5 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Distribusi per Tahap</h6>
            <div id="globalStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-7 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Penyelesaian (30 hari)</h6>
            <div id="globalTrendChart"></div>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa per Editor (30 hari)</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead><tr><th>Editor</th><th>On-time</th><th>Selesai</th><th>Antrian Aktif</th></tr></thead>
                    <tbody>
                        @foreach($editors as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ is_null($row['stats']['on_time_rate']) ? '—' : $row['stats']['on_time_rate'] . '%' }}</td>
                            <td>{{ $row['stats']['completed'] }}</td>
                            <td>{{ $row['stats']['active_queue'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</div>

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new ApexCharts(document.querySelector("#globalStageChart"), {
            chart: { type: 'donut', height: 260 },
            series: @json($global['per_stage']['series']),
            labels: @json($global['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();

        new ApexCharts(document.querySelector("#globalTrendChart"), {
            chart: { type: 'area', height: 260, toolbar: { show: false } },
            series: [{ name: 'Selesai', data: @json($global['completion_trend']['series']) }],
            xaxis: { categories: @json($global['completion_trend']['labels']), labels: { rotate: -45, style: { fontSize: '9px' } } },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#05a34a'],
        }).render();

        $(".datatable").DataTable({ pageLength: 10, searching: true, ordering: true });
    });
</script>
@endpush
```

- [ ] **Step 3: Buat `/dashboard` role-aware**

Di `resources/views/dashboard.blade.php`:

(a) Tepat **setelah** baris 7 `@section('content')`, sisipkan:

```blade
@if(($dashboardView ?? 'financial') === 'production')
    @include('dashboard.partials.production')
@else
```

(b) Tepat **sebelum** baris `@endsection` (yang menutup `@section('content')`), sisipkan:

```blade
    @hasanyrole('manager|superadmin')
        @include('dashboard.partials.progress-global')
    @endhasanyrole
@endif
```

(c) Gate blok skrip finansial agar **tidak** jalan untuk production. Tepat setelah baris `@push('custom-scripts')` (yang berisi init chart finansial), sisipkan `@if(($dashboardView ?? 'financial') !== 'production')`, dan tepat sebelum `@endpush` penutupnya sisipkan `@endif`.

- [ ] **Step 4: Jalankan test dashboard — pastikan PASS**

Run: `php artisan view:clear && php artisan test --filter=ProductionWorkspaceTest`
Expected: PASS (semua, termasuk dashboard production & manager).

- [ ] **Step 5: Commit (controller Task 6 + views Task 7 bersama)**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php resources/views/dashboard/partials/production.blade.php resources/views/dashboard/partials/progress-global.blade.php tests/Feature/ProductionWorkspaceTest.php
git commit -m "$(printf 'feat: role-aware dashboard (production KPIs + global progress + per-editor performance, ApexCharts)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 8: Verifikasi end-to-end

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS — 117 lama + PerformanceService (3) + ProductionDashboardService (2) + ProductionWorkspace (≈6) hijau.

- [ ] **Step 2: QA manual (browser)**
- [ ] production: menu "Meja Kerja Saya"; papan default `scope=mine` berisi tugas + belum-diambil; tombol **Ambil** memindahkan ke tugas saya; toggle "Semua" menampilkan semua; `/dashboard` = KPI produksi + 3 chart, tanpa blok finansial.
- [ ] manager/superadmin: `/dashboard` = finansial + seksi "Progres Naskah (Global)" + tabel performa per-editor (DataTable) + chart.
- [ ] marketing: `/dashboard` tetap finansial seperti semula.

- [ ] **Step 3: Cek log error kosong**

Run: `php artisan view:clear` lalu jalankan alur; pastikan `storage/logs/laravel.log` tak ada error baru.

---

## Self-Review Coverage (spec → task)

| Bagian Spec | Task |
|-------------|------|
| §1 Konsep/scope, stage produksi/final | Task 1, 4 |
| §2 Meja Kerja Saya (scope, Ambil, sort, menu) | Task 4, 5 |
| §3a Dashboard production | Task 6, 7 |
| §3b Dashboard global manager/super | Task 6, 7 |
| §3c Marketing tetap finansial | Task 6 (tidak diubah) + Task 7 (gate) |
| §4 PerformanceService on-time | Task 2 |
| §5 Komponen/file | semua |
| §8 QA/testing | Task 2,3,4,6 (otomatis) + Task 8 (manual) |
| §9 YAGNI | tidak diimplementasi |
</content>
