# Akuntansi Fase C: Rekap Bulanan + Dashboard Keuangan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman Dashboard Keuangan (filter tahun): KPI YTD + chart + tabel rekap bulanan, otomatis dari Jurnal Kas (laba berbasis kas).

**Architecture:** `CashRecapService` menghitung `monthlyRecap`/`ytd` dari `CashEntry` (+ saldo awal). `AccountingDashboardController` → view `accounting/dashboard` (ApexCharts). Read-only, tanpa migrasi.

**Tech Stack:** Laravel 11, Eloquent, Blade + ApexCharts. Test: PHPUnit `.env.testing`.

---

## File Structure

- `app/Services/CashRecapService.php` (**create**)
- `app/Http/Controllers/Pages/AccountingDashboardController.php` (**create**)
- `resources/views/accounting/dashboard.blade.php` (**create**)
- `routes/web.php` (**modify**) — `accounting.dashboard`
- `resources/views/layouts/sidebar.blade.php` (**modify**) — menu Dashboard Keuangan
- `tests/Unit/CashRecapServiceTest.php`, `tests/Feature/AccountingDashboardTest.php` (**create**)

---

## Konteks untuk implementer

- `CashEntry` (`tb_cash_entries`): `tanggal` (cast date/Carbon), `jenis` (pemasukan/pengeluaran), `amount` (cast decimal:2 → string), `produk` (artikel/buku/operasional/null), `cash_category_id` → `category()` (name). `CashSetting::singleton()->saldo_awal`.
- Rute `accounting.*` di grup `role:superadmin|accounting` (di `routes/web.php`, blok "Akuntansi — Jurnal Kas"). Role `accounting` sudah ada (migrasi Fase A). Menu sidebar sudah punya blok `@role(['superadmin','accounting'])` "Keuangan" → tambah item Dashboard Keuangan.
- ApexCharts: `assets/plugins/apexcharts/apexcharts.min.js`; init `new ApexCharts(document.querySelector('#id'), {...}).render();`. Layout master punya `@stack('plugin-scripts')` & `@stack('custom-scripts')` (dipakai journal.blade).
- Tanpa migrasi.

---

### Task 1: CashRecapService + unit test

**Files:** `app/Services/CashRecapService.php`; `tests/Unit/CashRecapServiceTest.php`

- [ ] **Step 1: Unit test (gagal dulu)** — `tests/Unit/CashRecapServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Models\CashCategory;
use App\Models\CashSetting;
use App\Services\CashRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashRecapServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $tanggal, string $jenis, $amount, ?string $produk = null, ?int $catId = null): void
    {
        CashEntry::create(['tanggal' => $tanggal, 'jenis' => $jenis, 'amount' => $amount, 'produk' => $produk, 'cash_category_id' => $catId, 'keterangan' => 'x', 'source' => 'manual']);
    }

    private function seedData(): void
    {
        CashSetting::singleton()->update(['saldo_awal' => 1000000]);
        $opCat = CashCategory::where('name', 'Operational')->first();
        $this->entry('2026-01-05', 'pemasukan', 500000, 'artikel');
        $this->entry('2026-01-10', 'pemasukan', 300000, 'buku');
        $this->entry('2026-01-15', 'pengeluaran', 200000, null, $opCat?->id);
        $this->entry('2026-02-05', 'pemasukan', 400000, 'artikel');
    }

    /** @test */
    public function monthly_recap_income_expense_laba_and_running_saldo(): void
    {
        $this->seedData();
        $r = (new CashRecapService())->monthlyRecap(2026);

        $jan = $r[0];
        $this->assertSame(500000.0, $jan['inArtikel']);
        $this->assertSame(300000.0, $jan['inBuku']);
        $this->assertSame(800000.0, $jan['totalIn']);
        $this->assertSame(200000.0, $jan['totalOut']);
        $this->assertSame(600000.0, $jan['laba']);
        $this->assertSame(1600000.0, $jan['saldoAkhir']); // 1jt awal + 600rb

        $feb = $r[1];
        $this->assertSame(400000.0, $feb['totalIn']);
        $this->assertSame(400000.0, $feb['laba']);
        $this->assertSame(2000000.0, $feb['saldoAkhir']);
    }

    /** @test */
    public function ytd_aggregates(): void
    {
        $this->seedData();
        $y = (new CashRecapService())->ytd(2026);

        $this->assertSame(1200000.0, $y['totalIn']);
        $this->assertSame(200000.0, $y['totalOut']);
        $this->assertSame(1000000.0, $y['laba']);
        $this->assertSame(2000000.0, $y['saldoAkhir']);
        $this->assertSame(900000.0, $y['incomeArtikel']);
        $this->assertSame(300000.0, $y['incomeBuku']);
        $this->assertSame('Jan', $y['bestMonthLabel']);
        $this->assertArrayHasKey('Operational', $y['expenseByCategory']);
        $this->assertSame(200000.0, $y['expenseByCategory']['Operational']);
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (service belum ada).
Run: `php artisan test --env=testing tests/Unit/CashRecapServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Buat `app/Services/CashRecapService.php`**

```php
<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashSetting;
use Carbon\Carbon;

class CashRecapService
{
    private array $labels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** @return array<int,array> 12 bulan: month,label,inArtikel,inBuku,totalIn,totalOut,laba,saldoAkhir */
    public function monthlyRecap(int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $opening = (float) CashSetting::singleton()->saldo_awal
            + (float) CashEntry::where('tanggal', '<', $yearStart)->where('jenis', 'pemasukan')->sum('amount')
            - (float) CashEntry::where('tanggal', '<', $yearStart)->where('jenis', 'pengeluaran')->sum('amount');

        $entries = CashEntry::whereYear('tanggal', $year)->get();
        $running = $opening;
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $mo = $entries->filter(fn ($e) => (int) $e->tanggal->month === $m);
            $in = $mo->where('jenis', 'pemasukan');
            $inArtikel = (float) $in->where('produk', 'artikel')->sum('amount');
            $inBuku    = (float) $in->where('produk', 'buku')->sum('amount');
            $totalIn   = (float) $in->sum('amount');
            $totalOut  = (float) $mo->where('jenis', 'pengeluaran')->sum('amount');
            $laba      = $totalIn - $totalOut;
            $running  += $laba;
            $out[] = [
                'month' => $m, 'label' => $this->labels[$m],
                'inArtikel' => $inArtikel, 'inBuku' => $inBuku,
                'totalIn' => $totalIn, 'totalOut' => $totalOut, 'laba' => $laba, 'saldoAkhir' => $running,
            ];
        }
        return $out;
    }

    /** @return array totalIn,totalOut,laba,saldoAkhir,avgLaba,incomeArtikel,incomeBuku,expenseByCategory,bestMonthLabel */
    public function ytd(int $year): array
    {
        $recap = $this->monthlyRecap($year);
        $totalIn  = (float) array_sum(array_column($recap, 'totalIn'));
        $totalOut = (float) array_sum(array_column($recap, 'totalOut'));
        $laba     = $totalIn - $totalOut;
        $saldoAkhir = (float) end($recap)['saldoAkhir'];
        $activeMonths = count(array_filter($recap, fn ($r) => $r['totalIn'] > 0 || $r['totalOut'] > 0));
        $avgLaba = $laba / max(1, $activeMonths);
        $incomeArtikel = (float) array_sum(array_column($recap, 'inArtikel'));
        $incomeBuku    = (float) array_sum(array_column($recap, 'inBuku'));

        $expenseByCategory = CashEntry::whereYear('tanggal', $year)->where('jenis', 'pengeluaran')
            ->with('category')->get()
            ->groupBy(fn ($e) => optional($e->category)->name ?? 'Tanpa Kategori')
            ->map(fn ($g) => (float) $g->sum('amount'))
            ->sortDesc()->toArray();

        $best = collect($recap)->filter(fn ($r) => $r['totalIn'] > 0 || $r['totalOut'] > 0)->sortByDesc('laba')->first();
        $bestMonthLabel = $best['label'] ?? null;

        return compact('totalIn', 'totalOut', 'laba', 'saldoAkhir', 'avgLaba', 'incomeArtikel', 'incomeBuku', 'expenseByCategory', 'bestMonthLabel');
    }
}
```

- [ ] **Step 4: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/CashRecapServiceTest.php`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CashRecapService.php tests/Unit/CashRecapServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): CashRecapService (rekap bulanan + YTD dari Jurnal Kas)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: Controller + rute + view + sidebar + feature test

**Files:** `AccountingDashboardController.php`; `routes/web.php`; `resources/views/accounting/dashboard.blade.php`; `resources/views/layouts/sidebar.blade.php`; `tests/Feature/AccountingDashboardTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/AccountingDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    public function accounting_can_open_dashboard(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertSee('Dashboard Keuangan')->assertSee('Total Pemasukan');
    }

    /** @test */
    public function marketing_cannot_access_dashboard(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.dashboard'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [accounting.dashboard] not defined`).
Run: `php artisan test --env=testing tests/Feature/AccountingDashboardTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Pages/AccountingDashboardController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\CashRecapService;
use Illuminate\Http\Request;

class AccountingDashboardController extends Controller
{
    public function __construct(private CashRecapService $service) {}

    public function index(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        return view('accounting.dashboard', [
            'year'  => $year,
            'recap' => $this->service->monthlyRecap($year),
            'ytd'   => $this->service->ytd($year),
        ]);
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`** — di grup `role:superadmin|accounting` (blok Akuntansi), setelah `accounting.journal`, tambah:

```php
        Route::get('accounting/dashboard', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'index'])->name('accounting.dashboard');
```

- [ ] **Step 5: View `resources/views/accounting/dashboard.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Dashboard Keuangan - SiMAPA')

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $pctArtikel = ($ytd['incomeArtikel'] + $ytd['incomeBuku']) > 0 ? round($ytd['incomeArtikel'] / ($ytd['incomeArtikel'] + $ytd['incomeBuku']) * 100) : 0;
    $chartLabels = array_column($recap, 'label');
    $chartIn   = array_map(fn ($r) => (int) $r['totalIn'], $recap);
    $chartOut  = array_map(fn ($r) => (int) $r['totalOut'], $recap);
    $chartLaba = array_map(fn ($r) => (int) $r['laba'], $recap);
    $expLabels = array_keys($ytd['expenseByCategory']);
    $expVals   = array_map('intval', array_values($ytd['expenseByCategory']));
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Dashboard Keuangan</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
        <button class="btn btn-sm btn-outline-secondary">Tahun</button>
    </form>
</div>

<div class="row">
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan</div><div class="h5 mb-0 text-success">{{ $rp($ytd['totalIn']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran</div><div class="h5 mb-0 text-danger">{{ $rp($ytd['totalOut']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Laba Bersih (Kas)</div><div class="h5 mb-0">{{ $rp($ytd['laba']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Terakhir</div><div class="h5 mb-0">{{ $rp($ytd['saldoAkhir']) }}</div></div></div></div>
</div>
<div class="row">
    <div class="col-md-4 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Rata² Laba/Bulan</div><div class="h6 mb-0">{{ $rp($ytd['avgLaba']) }}</div></div></div></div>
    <div class="col-md-4 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Artikel vs Buku</div><div class="h6 mb-0">{{ $pctArtikel }}% Artikel</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Bulan Terbaik (Laba)</div><div class="h6 mb-0">{{ $ytd['bestMonthLabel'] ?? '—' }}</div></div></div></div>
</div>

<div class="row">
    <div class="col-md-7 col-12 grid-margin stretch-card"><div class="card"><div class="card-body"><h6 class="card-title">Tren Bulanan</h6><div id="cashTrendChart"></div></div></div></div>
    <div class="col-md-5 col-12 grid-margin stretch-card"><div class="card"><div class="card-body"><h6 class="card-title">Breakdown Pengeluaran</h6><div id="cashExpenseChart"></div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Rekap Bulanan {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Bulan</th><th class="text-end">Pemasukan Artikel</th><th class="text-end">Pemasukan Buku</th><th class="text-end">Total Pemasukan</th><th class="text-end">Total Pengeluaran</th><th class="text-end">Laba</th><th class="text-end">Saldo Akhir</th></tr></thead>
            <tbody>
                @foreach($recap as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        <td class="text-end">{{ $rp($r['inArtikel']) }}</td>
                        <td class="text-end">{{ $rp($r['inBuku']) }}</td>
                        <td class="text-end">{{ $rp($r['totalIn']) }}</td>
                        <td class="text-end">{{ $rp($r['totalOut']) }}</td>
                        <td class="text-end {{ $r['laba'] < 0 ? 'text-danger' : '' }}">{{ $rp($r['laba']) }}</td>
                        <td class="text-end">{{ $rp($r['saldoAkhir']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>TOTAL YTD</td>
                <td class="text-end">{{ $rp($ytd['incomeArtikel']) }}</td>
                <td class="text-end">{{ $rp($ytd['incomeBuku']) }}</td>
                <td class="text-end">{{ $rp($ytd['totalIn']) }}</td>
                <td class="text-end">{{ $rp($ytd['totalOut']) }}</td>
                <td class="text-end">{{ $rp($ytd['laba']) }}</td>
                <td class="text-end">{{ $rp($ytd['saldoAkhir']) }}</td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    var labels = @json($chartLabels);
    var pemasukan = @json($chartIn);
    var pengeluaran = @json($chartOut);
    var laba = @json($chartLaba);
    var expLabels = @json($expLabels);
    var expVals = @json($expVals);

    if (window.ApexCharts) {
        new ApexCharts(document.querySelector('#cashTrendChart'), {
            chart: { height: 300, type: 'line', toolbar: { show: false } },
            series: [
                { name: 'Pemasukan', type: 'column', data: pemasukan },
                { name: 'Pengeluaran', type: 'column', data: pengeluaran },
                { name: 'Laba', type: 'line', data: laba },
            ],
            xaxis: { categories: labels },
            stroke: { width: [0, 0, 3] },
            colors: ['#22c55e', '#ef4444', '#3b82f6'],
        }).render();

        new ApexCharts(document.querySelector('#cashExpenseChart'), {
            chart: { height: 300, type: 'donut' },
            series: expVals.length ? expVals : [1],
            labels: expLabels.length ? expLabels : ['Tidak ada'],
            legend: { position: 'bottom' },
        }).render();
    }
})();
</script>
@endpush
```

- [ ] **Step 6: Menu sidebar `resources/views/layouts/sidebar.blade.php`** — di blok `@role(['superadmin','accounting'])` "Keuangan" (yang sudah memuat Jurnal Kas), tambahkan item Dashboard Keuangan SETELAH item Jurnal Kas (di dalam `@role` yang sama):

```blade
                <li class="nav-item {{ active_class(['accounting/dashboard']) }}">
                    <a href="{{ route('accounting.dashboard') }}" class="nav-link">
                        <i class="link-icon" data-feather="bar-chart-2"></i>
                        <span class="link-title">Dashboard Keuangan</span>
                    </a>
                </li>
```

- [ ] **Step 7: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/AccountingDashboardTest.php`
Expected: 2 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error (blade `@json(array_map(fn…))` terkompilasi).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/AccountingDashboardController.php routes/web.php resources/views/accounting/dashboard.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/AccountingDashboardTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): Dashboard Keuangan (KPI + chart ApexCharts + rekap bulanan) + menu

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Verifikasi menyeluruh

- [ ] **Step 1: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 442 + 4 baru = 446 passed).

- [ ] **Step 2: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

> Tanpa migrasi (fitur turunan/baca dari Jurnal Kas) — tak perlu `php artisan migrate` di dev.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 `CashRecapService.monthlyRecap/ytd` → Task 1 + unit test (angka recap+ytd, running saldo, byProduk, byCategory, bestMonth). ✓
- §3 controller+rute (filter year, role gate) → Task 2 + feature test (accounting 200, marketing 403). ✓
- §4 view (KPI + 3 chart + tabel rekap + menu) → Task 2 Step 5-6. ✓
- §5 test → Task 1/2. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step.

**3. Type/nama konsistensi:** `CashRecapService::monthlyRecap` (keys month/label/inArtikel/inBuku/totalIn/totalOut/laba/saldoAkhir) & `ytd` (totalIn/totalOut/laba/saldoAkhir/avgLaba/incomeArtikel/incomeBuku/expenseByCategory/bestMonthLabel) konsisten dipakai controller↔view↔test. Rute `accounting.dashboard` konsisten controller↔view↔test↔sidebar. `CashEntry`/`CashSetting`/`CashCategory` sesuai Fase A/B. ApexCharts init dari data view. Tanpa migrasi.
