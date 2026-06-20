# Laporan Keuangan Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rombak menu "Pendapatan" jadi 3 laporan konsisten (Pemasukan kas-basis, Piutang, Order Selesai) dengan satu definisi pemasukan kanonik yang dibagi Dashboard & Laporan, plus detail per-baris (DataTables) dan export PDF + CSV.

**Architecture:** Scope kanonik di model `Payment` (`approved()` = status paid, `forOrdersOf($user)`) jadi satu sumber definisi. `FinancialReportService` memusatkan 3 dataset + scope role. `IncomeController` tipis (delegasi). `MarketingDashboardService` di-refactor agar pakai scope yang sama (perilaku tetap). Export PDF via DomPDF, CSV via `streamDownload`+`fputcsv` (tanpa paket baru).

**Tech Stack:** Laravel 10, Spatie Permission, Blade + Bootstrap, DataTables (`datatables.net-bs4`), DomPDF, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-17-financial-report-redesign-design.md`

> **Branch:** `Fitur` (jangan merge). **Commit:** author `WellkitDev` (git config sudah di-set); akhiri tiap pesan commit dengan `Co-Authored-By: Mira <admin@avidpedia.com>` (BUKAN "Claude"). `git add` path eksplisit saja; jangan commit file lokal-only (`template-web/`, `avidpedi_simapa.sql`, `database/seeders/*`, `.gitignore`, `public/error_log`, design HTML).
>
> **Testing:** `php artisan test` (otomatis `.env.testing`). Suite saat ini **158 passed** — harus tetap hijau.

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Modify | `app/Models/Payment.php` | scope `approved()` + `forOrdersOf()` |
| Create | `app/Services/FinancialReportService.php` | pemasukan/piutang/orderSelesai + resolveScope |
| Create | `tests/Unit/FinancialReportServiceTest.php` | basis kas incl DP, scoping, piutang konsisten |
| Modify | `app/Http/Controllers/Pages/IncomeController.php` | 3 view method + 6 export method + helper CSV |
| Modify | `routes/web.php` | group `income.` → pemasukan/piutang/lunas + export |
| Modify | `resources/views/layouts/sidebar.blade.php` | menu Laporan ▸ Pemasukan · Piutang · Order Selesai |
| Create | `resources/views/income/pemasukan.blade.php` | KPI + rekap + detail + export |
| Create | `resources/views/income/piutang.blade.php` | KPI + detail + export |
| Create | `resources/views/income/lunas.blade.php` | KPI + detail + export |
| Create | `resources/views/income/pdf/pemasukan.blade.php` | PDF |
| Create | `resources/views/income/pdf/piutang.blade.php` | PDF |
| Create | `resources/views/income/pdf/lunas.blade.php` | PDF |
| Delete | `resources/views/income/index-order.blade.php`, `index-payment.blade.php`, `index-report.blade.php`, `index-lunas.blade.php` | digantikan |
| Modify | `app/Services/MarketingDashboardService.php` | pakai `Payment::approved()->forOrdersOf()` |
| Create | `tests/Feature/FinancialReportTest.php` | render + scope + export + konsistensi Dashboard |

---

## Task 1: Payment scopes + FinancialReportService

**Files:**
- Modify: `app/Models/Payment.php`
- Create: `app/Services/FinancialReportService.php`
- Create: `tests/Unit/FinancialReportServiceTest.php`

- [ ] **Step 1: Tulis unit test (failing)** — `tests/Unit/FinancialReportServiceTest.php`

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class FinancialReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new FinancialReportService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function orderFor(User $u, string $status, int $cost): Order
    {
        $o = Order::factory()->create(['user_id' => $u->id, 'status' => $status]);
        OrderDetail::factory()->create(['order_id' => $o->id, 'type' => 'bk_mandiri', 'cost_amount' => $cost]);
        return $o;
    }

    private function paid(Order $o, int $amount, string $type = 'dp', $paidAt = null): Payment
    {
        return Payment::create([
            'order_id' => $o->id, 'payment_type' => $type, 'amount' => $amount,
            'paid_at' => $paidAt ?? now(), 'status' => 'paid',
        ]);
    }

    /** @test */
    public function pemasukan_is_cash_basis_including_dp_on_unsettled_orders(): void
    {
        $me = $this->marketing();
        $pending = $this->orderFor($me, 'pending', 5000000); // belum lunas
        $this->paid($pending, 2000000, 'dp');                // DP di order belum lunas → tetap dihitung
        $lunas = $this->orderFor($me, 'lunas', 3000000);
        $this->paid($lunas, 3000000, 'pelunasan');
        Payment::create(['order_id' => $pending->id, 'payment_type' => 'dp', 'amount' => 999, 'paid_at' => now(), 'status' => 'rejected']); // ditolak → tidak

        $d = $this->svc->pemasukan($me);

        $this->assertEquals(5000000, $d['kpi']['total']);     // 2jt DP + 3jt pelunasan
        $this->assertEquals(2, $d['kpi']['pembayaran']);
        $this->assertEquals(2, $d['kpi']['order']);
        $this->assertCount(2, $d['detail']);
    }

    /** @test */
    public function pemasukan_scoped_to_own_orders(): void
    {
        $me = $this->marketing();
        $other = $this->marketing();
        $this->paid($this->orderFor($me, 'pending', 1000000), 1000000, 'dp');
        $this->paid($this->orderFor($other, 'pending', 9000000), 9000000, 'dp');

        $this->assertEquals(1000000, $this->svc->pemasukan($me)['kpi']['total']);
    }

    /** @test */
    public function piutang_kpi_and_list_are_both_scoped_consistently(): void
    {
        $me = $this->marketing();
        $other = $this->marketing();
        $o = $this->orderFor($me, 'pending', 5000000);
        $this->paid($o, 2000000, 'dp');                       // sisa 3jt
        $this->orderFor($other, 'pending', 8000000);          // milik orang lain → tak masuk

        $d = $this->svc->piutang($me);

        $this->assertEquals(5000000, $d['kpi']['nilai']);
        $this->assertEquals(2000000, $d['kpi']['dibayar']);
        $this->assertEquals(3000000, $d['kpi']['sisa']);
        $this->assertCount(1, $d['detail']);                  // hanya order milik me
    }

    /** @test */
    public function order_selesai_only_lunas(): void
    {
        $me = $this->marketing();
        $this->orderFor($me, 'lunas', 3000000);
        $this->orderFor($me, 'pending', 1000000);

        $d = $this->svc->orderSelesai($me);
        $this->assertEquals(1, $d['kpi']['jumlah']);
        $this->assertEquals(3000000, $d['kpi']['nilai']);
    }

    /** @test */
    public function resolve_scope_returns_user_only_for_marketing_only(): void
    {
        $mkt = $this->marketing();
        $mgr = User::factory()->create(); $mgr->assignRole('manager');

        $this->assertNotNull($this->svc->resolveScope($mkt));
        $this->assertNull($this->svc->resolveScope($mgr));
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=FinancialReportServiceTest`
Expected: FAIL — scope `approved`/service belum ada.

- [ ] **Step 3: Tambah scope di `app/Models/Payment.php`** — sisipkan dua method sebelum penutup class (setelah relasi `approval()`):

```php
    /** Pembayaran yang dianggap "uang masuk" (di-set bersamaan approval saat approve). */
    public function scopeApproved($query)
    {
        return $query->where('status', 'paid');
    }

    /** Scope ke order milik $user (marketing). Bila null → tanpa filter (manager/superadmin). */
    public function scopeForOrdersOf($query, ?User $user)
    {
        return $user
            ? $query->whereHas('order', fn ($o) => $o->where('user_id', $user->id))
            : $query;
    }
```

> `User` satu namespace dengan `Payment` (`App\Models`), tak perlu import tambahan.

- [ ] **Step 4: Buat `app/Services/FinancialReportService.php`**

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

class FinancialReportService
{
    /** marketing-only → scope ke order sendiri; selain itu null (lihat semua). */
    public function resolveScope(User $user): ?User
    {
        return $user->hasRole('marketing') && ! $user->hasAnyRole(['manager', 'superadmin'])
            ? $user
            : null;
    }

    /** Pemasukan (kas masuk) — semua payment approved per paid_at, termasuk DP order belum lunas. */
    public function pemasukan(?User $scopeUser): array
    {
        $year = now()->year;
        $q = fn () => Payment::approved()->forOrdersOf($scopeUser);

        $kpi = [
            'total'      => (int) $q()->whereYear('paid_at', $year)->sum('amount'),
            'pembayaran' => $q()->whereYear('paid_at', $year)->count(),
            'order'      => $q()->whereYear('paid_at', $year)->distinct()->count('order_id'),
        ];

        $yearly = $q()
            ->selectRaw('YEAR(paid_at) as year, SUM(amount) as total, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('year')->orderByDesc('year')->get();

        $monthly = $q()
            ->selectRaw('YEAR(paid_at) as year, MONTH(paid_at) as month, SUM(amount) as total, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('year', 'month')->orderByDesc('year')->orderByDesc('month')->get();

        $detail = $q()
            ->with(['order.details', 'order.contact', 'invoice'])
            ->orderByDesc('paid_at')->get();

        return compact('kpi', 'yearly', 'monthly', 'detail');
    }

    /** Piutang — order belum lunas: nilai, sudah bayar, sisa. KPI & daftar sama-sama ter-scope. */
    public function piutang(?User $scopeUser): array
    {
        $orders = Order::where('status', '!=', 'lunas')
            ->when($scopeUser, fn ($q) => $q->where('user_id', $scopeUser->id))
            ->with(['details', 'contact'])
            ->withSum(['payments as total_paid' => fn ($q) => $q->approved()], 'amount')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($o) {
                $o->nilai       = (int) (optional($o->details)->cost_amount ?? 0);
                $o->paid_amount = (int) ($o->total_paid ?? 0);
                $o->sisa        = $o->nilai - $o->paid_amount;
                return $o;
            });

        $kpi = [
            'nilai'   => (int) $orders->sum('nilai'),
            'dibayar' => (int) $orders->sum('paid_amount'),
            'sisa'    => (int) $orders->sum('sisa'),
        ];

        return ['kpi' => $kpi, 'detail' => $orders];
    }

    /** Order selesai (lunas) — nilai penjualan tuntas. */
    public function orderSelesai(?User $scopeUser): array
    {
        $orders = Order::where('status', 'lunas')
            ->when($scopeUser, fn ($q) => $q->where('user_id', $scopeUser->id))
            ->with(['details', 'contact'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($o) {
                $o->nilai         = (int) (optional($o->details)->cost_amount ?? 0);
                $o->tanggal_lunas = $o->completed_at ?? $o->updated_at;
                return $o;
            });

        $kpi = ['jumlah' => $orders->count(), 'nilai' => (int) $orders->sum('nilai')];

        return ['kpi' => $kpi, 'detail' => $orders];
    }
}
```

- [ ] **Step 5: Jalankan — pastikan PASS**

Run: `php artisan test --filter=FinancialReportServiceTest`
Expected: PASS (5 test).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Payment.php app/Services/FinancialReportService.php tests/Unit/FinancialReportServiceTest.php
git commit -m "$(printf 'feat: Payment approved/forOrdersOf scopes + FinancialReportService (cash-basis)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 2: IncomeController + routes + views + sidebar

**Files:**
- Modify: `app/Http/Controllers/Pages/IncomeController.php`
- Modify: `routes/web.php`
- Create: `resources/views/income/pemasukan.blade.php`, `piutang.blade.php`, `lunas.blade.php`
- Delete: `resources/views/income/index-order.blade.php`, `index-payment.blade.php`, `index-report.blade.php`, `index-lunas.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Create: `tests/Feature/FinancialReportTest.php`

- [ ] **Step 1: Tulis feature test (failing)** — `tests/Feature/FinancialReportTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function orderWithPayment(User $u, string $code, int $cost, int $paid, string $status = 'pending'): Order
    {
        $o = Order::factory()->create(['user_id' => $u->id, 'status' => $status, 'code_order' => $code]);
        OrderDetail::factory()->create(['order_id' => $o->id, 'type' => 'bk_mandiri', 'cost_amount' => $cost]);
        if ($paid > 0) {
            Payment::create(['order_id' => $o->id, 'payment_type' => 'dp', 'amount' => $paid, 'paid_at' => now(), 'status' => 'paid']);
        }
        return $o;
    }

    /** @test */
    public function marketing_pemasukan_shows_only_own_and_includes_dp(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-MINE', 5000000, 2000000);
        $this->orderWithPayment($this->user('marketing'), 'ORD-OTHER', 9000000, 9000000);

        $this->actingAs($me);
        $this->get(route('income.pemasukan'))
            ->assertOk()
            ->assertSee('ORD-MINE')
            ->assertDontSee('ORD-OTHER')
            ->assertSee('2.000.000'); // DP order belum lunas tetap muncul
    }

    /** @test */
    public function piutang_shows_remaining_balance_scoped(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-DEBT', 5000000, 2000000); // sisa 3jt

        $this->actingAs($me);
        $this->get(route('income.piutang'))
            ->assertOk()
            ->assertSee('ORD-DEBT')
            ->assertSee('3.000.000');
    }

    /** @test */
    public function lunas_lists_completed_orders(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-DONE', 4000000, 4000000, 'lunas');
        $this->orderWithPayment($me, 'ORD-OPEN', 1000000, 0, 'pending');

        $this->actingAs($me);
        $this->get(route('income.lunas'))
            ->assertOk()
            ->assertSee('ORD-DONE')
            ->assertDontSee('ORD-OPEN');
    }

    /** @test */
    public function old_income_routes_are_replaced(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.order'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.payment'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('income.pending'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('income.pemasukan'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('income.piutang'));
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=FinancialReportTest`
Expected: FAIL — route income.pemasukan belum ada.

- [ ] **Step 3: Ganti isi `app/Http/Controllers/Pages/IncomeController.php`** dengan (3 view method + helper; export ditambah Task 3):

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Services\FinancialReportService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class IncomeController extends Controller
{
    public function __construct(private FinancialReportService $svc) {}

    private function scope()
    {
        return $this->svc->resolveScope(Auth::user());
    }

    public function pemasukan()
    {
        return view('income.pemasukan', $this->svc->pemasukan($this->scope()));
    }

    public function piutang()
    {
        return view('income.piutang', $this->svc->piutang($this->scope()));
    }

    public function lunas()
    {
        return view('income.lunas', $this->svc->orderSelesai($this->scope()));
    }
}
```

- [ ] **Step 4: Ganti route group `income` di `routes/web.php`** (baris 140-145) menjadi:

```php
    Route::prefix('income')->name('income.')->group(function () {
        Route::get('pemasukan',     [IncomeController::class, 'pemasukan'])->name('pemasukan');
        Route::get('pemasukan/pdf', [IncomeController::class, 'pemasukanPdf'])->name('pemasukan.pdf');
        Route::get('pemasukan/csv', [IncomeController::class, 'pemasukanCsv'])->name('pemasukan.csv');
        Route::get('piutang',       [IncomeController::class, 'piutang'])->name('piutang');
        Route::get('piutang/pdf',   [IncomeController::class, 'piutangPdf'])->name('piutang.pdf');
        Route::get('piutang/csv',   [IncomeController::class, 'piutangCsv'])->name('piutang.csv');
        Route::get('lunas',         [IncomeController::class, 'lunas'])->name('lunas');
        Route::get('lunas/pdf',     [IncomeController::class, 'lunasPdf'])->name('lunas.pdf');
        Route::get('lunas/csv',     [IncomeController::class, 'lunasCsv'])->name('lunas.csv');
    });
```

> Method export (`pemasukanPdf` dst.) baru dibuat di Task 3 — route menunjuk ke method yang belum ada, tapi route TIDAK dipanggil oleh test Task 2 (hanya `pemasukan`/`piutang`/`lunas`). Laravel hanya error saat route export benar-benar diakses. Aman untuk commit Task 2; Task 3 melengkapi method-nya. (Bila ingin, tunda 6 baris export sampai Task 3 — tapi menaruhnya sekarang menghindari sentuh routes dua kali.)

- [ ] **Step 5: Buat view `resources/views/income/pemasukan.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Laporan Pemasukan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Laporan Pemasukan (Kas Masuk)</h4>
    <div class="btn-group">
        <a href="{{ route('income.pemasukan.pdf') }}" target="_blank" class="btn btn-sm btn-outline-danger">Export PDF</a>
        <a href="{{ route('income.pemasukan.csv') }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    </div>
</div>

<div class="row">
    @php
        $cards = [
            ['Total Kas Masuk (tahun ini)', 'Rp ' . number_format($kpi['total'], 0, ',', '.'), 'success'],
            ['Jumlah Pembayaran', $kpi['pembayaran'], 'primary'],
            ['Jumlah Order', $kpi['order'], 'info'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h4 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h4>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Rekap per Tahun</h6>
            <table class="table table-sm">
                <thead><tr><th>Tahun</th><th class="text-end">Order</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @forelse($yearly as $row)
                        <tr><td>{{ $row->year }}</td><td class="text-end">{{ $row->order_count }}</td><td class="text-end">Rp {{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Rekap per Bulan</h6>
            <table class="table table-sm">
                <thead><tr><th>Bulan</th><th class="text-end">Order</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @forelse($monthly as $row)
                        <tr><td>{{ $row->month }}/{{ $row->year }}</td><td class="text-end">{{ $row->order_count }}</td><td class="text-end">Rp {{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Detail Pembayaran</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead>
                        <tr><th>Tanggal</th><th>Kode Order</th><th>Klien</th><th>Tipe</th><th>Nominal</th><th>No Invoice</th></tr>
                    </thead>
                    <tbody>
                        @foreach($detail as $p)
                        <tr>
                            <td data-order="{{ optional($p->paid_at)->timestamp ?? 0 }}">{{ optional($p->paid_at)->format('d M Y') ?? '-' }}</td>
                            <td>{{ optional($p->order)->code_order }}</td>
                            <td>{{ optional(optional($p->order)->details)->title ?? '-' }}<br><small class="text-muted">{{ optional(optional($p->order)->contact)->cp_email }}</small></td>
                            <td>{{ ucfirst($p->payment_type) }}</td>
                            <td>Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                            <td>{{ optional($p->invoice)->invoice_no ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
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
        $(".datatable").DataTable({ pageLength: 25, responsive: true, order: [[0, 'desc']], language: { emptyTable: "Belum ada pemasukan." } });
    });
</script>
@endpush
```

- [ ] **Step 6: Buat view `resources/views/income/piutang.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Laporan Piutang - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Laporan Piutang (Outstanding)</h4>
    <div class="btn-group">
        <a href="{{ route('income.piutang.pdf') }}" target="_blank" class="btn btn-sm btn-outline-danger">Export PDF</a>
        <a href="{{ route('income.piutang.csv') }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    </div>
</div>

<div class="row">
    @php
        $cards = [
            ['Total Nilai Order Belum Lunas', $kpi['nilai'], 'primary'],
            ['Total Sudah Dibayar', $kpi['dibayar'], 'success'],
            ['Total Sisa Piutang', $kpi['sisa'], 'danger'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format($val, 0, ',', '.') }}</h4>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Detail Order Belum Lunas</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead><tr><th>Kode Order</th><th>Klien</th><th>Nilai</th><th>Sudah Bayar</th><th>Sisa</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($detail as $o)
                        <tr>
                            <td>{{ $o->code_order }}</td>
                            <td>{{ optional($o->details)->title ?? '-' }}<br><small class="text-muted">{{ optional($o->contact)->cp_email }}</small></td>
                            <td>Rp {{ number_format($o->nilai, 0, ',', '.') }}</td>
                            <td class="text-success">Rp {{ number_format($o->paid_amount, 0, ',', '.') }}</td>
                            <td class="text-danger">Rp {{ number_format($o->sisa, 0, ',', '.') }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($o->status) }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
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
    $(function () { $(".datatable").DataTable({ pageLength: 25, responsive: true, language: { emptyTable: "Tidak ada piutang." } }); });
</script>
@endpush
```

- [ ] **Step 7: Buat view `resources/views/income/lunas.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Laporan Order Selesai - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Laporan Order Selesai (Lunas)</h4>
    <div class="btn-group">
        <a href="{{ route('income.lunas.pdf') }}" target="_blank" class="btn btn-sm btn-outline-danger">Export PDF</a>
        <a href="{{ route('income.lunas.csv') }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    </div>
</div>

<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body"><h6 class="card-title mb-0">Jumlah Order Lunas</h6><h4 class="mt-2 mb-0 text-success">{{ $kpi['jumlah'] }}</h4></div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body"><h6 class="card-title mb-0">Total Nilai</h6><h4 class="mt-2 mb-0 text-primary">Rp {{ number_format($kpi['nilai'], 0, ',', '.') }}</h4></div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Detail Order Selesai</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead><tr><th>Kode Order</th><th>Klien</th><th>Nilai</th><th>Tanggal Lunas</th></tr></thead>
                    <tbody>
                        @foreach($detail as $o)
                        <tr>
                            <td>{{ $o->code_order }}</td>
                            <td>{{ optional($o->details)->title ?? '-' }}<br><small class="text-muted">{{ optional($o->contact)->cp_email }}</small></td>
                            <td>Rp {{ number_format($o->nilai, 0, ',', '.') }}</td>
                            <td data-order="{{ optional($o->tanggal_lunas)->timestamp ?? 0 }}">{{ optional($o->tanggal_lunas)->format('d M Y') ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
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
    $(function () { $(".datatable").DataTable({ pageLength: 25, responsive: true, language: { emptyTable: "Belum ada order selesai." } }); });
</script>
@endpush
```

- [ ] **Step 8: Hapus view lama**

```bash
git rm resources/views/income/index-order.blade.php resources/views/income/index-payment.blade.php resources/views/income/index-report.blade.php resources/views/income/index-lunas.blade.php
```

- [ ] **Step 9: Update sidebar** — di `resources/views/layouts/sidebar.blade.php`, ganti 4 sub-link lama (Order/Payment/Pending/Lunas, baris 105-108) menjadi:

```blade
                        <li class="nav-item"><a href="{{ route('income.pemasukan') }}" class="nav-link">Pemasukan</a></li>
                        <li class="nav-item"><a href="{{ route('income.piutang') }}" class="nav-link">Piutang</a></li>
                        <li class="nav-item"><a href="{{ route('income.lunas') }}" class="nav-link">Order Selesai</a></li>
```

> Cari juga referensi lain ke route lama: `grep -rn "income.order\|income.payment\|income.pending" resources/ app/` — bila ada selain sidebar, perbarui ke route baru. (Diketahui hanya sidebar.)

- [ ] **Step 10: Jalankan — pastikan PASS + tak ada regresi**

Run: `php artisan view:clear && php artisan test --filter=FinancialReportTest`
Expected: PASS (4 test — render pemasukan/piutang/lunas + route-lama-hilang). Catatan: test export belum ada (Task 3); 4 test di Task 2 hanya menyentuh view, bukan route export.
Run: `php artisan test`
Expected: 158 lama + FinancialReportService(5, Task1) + FinancialReport(4) hijau; tak ada test lama yang patah (tak ada test yang refer route income lama — sudah diverifikasi).

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Pages/IncomeController.php routes/web.php resources/views/income/pemasukan.blade.php resources/views/income/piutang.blade.php resources/views/income/lunas.blade.php resources/views/income/index-order.blade.php resources/views/income/index-payment.blade.php resources/views/income/index-report.blade.php resources/views/income/index-lunas.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/FinancialReportTest.php
git commit -m "$(printf 'feat: 3 financial reports (pemasukan/piutang/order selesai) + sidebar; retire duplicate income menus\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 3: Export PDF + CSV

**Files:**
- Modify: `app/Http/Controllers/Pages/IncomeController.php`
- Create: `resources/views/income/pdf/pemasukan.blade.php`, `piutang.blade.php`, `lunas.blade.php`
- Modify: `tests/Feature/FinancialReportTest.php`

- [ ] **Step 1: Tambah test (failing)** ke `tests/Feature/FinancialReportTest.php`

```php
    /** @test */
    public function exports_return_correct_content_types(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-EXP', 5000000, 2000000);
        $this->actingAs($me);

        $pdf = $this->get(route('income.pemasukan.pdf'))->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));

        $csv = $this->get(route('income.pemasukan.csv'))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('ORD-EXP', $csv->streamedContent());

        $this->get(route('income.piutang.pdf'))->assertOk();
        $this->get(route('income.piutang.csv'))->assertOk();
        $this->get(route('income.lunas.pdf'))->assertOk();
        $this->get(route('income.lunas.csv'))->assertOk();
    }
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=exports_return_correct_content_types`
Expected: FAIL — method export belum ada.

- [ ] **Step 3: Tambah method export + helper ke `IncomeController`** — tambahkan import di atas:

```php
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;
```
lalu tambahkan method (di dalam class, setelah `lunas()`):

```php
    public function pemasukanPdf()
    {
        return Pdf::loadView('income.pdf.pemasukan', $this->svc->pemasukan($this->scope()))
            ->stream('Laporan_Pemasukan.pdf');
    }

    public function pemasukanCsv(): StreamedResponse
    {
        $d = $this->svc->pemasukan($this->scope());
        return $this->csv('pemasukan.csv',
            ['Tanggal', 'Kode Order', 'Klien', 'Tipe', 'Nominal', 'No Invoice'],
            $d['detail']->map(fn ($p) => [
                optional($p->paid_at)->format('Y-m-d') ?? '',
                optional($p->order)->code_order,
                optional(optional($p->order)->details)->title,
                $p->payment_type,
                $p->amount,
                optional($p->invoice)->invoice_no ?? '-',
            ])->all()
        );
    }

    public function piutangPdf()
    {
        return Pdf::loadView('income.pdf.piutang', $this->svc->piutang($this->scope()))
            ->stream('Laporan_Piutang.pdf');
    }

    public function piutangCsv(): StreamedResponse
    {
        $d = $this->svc->piutang($this->scope());
        return $this->csv('piutang.csv',
            ['Kode Order', 'Klien', 'Nilai', 'Sudah Bayar', 'Sisa', 'Status'],
            $d['detail']->map(fn ($o) => [
                $o->code_order,
                optional($o->details)->title,
                $o->nilai, $o->paid_amount, $o->sisa, $o->status,
            ])->all()
        );
    }

    public function lunasPdf()
    {
        return Pdf::loadView('income.pdf.lunas', $this->svc->orderSelesai($this->scope()))
            ->stream('Laporan_Order_Selesai.pdf');
    }

    public function lunasCsv(): StreamedResponse
    {
        $d = $this->svc->orderSelesai($this->scope());
        return $this->csv('order_selesai.csv',
            ['Kode Order', 'Klien', 'Nilai', 'Tanggal Lunas'],
            $d['detail']->map(fn ($o) => [
                $o->code_order,
                optional($o->details)->title,
                $o->nilai,
                optional($o->tanggal_lunas)->format('Y-m-d') ?? '',
            ])->all()
        );
    }

    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
```

- [ ] **Step 4: Buat `resources/views/income/pdf/pemasukan.blade.php`**

```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Pemasukan (Kas Masuk)</h1>
    <div class="muted">Total tahun ini: Rp {{ number_format($kpi['total'], 0, ',', '.') }} · {{ $kpi['pembayaran'] }} pembayaran · {{ $kpi['order'] }} order</div>
    <table>
        <thead><tr><th>Tanggal</th><th>Kode Order</th><th>Klien</th><th>Tipe</th><th class="right">Nominal</th><th>No Invoice</th></tr></thead>
        <tbody>
            @foreach($detail as $p)
            <tr>
                <td>{{ optional($p->paid_at)->format('d/m/Y') ?? '-' }}</td>
                <td>{{ optional($p->order)->code_order }}</td>
                <td>{{ optional(optional($p->order)->details)->title ?? '-' }}</td>
                <td>{{ ucfirst($p->payment_type) }}</td>
                <td class="right">{{ number_format($p->amount, 0, ',', '.') }}</td>
                <td>{{ optional($p->invoice)->invoice_no ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
```

- [ ] **Step 5: Buat `resources/views/income/pdf/piutang.blade.php`**

```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Piutang (Outstanding)</h1>
    <div class="muted">Nilai: Rp {{ number_format($kpi['nilai'], 0, ',', '.') }} · Dibayar: Rp {{ number_format($kpi['dibayar'], 0, ',', '.') }} · Sisa: Rp {{ number_format($kpi['sisa'], 0, ',', '.') }}</div>
    <table>
        <thead><tr><th>Kode Order</th><th>Klien</th><th class="right">Nilai</th><th class="right">Sudah Bayar</th><th class="right">Sisa</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($detail as $o)
            <tr>
                <td>{{ $o->code_order }}</td>
                <td>{{ optional($o->details)->title ?? '-' }}</td>
                <td class="right">{{ number_format($o->nilai, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($o->paid_amount, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($o->sisa, 0, ',', '.') }}</td>
                <td>{{ ucfirst($o->status) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
```

- [ ] **Step 6: Buat `resources/views/income/pdf/lunas.blade.php`**

```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Order Selesai (Lunas)</h1>
    <div class="muted">{{ $kpi['jumlah'] }} order · Total nilai: Rp {{ number_format($kpi['nilai'], 0, ',', '.') }}</div>
    <table>
        <thead><tr><th>Kode Order</th><th>Klien</th><th class="right">Nilai</th><th>Tanggal Lunas</th></tr></thead>
        <tbody>
            @foreach($detail as $o)
            <tr>
                <td>{{ $o->code_order }}</td>
                <td>{{ optional($o->details)->title ?? '-' }}</td>
                <td class="right">{{ number_format($o->nilai, 0, ',', '.') }}</td>
                <td>{{ optional($o->tanggal_lunas)->format('d/m/Y') ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
```

- [ ] **Step 7: Jalankan — pastikan PASS**

Run: `php artisan view:clear && php artisan test --filter=FinancialReportTest`
Expected: PASS (5 test — termasuk export).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/IncomeController.php resources/views/income/pdf/pemasukan.blade.php resources/views/income/pdf/piutang.blade.php resources/views/income/pdf/lunas.blade.php tests/Feature/FinancialReportTest.php
git commit -m "$(printf 'feat: export laporan keuangan PDF (DomPDF) + CSV (native)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 4: Samakan definisi Dashboard + verifikasi

**Files:**
- Modify: `app/Services/MarketingDashboardService.php`
- Modify: `tests/Feature/FinancialReportTest.php`

- [ ] **Step 1: Tambah test konsistensi (failing-ish)** ke `tests/Feature/FinancialReportTest.php`

```php
    /** @test */
    public function laporan_pemasukan_matches_dashboard_income(): void
    {
        $me = $this->user('marketing');
        $this->orderWithPayment($me, 'ORD-A', 5000000, 2000000);
        $this->orderWithPayment($me, 'ORD-B', 3000000, 3000000, 'lunas');

        $fr   = app(\App\Services\FinancialReportService::class)->pemasukan($me);
        $dash = app(\App\Services\MarketingDashboardService::class)->forUser($me);

        $this->assertEquals($dash['pemasukan_tahun_ini'], $fr['kpi']['total']);
        $this->assertEquals(5000000, $fr['kpi']['total']); // 2jt + 3jt, by paid_at tahun ini
    }
```

- [ ] **Step 2: Jalankan — biasanya sudah PASS** (kedua-duanya `status=paid` scoped). Bila PASS, lanjut ke refactor agar definisi benar-benar satu sumber. Bila FAIL, perbaiki sesuai definisi kanonik.

Run: `php artisan test --filter=laporan_pemasukan_matches_dashboard_income`

- [ ] **Step 3: Refactor `MarketingDashboardService` agar pakai scope kanonik** — di `app/Services/MarketingDashboardService.php`, ganti closure `$income`:

dari:
```php
        $income = fn () => Payment::query()
            ->where('status', 'paid')
            ->whereHas('order', fn ($q) => $q->where('user_id', $uid));
```
menjadi:
```php
        $income = fn () => Payment::approved()->forOrdersOf($user);
```

> Perilaku identik (`status=paid` + order milik user) — tapi kini lewat scope yang sama dengan Laporan, jadi definisi tak bisa melenceng. `$user` adalah parameter method `forUser(User $user)`.

- [ ] **Step 4: Jalankan — pastikan tak ada regresi**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS (semua test dashboard tetap hijau — perilaku tak berubah).
Run: `php artisan test --filter=FinancialReportTest`
Expected: PASS (6 test).

- [ ] **Step 5: Seluruh suite**

Run: `php artisan view:clear && php artisan test`
Expected: 158 lama + FinancialReportService(5) + FinancialReport(6) hijau, tanpa regresi.

- [ ] **Step 6: Commit**

```bash
git add app/Services/MarketingDashboardService.php tests/Feature/FinancialReportTest.php
git commit -m "$(printf 'refactor: dashboard income uses canonical Payment::approved scope (single definition)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

- [ ] **Step 7: QA manual (browser)** — jalankan `php artisan migrate` (tak ada migrasi baru di fitur ini, tapi pastikan DB dev sinkron), lalu:
- [ ] Login marketing → Laporan ▸ Pemasukan = kas masuk termasuk DP order belum lunas; angka Total = angka Dashboard "Pemasukan Tahun Ini".
- [ ] Piutang: KPI total = jumlah baris tabel (ter-scope); sisa = nilai − dibayar.
- [ ] Order Selesai: hanya order lunas.
- [ ] Export PDF & CSV tiap laporan terunduh & isinya benar.
- [ ] Manager/superadmin: semua laporan tampil lintas user (tak ter-scope).

---

## Self-Review Coverage (spec → task)

| Bagian Spec | Task |
|-------------|------|
| §1 definisi kanonik (scope approved/forOrdersOf, paid_at) | Task 1 |
| §2 scoping role (KPI & tabel konsisten) | Task 1 (service), Task 2 (render) |
| §3A Pemasukan (rekap + detail) | Task 1, Task 2 |
| §3B Piutang (fix bug scope) | Task 1, Task 2 |
| §3C Order Selesai | Task 1, Task 2 |
| §4 export PDF + CSV | Task 3 |
| §5 sidebar + buang duplikat | Task 2 |
| §1/§6 samakan Dashboard | Task 4 |
| §8 QA/testing | Task 1-3 (otomatis) + Task 4 (konsistensi + manual) |
| §9 YAGNI (target/komisi, filter tanggal, xlsx) | tidak diimplementasi |
