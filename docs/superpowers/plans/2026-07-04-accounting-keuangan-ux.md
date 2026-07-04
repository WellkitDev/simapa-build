# Keuangan UX (Export + Overview + Mask Ribuan) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Menambah mask ribuan pada input Rupiah, export CSV+PDF untuk Jurnal Kas & Rekap, dan halaman Ringkasan Keuangan — tanpa dependency baru.

**Architecture:** Mask pakai `jquery.inputmask` (bundled) via partial Blade. Export: CSV native (`streamDownload`+`fputcsv`, `;`+BOM), PDF via `barryvdh/laravel-dompdf` (`Pdf::loadView->download`). Overview = kontroler baru agregasi service yang sudah ada. Semua rute di grup role `superadmin|accounting`.

**Tech Stack:** Laravel 11, PHP 8.2, Blade + Bootstrap 5 (NobleUI), jquery.inputmask, dompdf, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-04-accounting-keuangan-ux-design.md`

---

## Konvensi (semua task)

- **Test:** `php artisan test` (phpunit.xml → `APP_ENV=testing` → DB `avidpedi_simapa_test`). Single: `php artisan test --filter=Nama`. Jangan pakai DB dev untuk test.
- **TDD:** test gagal dulu → konfirmasi gagal → implementasi → konfirmasi lulus.
- **Commit:** author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`. JANGAN "Claude"/Anthropic. `git add` path eksplisit saja (jangan `git add .`). Commit heredoc via Bash tool.
- **Tak ada migrasi** di fitur ini (tak perlu migrate dev).

---

## File Structure

- **Buat:** `resources/views/accounting/partials/money-mask.blade.php`; `resources/views/accounting/pdf/journal.blade.php`; `resources/views/accounting/pdf/recap.blade.php`; `app/Http/Controllers/Pages/AccountingOverviewController.php`; `resources/views/accounting/overview.blade.php`; `tests/Feature/AccountingMoneyMaskTest.php`; `tests/Feature/AccountingExportTest.php`; `tests/Feature/AccountingOverviewTest.php`.
- **Ubah:** views `journal`/`target`/`assumption`/`distribution` (mask + tombol export di journal); `dashboard` (tombol export); `CashEntryController` (exportCsv/exportPdf + helper filter); `AccountingDashboardController` (exportCsv/exportPdf); `routes/web.php` (+5 rute); `layouts/sidebar.blade.php` (+Ringkasan).

---

## Task 1: Mask ribuan (input Rupiah)

**Files:** Create `resources/views/accounting/partials/money-mask.blade.php`; Modify `resources/views/accounting/journal.blade.php`, `target.blade.php`, `assumption.blade.php`, `distribution.blade.php`; Test `tests/Feature/AccountingMoneyMaskTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/AccountingMoneyMaskTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingMoneyMaskTest extends TestCase
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
    public function journal_page_loads_money_mask(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.journal'))
            ->assertOk()
            ->assertSee('money-mask')
            ->assertSee('jquery.inputmask.min.js');
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=AccountingMoneyMaskTest`
Expected: FAIL (`money-mask` belum ada di halaman).

- [ ] **Step 3: Buat partial**

Buat `resources/views/accounting/partials/money-mask.blade.php`:
```blade
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/inputmask/jquery.inputmask.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.money-mask').inputmask({
        alias: 'numeric', groupSeparator: '.', digits: 0, autoGroup: true,
        rightAlign: false, autoUnmask: true, removeMaskOnSubmit: true, allowMinus: false
    });
});
</script>
@endpush
```

- [ ] **Step 4: Edit `journal.blade.php` (4 input + include)**

Ganti 4 baris input (ubah `type="number"`→`type="text"`, tambah `money-mask` di class):
```
<div class="col-md-2"><label class="form-label small mb-1">Nominal (Rp)</label><input type="number" name="amount" class="form-control form-control-sm" min="1" required></div>
```
→
```
<div class="col-md-2"><label class="form-label small mb-1">Nominal (Rp)</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="1" required></div>
```
Dan:
```
<div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="number" name="amount" class="form-control form-control-sm" min="0" required></div>
```
→
```
<div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" required></div>
```
Dan:
```
                        <div><label class="form-label small mb-0">Saldo Awal</label><input type="number" name="opening_balance" value="{{ (int) $a->opening_balance }}" class="form-control form-control-sm" style="max-width:130px" min="0"></div>
```
→
```
                        <div><label class="form-label small mb-0">Saldo Awal</label><input type="text" name="opening_balance" value="{{ (int) $a->opening_balance }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:130px" min="0"></div>
```
Dan:
```
                <div class="col-md-2"><input type="number" name="opening_balance" value="0" class="form-control form-control-sm" min="0" title="Saldo awal"></div>
```
→
```
                <div class="col-md-2"><input type="text" name="opening_balance" value="0" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" title="Saldo awal"></div>
```
Lalu tambahkan `@include('accounting.partials.money-mask')` tepat sebelum `@endsection` (di akhir blok `@section('content')`).

- [ ] **Step 5: Edit `target.blade.php` (2 input + include)**

```
<div><label class="form-label small mb-1">Target Operasional (Rp/bln)</label><input type="number" name="target_operasional" value="{{ (int) $setting->target_operasional }}" min="0" class="form-control form-control-sm" style="width:180px"></div>
```
→ ubah `type="text"` + tambah `money-mask` di class:
```
<div><label class="form-label small mb-1">Target Operasional (Rp/bln)</label><input type="text" name="target_operasional" value="{{ (int) $setting->target_operasional }}" min="0" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:180px"></div>
```
Dan:
```
<div><label class="form-label small mb-1">Target Order (Rp/bln)</label><input type="number" name="target_order" value="{{ (int) $setting->target_order }}" min="0" class="form-control form-control-sm" style="width:180px"></div>
```
→
```
<div><label class="form-label small mb-1">Target Order (Rp/bln)</label><input type="text" name="target_order" value="{{ (int) $setting->target_order }}" min="0" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:180px"></div>
```
Tambahkan `@include('accounting.partials.money-mask')` sebelum `@endsection`.

- [ ] **Step 6: Edit `assumption.blade.php` (2 input + include)**

```
                                    <input type="number" name="amount" value="{{ (int) $e->amount }}" class="form-control form-control-sm" style="max-width:140px">
```
→
```
                                    <input type="text" name="amount" value="{{ (int) $e->amount }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:140px">
```
Dan:
```
        <input type="number" name="amount" placeholder="Nominal" class="form-control form-control-sm" style="max-width:140px">
```
→
```
        <input type="text" name="amount" placeholder="Nominal" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:140px">
```
Tambahkan `@include('accounting.partials.money-mask')` sebelum `@endsection`.

- [ ] **Step 7: Edit `distribution.blade.php` (1 input + include)**

```
        <input type="number" name="profit" value="{{ (int) $profit }}" class="form-control form-control-sm" style="width:150px" placeholder="Profit (Rp)" title="Kosongkan untuk pakai laba kas bulan">
```
→
```
        <input type="text" name="profit" value="{{ (int) $profit }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:150px" placeholder="Profit (Rp)" title="Kosongkan untuk pakai laba kas bulan">
```
Tambahkan `@include('accounting.partials.money-mask')` sebelum `@endsection`. **JANGAN** ubah input `name="value"`, `name="margin_pct"`, `name="team_members"`, `name="year"` (bukan Rupiah bilangan bulat).

- [ ] **Step 8: Run — lulus + regresi mask**

Run: `php artisan test --filter=AccountingMoneyMaskTest`
Expected: PASS.
Run: `php artisan test --filter=AccountingJournalTest`
Expected: PASS (store dgn angka polos tetap lolos — form kirim nilai unmasked).

- [ ] **Step 9: view:cache bersih**

Run: `php artisan view:cache` → `Blade templates cached successfully.` (tanpa error). Lalu `php artisan view:clear`.

- [ ] **Step 10: Commit**

```bash
git add resources/views/accounting/partials/money-mask.blade.php resources/views/accounting/journal.blade.php resources/views/accounting/target.blade.php resources/views/accounting/assumption.blade.php resources/views/accounting/distribution.blade.php tests/Feature/AccountingMoneyMaskTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): mask ribuan pada input Rupiah (jquery.inputmask)

Input Rupiah tampil pemisah ribuan; nilai terkirim tetap angka polos
(autoUnmask+removeMaskOnSubmit). Margin/persen/cacah tidak di-mask.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 2: Export Jurnal Kas (CSV + PDF)

**Files:** Modify `app/Http/Controllers/Pages/CashEntryController.php`, `routes/web.php`, `resources/views/accounting/journal.blade.php`; Create `resources/views/accounting/pdf/journal.blade.php`; Test `tests/Feature/AccountingExportTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/AccountingExportTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingExportTest extends TestCase
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
    public function journal_csv_download(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'Masuk Juni', 'source' => 'manual']);
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.journal.export.csv', ['year' => 2026, 'month' => 6]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('Pemasukan', $body); // header kolom
        $this->assertStringContainsString('Masuk Juni', $body); // baris entri
    }

    /** @test */
    public function journal_pdf_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.journal.export.pdf', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
    }

    /** @test */
    public function marketing_cannot_export(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.journal.export.csv'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=AccountingExportTest`
Expected: FAIL (`Route [accounting.journal.export.csv] not defined`).

- [ ] **Step 3: Tambah helper + export di `CashEntryController`**

Di `app/Http/Controllers/Pages/CashEntryController.php`:
- Tambah import: `use Barryvdh\DomPDF\Facade\Pdf;`
- Tambahkan method helper (mis. setelah konstruktor):
```php
    /** @return array{0:int,1:?int,2:?string,3:?int} [year, month, jenis, accountId] dari query. */
    private function resolveFilters(Request $request): array
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $jenis = in_array($request->query('jenis'), ['pemasukan', 'pengeluaran'], true) ? $request->query('jenis') : null;
        $acc   = $request->query('account');
        $accountId = ($acc === null || $acc === '' || $acc === 'all') ? null : (int) $acc;
        return [$year, $month, $jenis, $accountId];
    }
```
- Ganti awal `index()` agar pakai helper (bagian atas method, gantikan blok parsing year/month/jenis/acc/accountId):
```php
    public function index(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);

        $data = $this->service->compute($year, $month, $jenis, $accountId);

        return view('accounting.journal', array_merge($data, [
            'year'          => $year,
            'month'         => $month,
            'jenis'         => $jenis,
            'accountId'     => $accountId,
            'categories'    => CashCategory::active()->orderBy('jenis')->orderBy('position')->get(),
            'allCategories' => CashCategory::orderBy('jenis')->orderBy('position')->get(),
            'accounts'      => CashAccount::active()->orderBy('position')->get(),
            'allAccounts'   => CashAccount::orderBy('position')->get(),
            'balances'      => $this->service->accountBalances(),
        ]));
    }
```
- Tambahkan dua method export:
```php
    public function exportCsv(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);
        $data = $this->service->compute($year, $month, $jenis, $accountId);
        $filename = 'Jurnal_Kas_' . $year . '_' . ($month ?? 'semua') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($h, ['Tanggal', 'Kode', 'Keterangan', 'Akun', 'Kategori', 'Produk', 'Pemasukan', 'Pengeluaran', 'Saldo', 'Ref'], ';');
            foreach ($data['entries'] as $e) {
                fputcsv($h, [
                    optional($e->tanggal)->format('Y-m-d'),
                    $e->kode,
                    $e->keterangan,
                    $e->account?->name ?? '',
                    $e->category?->name ?? '',
                    \App\Models\CashEntry::PRODUK[$e->produk] ?? '',
                    $e->isPemasukan() ? (int) $e->amount : '',
                    ! $e->isPemasukan() ? (int) $e->amount : '',
                    (int) ($e->saldo ?? 0),
                    $e->ref ?? '',
                ], ';');
            }
            fclose($h);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);
        $data = $this->service->compute($year, $month, $jenis, $accountId);
        $data['year'] = $year;
        $data['month'] = $month;

        return Pdf::loadView('accounting.pdf.journal', $data)
            ->download('Jurnal_Kas_' . $year . '_' . ($month ?? 'semua') . '.pdf');
    }
```

- [ ] **Step 4: Buat view PDF** `resources/views/accounting/pdf/journal.blade.php`:
```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:11px;color:#222}
h2{margin:0 0 4px}.muted{color:#666;margin-bottom:8px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>Jurnal Kas {{ $year }}@if($month) — Bulan {{ $month }}@endif</h2>
<div class="muted">Saldo awal periode: {{ $rp($opening) }} · Pemasukan: {{ $rp($totalIn) }} · Pengeluaran: {{ $rp($totalOut) }} · Saldo akhir: {{ $rp($saldoAkhir) }}</div>
<table>
    <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Akun</th><th>Kategori</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        <tr>
            <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
            <td>{{ $e->kode }}</td>
            <td>{{ $e->keterangan }}</td>
            <td>{{ $e->account?->name ?? '-' }}</td>
            <td>{{ $e->category?->name ?? '-' }}</td>
            <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
            <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
            <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body></html>
```

- [ ] **Step 5: Tambah rute** — di `routes/web.php`, grup accounting (dekat rute `accounting.entry.*`):
```php
        Route::get('accounting/journal/export/csv', [\App\Http\Controllers\Pages\CashEntryController::class, 'exportCsv'])->name('accounting.journal.export.csv');
        Route::get('accounting/journal/export/pdf', [\App\Http\Controllers\Pages\CashEntryController::class, 'exportPdf'])->name('accounting.journal.export.pdf');
```

- [ ] **Step 6: Tombol export di `journal.blade.php`** — di dalam form filter GET (setelah tombol "Filter", masih di dalam `<form method="GET" ...>` **tidak boleh**, karena link bukan submit). Letakkan tepat SETELAH `</form>` penutup form filter, di dalam div header flex. Tambahkan:
```blade
        <a href="{{ route('accounting.journal.export.csv', request()->query()) }}" class="btn btn-sm btn-outline-success">Export CSV</a>
        <a href="{{ route('accounting.journal.export.pdf', request()->query()) }}" class="btn btn-sm btn-outline-danger">Export PDF</a>
```
(Cari `<button class="btn btn-sm btn-outline-secondary">Filter</button>` lalu `</form>`; sisipkan dua `<a>` di atas tepat setelah `</form>` itu, sebelum penutup `</div>` header.)

- [ ] **Step 7: Run — lulus**

Run: `php artisan test --filter=AccountingExportTest`
Expected: PASS (`journal_csv_download`, `journal_pdf_download`, `marketing_cannot_export`).

- [ ] **Step 8: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/CashEntryController.php routes/web.php resources/views/accounting/pdf/journal.blade.php resources/views/accounting/journal.blade.php tests/Feature/AccountingExportTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): export Jurnal Kas ke CSV & PDF (ikut filter)

CSV native (; + BOM UTF-8) + PDF dompdf; tombol di halaman Jurnal Kas
meneruskan filter aktif. Helper resolveFilters dipakai index+export.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: Export Rekap (CSV + PDF)

**Files:** Modify `app/Http/Controllers/Pages/AccountingDashboardController.php`, `routes/web.php`, `resources/views/accounting/dashboard.blade.php`; Create `resources/views/accounting/pdf/recap.blade.php`; Modify `tests/Feature/AccountingExportTest.php`.

- [ ] **Step 1: Tambah test gagal** — di `tests/Feature/AccountingExportTest.php` tambahkan:
```php
    /** @test */
    public function recap_csv_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.recap.export.csv', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('YTD', $res->streamedContent());
    }

    /** @test */
    public function recap_pdf_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.recap.export.pdf', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
    }
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=AccountingExportTest`
Expected: FAIL pada `recap_csv_download`/`recap_pdf_download` (`Route [accounting.recap.export.csv] not defined`).

- [ ] **Step 3: Export di `AccountingDashboardController`** — tambah import `use Barryvdh\DomPDF\Facade\Pdf;` dan dua method:
```php
    public function exportCsv(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $recap = $this->service->monthlyRecap($year);
        $ytd = $this->service->ytd($year);

        return response()->streamDownload(function () use ($recap, $ytd) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Bulan', 'Pemasukan', 'Pengeluaran', 'Laba', 'Saldo Akhir'], ';');
            foreach ($recap as $r) {
                fputcsv($h, [$r['label'], (int) $r['totalIn'], (int) $r['totalOut'], (int) $r['laba'], (int) $r['saldoAkhir']], ';');
            }
            fputcsv($h, ['YTD', (int) $ytd['totalIn'], (int) $ytd['totalOut'], (int) $ytd['laba'], (int) $ytd['saldoAkhir']], ';');
            fclose($h);
        }, 'Rekap_' . $year . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $recap = $this->service->monthlyRecap($year);
        $ytd = $this->service->ytd($year);

        return Pdf::loadView('accounting.pdf.recap', compact('year', 'recap', 'ytd'))
            ->download('Rekap_' . $year . '.pdf');
    }
```

- [ ] **Step 4: Buat view PDF** `resources/views/accounting/pdf/recap.blade.php`:
```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:11px;color:#222}
h2{margin:0 0 8px}table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ccc;padding:5px 7px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}tfoot td{font-weight:bold;background:#fafafa}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>Rekap Keuangan {{ $year }}</h2>
<table>
    <thead><tr><th>Bulan</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Laba</th><th class="text-end">Saldo Akhir</th></tr></thead>
    <tbody>
    @foreach($recap as $r)
        <tr>
            <td>{{ $r['label'] }}</td>
            <td class="text-end">{{ $rp($r['totalIn']) }}</td>
            <td class="text-end">{{ $rp($r['totalOut']) }}</td>
            <td class="text-end">{{ $rp($r['laba']) }}</td>
            <td class="text-end">{{ $rp($r['saldoAkhir']) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr>
        <td>YTD</td>
        <td class="text-end">{{ $rp($ytd['totalIn']) }}</td>
        <td class="text-end">{{ $rp($ytd['totalOut']) }}</td>
        <td class="text-end">{{ $rp($ytd['laba']) }}</td>
        <td class="text-end">{{ $rp($ytd['saldoAkhir']) }}</td>
    </tr></tfoot>
</table>
</body></html>
```

- [ ] **Step 5: Rute** — di `routes/web.php`, grup accounting (dekat `accounting.dashboard`):
```php
        Route::get('accounting/recap/export/csv', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'exportCsv'])->name('accounting.recap.export.csv');
        Route::get('accounting/recap/export/pdf', [\App\Http\Controllers\Pages\AccountingDashboardController::class, 'exportPdf'])->name('accounting.recap.export.pdf');
```

- [ ] **Step 6: Tombol di `dashboard.blade.php`** — ganti blok form filter (baris ~17-20):
```blade
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
        <button class="btn btn-sm btn-outline-secondary">Tahun</button>
    </form>
```
→
```blade
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
            <button class="btn btn-sm btn-outline-secondary">Tahun</button>
        </form>
        <a href="{{ route('accounting.recap.export.csv', ['year' => $year]) }}" class="btn btn-sm btn-outline-success">Export Rekap CSV</a>
        <a href="{{ route('accounting.recap.export.pdf', ['year' => $year]) }}" class="btn btn-sm btn-outline-danger">Export Rekap PDF</a>
    </div>
```

- [ ] **Step 7: Run — lulus** — `php artisan test --filter=AccountingExportTest` → PASS (5 test).

- [ ] **Step 8: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/AccountingDashboardController.php routes/web.php resources/views/accounting/pdf/recap.blade.php resources/views/accounting/dashboard.blade.php tests/Feature/AccountingExportTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): export Rekap bulanan ke CSV & PDF

12 bulan + baris YTD; tombol di Dashboard Keuangan.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: Ringkasan Keuangan (overview)

**Files:** Create `app/Http/Controllers/Pages/AccountingOverviewController.php`, `resources/views/accounting/overview.blade.php`; Modify `routes/web.php`, `resources/views/layouts/sidebar.blade.php`; Test `tests/Feature/AccountingOverviewTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/AccountingOverviewTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingOverviewTest extends TestCase
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
    public function overview_shows_kpis(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.overview'))
            ->assertOk()
            ->assertSee('Total Saldo')
            ->assertSee('Laba')
            ->assertSee('Kas Pemasukan'); // kartu akun (seed)
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.overview'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=AccountingOverviewTest` → FAIL (`Route [accounting.overview] not defined`).

- [ ] **Step 3: Buat controller** `app/Http/Controllers/Pages/AccountingOverviewController.php`:
```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use App\Services\CashJournalService;
use App\Services\CashRecapService;
use Illuminate\Http\Request;

class AccountingOverviewController extends Controller
{
    public function __construct(
        private CashJournalService $journal,
        private CashRecapService $recap,
        private BudgetTargetService $budget,
    ) {}

    public function index(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $balances = $this->journal->accountBalances();
        $ytd = $this->recap->ytd($year);
        $achievement = $this->budget->monthlyAchievement($year);
        $ytdRealisasi = (float) array_sum(array_column($achievement, 'realisasi'));
        $target = (float) CashSetting::singleton()->target_operasional;
        $ytdTarget = $target * 12;
        $pct = $ytdTarget > 0 ? (int) round($ytdRealisasi / $ytdTarget * 100) : 0;
        $fixedMonthly = CashFixedExpense::where('active', true)->get()->sum(fn ($e) => $e->monthlyAmount());

        return view('accounting.overview', compact('year', 'balances', 'ytd', 'ytdRealisasi', 'ytdTarget', 'pct', 'fixedMonthly'));
    }
}
```

- [ ] **Step 4: Buat view** `resources/views/accounting/overview.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Ringkasan Keuangan - SiMAPA')

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $purposeBadge = ['pemasukan' => 'bg-success', 'operational' => 'bg-primary', 'harta' => 'bg-warning text-dark', 'umum' => 'bg-secondary'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Ringkasan Keuangan</h5>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
            <button class="btn btn-sm btn-outline-secondary">Tahun</button>
        </form>
        <a href="{{ route('accounting.recap.export.csv', ['year' => $year]) }}" class="btn btn-sm btn-outline-success">Export CSV</a>
        <a href="{{ route('accounting.recap.export.pdf', ['year' => $year]) }}" class="btn btn-sm btn-outline-danger">Export PDF</a>
    </div>
</div>

<div class="row">
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card bg-dark text-white"><div class="card-body py-3"><div class="small text-white-50">Total Saldo Semua Akun</div><div class="h5 mb-0">{{ $rp($balances['total']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Pemasukan YTD</div><div class="h5 mb-0 text-success">{{ $rp($ytd['totalIn']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Pengeluaran YTD</div><div class="h5 mb-0 text-danger">{{ $rp($ytd['totalOut']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Laba YTD</div><div class="h5 mb-0">{{ $rp($ytd['laba']) }}</div></div></div></div>
</div>

<div class="row">
    <div class="col-lg-7 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="mb-3">Saldo per Akun</h6>
        <div class="row">
            @foreach($balances['rows'] as $row)
                <div class="col-md-6 mb-2">
                    <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                        <span>{{ $row['account']->name }}
                            @if($row['account']->purpose)<span class="badge {{ $purposeBadge[$row['account']->purpose] ?? 'bg-secondary' }}">{{ \App\Models\CashAccount::PURPOSES[$row['account']->purpose] ?? $row['account']->purpose }}</span>@endif
                        </span>
                        <strong>{{ $rp($row['saldo']) }}</strong>
                    </div>
                </div>
            @endforeach
        </div>
    </div></div></div>
    <div class="col-lg-5 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="mb-3">Target & Biaya</h6>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Realisasi YTD</span><strong>{{ $rp($ytdRealisasi) }}</strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Target YTD</span><strong>{{ $rp($ytdTarget) }}</strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pencapaian</span><span class="badge {{ $pct >= 100 ? 'bg-success' : 'bg-warning text-dark' }}">{{ $pct }}%</span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Total Biaya Tetap / bln</span><strong>{{ $rp($fixedMonthly) }}</strong></div>
    </div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-3">Pintasan</h6>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('accounting.journal') }}" class="btn btn-sm btn-outline-primary">Jurnal Kas</a>
        <a href="{{ route('accounting.dashboard') }}" class="btn btn-sm btn-outline-primary">Dashboard</a>
        <a href="{{ route('accounting.distribution') }}" class="btn btn-sm btn-outline-primary">Distribusi Profit</a>
        <a href="{{ route('accounting.assumption') }}" class="btn btn-sm btn-outline-primary">Asumsi</a>
        <a href="{{ route('accounting.target') }}" class="btn btn-sm btn-outline-primary">Anggaran & Target</a>
    </div>
</div></div></div>
@endsection
```

- [ ] **Step 5: Rute** — di `routes/web.php`, grup accounting (paling atas blok accounting, sebelum `accounting.journal`):
```php
        Route::get('accounting/overview', [\App\Http\Controllers\Pages\AccountingOverviewController::class, 'index'])->name('accounting.overview');
```

- [ ] **Step 6: Menu sidebar** — di `resources/views/layouts/sidebar.blade.php`, cari:
```blade
                <li class="nav-item nav-category">Keuangan</li>
                <li class="nav-item {{ active_class(['accounting/journal', 'accounting/*']) }}">
```
Sisipkan item Ringkasan di antaranya:
```blade
                <li class="nav-item nav-category">Keuangan</li>
                <li class="nav-item {{ active_class(['accounting/overview']) }}">
                    <a href="{{ route('accounting.overview') }}" class="nav-link">
                        <i class="link-icon" data-feather="pie-chart"></i>
                        <span class="link-title">Ringkasan</span>
                    </a>
                </li>
                <li class="nav-item {{ active_class(['accounting/journal', 'accounting/*']) }}">
```

- [ ] **Step 7: Run — lulus** — `php artisan test --filter=AccountingOverviewTest` → PASS (2 test).

- [ ] **Step 8: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/AccountingOverviewController.php resources/views/accounting/overview.blade.php routes/web.php resources/views/layouts/sidebar.blade.php tests/Feature/AccountingOverviewTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): halaman Ringkasan Keuangan (KPI + saldo per akun + pintasan)

KPI (total saldo semua akun, pemasukan/pengeluaran/laba YTD), saldo per
akun, target vs realisasi YTD, biaya tetap/bln, pintasan + export. Menu
sidebar Keuangan → Ringkasan.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 5: Regresi penuh

- [ ] **Step 1: Seluruh suite** — `php artisan test` → PASS semua. Bila gagal, perbaiki sebelum lanjut.
- [ ] **Step 2: view:cache final** — `php artisan view:cache` (sukses, tanpa error Blade) lalu `php artisan view:clear`.
- [ ] **Step 3: (opsional) Verifikasi manual** — buka `/accounting/overview` (superadmin): KPI + saldo per akun tampil; tombol Export di Jurnal Kas & Dashboard mengunduh CSV/PDF; ketik nominal di form → muncul titik ribuan.

---

## Self-Review (penulis plan)

**1. Spec coverage:** §2 mask → Task 1 (9 input, partial). §3a export Jurnal → Task 2. §3b export Rekap → Task 3. §4 overview + sidebar → Task 4. §5 test (AccountingExport, AccountingOverview, mask) → Task 1/2/3/4. Semua tercakup.

**2. Placeholder scan:** Tak ada TBD; semua step berisi kode utuh + perintah + expected. Satu langkah verifikasi manual ditandai opsional.

**3. Type consistency:** `resolveFilters` mengembalikan `[year,month,jenis,accountId]` dipakai konsisten di index/exportCsv/exportPdf (Task 2). `Pdf::loadView(...)->download(...)` konsisten (Task 2/3). View PDF `accounting.pdf.journal`/`accounting.pdf.recap` cocok dgn `loadView`. Rute `accounting.journal.export.csv|pdf`, `accounting.recap.export.csv|pdf`, `accounting.overview` konsisten antara controller/route/view/test/sidebar. `accountBalances()['rows'|'total']`, `ytd[...]`, `monthlyAchievement`→`realisasi` konsisten dgn service yang ada. Mask hanya menyentuh input Rupiah bulat (bukan `value`/`margin_pct`/`team_members`/`year`).
