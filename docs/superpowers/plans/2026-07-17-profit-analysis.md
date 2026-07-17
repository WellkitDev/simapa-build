# Analisa Profit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tiap bulan langsung terlihat berapa uang yang siap dibagi — tiap pembayaran dipecah pakai margin Asumsi jadi cadangan APC dan pendapatan margin, plus akumulasi 12 bulan.

**Architecture:** `ProfitAnalysisService` membaca `Payment` (paid, refund negatif) → `OrderDetail.type` + `indexation` → kode margin → `CashMargin.margin_pct`. Halaman baca-saja; tak menyentuh Jurnal Kas. Menyambung ke Distribusi Profit lewat `?profit=` yang sudah diterimanya — halaman itu **tidak diubah**.

**Tech Stack:** Laravel 11, PHPUnit, DataTables (`assets/libs/datatables.net-bs4`, pola `titles/index`). Tanpa migrasi, tanpa dependency.

**Spec:** `docs/superpowers/specs/2026-07-17-profit-analysis-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit (`data-excel/` berisi CSV keuangan; gitignored, jangan sampai ter-stage).
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell di dalam tool Bash.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`.
- DataTables: **salin pola dari `resources/views/titles/index.blade.php`** — jangan menebak path plugin.

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Services/ProfitAnalysisService.php` (**baru**) | Semua aturan: normalisasi tier, pilih margin, pecah tiap payment, akumulasi 12 bulan. |
| `app/Http/Controllers/Pages/ProfitAnalysisController.php` (**baru**) | Filter bulan/tahun → view. |
| `resources/views/accounting/profit-analysis.blade.php` (**baru**) | Ringkasan + peringatan + akumulasi + rincian. |
| `tests/Feature/ProfitAnalysisTest.php` (**baru**) | Mengunci 2 contoh user + aturan margin + refund + akumulasi. |

---

## Task 1: `ProfitAnalysisService` (TDD)

**Files:**
- Create: `tests/Feature/ProfitAnalysisTest.php`
- Create: `app/Services/ProfitAnalysisService.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ProfitAnalysisTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CashMargin;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\ProfitAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tiap uang masuk dipecah pakai margin Asumsi: cadangan APC + siap dibagi.
 * Dua contoh user dikunci di sini — lihat spec §"Pertanyaan user, dijawab".
 */
class ProfitAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** Order + detail; $indexation null meniru 31 order lapangan yang kosong. */
    private function order(string $type, ?string $indexation, int $cost = 10_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => '2026-06-01',
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'indexation' => $indexation, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp', string $tgl = '2026-06-10'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => 'paid', 'paid_at' => $tgl,
        ]);
    }

    private function juni(): array
    {
        return app(ProfitAnalysisService::class)->forMonth(2026, 6);
    }

    /** @test */
    public function kolaborasi_dp_sinta2_matches_user_example(): void
    {
        // Contoh user: "orderan artikel kolaborasi dengan DP 1,5jt" → M_KOL_S2 25%.
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(375_000.0, $h['totalMargin'], 'Siap dibagi = 25% x 1,5jt.');
        $this->assertSame(1_125_000.0, $h['totalReserve'], 'Cadangan APC = sisanya.');
        $this->assertSame(1_500_000.0, $h['totalIn']);
    }

    /** @test */
    public function kolaborasi_dp_sinta4_uses_30_percent(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 4'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(450_000.0, $h['totalMargin']);
        $this->assertSame(1_050_000.0, $h['totalReserve']);
    }

    /** @test */
    public function mandiri_lunas_uses_assumption_not_actual(): void
    {
        // Contoh user memberi 2jt (APC aktual 5,5jt), tapi keputusannya: ASUMSI menang.
        $this->pay($this->order('at_mandiri', 'sinta 2', 7_500_000), 7_500_000, 'pelunasan');

        $h = $this->juni();

        $this->assertSame(1_875_000.0, $h['totalMargin'], 'Asumsi 25% x 7,5jt — bukan 2jt dari biaya aktual.');
    }

    /** @test */
    public function book_uses_87_percent(): void
    {
        $this->pay($this->order('bk_kolab', null, 100_000), 100_000);

        $this->assertSame(87_000.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function uppercase_indexation_is_normalised(): void
    {
        // Data lapangan punya "sinta 2" DAN "SINTA 2".
        $this->pay($this->order('at_kolab', 'SINTA 2'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(375_000.0, $h['totalMargin']);
        $this->assertSame(0, $h['unknownTier'], 'SINTA 2 harus dikenali, bukan dianggap tak diketahui.');
    }

    /** @test */
    public function sinta3_uses_s2_and_sinta5_uses_s4(): void
    {
        $this->pay($this->order('at_mandiri', 'sinta 3'), 1_000_000);
        $this->pay($this->order('at_mandiri', 'sinta 5'), 1_000_000);

        // 25% x 1jt + 30% x 1jt
        $this->assertSame(550_000.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function unknown_indexation_uses_lowest_margin_and_is_flagged(): void
    {
        $this->pay($this->order('at_kolab', null), 1_500_000);
        $this->pay($this->order('at_kolab', 'coppernicus'), 1_500_000);

        $h = $this->juni();

        $this->assertSame(750_000.0, $h['totalMargin'], 'Keduanya pakai 25% (margin terendah).');
        $this->assertSame(2, $h['unknownTier'], 'Keduanya ditandai agar bisa dibenahi.');
    }

    /** @test */
    public function refund_reduces_margin(): void
    {
        $o = $this->order('at_kolab', 'sinta 2');
        $this->pay($o, 1_500_000);
        $this->pay($o, 300_000, 'refund');

        $h = $this->juni();

        $this->assertSame(300_000.0, $h['totalMargin'], '375rb - (25% x 300rb) = 300rb.');
        $this->assertSame(1_200_000.0, $h['totalIn'], 'Uang masuk bersih setelah refund.');
    }

    /** @test */
    public function missing_margin_row_yields_zero_and_flag(): void
    {
        CashMargin::where('code', 'M_BK_ALL')->update(['active' => false]);
        $this->pay($this->order('bk_kolab', null, 100_000), 100_000);

        $h = $this->juni();

        $this->assertSame(0.0, $h['totalMargin'], 'Margin belum diatur → nol, bukan tebakan.');
        $this->assertTrue($h['rows'][0]['marginMissing']);
    }

    /** @test */
    public function payment_without_order_is_counted_separately(): void
    {
        Payment::create([
            'order_id' => null, 'payment_type' => 'dp', 'amount' => 500_000,
            'status' => 'paid', 'paid_at' => '2026-06-10',
        ]);

        $h = $this->juni();

        $this->assertSame(1, $h['noOrder']);
        $this->assertSame(0.0, $h['totalMargin'], 'Tanpa order → tak punya margin → tak dihitung.');
    }

    /** @test */
    public function other_months_are_not_mixed(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-05-10');

        $this->assertSame(0.0, $this->juni()['totalMargin']);
    }

    /** @test */
    public function yearly_accumulates_12_months(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-05-10');
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000, 'dp', '2026-06-10');

        $tahun = app(ProfitAnalysisService::class)->yearly(2026);

        $this->assertCount(12, $tahun);
        $this->assertSame(375_000.0, $tahun[4]['totalMargin'], 'Mei (index 4).');
        $this->assertSame(375_000.0, $tahun[5]['totalMargin'], 'Juni (index 5).');
        $this->assertSame(0.0, $tahun[0]['totalMargin'], 'Januari kosong.');
        $this->assertSame(750_000.0, array_sum(array_column($tahun, 'totalMargin')), 'Akumulasi setahun.');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: **FAIL** — `Class "App\Services\ProfitAnalysisService" not found` (BindingResolutionException) di semua test.

- [ ] **Step 3: Buat service**

Buat `app/Services/ProfitAnalysisService.php`:

```php
<?php

namespace App\Services;

use App\Models\CashMargin;
use App\Models\OrderDetail;
use App\Models\Payment;

class ProfitAnalysisService
{
    private array $labels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Tier + margin untuk satu OrderDetail.
     *
     * Tier tak dikenal (null/coppernicus) → S2 = margin TERENDAH: cadangan APC
     * lebih besar, siap-dibagi lebih kecil. Salah ke arah aman — lebih baik
     * kurang membagi daripada membagi uang yang ternyata dibutuhkan APC.
     *
     * @return array{code:?string,pct:float,unknownTier:bool,marginMissing:bool}
     */
    public function marginFor(?OrderDetail $detail): array
    {
        if (! $detail || ! $detail->type) {
            return ['code' => null, 'pct' => 0.0, 'unknownTier' => false, 'marginMissing' => false];
        }

        $isBook = str_starts_with($detail->type, 'bk_');
        $tier   = $this->tierOf($detail->indexation);

        // Buku tak bertingkat → tier tak relevan, jangan ditandai unknown.
        $unknown = ! $isBook && $tier === null;
        $bucket  = $tier ?? 'S2';

        $code = $isBook
            ? 'M_BK_ALL'
            : (str_starts_with($detail->type, 'at_mandiri') ? 'M_ART_' : 'M_KOL_') . $bucket;

        $margin = CashMargin::where('code', $code)->where('active', true)->first();

        return [
            'code'          => $code,
            'pct'           => (float) ($margin->margin_pct ?? 0),
            'unknownTier'   => $unknown,
            'marginMissing' => $margin === null,
        ];
    }

    /** "SINTA 2"/"sinta 2" → S2 · "sinta 4/5/6" → S4 · selain itu null. */
    private function tierOf(?string $indexation): ?string
    {
        if (! $indexation) {
            return null;
        }
        if (! preg_match('/sinta\s*(\d)/i', trim($indexation), $m)) {
            return null;
        }
        $n = (int) $m[1];

        return match (true) {
            in_array($n, [2, 3], true)    => 'S2',
            in_array($n, [4, 5, 6], true) => 'S4',
            default                       => null,
        };
    }

    /**
     * @return array{rows:array,totalIn:float,totalReserve:float,totalMargin:float,unknownTier:int,noOrder:int}
     */
    public function forMonth(int $year, int $month): array
    {
        $payments = Payment::where('status', 'paid')
            ->whereYear('paid_at', $year)->whereMonth('paid_at', $month)
            ->with(['order.details'])
            ->orderBy('paid_at')->get();

        $rows = [];
        $totalIn = $totalReserve = $totalMargin = 0.0;
        $unknownTier = $noOrder = 0;

        foreach ($payments as $p) {
            $detail = optional($p->order)->details;
            if (! $detail) {
                $noOrder++;
                $totalIn += $this->signed($p);
                continue;
            }

            $m    = $this->marginFor($detail);
            $base = $this->signed($p);                 // refund → negatif
            $marg = round($base * $m['pct'] / 100, 2);
            $res  = $base - $marg;

            $totalIn     += $base;
            $totalMargin += $marg;
            $totalReserve += $res;
            if ($m['unknownTier']) {
                $unknownTier++;
            }

            $rows[] = [
                'tanggal'       => optional($p->paid_at)->format('d/m/y'),
                'code_order'    => optional($p->order)->code_order,
                'judul'         => $detail->title,
                'type'          => $detail->type,
                'indexation'    => $detail->indexation,
                'marginCode'    => $m['code'],
                'pct'           => $m['pct'],
                'base'          => $base,
                'reserve'       => $res,
                'margin'        => $marg,
                'unknownTier'   => $m['unknownTier'],
                'marginMissing' => $m['marginMissing'],
            ];
        }

        return compact('rows', 'totalIn', 'totalReserve', 'totalMargin', 'unknownTier', 'noOrder');
    }

    /** Akumulasi 12 bulan (permintaan user). @return array<int,array> */
    public function yearly(int $year): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $h = $this->forMonth($year, $m);
            $out[] = [
                'month'        => $m,
                'label'        => $this->labels[$m],
                'totalIn'      => $h['totalIn'],
                'totalReserve' => $h['totalReserve'],
                'totalMargin'  => $h['totalMargin'],
            ];
        }

        return $out;
    }

    /** Refund = uang keluar → negatif (cermin Order::paidNet). */
    private function signed(Payment $p): float
    {
        return $p->payment_type === 'refund' ? -(float) $p->amount : (float) $p->amount;
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: **PASS**, 12 test.

> Bila `payment_without_order_is_counted_separately` gagal karena `order_id` tak boleh null di skema, hapus test itu **dan** cabang `noOrder` dari service + catatan `noOrder` di spec/view — jangan memaksakan kolom nullable. Laporkan bila terjadi.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProfitAnalysisService.php tests/Feature/ProfitAnalysisTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
feat(accounting): ProfitAnalysisService (margin per order -> siap dibagi)

Tiap payment dipecah pakai margin Asumsi: cadangan APC + siap dibagi.
Contoh user dikunci test: artikel kolaborasi DP 1,5jt sinta 2 -> 375rb
siap dibagi, 1.125rb cadangan.

Tier dinormalisasi ("SINTA 2" = "sinta 2"; 2-3 -> S2, 4-6 -> S4). Tier
tak dikenal -> margin TERENDAH 25% + ditandai: salah ke arah aman.
Margin nonaktif -> 0% + ditandai, bukan menebak. Refund negatif (cermin
Order::paidNet). Plus akumulasi 12 bulan.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Controller + rute + view + menu

**Files:**
- Create: `app/Http/Controllers/Pages/ProfitAnalysisController.php`
- Create: `resources/views/accounting/profit-analysis.blade.php`
- Modify: `routes/web.php` (grup `role:superadmin|accounting`, dekat `accounting.audit`)
- Modify: `resources/views/layouts/sidebar.blade.php` (setelah menu `accounting.audit`)
- Modify: `tests/Feature/ProfitAnalysisTest.php` (+test render)

- [ ] **Step 1: Controller**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\ProfitAnalysisService;
use Illuminate\Http\Request;

class ProfitAnalysisController extends Controller
{
    public function __construct(private ProfitAnalysisService $service) {}

    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }

        return view('accounting.profit-analysis', array_merge(
            $this->service->forMonth($year, $month),
            ['year' => $year, 'month' => $month, 'yearly' => $this->service->yearly($year)]
        ));
    }
}
```

- [ ] **Step 2: Rute**

Di `routes/web.php`, tepat **setelah** baris rute `accounting.audit`:

```php
        Route::get('accounting/profit', [\App\Http\Controllers\Pages\ProfitAnalysisController::class, 'index'])->name('accounting.profit');
```

- [ ] **Step 3: View**

Buat `resources/views/accounting/profit-analysis.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Analisa Profit - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Analisa Profit</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>@endfor
        </select>
        <button class="btn btn-sm btn-primary">Tampilkan</button>
    </form>
</div>

<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3">
        <div class="text-muted small">Uang Masuk (setelah refund)</div>
        <div class="h5 mb-0">{{ $rp($totalIn) }}</div>
    </div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3">
        <div class="text-muted small">Cadangan APC</div>
        <div class="h5 mb-0 text-warning">{{ $rp($totalReserve) }}</div>
    </div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card bg-success text-white"><div class="card-body py-3">
        <div class="small text-white-50">Siap Dibagi</div>
        <div class="h4 mb-2">{{ $rp($totalMargin) }}</div>
        <a href="{{ route('accounting.distribution', ['year' => $year, 'month' => $month, 'profit' => (int) $totalMargin]) }}" class="btn btn-sm btn-light">Bagi profit ini &rarr;</a>
    </div></div></div>
</div>

@if($unknownTier > 0 || $noOrder > 0)
    <div class="alert alert-warning" role="alert">
        @if($unknownTier > 0)
            <div><strong>{{ $unknownTier }} pembayaran</strong> dari order yang belum punya indeksasi — dihitung memakai <strong>margin terendah (25%)</strong>. Tentukan indeksasinya agar angka ini akurat; angka "siap dibagi" akan menyesuaikan sendiri.</div>
        @endif
        @if($noOrder > 0)
            <div>{{ $noOrder }} pembayaran tanpa order tidak dihitung — tak punya margin.</div>
        @endif
    </div>
@endif

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-3">Akumulasi {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Bulan</th><th class="text-end">Uang Masuk</th><th class="text-end">Cadangan APC</th><th class="text-end">Pendapatan Margin</th></tr></thead>
            <tbody>
                @foreach($yearly as $row)
                    <tr class="{{ $row['month'] === $month ? 'table-active fw-bold' : '' }}">
                        <td>{{ $row['label'] }}</td>
                        <td class="text-end">{{ $rp($row['totalIn']) }}</td>
                        <td class="text-end">{{ $rp($row['totalReserve']) }}</td>
                        <td class="text-end">{{ $rp($row['totalMargin']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>TOTAL</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalIn'))) }}</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalReserve'))) }}</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalMargin'))) }}</td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div></div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-1">Rincian per Pembayaran</h6>
    <p class="text-muted small mb-3">
        Angka ini berbasis <strong>margin asumsi</strong> (Asumsi &rarr; Margin per Produk), bukan biaya APC yang sesungguhnya —
        sistem belum bisa menautkan pengeluaran ke order tertentu. Pemasukan non-order tidak dihitung karena tak punya margin.
    </p>
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable">
            <thead><tr><th>Tgl</th><th>Order</th><th>Judul</th><th>Jenis</th><th>Indeksasi</th><th class="text-end">Margin</th><th class="text-end">Masuk</th><th class="text-end">Cadangan APC</th><th class="text-end">Siap Dibagi</th></tr></thead>
            <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td>{{ $r['tanggal'] }}</td>
                        <td>{{ $r['code_order'] ?? '—' }}</td>
                        <td style="max-width:260px;word-break:break-word">{{ $r['judul'] }}</td>
                        <td>{{ $r['type'] }}</td>
                        <td>
                            {{ $r['indexation'] ?? '—' }}
                            @if($r['unknownTier'])<span class="badge bg-warning text-dark" title="Belum ada indeksasi — dihitung dgn margin terendah">?</span>@endif
                        </td>
                        <td class="text-end">
                            {{ rtrim(rtrim(number_format($r['pct'], 2), '0'), ',.') }}%
                            @if($r['marginMissing'])<span class="badge bg-danger" title="Margin belum diatur di Asumsi">!</span>@endif
                        </td>
                        <td class="text-end">{{ $rp($r['base']) }}</td>
                        <td class="text-end text-warning">{{ $rp($r['reserve']) }}</td>
                        <td class="text-end text-success">{{ $rp($r['margin']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [], language: { emptyTable: 'Belum ada pembayaran bulan ini.' } }); });</script>
@endpush
```

- [ ] **Step 4: Menu sidebar**

Di `resources/views/layouts/sidebar.blade.php`, tepat **setelah** blok `<li>` menu `accounting.audit` (Riwayat Perubahan) dan **sebelum** `@endrole`:

```blade
                <li class="nav-item {{ nav_active('accounting.profit') }}">
                    <a href="{{ route('accounting.profit') }}" class="nav-link">
                        <i class="link-icon" data-feather="trending-up"></i>
                        <span class="link-title">Analisa Profit</span>
                    </a>
                </li>
```

- [ ] **Step 5: Test render**

Tambahkan ke `tests/Feature/ProfitAnalysisTest.php`:

```php
    /** @test */
    public function page_renders_and_links_to_distribution(): void
    {
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');
        $this->actingAs($sa)->get(route('accounting.profit', ['year' => 2026, 'month' => 6]))
            ->assertOk()
            ->assertSee('Siap Dibagi')
            ->assertSee('Akumulasi 2026')
            ->assertSee(route('accounting.distribution', ['year' => 2026, 'month' => 6, 'profit' => 375000]), false);

        $acc = User::factory()->create();
        $acc->assignRole('accounting');
        $this->actingAs($acc)->get(route('accounting.profit'))->assertOk();

        $mk = User::factory()->create();
        $mk->assignRole('marketing');
        $this->actingAs($mk)->get(route('accounting.profit'))->assertForbidden();
    }
```

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: **PASS**, 13 test.

- [ ] **Step 7: Suite penuh + Blade**

Run: `php artisan test`
Expected: PASS semua (**566** = 553 + 13).

Run: `php artisan view:cache && php artisan view:clear`
Expected: tanpa error.

**Bila test lama gagal:** temuan — laporkan, jangan sesuaikan diam-diam.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/ProfitAnalysisController.php resources/views/accounting/profit-analysis.blade.php routes/web.php resources/views/layouts/sidebar.blade.php tests/Feature/ProfitAnalysisTest.php
git commit -F <path-pesan>   # feat(accounting): halaman Analisa Profit + akumulasi 12 bulan
```

---

## Task 3: Verifikasi di data sungguhan

**Files:** tak ada perubahan kode.

- [ ] **Step 1: Hitung dgn data dev**

```bash
php artisan tinker --execute="
\$s = app(\App\Services\ProfitAnalysisService::class);
foreach ([1,2,3,4,5,6] as \$m) {
  \$h = \$s->forMonth(2026, \$m);
  echo 'bln '.\$m.': masuk '.number_format(\$h['totalIn']).' | cadangan '.number_format(\$h['totalReserve']).' | siap dibagi '.number_format(\$h['totalMargin']).' | tanpa indeksasi: '.\$h['unknownTier'].PHP_EOL;
}"
```

Expected: angka masuk cocok dgn Rekap Bulanan (Jan 15.350.000 · Feb 6.300.000 · Mar 11.675.000 · Apr 23.825.000 · Mei 18.400.000 · Jun 5.050.000); `unknownTier` > 0 (31 order lapangan tanpa indeksasi).

**Bila "masuk" tak cocok dgn Rekap Bulanan**, selidiki — keduanya membaca payment yang sama. Laporkan.

- [ ] **Step 2: Buka halamannya**

`php artisan serve --port=8128` di background; superadmin sementara; login via curl; GET `/accounting/profit?year=2026&month=4`.
Expected: 200; memuat "Siap Dibagi"; tabel akumulasi memuat 12 baris; tautan `accounting.distribution` ber-`profit=`.

- [ ] **Step 3: Ikuti tombolnya**

Ambil URL dari tautan "Bagi profit ini", GET URL itu.
Expected: 200, halaman Distribusi Profit memakai angka tsb (`viewData('profit')` = angka yang sama). Membuktikan sambungan ke fitur lama benar-benar bekerja, bukan cuma tautan yang tampak benar.

- [ ] **Step 4: Bersihkan + commit**

Hapus user sementara, matikan server, pastikan `git status` bersih (**`data-excel/` tak boleh ter-stage**).

```bash
git add docs/superpowers/plans/2026-07-17-profit-analysis.md
git commit -F <path-pesan>   # docs(plan): tandai Analisa Profit selesai
```

---

## Self-Review

- **Cakupan spec:** aturan margin + normalisasi tier §Aturan (T1 S3) · unknown → 25% + tandai (T1 S3, test S1) · margin nonaktif → 0 + tandai (T1 S3) · refund negatif (T1 S3) · service 3 method §1 (T1 S3) · controller+rute+menu §2 (T2 S1-S4) · view 4 bagian §3 (T2 S3) · batasan tertulis di halaman §4 (T2 S3 paragraf `text-muted`) · 13 test §5 (T1 S1 + T2 S5) · akumulasi 12 bulan (permintaan user) (T1 S3 `yearly`, T2 S3 tabel). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `forMonth(): array{rows,totalIn,totalReserve,totalMargin,unknownTier,noOrder}` → `array_merge` ke view, jadi view membaca `$rows/$totalIn/$totalReserve/$totalMargin/$unknownTier/$noOrder` langsung; `yearly()` mengembalikan list 12 dgn kunci `month,label,totalIn,totalReserve,totalMargin` — dipakai test (index 4 = Mei) dan view (`array_column`).
- **Catatan:** `forMonth` dipanggil 12× oleh `yearly` (12 query). Dengan 132 payment ini sepele; bila kelak berat, itu masalah yang sama dgn `CashRecapService` (backlog agregasi SQL) — jangan dioptimasi sekarang tanpa bukti.
