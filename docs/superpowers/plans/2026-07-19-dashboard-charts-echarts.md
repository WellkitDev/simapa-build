# Dashboard Donut per-Judul + Visualisasi ECharts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Perbaiki donut 4 dashboard agar menghitung judul unik per tahap (pisah Buku/Artikel, seperti Pelacak Naskah), dan tambahkan 3 visualisasi Apache ECharts di dashboard superadmin/manager (perbandingan marketing, tren traffic restyle, ketepatan produksi) dengan format Rupiah.

**Architecture:** Dua service data baru/diperluas (`ManuscriptStageStatsService`, `SalesDashboardService::perMarketingComparison`) memberi makan Blade. Donut tetap ApexCharts (perubahan data-only, dibagi lewat satu partial). ECharts di-vendor lokal + helper `SimapaECharts`; charting pakai komponen `dataset` (object-array, share dataset, dual-grid) agar tiap chart punya satu sumbu jujur.

**Tech Stack:** Laravel 10, PHP 8, PHPUnit, Spatie Permission, ApexCharts (ada), Apache ECharts 5.5.1 (baru, self-host), Bootstrap 4 (NobleUI).

---

## Spec

`docs/superpowers/specs/2026-07-19-dashboard-charts-echarts-design.md`

## File Structure

**Dibuat:**
- `app/Services/ManuscriptStageStatsService.php` — hitung judul unik per tahap (buku/artikel), reuse `TitleArchiveService::groupDetails()`.
- `resources/views/dashboard/partials/stage-donuts.blade.php` — dua donut (Buku/Artikel) reusable.
- `public/assets/plugins/echarts/echarts.min.js` — library ECharts (di-unduh).
- `public/assets/js/simapa-echarts.js` — helper `window.SimapaECharts` (palet, Rupiah, builder chart).
- `tests/Unit/ManuscriptStageStatsServiceTest.php`
- (tambah kasus di) `tests/Unit/SalesDashboardServiceTest.php`
- (tambah kasus di) `tests/Feature/DashboardRoleRoutingTest.php`

**Dimodifikasi:**
- `app/Services/SalesDashboardService.php` — tambah `perMarketingComparison()`.
- `app/Http/Controllers/DashboardController.php` — wire `stageStats` (company/admin/production) + `perMarketing` (company).
- `resources/views/dashboard/partials/progress-global.blade.php` — donut → partial; simpan trend.
- `resources/views/dashboard/partials/admin.blade.php` — donut → partial.
- `resources/views/dashboard/partials/production.blade.php` — donut → partial.
- `resources/views/dashboard/partials/company.blade.php` — 3 blok ECharts + restyle traffic.

## Catatan lingkungan test

- Test jalan atas DB `avidpedi_simapa_test` lewat `.env.testing` (otomatis via `php artisan test`). **Jangan** sentuh DB nyata.
- Role `accounting` sudah di-seed migrasi → selalu pakai `Role::firstOrCreate` di test (bukan `Role::create`).
- Belum ada `Payment` factory → buat Payment via `Payment::create([...])` (lihat pola di `SalesDashboardServiceTest`).
- Dua OrderDetail berbagi satu **judul** (group_key) bila `type` + `title` sama (hook `OrderDetail::saving` menghitung `group_key = pipelineClass|normalizedTitle`).

---

## Task 1: `ManuscriptStageStatsService` (data donut)

**Files:**
- Create: `app/Services/ManuscriptStageStatsService.php`
- Test: `tests/Unit/ManuscriptStageStatsServiceTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/ManuscriptStageStatsServiceTest.php`:

```php
<?php
// tests/Unit/ManuscriptStageStatsServiceTest.php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ManuscriptStageStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManuscriptStageStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManuscriptStageStatsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = app(ManuscriptStageStatsService::class);
    }

    /** Satu judul (group_key sama) dengan varian di dua tahap → dihitung SEKALI di bottleneck. */
    private function titleWithVariants(string $type, string $title, array $stages, ?int $assignedTo = null): void
    {
        $order = Order::factory()->create();
        foreach ($stages as $status) {
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => $type, 'title' => $title,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role' => 'production', 'assigned_user_id' => $assignedTo,
                'started_at' => now(),
            ]);
        }
    }

    /** @test */
    public function menghitung_judul_unik_bukan_baris_order_detail(): void
    {
        // Satu buku, 2 varian: editing (idx 1) & layout (idx 2) → bottleneck = editing.
        $this->titleWithVariants('bk_mandiri', 'Buku A', ['editing', 'layout']);

        $out = $this->svc->global();

        $this->assertSame(['Editing'], $out['buku']['labels']);
        $this->assertSame([1], $out['buku']['series']); // 1 judul, bukan 2
    }

    /** @test */
    public function memisahkan_buku_dan_artikel(): void
    {
        $this->titleWithVariants('bk_mandiri', 'Buku A', ['editing']);
        $this->titleWithVariants('bk_kolab',   'Buku B', ['layout']);
        $this->titleWithVariants('jr_sinta',   'Artikel A', ['templating']);

        $out = $this->svc->global();

        $this->assertEqualsCanonicalizing(['Editing', 'Layout'], $out['buku']['labels']);
        $this->assertSame(2, array_sum($out['buku']['series']));
        $this->assertSame(['Templating'], $out['artikel']['labels']);
        $this->assertSame([1], $out['artikel']['series']);
    }

    /** @test */
    public function mengecualikan_menunggu_proses_dan_final(): void
    {
        $this->titleWithVariants('bk_mandiri', 'Nunggu', ['menunggu_proses']);
        $this->titleWithVariants('bk_mandiri', 'Selesai', ['terbit']);
        $this->titleWithVariants('bk_mandiri', 'Aktif', ['proofreading']);

        $out = $this->svc->global();

        $this->assertSame(['Proofreading'], $out['buku']['labels']);
        $this->assertSame([1], $out['buku']['series']);
    }

    /** @test */
    public function labels_terurut_sesuai_urutan_tahap(): void
    {
        $this->titleWithVariants('bk_mandiri', 'B1', ['isbn']);        // idx 4
        $this->titleWithVariants('bk_mandiri', 'B2', ['editing']);     // idx 1
        $this->titleWithVariants('bk_mandiri', 'B3', ['layout']);      // idx 2

        $out = $this->svc->global();

        $this->assertSame(['Editing', 'Layout', 'Isbn'], $out['buku']['labels']);
    }

    /** @test */
    public function for_editor_hanya_judul_yang_punya_varian_miliknya(): void
    {
        $me = User::factory()->create(); $me->assignRole('production');
        $other = User::factory()->create(); $other->assignRole('production');

        $this->titleWithVariants('bk_mandiri', 'Milikku', ['editing'], $me->id);
        $this->titleWithVariants('bk_mandiri', 'Milik Orang', ['editing'], $other->id);

        $out = $this->svc->forEditor($me);

        $this->assertSame([1], $out['buku']['series']); // hanya "Milikku"
    }

    /** @test */
    public function kosong_menghasilkan_labels_dan_series_kosong(): void
    {
        $out = $this->svc->global();
        $this->assertSame([], $out['buku']['labels']);
        $this->assertSame([], $out['buku']['series']);
        $this->assertSame([], $out['artikel']['labels']);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=ManuscriptStageStatsServiceTest`
Expected: FAIL (`Class "App\Services\ManuscriptStageStatsService" not found`).

- [ ] **Step 3: Implementasi service**

Create `app/Services/ManuscriptStageStatsService.php`:

```php
<?php
// app/Services/ManuscriptStageStatsService.php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sumber tunggal "judul unik per tahap" untuk donut dashboard.
 * Reuse TitleArchiveService::groupDetails() agar penghitungan (dedupe group_key +
 * bottleneck + tipe buku/artikel) SELALU sama dengan Pelacak Naskah / Arsip Judul.
 */
class ManuscriptStageStatsService
{
    public function __construct(private TitleArchiveService $archive) {}

    /** Seluruh judul dalam produksi. */
    public function global(): array
    {
        return $this->tally($this->loadDetails(null));
    }

    /** Scope "mine": judul yang punya minimal satu varian assigned ke $user. */
    public function forEditor(User $user): array
    {
        return $this->tally($this->loadDetails($user->id));
    }

    private function loadDetails(?int $assignedUserId): Collection
    {
        $query = OrderDetail::query()
            ->with(['titleProgress', 'authors:id'])
            ->whereHas('titleProgress');

        if ($assignedUserId !== null) {
            $groupKeys = OrderDetail::query()
                ->whereHas('titleProgress', fn ($t) => $t->where('assigned_user_id', $assignedUserId))
                ->pluck('group_key')->unique()->all();
            $query->whereIn('group_key', $groupKeys); // [] → tak ada baris
        }

        return $query->get();
    }

    private function tally(Collection $details): array
    {
        $buku = [];
        $artikel = [];

        foreach ($this->archive->groupDetails($details) as $summary) {
            $stage = $summary->bottleneck_status;
            if ($stage === 'menunggu_proses' || in_array($stage, TitleProgress::FINAL_STAGES, true)) {
                continue;
            }
            if ($summary->type_label === 'Buku') {
                $buku[$stage] = ($buku[$stage] ?? 0) + 1;
            } else {
                $artikel[$stage] = ($artikel[$stage] ?? 0) + 1;
            }
        }

        return [
            'buku'    => $this->toChart($buku, TitleProgress::BOOK_STAGES),
            'artikel' => $this->toChart($artikel, TitleProgress::ARTICLE_STAGES),
        ];
    }

    /** {labels, series} terurut sesuai urutan tahap; hanya tahap yang punya judul. */
    private function toChart(array $counts, array $stageOrder): array
    {
        $labels = [];
        $series = [];
        foreach ($stageOrder as $stage) {
            if (! empty($counts[$stage])) {
                $labels[] = Str::title(str_replace('_', ' ', $stage));
                $series[] = $counts[$stage];
            }
        }
        return ['labels' => $labels, 'series' => $series];
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=ManuscriptStageStatsServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ManuscriptStageStatsService.php tests/Unit/ManuscriptStageStatsServiceTest.php
git commit -m "feat(dashboard): ManuscriptStageStatsService — judul unik per tahap (buku/artikel)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 2: `SalesDashboardService::perMarketingComparison()`

**Files:**
- Modify: `app/Services/SalesDashboardService.php`
- Test: `tests/Unit/SalesDashboardServiceTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan method test ini ke dalam class `SalesDashboardServiceTest` (sebelum `}` penutup terakhir):

```php
    /** @test */
    public function per_marketing_comparison_memisah_income_refund_order_per_marketing(): void
    {
        $a = $this->marketing(); $a->update(['name' => 'Andi']);
        $b = $this->marketing(); $b->update(['name' => 'Budi']);

        $oa = $this->orderFor($a);
        $this->paid($oa, 1_000_000, 'dp');
        $this->paid($oa, 500_000, 'pelunasan');
        Payment::create(['order_id' => $oa->id, 'payment_type' => 'refund',
            'amount' => 200_000, 'paid_at' => now(), 'status' => 'paid']); // refund → bukan income

        $this->orderFor($b); // Budi punya 1 order, tanpa pembayaran

        $rows = $this->svc->perMarketingComparison();

        $andi = $rows->firstWhere('name', 'Andi');
        $this->assertSame(1_500_000, $andi['pemasukan']); // dp + pelunasan, TANPA refund
        $this->assertSame(200_000, $andi['refund']);
        $this->assertSame(1, $andi['order']);

        $budi = $rows->firstWhere('name', 'Budi');
        $this->assertSame(0, $budi['pemasukan']);
        $this->assertSame(0, $budi['refund']);
        $this->assertSame(1, $budi['order']);
    }

    /** @test */
    public function per_marketing_comparison_diurutkan_pemasukan_desc(): void
    {
        $kecil = $this->marketing(); $kecil->update(['name' => 'Kecil']);
        $besar = $this->marketing(); $besar->update(['name' => 'Besar']);
        $this->paid($this->orderFor($kecil), 100_000);
        $this->paid($this->orderFor($besar), 900_000);

        $rows = $this->svc->perMarketingComparison();

        $this->assertSame('Besar', $rows->first()['name']);
    }
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=per_marketing_comparison`
Expected: FAIL (`Call to undefined method ...perMarketingComparison()`).

- [ ] **Step 3: Implementasi method**

Di `app/Services/SalesDashboardService.php`, tambahkan `use Illuminate\Support\Collection;` bila belum ada, lalu tambahkan method publik ini setelah `forCompany()`:

```php
    /**
     * Satu baris per marketing untuk chart perbandingan (YTD tahun berjalan):
     * [ ['name','pemasukan','refund','order'], ... ] urut pemasukan desc.
     * Refund TIDAK mengurangi pemasukan (kolom terpisah).
     */
    public function perMarketingComparison(): Collection
    {
        $year = Carbon::today()->year;

        return User::role('marketing')->orderBy('name')->get()
            ->map(fn (User $m) => [
                'name'      => $m->name,
                'pemasukan' => (int) Payment::income()->forOrdersOf($m)->whereYear('paid_at', $year)->sum('amount'),
                'refund'    => (int) Payment::refund()->forOrdersOf($m)->whereYear('paid_at', $year)->sum('amount'),
                'order'     => Order::where('user_id', $m->id)->whereYear('ordered_at', $year)->count(),
            ])
            ->sortByDesc('pemasukan')
            ->values();
    }
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalesDashboardServiceTest`
Expected: PASS (semua, termasuk 2 test baru).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SalesDashboardService.php tests/Unit/SalesDashboardServiceTest.php
git commit -m "feat(dashboard): perMarketingComparison — pemasukan/refund/order per marketing (YTD)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 3: Wire controller + feature test

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Tambahkan ke `DashboardRoleRoutingTest`:

```php
    /** @test */
    public function dashboard_menyediakan_data_stage_stats_dan_perbandingan_marketing(): void
    {
        $res = $this->actingAs($this->user('superadmin'))->get(route('dashboard'))->assertOk();

        $stage = $res->viewData('stageStats');
        $this->assertArrayHasKey('buku', $stage);
        $this->assertArrayHasKey('artikel', $stage);
        $this->assertArrayHasKey('labels', $stage['buku']);

        $this->assertNotNull($res->viewData('perMarketing'));
    }

    /** @test */
    public function admin_dan_production_menerima_stage_stats(): void
    {
        $this->actingAs($this->user('admin'))->get(route('dashboard'))
            ->assertOk()->assertViewHas('stageStats');

        $this->actingAs($this->user('production'))->get(route('dashboard'))
            ->assertOk()->assertViewHas('stageStats');
    }
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: FAIL pada 2 test baru (`stageStats`/`perMarketing` undefined).

- [ ] **Step 3: Wire controller**

Di `app/Http/Controllers/DashboardController.php`:

Tambahkan import di atas (setelah `use App\Services\ExpenseGapService;`):
```php
use App\Services\ManuscriptStageStatsService;
```

Ganti method `production()`:
```php
    private function production($user)
    {
        return view('dashboard', [
            'dashboardView' => 'production',
            'prod' => app(ProductionDashboardService::class)->forUser($user),
            'perf' => app(PerformanceService::class)->forEditor($user),
            'stageStats' => app(ManuscriptStageStatsService::class)->forEditor($user),
        ]);
    }
```

Ganti method `admin()`:
```php
    private function admin()
    {
        return view('dashboard', [
            'dashboardView' => 'admin',
            'adm'    => app(AdminDashboardService::class)->forAdmin(),
            'global' => app(ProductionDashboardService::class)->global(),
            'stageStats' => app(ManuscriptStageStatsService::class)->global(),
        ]);
    }
```

Dalam method `company()`, tambahkan dua key ke array `$data` (setelah `'global' => ...`):
```php
            'stageStats'    => app(ManuscriptStageStatsService::class)->global(),
            'perMarketing'  => app(SalesDashboardService::class)->perMarketingComparison(),
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS (semua).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardRoleRoutingTest.php
git commit -m "feat(dashboard): wire stageStats (company/admin/production) + perMarketing (company)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 4: Donut per-judul di view (partial reusable) — ApexCharts

Ganti donut tunggal jadi dua donut (Buku/Artikel) di 3 blade lewat satu partial. Tak ada test otomatis baru (feature `assertOk` dari Task 3 melindungi render); verifikasi visual di Task 8.

**Files:**
- Create: `resources/views/dashboard/partials/stage-donuts.blade.php`
- Modify: `resources/views/dashboard/partials/progress-global.blade.php`
- Modify: `resources/views/dashboard/partials/admin.blade.php`
- Modify: `resources/views/dashboard/partials/production.blade.php`

- [ ] **Step 1: Buat partial dua donut**

Create `resources/views/dashboard/partials/stage-donuts.blade.php`:

```blade
{{-- Dua donut "judul unik per tahap": Buku & Artikel.
     Butuh: $stats (['buku'=>['labels','series'],'artikel'=>[...]]) + $idPrefix (string unik).
     Bergantung pada SimapaCharts (dashboard-charts.js) + apexcharts yang dimuat parent. --}}
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Judul Buku per Tahap</h6>
            <div id="{{ $idPrefix }}BukuChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Judul Artikel per Tahap</h6>
            <div id="{{ $idPrefix }}ArtikelChart"></div>
        </div></div>
    </div>
</div>
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var C = window.SimapaCharts;
    var buku = { labels: @json($stats['buku']['labels']), series: @json($stats['buku']['series']) };
    var art  = { labels: @json($stats['artikel']['labels']), series: @json($stats['artikel']['series']) };
    C.render('#{{ $idPrefix }}BukuChart',    C.donut(buku, 'Judul Buku'),    buku.series);
    C.render('#{{ $idPrefix }}ArtikelChart', C.donut(art,  'Judul Artikel'), art.series);
});
</script>
@endpush
```

- [ ] **Step 2: `progress-global.blade.php` — ganti donut lama dengan partial**

Ganti blok baris chart (mulai `<div class="row">` yang memuat `#globalStageChart` s/d `#globalTrendChart`, sekitar baris 32-45) menjadi:

```blade
@include('dashboard.partials.stage-donuts', ['stats' => $stageStats, 'idPrefix' => 'co'])

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Penyelesaian (30 hari)</h6>
            <div id="globalTrendChart"></div>
        </div></div>
    </div>
</div>
```

Di blok `@push('custom-scripts')` bawah, **hapus** pembuatan `#globalStageChart` (ApexCharts donut) — sisakan hanya `#globalTrendChart` dan `$(".datatable").DataTable(...)`. Hasil script menjadi:

```blade
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new ApexCharts(document.querySelector("#globalTrendChart"), {
            chart: { type: 'area', height: 260, toolbar: { show: false } },
            series: [{ name: 'Selesai', data: @json($global['completion_trend']['series']) }],
            xaxis: {
                categories: @json($global['completion_trend']['labels']),
                tickAmount: 10, tickPlacement: 'on',
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } },
                axisTicks: { show: false },
            },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#05a34a'],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
            grid: { borderColor: '#f1f1f1', strokeDashArray: 4 },
        }).render();

        $(".datatable").DataTable({ pageLength: 10, searching: true, ordering: true });
    });
</script>
@endpush
```

- [ ] **Step 3: `admin.blade.php` — ganti donut**

Ganti blok (sekitar baris 75-82):
```blade
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah per Tahap</h6>
            <div id="admStageChart"></div>
        </div></div>
    </div>
</div>
```
menjadi:
```blade
@include('dashboard.partials.stage-donuts', ['stats' => $stageStats, 'idPrefix' => 'adm'])
```

Di `@push('custom-scripts')` bawah admin.blade, **hapus** blok `#admStageChart` (SimapaCharts donut). Blok script bawah menjadi kosong dari donut — hapus seluruh `@push('custom-scripts')...@endpush` bila tak ada isi lain (partial sudah push scriptnya sendiri). `@push('plugin-scripts')` (apexcharts + dashboard-charts.js) **tetap** dipertahankan karena partial membutuhkannya.

- [ ] **Step 4: `production.blade.php` — ganti donut**

Ubah baris chart pertama (baris ~40-70) menjadi 2 kolom (buang kolom donut tengah):
```blade
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa Saya</h6>
            <div id="prodPerfChart"></div>
            <div class="text-center text-muted" style="font-size:12px">
                Selesai {{ $perf['completed'] }} · Antrian {{ $perf['active_queue'] }} ·
                Tepat waktu {{ $perf['on_time_rate'] === null ? '—' : $perf['on_time_rate'] . '%' }}
            </div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="card-title mb-0">Aktivitas Saya</h6>
                <div class="btn-group btn-group-sm" id="prodRangeToggle">
                    <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
                    <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
                    <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
                </div>
            </div>
            <div id="prodActivityChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Naskah Saya per Tahap</h6>
@include('dashboard.partials.stage-donuts', ['stats' => $stageStats, 'idPrefix' => 'prod'])
```

Di `@push('custom-scripts')` production.blade, **hapus** blok `#prodStageChart` (ApexCharts donut) — sisakan `#prodPerfChart` (radialBar) dan `#prodActivityChart` (SimapaCharts area + rangeToggle).

- [ ] **Step 5: Verifikasi render server tak pecah**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS (blade baru ter-render untuk superadmin/admin/production).

- [ ] **Step 6: Commit**

```bash
git add resources/views/dashboard/partials/stage-donuts.blade.php resources/views/dashboard/partials/progress-global.blade.php resources/views/dashboard/partials/admin.blade.php resources/views/dashboard/partials/production.blade.php
git commit -m "feat(dashboard): dua donut judul unik (Buku/Artikel) via partial di 4 dashboard

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 5: Vendor ECharts + helper `SimapaECharts`

**Files:**
- Create: `public/assets/plugins/echarts/echarts.min.js` (unduh)
- Create: `public/assets/js/simapa-echarts.js`

- [ ] **Step 1: Unduh ECharts 5.5.1 ke folder plugin**

Run (PowerShell):
```powershell
New-Item -ItemType Directory -Force public/assets/plugins/echarts | Out-Null
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js" -OutFile "public/assets/plugins/echarts/echarts.min.js"
(Get-Item public/assets/plugins/echarts/echarts.min.js).Length
```
Expected: file tersimpan, ukuran ± 1.000.000+ byte (bukan 0).

- [ ] **Step 2: Buat helper `SimapaECharts`**

Create `public/assets/js/simapa-echarts.js`:

```javascript
// public/assets/js/simapa-echarts.js
// Helper Apache ECharts untuk dashboard superadmin/manager.
// Palet dipinjam dari SimapaCharts agar "hijau berarti sama" di seluruh app.
window.SimapaECharts = (function () {
    var P = (window.SimapaCharts && window.SimapaCharts.PALETTE) || {
        primary: '#6571ff', success: '#05a34a', warning: '#fbbc06',
        danger: '#ff3366', dark: '#0c1427', info: '#0dcaf0',
    };
    var INK = '#7987a1', GRID = '#f1f1f1', AXIS = '#e8ebf3';

    function rupiah(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }
    function count(v) { return Number(v || 0).toLocaleString('id-ID'); }

    function isEmptyRows(rows, keys) {
        if (!rows || !rows.length) return true;
        return rows.every(function (r) { return keys.every(function (k) { return !r[k]; }); });
    }
    function emptyState(el) {
        el.innerHTML = '<div class="text-center text-muted py-5" style="font-size:13px">Belum ada data</div>';
    }

    /** Init aman-kosong + auto-resize. Kembalikan chart atau null. */
    function init(selector, option, rows, keys) {
        var el = document.querySelector(selector);
        if (!el) return null;
        if (rows !== undefined && isEmptyRows(rows, keys || [])) { emptyState(el); return null; }
        var c = echarts.init(el);
        c.setOption(option);
        window.addEventListener('resize', function () { c.resize(); });
        return c;
    }

    /**
     * Satu dataset (object-array) → DUA grid bertumpuk yang BERBAGI dataset (Share Dataset).
     * Bukan dual-axis: tiap grid punya satu skala-Y jujur sendiri.
     * source: array of objects. xDim: nama field kategori.
     * top/bottom: { title, money:bool, max?:number, series:[{name,dim,color}] }
     */
    function sharedDualGrid(source, xDim, top, bottom) {
        var fmtByName = {};
        top.series.forEach(function (s) { fmtByName[s.name] = top.money ? rupiah : count; });
        bottom.series.forEach(function (s) { fmtByName[s.name] = bottom.money ? rupiah : count; });

        function mkSeries(cfg, axisIndex) {
            return cfg.series.map(function (s) {
                return {
                    type: 'bar', name: s.name,
                    xAxisIndex: axisIndex, yAxisIndex: axisIndex,
                    encode: { x: xDim, y: s.dim },
                    seriesLayoutBy: 'column', // tiap kolom dataset = satu seri
                    barMaxWidth: 26,
                    itemStyle: { color: s.color, borderRadius: [4, 4, 0, 0] },
                };
            });
        }

        return {
            animationDuration: 750, animationEasing: 'cubicOut',
            legend: { top: 0, textStyle: { color: INK },
                      data: top.series.concat(bottom.series).map(function (s) { return s.name; }) },
            tooltip: {
                trigger: 'item',
                formatter: function (p) {
                    var f = fmtByName[p.seriesName] || count;
                    var dim = p.dimensionNames[p.encode.y[0]];
                    return p.marker + p.name + ' — ' + p.seriesName + '<br/><b>' + f(p.data[dim]) + '</b>';
                },
            },
            dataset: { source: source },
            grid: [
                { left: 8, right: 16, top: 44, height: '36%', containLabel: true },
                { left: 8, right: 16, bottom: 8, height: '30%', containLabel: true },
            ],
            xAxis: [
                { type: 'category', gridIndex: 0, axisTick: { show: false },
                  axisLine: { lineStyle: { color: AXIS } }, axisLabel: { show: false } },
                { type: 'category', gridIndex: 1, axisTick: { show: false },
                  axisLine: { lineStyle: { color: AXIS } },
                  axisLabel: { color: INK, interval: 0, rotate: 30, fontSize: 10 } },
            ],
            yAxis: [
                { type: 'value', gridIndex: 0, name: top.title, nameTextStyle: { color: INK },
                  splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                  axisLabel: { color: INK, formatter: top.money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
                { type: 'value', gridIndex: 1, name: bottom.title, nameTextStyle: { color: INK }, max: bottom.max,
                  splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                  axisLabel: { color: INK, formatter: bottom.money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
            ],
            series: mkSeries(top, 0).concat(mkSeries(bottom, 1)),
        };
    }

    /**
     * Area tren tunggal (Simple Example of Dataset).
     * labels:[...], values:[...] → source object-array {d, v}.
     */
    function areaTrend(labels, values, color, money) {
        var source = labels.map(function (l, i) { return { d: l, v: values[i] }; });
        var tickInterval = labels.length > 31 ? Math.ceil(labels.length / 12) : (labels.length > 14 ? Math.ceil(labels.length / 10) : 0);
        return {
            animationDuration: 750, animationEasing: 'cubicOut',
            color: [color],
            dataset: { source: source },
            grid: { left: 8, right: 16, top: 16, bottom: 8, containLabel: true },
            tooltip: { trigger: 'axis', valueFormatter: money ? rupiah : count },
            xAxis: { type: 'category', boundaryGap: false, axisTick: { show: false },
                     axisLine: { lineStyle: { color: AXIS } },
                     axisLabel: { color: INK, interval: tickInterval, rotate: -45, fontSize: 10 } },
            yAxis: { type: 'value', splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                     axisLabel: { color: INK, formatter: money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
            series: [{
                type: 'line', name: money ? 'Pemasukan' : 'Order',
                encode: { x: 'd', y: 'v' }, smooth: true, showSymbol: false,
                lineStyle: { width: 2, color: color },
                areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: color + '66' }, { offset: 1, color: color + '05' },
                ]) },
            }],
        };
    }

    return {
        PALETTE: P, rupiah: rupiah, count: count,
        init: init, isEmptyRows: isEmptyRows, emptyState: emptyState,
        sharedDualGrid: sharedDualGrid, areaTrend: areaTrend,
    };
})();
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/plugins/echarts/echarts.min.js public/assets/js/simapa-echarts.js
git commit -m "chore(dashboard): vendor Apache ECharts 5.5.1 + helper SimapaECharts

Co-Authored-By: Mira <admin@avidpedia.com>"
```

> Catatan: `public/assets/**` **tidak** termasuk daftar do-not-commit (memory), jadi aman di-commit. `git add` path eksplisit.

---

## Task 6: Blok ECharts di `company.blade.php` (superadmin/manager)

Tambahkan (a) perbandingan per marketing, (b) ketepatan produksi, dan (c) restyle traffic ke ECharts. Semua di `resources/views/dashboard/partials/company.blade.php`.

**Files:**
- Modify: `resources/views/dashboard/partials/company.blade.php`

- [ ] **Step 1: Muat ECharts di `@push('plugin-scripts')`**

Di blok `@push('plugin-scripts')` company.blade (yang sudah memuat apexcharts + dashboard-charts.js + datatables), tambahkan dua baris (sebelum baris datatables):
```blade
    <script src="{{ asset('assets/plugins/echarts/echarts.min.js') }}"></script>
    <script src="{{ asset('assets/js/simapa-echarts.js') }}"></script>
```

- [ ] **Step 2: Tambah markup blok "Perbandingan per Marketing"**

Sisipkan tepat SETELAH blok `<h6 ...>Statistik Order &amp; Tagihan</h6>` beserta row kartunya (sebelum blok Traffic), markup ini:

```blade
<h6 class="text-muted mb-2 mt-2">Perbandingan per Marketing (tahun ini)</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Pemasukan &amp; Refund (atas) · Jumlah Order (bawah)</h6>
            <div id="coMarketingCompare" style="height:420px"></div>
        </div></div>
    </div>
</div>
```

- [ ] **Step 3: Tambah markup blok "Ketepatan Produksi"**

Sisipkan tepat SETELAH `@include('dashboard.partials.progress-global')` (blok "Produksi Global"):

```blade
<h6 class="text-muted mb-2 mt-2">Ketepatan Produksi (30 hari)</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">On-time % (atas) · Jumlah Selesai (bawah) per staf produksi</h6>
            <div id="coProdAccuracy" style="height:420px"></div>
        </div></div>
    </div>
</div>
```

- [ ] **Step 4: Restyle Traffic ke ECharts (ganti markup chart)**

Blok Traffic saat ini punya `#coIncomeChart` dan `#coOrderChart` (dipakai SimapaCharts/ApexCharts). Biarkan markup div-nya (id sama) — hanya scriptnya yang berubah di Step 5. Pastikan container punya tinggi; ubah kedua div menjadi:
```blade
            <h6 class="card-title">Tren Pemasukan</h6><div id="coIncomeChart" style="height:260px"></div>
```
dan
```blade
            <h6 class="card-title">Tren Jumlah Order</h6><div id="coOrderChart" style="height:260px"></div>
```

- [ ] **Step 5: Ganti isi `@push('custom-scripts')` company.blade**

Ganti blok script traffic lama (yang memakai `window.SimapaCharts` untuk `#coIncomeChart`/`#coOrderChart` + `C.rangeToggle`) dengan versi ECharts di bawah. **Pertahankan** blok `$('#teamTargetTable').DataTable(...)` yang ada. Hasil `@push('custom-scripts')` menjadi:

```blade
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var E = window.SimapaECharts;

    // ---- (1) Perbandingan per marketing — satu dataset, dua grid berbagi ----
    var marketing = @json($perMarketing);
    E.init('#coMarketingCompare',
        E.sharedDualGrid(marketing, 'name',
            { title: 'Rp', money: true, series: [
                { name: 'Pemasukan', dim: 'pemasukan', color: E.PALETTE.success },
                { name: 'Refund',    dim: 'refund',    color: E.PALETTE.danger },
            ] },
            { title: 'Order', money: false, series: [
                { name: 'Jumlah Order', dim: 'order', color: E.PALETTE.primary },
            ] }
        ), marketing, ['pemasukan', 'refund', 'order']);

    // ---- (2) Ketepatan produksi — satu dataset, dua grid berbagi ----
    var editors = @json($editors).map(function (e) {
        return { name: e.name, on_time: e.stats.on_time_rate == null ? 0 : e.stats.on_time_rate, selesai: e.stats.completed };
    }).sort(function (a, b) { return b.on_time - a.on_time; });
    E.init('#coProdAccuracy',
        E.sharedDualGrid(editors, 'name',
            { title: '%', money: false, max: 100, series: [
                { name: 'On-time %', dim: 'on_time', color: E.PALETTE.success },
            ] },
            { title: 'Selesai', money: false, series: [
                { name: 'Jumlah Selesai', dim: 'selesai', color: E.PALETTE.info },
            ] }
        ), editors, ['on_time', 'selesai']);

    // ---- (3) Traffic (restyle ECharts) + toggle 7/30/90 ----
    var full = {
        inc: { labels: @json($mkt['income_trend']['labels']), series: @json($mkt['income_trend']['series']) },
        ord: { labels: @json($mkt['order_trend']['labels']),  series: @json($mkt['order_trend']['series']) },
    };
    function sliceArr(a, n) { return a.slice(-n); }
    var incChart = E.init('#coIncomeChart', E.areaTrend(sliceArr(full.inc.labels, 30), sliceArr(full.inc.series, 30), E.PALETTE.success, true));
    var ordChart = E.init('#coOrderChart',  E.areaTrend(sliceArr(full.ord.labels, 30), sliceArr(full.ord.series, 30), E.PALETTE.primary, false));

    document.querySelectorAll('#coRangeToggle [data-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var n = +this.dataset.range;
            if (incChart) incChart.setOption(E.areaTrend(sliceArr(full.inc.labels, n), sliceArr(full.inc.series, n), E.PALETTE.success, true), true);
            if (ordChart) ordChart.setOption(E.areaTrend(sliceArr(full.ord.labels, n), sliceArr(full.ord.series, n), E.PALETTE.primary, false), true);
            document.querySelectorAll('#coRangeToggle [data-range]').forEach(function (b) {
                b.classList.remove('btn-primary', 'active'); b.classList.add('btn-outline-primary');
            });
            this.classList.remove('btn-outline-primary'); this.classList.add('btn-primary', 'active');
        });
    });
});
$(function () {
    if ($.fn.DataTable && document.getElementById('teamTargetTable')) {
        $('#teamTargetTable').DataTable({ pageLength: 10, order: [[4, 'desc']] });
        $('#teamTargetTable_wrapper .dataTables_length select, #teamTargetTable_wrapper .dataTables_filter input').addClass('form-control mb-2');
    }
});
</script>
@endpush
```

> Catatan: `dashboard-charts.js` (ApexCharts helper) masih dimuat karena partial donut (`stage-donuts`) memakainya. Traffic company kini ECharts; donut company tetap ApexCharts (sesuai spec).

- [ ] **Step 6: Verifikasi render server**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS (company ter-render untuk superadmin & manager; `perMarketing`/`editors`/`mkt` tersedia).

- [ ] **Step 7: Commit**

```bash
git add resources/views/dashboard/partials/company.blade.php
git commit -m "feat(dashboard): 3 visualisasi ECharts superadmin/manager (marketing, produksi, traffic)

Perbandingan pemasukan/refund/order per marketing & ketepatan produksi
pakai satu dataset dua grid (Share Dataset, satu sumbu jujur per grid).
Traffic di-restyle ke ECharts, toggle 7/30/90 dipertahankan. Rupiah di
semua nilai uang.

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 7: Suite lengkap + verifikasi manual browser

**Files:** — (tak ada perubahan kode; gerbang verifikasi)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (baseline 593 + test baru; tak ada regresi). Bila ada yang gagal, perbaiki sebelum lanjut.

- [ ] **Step 2: Verifikasi manual di browser (tak bisa headless)**

Buka XAMPP, login dan buka `/dashboard` untuk tiap role. Centang:
- [ ] **superadmin**: dua donut (Buku/Artikel) tampil; chart "Perbandingan per Marketing" tampil dua grid (pemasukan+refund Rp di atas, order di bawah), tooltip Rupiah benar; "Ketepatan Produksi" dua grid (on-time% 0–100 + selesai); Traffic (ECharts) tampil + toggle 7/30/90 mengubah rentang; sumbu/tooltip uang berformat `Rp 240.000`.
- [ ] **manager**: sama seperti superadmin (tanpa blok kas).
- [ ] **admin**: dua donut (Buku/Artikel) tampil; tak ada angka uang.
- [ ] **production**: dua donut "Naskah Saya per Tahap" (Buku/Artikel) hanya judul miliknya; Performa & Aktivitas tetap jalan.
- [ ] **marketing**: dashboard TIDAK berubah (regresi visual nol).
- [ ] Donut kosong menampilkan "Belum ada data", bukan chart rusak.
- [ ] Cocokkan jumlah donut vs menu **Pelacak Naskah** (tipe Buku & Artikel) untuk data yang sama — angka harus sama.

- [ ] **Step 3: Update memory follow-up**

Update memory `dashboard-per-role-followups` (atau buat catatan): donut kini judul-unik per tahap (Buku/Artikel) via `ManuscriptStageStatsService`; ECharts ditambahkan untuk superadmin/manager; item "verifikasi chart di browser" mencakup blok ECharts baru.

- [ ] **Step 4: Commit (bila ada perbaikan dari verifikasi)**

```bash
git add -- <file yang diperbaiki>
git commit -m "fix(dashboard): perbaikan dari verifikasi browser ECharts/donut

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Self-review (sudah dilakukan penulis plan)

- **Spec coverage:** Donut per-judul buku/artikel (Task 1,4) ✓ · ECharts vendored (Task 5) ✓ · perbandingan marketing share-dataset (Task 2,6) ✓ · traffic restyle (Task 6) ✓ · ketepatan produksi (Task 6) ✓ · Rupiah (helper `rupiah`, Task 5–6) ✓ · satu sumbu per chart / dual-grid bukan dual-axis (Task 5) ✓ · testing unit+feature+manual (Task 1–3,7) ✓.
- **Placeholder scan:** tak ada TBD; on-time null → 0 (diputuskan, Task 6 Step 5).
- **Type consistency:** `stageStats` bentuk `['buku'=>['labels','series'],'artikel'=>[...]]` konsisten Task 1↔3↔4. `perMarketing` baris `{name,pemasukan,refund,order}` konsisten Task 2↔6. `editors` memakai `stats.on_time_rate`/`stats.completed` sesuai `PerformanceService::allEditors()`.
- **Deviasi spec sadar:** traffic company yang tadinya ApexCharts kini ECharts (spec memang minta restyle); `dashboard-charts.js` tetap dimuat untuk donut ApexCharts.
```
