# Peringatan Celah Pengeluaran — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tiga halaman yang menampilkan "laba" berkata jujur ketika tak ada pengeluaran tercatat — sehingga tak ada yang membagi pemasukan kotor sebagai laba.

**Architecture:** `ExpenseGapService::check($year, $month = null)` jadi satu-satunya rumah aturan "periode ini tak punya pengeluaran tercatat"; tiga controller memanggilnya dan mengirim `$gap` + `$periodeLabel` ke view; satu partial Blade merender peringatannya. Tak ada angka yang diubah — Asumsi tetap referensi, hanya kas nyata yang memotong laba.

**Tech Stack:** Laravel 11, PHPUnit, Bootstrap alert (sudah ada). Tanpa dependency baru, tanpa migrasi.

**Spec:** `docs/superpowers/specs/2026-07-17-expense-gap-warning-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit.
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell (`@'...'@`) di dalam tool Bash.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`.

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Services/ExpenseGapService.php` (**baru**) | Satu rumah aturan celah: pengeluaran tercatat periode ini + biaya tetap Asumsi + `hasGap`. |
| `resources/views/accounting/partials/expense-warning.blade.php` (**baru**) | Tampilan peringatan; dipakai 3 halaman. |
| 3 controller akuntansi (**diubah**) | Mengirim `$gap` + `$periodeLabel`. |
| 3 view akuntansi (**diubah**) | Satu `@include` masing-masing. |
| `tests/Feature/ExpenseGapTest.php` (**baru**) | Kunci aturan + render + jaminan angka tak berubah. |

---

## Task 1: `ExpenseGapService` (TDD)

**Files:**
- Create: `tests/Feature/ExpenseGapTest.php`
- Create: `app/Services/ExpenseGapService.php`

- [x] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ExpenseGapTest.php` (4 test aturan dulu; test render ditambah di Task 2):

```php
<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\ExpenseGapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Peringatan celah pengeluaran: bila periode tak punya pengeluaran tercatat
 * sama sekali, angka "laba" sebenarnya pemasukan kotor. Ambang sengaja NOL —
 * lihat spec §1 (ambang "kurang dari biaya tetap" sering salah → diabaikan).
 */
class ExpenseGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function pengeluaran(string $tanggal, int $amount = 500_000, bool $transfer = false): CashEntry
    {
        return CashEntry::create([
            'tanggal'          => $tanggal,
            'kode'             => 'X' . uniqid(),
            'keterangan'       => 'Uji',
            'jenis'            => 'pengeluaran',
            'amount'           => $amount,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
            'source'           => 'manual',
            'is_transfer'      => $transfer,
        ]);
    }

    /** @test */
    public function no_expense_recorded_sets_gap(): void
    {
        $hasil = app(ExpenseGapService::class)->check(2026, 6);

        $this->assertTrue($hasil['hasGap']);
        $this->assertSame(0.0, $hasil['recorded']);
    }

    /** @test */
    public function recorded_expense_clears_gap(): void
    {
        $this->pengeluaran('2026-06-10');

        $hasil = app(ExpenseGapService::class)->check(2026, 6);

        $this->assertFalse($hasil['hasGap']);
        $this->assertSame(500_000.0, $hasil['recorded']);
    }

    /** @test */
    public function transfer_is_not_an_expense(): void
    {
        // Pemindahan antar akun sendiri bukan pengeluaran — celahnya tetap ada.
        $this->pengeluaran('2026-06-10', 500_000, true);

        $this->assertTrue(app(ExpenseGapService::class)->check(2026, 6)['hasGap']);
    }

    /** @test */
    public function month_scope_is_independent(): void
    {
        $this->pengeluaran('2026-01-10');

        $svc = app(ExpenseGapService::class);
        $this->assertFalse($svc->check(2026, 1)['hasGap'], 'Januari punya pengeluaran.');
        $this->assertTrue($svc->check(2026, 2)['hasGap'], 'Februari tidak.');
        $this->assertFalse($svc->check(2026)['hasGap'], 'Setahun penuh: ada pengeluaran di Januari.');
    }
}
```

- [x] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ExpenseGapTest`
Expected: **FAIL** — `Class "App\Services\ExpenseGapService" not found` di semua test.

- [x] **Step 3: Buat service**

Buat `app/Services/ExpenseGapService.php`:

```php
<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashFixedExpense;

class ExpenseGapService
{
    /**
     * Periksa apakah periode ini tak punya pengeluaran tercatat sama sekali.
     * $month null → satu tahun penuh.
     *
     * Transfer internal dikecualikan (konsisten dgn CashRecapService):
     * memindahkan uang antar akun sendiri bukan pengeluaran.
     *
     * @return array{recorded:float, fixedMonthly:float, hasGap:bool}
     */
    public function check(int $year, ?int $month = null): array
    {
        $q = CashEntry::whereYear('tanggal', $year)
            ->where('jenis', 'pengeluaran')
            ->where('is_transfer', false);

        if ($month !== null) {
            $q->whereMonth('tanggal', $month);
        }

        $recorded = (float) $q->sum('amount');

        return [
            'recorded'     => $recorded,
            'fixedMonthly' => (float) CashFixedExpense::where('active', true)->get()
                                ->sum(fn (CashFixedExpense $e) => $e->monthlyAmount()),
            'hasGap'       => $recorded == 0.0,
        ];
    }
}
```

- [x] **Step 4: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ExpenseGapTest`
Expected: **PASS**, 4 test.

- [x] **Step 5: Commit**

```bash
git add app/Services/ExpenseGapService.php tests/Feature/ExpenseGapTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
feat(accounting): ExpenseGapService (deteksi periode tanpa pengeluaran)

Satu rumah untuk aturan "periode ini tak punya pengeluaran tercatat",
dipakai 3 halaman yang menampilkan laba. Ambang NOL: keadaan yang tak
mungkin benar dan selalu layak diteriaki. Transfer internal dikecualikan
(bukan pengeluaran), konsisten dgn CashRecapService.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Partial + pasang di 3 halaman

**Files:**
- Create: `resources/views/accounting/partials/expense-warning.blade.php`
- Modify: `app/Http/Controllers/Pages/ProfitDistributionController.php` (view data `index`)
- Modify: `app/Http/Controllers/Pages/AccountingDashboardController.php` (view data `index`)
- Modify: `app/Http/Controllers/Pages/AccountingOverviewController.php` (view data `index`)
- Modify: `resources/views/accounting/distribution.blade.php` (sisip sebelum `<div class="row">` baris 18)
- Modify: `resources/views/accounting/dashboard.blade.php` (sisip sebelum `<div class="row">` baris 27)
- Modify: `resources/views/accounting/overview.blade.php` (sisip sebelum `<div class="row">` baris 21)
- Modify: `tests/Feature/ExpenseGapTest.php` (+3 test render)

- [x] **Step 1: Buat partial**

Buat `resources/views/accounting/partials/expense-warning.blade.php`:

```blade
@if($gap['hasGap'])
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <span aria-hidden="true">⚠</span>
        <div>
            <strong>Belum ada pengeluaran tercatat {{ $periodeLabel }}.</strong>
            Angka laba di bawah adalah <strong>pemasukan kotor</strong> — belum dikurangi biaya apa pun.
            @if($gap['fixedMonthly'] > 0)
                Menurut <a href="{{ route('accounting.assumption') }}">Asumsi</a>, ada
                <strong>Rp {{ number_format($gap['fixedMonthly'], 0, ',', '.') }}/bulan</strong>
                biaya tetap yang belum masuk Jurnal Kas.
            @endif
            <a href="{{ route('accounting.journal') }}">Catat pengeluaran di Jurnal Kas &rarr;</a>
        </div>
    </div>
@endif
```

- [x] **Step 2: `ProfitDistributionController` — kirim data**

Tambah import di bagian `use`:

```php
use App\Services\ExpenseGapService;
```

Di `index()`, tambahkan dua kunci pada array `view('accounting.distribution', [...])` (setelah `'profit' => $profit,`):

```php
            'gap'           => app(ExpenseGapService::class)->check($year, $month),
            'periodeLabel'  => 'pada ' . \Carbon\Carbon::create()->month($month)->translatedFormat('F') . ' ' . $year,
```

- [x] **Step 3: `AccountingDashboardController` — kirim data**

Tambah import `use App\Services\ExpenseGapService;`, lalu pada array `view('accounting.dashboard', [...])` di `index()` tambahkan:

```php
            'gap'          => app(ExpenseGapService::class)->check($year),
            'periodeLabel' => 'sepanjang ' . $year,
```

- [x] **Step 4: `AccountingOverviewController` — kirim data**

Tambah import `use App\Services\ExpenseGapService;`. `index()` memakai `compact(...)` — tambahkan dua variabel sebelum `return`:

```php
        $gap = app(ExpenseGapService::class)->check($year);
        $periodeLabel = 'sepanjang ' . $year;
```

lalu masukkan ke `compact`: `compact('year', 'balances', 'ytd', 'ytdRealisasi', 'ytdTarget', 'pct', 'fixedMonthly', 'gap', 'periodeLabel')`.

> `$fixedMonthly` yang sudah ada **dibiarkan** — ia menampilkan biaya tetap sebagai informasi, beda maksud dari peringatan (spec §3).

- [x] **Step 5: Sisipkan `@include` di 3 view**

Di ketiga file, sisipkan baris berikut **tepat sebelum** `<div class="row">` pertama (distribution ±b.18, dashboard ±b.27, overview ±b.21):

```blade
@include('accounting.partials.expense-warning')
```

- [x] **Step 6: Tambah 3 test render**

Tambahkan ke `tests/Feature/ExpenseGapTest.php`, sebelum `}` penutup class. Tambahkan juga import yang dibutuhkan di atas: `use App\Models\User;`.

```php
    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** @test */
    public function warning_appears_on_three_pages(): void
    {
        $u = $this->superadmin();
        $pesan = 'Belum ada pengeluaran tercatat';

        $this->actingAs($u)->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee($pesan);
        $this->actingAs($u)->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertSee($pesan);
        $this->actingAs($u)->get(route('accounting.overview', ['year' => 2026]))
            ->assertOk()->assertSee($pesan);
    }

    /** @test */
    public function warning_absent_when_expense_exists(): void
    {
        $this->pengeluaran('2026-06-10');
        $u = $this->superadmin();
        $pesan = 'Belum ada pengeluaran tercatat';

        $this->actingAs($u)->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertDontSee($pesan);
        $this->actingAs($u)->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertDontSee($pesan);
        $this->actingAs($u)->get(route('accounting.overview', ['year' => 2026]))
            ->assertOk()->assertDontSee($pesan);
    }

    /** @test */
    public function warning_does_not_change_distribution(): void
    {
        // Peringatan hanya bicara — tak boleh diam-diam memotong laba.
        // Keputusan user: hanya pengeluaran nyata di Jurnal Kas yang memotong.
        $u = $this->superadmin();

        $profit = $this->actingAs($u)
            ->get(route('accounting.distribution', ['year' => 2026, 'month' => 6, 'profit' => 5_050_000]))
            ->assertOk()->viewData('profit');

        $this->assertSame(5_050_000.0, (float) $profit, 'Laba yang dibagi tak boleh berubah karena peringatan.');
    }
```

- [x] **Step 7: Jalankan test**

Run: `php artisan test --filter=ExpenseGapTest`
Expected: **PASS**, 7 test.

> Bila `warning_appears_on_three_pages` gagal dgn `Route [accounting.assumption] not defined`, periksa nama rute sebenarnya: `php artisan route:list --name=accounting` — sesuaikan **partial**, jangan hapus tautannya.

- [x] **Step 8: Suite penuh + Blade sehat**

Run: `php artisan test`
Expected: PASS semua (**536** = 529 + 7).

Run: `php artisan view:cache && php artisan view:clear`
Expected: "Blade templates cached successfully." tanpa error.

- [x] **Step 9: Commit**

```bash
git add resources/views/accounting/partials/expense-warning.blade.php resources/views/accounting/distribution.blade.php resources/views/accounting/dashboard.blade.php resources/views/accounting/overview.blade.php app/Http/Controllers/Pages/ProfitDistributionController.php app/Http/Controllers/Pages/AccountingDashboardController.php app/Http/Controllers/Pages/AccountingOverviewController.php tests/Feature/ExpenseGapTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
feat(accounting): peringatan celah pengeluaran di 3 halaman laba

Distribusi Profit, Dashboard Keuangan, dan Ringkasan kini berkata
terus terang saat tak ada pengeluaran tercatat: angkanya pemasukan
kotor, bukan laba - plus berapa biaya tetap menurut Asumsi yang belum
masuk Jurnal Kas, dan tautan untuk mencatatnya.

Tak ada angka yang diubah: Asumsi tetap referensi, hanya kas nyata yang
memotong laba (dikunci warning_does_not_change_distribution).

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 3: Lihat di aplikasi sungguhan

**Files:** tak ada perubahan kode.

- [x] **Step 1: Buka ketiga halaman**

`php artisan serve --port=8126` di background; buat superadmin sementara; login via curl; GET `/accounting/distribution`, `/accounting/dashboard`, `/accounting/overview`.

Expected: ketiganya 200 dan memuat teks "Belum ada pengeluaran tercatat" beserta "Rp 824.167/bulan" (DB dev: 6 biaya tetap aktif, pengeluaran = 0).

- [x] **Step 2: Buktikan peringatan hilang saat pengeluaran ada**

Buat 1 entri pengeluaran sementara di DB dev (mis. `2026-06-10`, 100.000, source manual), muat ulang `/accounting/dashboard?year=2026` → peringatan **hilang**. Lalu **hapus lagi entri itu** dan pastikan peringatan kembali muncul.

> Ini menguji kedua arah di data nyata — peringatan yang tak pernah bisa hilang sama buruknya dgn yang tak pernah muncul.

- [x] **Step 3: Bersihkan**

Hapus user sementara + entri uji, matikan server, pastikan `CashEntry::count()` kembali **132** dan `git status` bersih dari sampah uji.

- [x] **Step 4: Centang plan + commit**

```bash
git add docs/superpowers/plans/2026-07-17-expense-gap-warning.md
git commit -F <path-pesan>   # docs(plan): tandai peringatan celah pengeluaran selesai
```

---

## Self-Review

- **Cakupan spec:** service + ambang nol + transfer dikecualikan §1 (T1 S3) · partial §2 (T2 S1) · 3 pemasangan §3 (T2 S2-S5) · `$fixedMonthly` overview dibiarkan (T2 S4 catatan) · 7 test §4 (T1 S1 + T2 S6) · regresi + view:cache (T2 S8). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `check(int $year, ?int $month = null): array{recorded,fixedMonthly,hasGap}` dipakai identik di 3 controller (T2 S2-S4) dan test (T1 S1); partial membaca `$gap['hasGap']`/`$gap['fixedMonthly']` + `$periodeLabel` — keduanya dikirim oleh ketiga controller.
- **Catatan:** `warning_does_not_change_distribution` memakai `?profit=` eksplisit agar menguji jalur override; laba default dari recap diuji terpisah oleh `AccountingDistributionTest` yang sudah ada.
