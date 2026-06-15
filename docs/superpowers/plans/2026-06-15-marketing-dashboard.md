# Marketing Dashboard + Arsip Judul Target — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri marketing dashboard tersendiri (pemasukan harian/mingguan/tahunan termasuk DP/parsial + jumlah order + progres naskah miliknya) berbasis chart, dan tambah kolom Target/overdue di Arsip Judul.

**Architecture:** Tanpa tabel baru. `MarketingDashboardService` menampung query/agregasi ter-scope `order.user_id`; `DashboardController` menambah cabang marketing-only (early-return); `dashboard.blade` jadi 3 cabang (production | marketing | finansial-generik) dengan gating skrip yang benar; `TitleArchiveService::summarize()` ditambah `target_date`/`is_overdue`. Reuse ApexCharts + DataTables.

**Tech Stack:** Laravel 10, Spatie Permission, Blade + Bootstrap 5, ApexCharts, DataTables, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-15-marketing-dashboard-design.md`

> **Branch:** `Fitur` (aktif). **Commit:** author `WellkitDev` (git config sudah di-set); akhiri tiap pesan commit dengan `Co-Authored-By: Mira <admin@avidpedia.com>` (BUKAN "Claude"). `git add` path eksplisit saja; jangan commit file lokal-only (`template-web/`, `avidpedi_simapa.sql`, `database/seeders/*`, `.gitignore`, `public/error_log`, design HTML).
>
> **Testing:** `php artisan test` (otomatis `.env.testing`). Suite saat ini 131 passed — harus tetap hijau.

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `app/Services/MarketingDashboardService.php` | KPI pemasukan + order + progres (scoped) + data chart |
| Create | `tests/Unit/MarketingDashboardServiceTest.php` | KPI ter-scope & DP/parsial benar |
| Modify | `app/Http/Controllers/DashboardController.php` | cabang marketing-only |
| Modify | `resources/views/dashboard.blade.php` | 3 cabang + gating skrip finansial |
| Create | `resources/views/dashboard/partials/marketing.blade.php` | 4 seksi + ApexCharts |
| Modify | `app/Services/TitleArchiveService.php` | `summarize()` + `target_date`/`is_overdue` |
| Modify | `resources/views/orders/index-title.blade.php` | kolom Target + badge overdue |
| Create | `tests/Feature/MarketingDashboardTest.php` | render dashboard marketing + Arsip Judul Target |

---

## Task 1: MarketingDashboardService — TDD

**Files:**
- Create: `tests/Unit/MarketingDashboardServiceTest.php`
- Create: `app/Services/MarketingDashboardService.php`

- [ ] **Step 1: Tulis unit test (failing)**

```php
<?php
// tests/Unit/MarketingDashboardServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\MarketingDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new MarketingDashboardService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function orderFor(User $u, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge(['user_id' => $u->id], $attrs));
    }

    private function paid(Order $order, int $amount, string $type = 'dp', $paidAt = null): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type,
            'amount' => $amount, 'paid_at' => $paidAt ?? now(), 'status' => 'paid',
        ]);
    }

    private function naskah(Order $order, string $status, array $progressAttrs = []): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => 'production', 'started_at' => now(),
        ], $progressAttrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function income_sums_paid_payments_including_dp_and_partial_scoped_to_marketing(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->paid($o, 1000000, 'dp');         // DP
        $this->paid($o, 2000000, 'pelunasan');  // pelunasan
        Payment::create(['order_id' => $o->id, 'payment_type' => 'dp', 'amount' => 500000, 'paid_at' => now(), 'status' => 'rejected']); // ditolak → tidak dihitung
        $this->paid($this->orderFor($this->marketing()), 9999999, 'dp'); // marketing lain → tidak dihitung

        $d = $this->svc->forUser($mkt);

        $this->assertEquals(3000000, $d['pemasukan_tahun_ini']); // DP + pelunasan
        $this->assertEquals(3000000, $d['pemasukan_hari_ini']);
        $this->assertEquals(1, $d['jumlah_order_tahun_ini']);
    }

    /** @test */
    public function progress_kpis_are_scoped_and_categorised(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->naskah($o, 'menunggu_proses');                                              // belum diproses
        $this->naskah($o, 'editing');                                                      // aktif
        $this->naskah($o, 'layout', ['target_date' => now()->subDay()->toDateString()]);   // aktif + lewat target
        $this->naskah($o, 'terbit', ['started_at' => now()]);                              // selesai bulan ini + total
        $this->naskah($o, 'publish', ['started_at' => now()->subMonths(2)]);               // total selesai (bukan bulan ini)
        $this->naskah($this->orderFor($this->marketing()), 'editing');                     // marketing lain → tidak dihitung

        $d = $this->svc->forUser($mkt);

        $this->assertEquals(1, $d['belum_diproses']);
        $this->assertEquals(2, $d['naskah_aktif']);       // editing + layout
        $this->assertEquals(1, $d['lewat_target']);
        $this->assertEquals(1, $d['selesai_bulan_ini']);  // terbit bulan ini
        $this->assertEquals(2, $d['total_selesai']);      // terbit + publish
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: FAIL — `App\Services\MarketingDashboardService` belum ada.

- [ ] **Step 3: Buat service**

```php
<?php
// app/Services/MarketingDashboardService.php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketingDashboardService
{
    /** KPI pemasukan + order + progres naskah + data chart untuk satu marketing (ter-scope order.user_id). */
    public function forUser(User $user): array
    {
        $uid   = $user->id;
        $today = Carbon::today();

        $income = fn () => Payment::query()
            ->where('status', 'paid')
            ->whereHas('order', fn ($q) => $q->where('user_id', $uid));

        $prog = fn () => TitleProgress::query()
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $uid));

        return [
            // Pemasukan (tiap Payment paid dihitung — termasuk DP/parsial/pelunasan)
            'pemasukan_hari_ini'     => (int) $income()->whereDate('paid_at', $today)->sum('amount'),
            'pemasukan_minggu_ini'   => (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount'),
            'pemasukan_tahun_ini'    => (int) $income()->whereYear('paid_at', $today->year)->sum('amount'),
            'jumlah_order_tahun_ini' => Order::where('user_id', $uid)->whereYear('ordered_at', $today->year)->count(),
            'income_trend'           => $this->dailySum($income(), 'paid_at', 'amount'),
            'order_trend'            => $this->dailyCount(Order::where('user_id', $uid), 'ordered_at'),

            // Progres naskah (dari order milik marketing)
            'naskah_aktif'      => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->where('status', '!=', 'menunggu_proses')->count(),
            'belum_diproses'    => (clone $prog())->where('status', 'menunggu_proses')->count(),
            'lewat_target'      => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'     => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->whereNotNull('target_date')
                                    ->whereDate('target_date', '>=', $today)->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini' => (clone $prog())->whereIn('status', TitleProgress::FINAL_STAGES)->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'total_selesai'     => (clone $prog())->whereIn('status', TitleProgress::FINAL_STAGES)->count(),
            'per_stage'         => $this->stageChart(
                                    (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->get(['status'])->groupBy('status')->map->count()
                                   ),
            'completion_trend'  => $this->completionTrend($uid),
        ];
    }

    private function stageChart($perStage): array
    {
        return [
            'labels' => $perStage->keys()->map(fn ($s) => Str::title(str_replace('_', ' ', $s)))->values()->all(),
            'series' => $perStage->values()->all(),
        ];
    }

    /** Σ kolom per hari 30 hari → {labels, series}. */
    private function dailySum($query, string $dateCol, string $sumCol): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get([$dateCol, $sumCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map(fn ($g) => (int) $g->sum($sumCol));

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Count per hari 30 hari → {labels, series}. */
    private function dailyCount($query, string $dateCol): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get([$dateCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian naskah marketing per hari 30 hari (log to_value Terbit/Publish, scoped). */
    private function completionTrend(int $uid): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->whereHas('titleProgress.orderDetail.order', fn ($q) => $q->where('user_id', $uid))
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

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS (2 test).

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/MarketingDashboardServiceTest.php app/Services/MarketingDashboardService.php
git commit -m "$(printf 'feat: add MarketingDashboardService (scoped income incl DP/partial + naskah progress)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 2: DashboardController marketing branch + dashboard view (3 cabang) — TDD

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard.blade.php`
- Create: `resources/views/dashboard/partials/marketing.blade.php`
- Create: `tests/Feature/MarketingDashboardTest.php`

Commit controller + views + test bersama (harus konsisten).

- [ ] **Step 1: Tulis feature test (failing)**

```php
<?php
// tests/Feature/MarketingDashboardTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingDashboardTest extends TestCase
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

    /** @test */
    public function marketing_sees_marketing_dashboard_not_generic(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Pemasukan')   // dashboard marketing
            ->assertSee('Progres Naskah Saya')
            ->assertDontSee('total payment');     // blok generik approve/pending/reject tidak ada
    }

    /** @test */
    public function manager_dashboard_unchanged_generic_plus_global(): void
    {
        $this->actingAs($this->user('manager'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('total payment')          // generik tetap
            ->assertSee('Progres Naskah')         // seksi global
            ->assertDontSee('Ringkasan Pemasukan'); // bukan dashboard marketing
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=MarketingDashboardTest`
Expected: FAIL — cabang marketing belum ada.

- [ ] **Step 3: Tambah cabang marketing di `DashboardController`**

Tambahkan import (setelah `use App\Services\PerformanceService;`):
```php
use App\Services\MarketingDashboardService;
```

Di `index()`, tepat SETELAH blok `if ($isProductionOnly) { ... }` (penutup `}`-nya), tambahkan:
```php
        $isMarketingOnly = $user->hasRole('marketing') && ! $user->hasAnyRole(['manager', 'superadmin']);

        if ($isMarketingOnly) {
            return view('dashboard', [
                'dashboardView' => 'marketing',
                'mkt' => app(MarketingDashboardService::class)->forUser($user),
            ]);
        }
```

- [ ] **Step 4: Buat partial `resources/views/dashboard/partials/marketing.blade.php`**

```blade
{{-- resources/views/dashboard/partials/marketing.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Marketing</h4>
</div>

<h6 class="text-muted mb-2">Ringkasan Pemasukan</h6>
<div class="row">
    @php
        $income = [
            ['Pemasukan Hari Ini', $mkt['pemasukan_hari_ini'], 'success'],
            ['Pemasukan Minggu Ini', $mkt['pemasukan_minggu_ini'], 'primary'],
            ['Pemasukan Tahun Ini', $mkt['pemasukan_tahun_ini'], 'info'],
        ];
    @endphp
    @foreach($income as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format($val, 0, ',', '.') }}</h4>
            </div></div>
        </div>
    @endforeach
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
            <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan (30 hari)</h6>
            <div id="mktIncomeChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order (30 hari)</h6>
            <div id="mktOrderChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Progres Naskah Saya</h6>
<div class="row">
    @php
        $prog = [
            ['Naskah Aktif', $mkt['naskah_aktif'], 'primary'],
            ['Belum Diproses', $mkt['belum_diproses'], 'secondary'],
            ['Lewat Target', $mkt['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $mkt['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $mkt['selesai_bulan_ini'], 'success'],
            ['Total Selesai', $mkt['total_selesai'], 'info'],
        ];
    @endphp
    @foreach($prog as [$label, $val, $tone])
        <div class="col-md-4 col-xl-2 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-5 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah Saya per Tahap</h6>
            <div id="mktStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-7 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Terbit/Publish (30 hari)</h6>
            <div id="mktCompletionChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var areaOpts = function (id, name, data, cats, color) {
            return {
                chart: { type: 'area', height: 240, toolbar: { show: false } },
                series: [{ name: name, data: data }],
                xaxis: { categories: cats, labels: { rotate: -45, style: { fontSize: '9px' } } },
                dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: [color],
            };
        };
        new ApexCharts(document.querySelector("#mktIncomeChart"), areaOpts('inc', 'Pemasukan', @json($mkt['income_trend']['series']), @json($mkt['income_trend']['labels']), '#05a34a')).render();
        new ApexCharts(document.querySelector("#mktOrderChart"), areaOpts('ord', 'Order', @json($mkt['order_trend']['series']), @json($mkt['order_trend']['labels']), '#6571ff')).render();
        new ApexCharts(document.querySelector("#mktCompletionChart"), areaOpts('cmp', 'Terbit/Publish', @json($mkt['completion_trend']['series']), @json($mkt['completion_trend']['labels']), '#fbbc06')).render();

        new ApexCharts(document.querySelector("#mktStageChart"), {
            chart: { type: 'donut', height: 260 },
            series: @json($mkt['per_stage']['series']),
            labels: @json($mkt['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();
    });
</script>
@endpush
```

- [ ] **Step 5: Jadikan `resources/views/dashboard.blade.php` 3 cabang**

(a) Cari blok pembuka cabang (setelah `@section('content')`):
```blade
@if(($dashboardView ?? 'financial') === 'production')
    @include('dashboard.partials.production')
@else
```
Ganti menjadi:
```blade
@if(($dashboardView ?? 'financial') === 'production')
    @include('dashboard.partials.production')
@elseif(($dashboardView ?? 'financial') === 'marketing')
    @include('dashboard.partials.marketing')
@else
```

(b) Perbaiki gating skrip finansial. Cari baris (di dalam `@push('custom-scripts')`):
```blade
@if(($dashboardView ?? 'financial') !== 'production')
```
Ganti menjadi:
```blade
@if(($dashboardView ?? 'financial') === 'financial')
```
(Sekarang skrip finansial hanya jalan untuk cabang finansial generik — production & marketing memakai skrip partial-nya sendiri.)

- [ ] **Step 6: Jalankan — pastikan PASS + tidak ada regresi**

Run: `php artisan view:clear && php artisan test --filter=MarketingDashboardTest`
Expected: PASS (2 test).
Run: `php artisan test --filter=ProductionWorkspaceTest`
Expected: PASS (production & manager dashboard tetap hijau — gating berubah dari `!== production` ke `=== financial`, perilaku untuk production & manager tidak berubah).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php resources/views/dashboard/partials/marketing.blade.php tests/Feature/MarketingDashboardTest.php
git commit -m "$(printf 'feat: marketing dashboard branch (income + naskah progress, ApexCharts)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 3: Arsip Judul — kolom Target + overdue

**Files:**
- Modify: `app/Services/TitleArchiveService.php`
- Modify: `resources/views/orders/index-title.blade.php`
- Modify: `tests/Feature/MarketingDashboardTest.php`

- [ ] **Step 1: Tambah test (failing)**

Tambahkan ke `tests/Feature/MarketingDashboardTest.php`:
```php
    /** @test */
    public function arsip_judul_shows_target_column(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'NASKAH BERTARGET']);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production',
            'started_at' => now(), 'target_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($me);
        $this->get(route('order.book.indexJudul'))
            ->assertOk()
            ->assertSee('NASKAH BERTARGET')
            ->assertSee('Target')      // header kolom
            ->assertSee('lewat');      // badge overdue (target kemarin, belum final)
    }
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=arsip_judul_shows_target_column`
Expected: FAIL — kolom Target belum ada.

- [ ] **Step 3: Tambah `target_date` + `is_overdue` di `TitleArchiveService::summarize()`**

Buka `app/Services/TitleArchiveService.php`. Di method `summarize()`, di dalam `return (object) [ ... ]`, tambahkan dua key (setelah `'is_mixed' => ...,`):
```php
            'target_date' => optional($repr->titleProgress)->target_date,
            'is_overdue'  => optional($repr->titleProgress)->target_date
                                && $repr->titleProgress->target_date->lt(today())
                                && ! TitleProgress::isFinal($bottleneck),
```

> `$repr` adalah varian representatif (titleProgress sudah di-eager-load oleh `indexJudul`). `$bottleneck` adalah status grup. `TitleProgress::isFinal()` & `today()` sudah tersedia. Pastikan `use App\Models\TitleProgress;` ada di atas file (sudah dipakai untuk `getHandlerForStatus`).

- [ ] **Step 4: Tambah kolom Target di `resources/views/orders/index-title.blade.php`**

(a) Di `<thead>`, tepat sebelum `<th>Aksi</th>`, sisipkan:
```blade
                                <th>Target</th>
```

(b) Di `<tbody>`, tepat sebelum sel Aksi (`<td>` yang berisi tombol Detail), sisipkan:
```blade
                                <td>
                                    @if($row->target_date)
                                        <span class="badge bg-{{ $row->is_overdue ? 'danger' : 'light text-dark border' }}">
                                            {{ \Carbon\Carbon::parse($row->target_date)->format('d M Y') }}{{ $row->is_overdue ? ' · lewat' : '' }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
```

> Catatan: kolom No (index 0) sudah non-orderable di init DataTable; menambah kolom Target tidak mengubah init (target hanya kolom tampilan). Tidak perlu ubah `@push('custom-scripts')`.

- [ ] **Step 5: Jalankan — pastikan PASS + no regресi**

Run: `php artisan view:clear && php artisan test --filter=MarketingDashboardTest`
Expected: PASS (3 test).
Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (test Arsip/archive existing tetap hijau).

- [ ] **Step 6: Commit**

```bash
git add app/Services/TitleArchiveService.php resources/views/orders/index-title.blade.php tests/Feature/MarketingDashboardTest.php
git commit -m "$(printf 'feat: Target + overdue column on Arsip Judul\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 4: Verifikasi end-to-end

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS — 131 lama + MarketingDashboardService (2) + MarketingDashboard (3) hijau.

- [ ] **Step 2: QA manual (browser)**
- [ ] login marketing → `/dashboard` = Ringkasan Pemasukan (hari/minggu/tahun) + Jumlah Order + chart, lalu Progres Naskah Saya (aktif/belum diproses/lewat target/jatuh tempo/selesai bulan ini/total selesai) + donut + tren; TIDAK ada blok approve/pending/reject.
- [ ] marketing → Arsip Judul: kolom Target tampil, badge merah "lewat" untuk yang overdue.
- [ ] manager/superadmin → `/dashboard` tetap finansial + Progres Naskah (Global); production → dashboard produksi (tak berubah).

- [ ] **Step 3: Cek log error kosong**

Run: `php artisan view:clear` lalu jalankan alur; pastikan `storage/logs/laravel.log` tak ada error baru.

---

## Self-Review Coverage (spec → task)

| Bagian Spec | Task |
|-------------|------|
| §2A/B Ringkasan Pemasukan + chart | Task 1 (service), Task 2 (view) |
| §2C/D Progres Naskah + chart | Task 1, Task 2 |
| §3 MarketingDashboardService | Task 1 |
| §2 cabang marketing + gating 3 cabang | Task 2 |
| §4 Arsip Judul Target/overdue | Task 3 |
| §8 QA/testing | Task 1,2,3 (otomatis) + Task 4 (manual) |
| §9 YAGNI (target/komisi, notif, dst.) | tidak diimplementasi |
</content>
