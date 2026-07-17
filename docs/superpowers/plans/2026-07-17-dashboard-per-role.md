# Dashboard per Role — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Setiap dari enam role (superadmin, manager, accounting, admin, marketing, production) mendapat dashboard yang dirancang untuk pekerjaannya, tanpa menggandakan definisi pemasukan dan tanpa membocorkan angka uang ke role yang tak berwenang.

**Architecture:** `MarketingDashboardService` di-rename jadi `SalesDashboardService` dengan scope sebagai parameter `?User $scopeUser` (null = seluruh perusahaan) — idiom yang **sudah** jadi konvensi di repo ini (`Payment::forOrdersOf(?User)`, `FinancialReportService::piutang(?User)`, `FinancialReportService::resolveScope()`). `DashboardController::index()` memakai peta role eksplisit berurutan prioritas, bukan rentetan `if` yang tumpang tindih. Agregasi harian pindah dari PHP ke SQL sebelum dilepas ke skala perusahaan.

**Tech Stack:** Laravel 11 · Blade · Spatie Permission · ApexCharts · DataTables (datatables.net-bs4) · PHPUnit + RefreshDatabase pada DB `avidpedi_simapa_test` via `.env.testing`.

**Spec:** `docs/superpowers/specs/2026-07-17-dashboard-per-role-design.md`

---

## Penyimpangan dari spec (disengaja)

Spec §Arsitektur menulis `build(Closure $scope)`. Rencana ini memakai **`build(?User $scopeUser)`**. Alasan: `Payment::scopeForOrdersOf(?User $user)` (`app/Models/Payment.php:61-66`) dan `FinancialReportService::piutang(?User $scopeUser)` (`app/Services/FinancialReportService.php:47-50`) **sudah** menerima null dengan arti "tanpa filter". Closure akan jadi konvensi kedua untuk hal yang sama. Hasil akhirnya identik; jalurnya mengikuti pola yang sudah ada.

## File Structure

| Aksi | Berkas | Tanggung jawab |
|---|---|---|
| Rename | `app/Services/MarketingDashboardService.php` → `app/Services/SalesDashboardService.php` | KPI penjualan; `forUser` (marketing) / `forCompany` (agregat/filter) |
| Create | `app/Services/AdminDashboardService.php` | Hitungan pekerjaan dokumen admin; nol angka uang |
| Modify | `app/Http/Controllers/DashboardController.php` | Peta role → partial + data |
| Modify | `resources/views/dashboard.blade.php` | Router partial; hapus blok `financial` |
| Rename | `resources/views/dashboard/partials/marketing.blade.php` → `sales.blade.php` | Dashboard marketing (tampilan tak berubah) |
| Create | `resources/views/dashboard/partials/company.blade.php` | Dashboard superadmin/manager |
| Create | `resources/views/dashboard/partials/admin.blade.php` | Dashboard admin |
| Create | `resources/views/dashboard/partials/accounting.blade.php` | Blok kas (dipakai `/dashboard` **dan** `/accounting/dashboard`) |
| Create | `resources/views/dashboard/partials/cash-block.blade.php` | Blok kas ringkas superadmin |
| Create | `resources/views/dashboard/partials/deadline-table.blade.php` | Tabel deadline bersama (marketing/company/production) |
| Create | `public/assets/js/dashboard-charts.js` | Opsi & palet ApexCharts bersama |
| Modify | `resources/views/dashboard/partials/delta.blade.php` | `invertGood` + cap >999% |
| Modify | `resources/views/dashboard/partials/production.blade.php` | Total Selesai, delta, toggle, tabel deadline |
| Modify | `resources/views/accounting/dashboard.blade.php` | Pakai partial `accounting` |
| Create | `tests/Unit/SalesDashboardServiceTest.php` | (rename dari `MarketingDashboardServiceTest.php`) + `forCompany` |
| Create | `tests/Unit/AdminDashboardServiceTest.php` | Hitungan admin |
| Create | `tests/Feature/DashboardRoleRoutingTest.php` | Peta role + uji kebocoran |

---

## Task 1: `delta` sadar arah + cap >999%

**Files:**
- Modify: `resources/views/dashboard/partials/delta.blade.php`
- Modify: `app/Services/MarketingDashboardService.php:82-89` (method `delta`)
- Test: `tests/Unit/MarketingDashboardServiceTest.php`

Dikerjakan lebih dulu karena Task 6 (company) menampilkan **piutang**, dan piutang naik itu buruk. Tanpa ini, piutang membengkak tampil hijau berpanah naik.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Unit/MarketingDashboardServiceTest.php` di dalam class:

```php
    /** @test */
    public function delta_menandai_lonjakan_ekstrem_sebagai_capped(): void
    {
        $m = new \ReflectionMethod($this->svc, 'delta');
        $m->setAccessible(true);

        // 50rb -> 5jt = +9900%: benar tapi tak bermakna dibaca.
        $d = $m->invoke($this->svc, 5_000_000, 50_000);
        $this->assertTrue($d['capped']);
        $this->assertSame('up', $d['dir']);

        // Kenaikan wajar tidak di-cap.
        $d2 = $m->invoke($this->svc, 120, 100);
        $this->assertFalse($d2['capped']);
        $this->assertSame(20.0, $d2['pct']);

        // Pembanding nol tetap "baru" (pct null), bukan capped.
        $d3 = $m->invoke($this->svc, 100, 0);
        $this->assertNull($d3['pct']);
        $this->assertFalse($d3['capped']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=delta_menandai_lonjakan_ekstrem_sebagai_capped`
Expected: FAIL — `Undefined array key "capped"`.

- [ ] **Step 3: Tambahkan `capped` ke `delta()`**

Di `app/Services/MarketingDashboardService.php`, ganti seluruh method `delta()`:

```php
    /** Indikator naik/turun: pct (null bila pembanding 0) + arah + penanda lonjakan ekstrem. */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat', 'capped' => false];
        }
        $pct = round(($current - $previous) / $previous * 100, 1);

        return [
            'pct'    => abs($pct),
            'dir'    => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'capped' => abs($pct) > 999,
        ];
    }
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS — semua test lama tetap hijau.

- [ ] **Step 5: Perbarui partial `delta`**

Ganti seluruh isi `resources/views/dashboard/partials/delta.blade.php`:

```blade
{{-- resources/views/dashboard/partials/delta.blade.php — indikator naik/turun.
     invertGood=true untuk metrik yang naiknya BURUK (mis. piutang). --}}
@php
    $dir    = $delta['dir'] ?? 'flat';
    $invert = $invertGood ?? false;
    $good   = $invert ? ($dir === 'down') : ($dir === 'up');
    $dcls   = $dir === 'flat' ? 'text-muted' : ($good ? 'text-success' : 'text-danger');
    $dic    = $dir === 'up' ? 'arrow-up' : ($dir === 'down' ? 'arrow-down' : 'minus');

    if (! isset($delta['pct']) || $delta['pct'] === null) {
        $dtxt = $dir === 'up' ? 'baru' : '—';
        $dttl = null;
    } elseif ($delta['capped'] ?? false) {
        $dtxt = '>999% vs periode lalu';
        $dttl = $delta['pct'] . '% vs periode lalu';
    } else {
        $dtxt = $delta['pct'] . '% vs periode lalu';
        $dttl = null;
    }
@endphp
<p class="{{ $dcls }} mb-0 mt-2" style="font-size:12px" @if($dttl) title="{{ $dttl }}" @endif>
    <i data-feather="{{ $dic }}" class="icon-sm mb-1"></i> {{ $dtxt }}
</p>
```

- [ ] **Step 6: Jalankan test dashboard yang me-render partial**

Run: `php artisan test --filter=MarketingDashboardTest`
Expected: PASS — partial tetap ter-render (default `invertGood` = false = perilaku lama).

- [ ] **Step 7: Commit**

```bash
git add app/Services/MarketingDashboardService.php resources/views/dashboard/partials/delta.blade.php tests/Unit/MarketingDashboardServiceTest.php
git commit -m "$(printf 'feat(dashboard): delta sadar arah (invertGood) + cap >999%%\n\nPiutang naik itu buruk; tanpa invertGood ia tampil hijau berpanah naik\nsaat masuk dashboard perusahaan.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 2: Rename `MarketingDashboardService` → `SalesDashboardService`

**Files:**
- Rename: `app/Services/MarketingDashboardService.php` → `app/Services/SalesDashboardService.php`
- Rename: `tests/Unit/MarketingDashboardServiceTest.php` → `tests/Unit/SalesDashboardServiceTest.php`
- Modify: `app/Http/Controllers/DashboardController.php:14,45`
- Modify: `tests/Feature/IncomeDefinitionTest.php:12,84`
- Modify: `tests/Feature/FinancialReportTest.php:122`
- Modify: `tests/Feature/TagihanLifecycleTest.php:203`

Rename murni — tanpa perubahan perilaku. Daftar pemakai di atas sudah lengkap (hasil grep); tidak ada pemakai lain di `app/`.

- [ ] **Step 1: Rename berkas dengan git mv**

```bash
git mv app/Services/MarketingDashboardService.php app/Services/SalesDashboardService.php
git mv tests/Unit/MarketingDashboardServiceTest.php tests/Unit/SalesDashboardServiceTest.php
```

- [ ] **Step 2: Ganti nama class di service**

Di `app/Services/SalesDashboardService.php`, ganti baris 2 dan 14:

```php
// app/Services/SalesDashboardService.php
```

```php
class SalesDashboardService
```

- [ ] **Step 3: Ganti nama class di test unit**

Di `tests/Unit/SalesDashboardServiceTest.php` ganti baris 2, 12, 16, 20, 28:

```php
// tests/Unit/SalesDashboardServiceTest.php
```
```php
use App\Services\SalesDashboardService;
```
```php
class SalesDashboardServiceTest extends TestCase
```
```php
    private SalesDashboardService $svc;
```
```php
        $this->svc = new SalesDashboardService();
```

- [ ] **Step 4: Perbarui 4 pemakai**

`app/Http/Controllers/DashboardController.php` baris 14 dan 45:
```php
use App\Services\SalesDashboardService;
```
```php
                'mkt' => app(SalesDashboardService::class)->forUser($user),
```

`tests/Feature/IncomeDefinitionTest.php` baris 12 dan 84:
```php
use App\Services\SalesDashboardService;
```
```php
        $kpi = app(SalesDashboardService::class)->forUser($this->marketing);
```

`tests/Feature/FinancialReportTest.php` baris 122:
```php
        $dash = app(\App\Services\SalesDashboardService::class)->forUser($me);
```

`tests/Feature/TagihanLifecycleTest.php` baris 203:
```php
        $svc = app(\App\Services\SalesDashboardService::class)->forUser($owner);
```

- [ ] **Step 5: Pastikan tak ada referensi tersisa di kode**

Run: `grep -rn "MarketingDashboardService" app/ tests/ resources/ routes/`
Expected: tidak ada keluaran. (Hasil di `docs/` diabaikan — itu catatan sejarah, jangan diubah.)

- [ ] **Step 6: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua — rename murni, tak ada perilaku berubah.

- [ ] **Step 7: Commit**

```bash
git add app/Services/SalesDashboardService.php tests/Unit/SalesDashboardServiceTest.php app/Http/Controllers/DashboardController.php tests/Feature/IncomeDefinitionTest.php tests/Feature/FinancialReportTest.php tests/Feature/TagihanLifecycleTest.php
git commit -m "$(printf 'refactor(dashboard): MarketingDashboardService -> SalesDashboardService\n\nNama lama menyesatkan begitu superadmin ikut memakainya.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 3: Scope jadi parameter — `build(?User)` + `forCompany()`

**Files:**
- Modify: `app/Services/SalesDashboardService.php`
- Test: `tests/Unit/SalesDashboardServiceTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Unit/SalesDashboardServiceTest.php`. Helper `marketing()` dan `orderFor()` sudah ada di berkas ini.

```php
    /** @test */
    public function for_company_menjumlahkan_seluruh_marketing(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();

        $oa = $this->orderFor($a);
        $ob = $this->orderFor($b);
        \App\Models\Payment::factory()->create([
            'order_id' => $oa->id, 'amount' => 1_000_000,
            'status' => 'paid', 'payment_type' => 'dp', 'paid_at' => now(),
        ]);
        \App\Models\Payment::factory()->create([
            'order_id' => $ob->id, 'amount' => 3_000_000,
            'status' => 'paid', 'payment_type' => 'dp', 'paid_at' => now(),
        ]);

        $company = $this->svc->forCompany();

        $this->assertSame(4_000_000, $company['pemasukan_tahun_ini']);
    }

    /** @test */
    public function for_company_dengan_filter_identik_dengan_for_user(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();

        $oa = $this->orderFor($a);
        $ob = $this->orderFor($b);
        \App\Models\Payment::factory()->create([
            'order_id' => $oa->id, 'amount' => 1_000_000,
            'status' => 'paid', 'payment_type' => 'dp', 'paid_at' => now(),
        ]);
        \App\Models\Payment::factory()->create([
            'order_id' => $ob->id, 'amount' => 3_000_000,
            'status' => 'paid', 'payment_type' => 'dp', 'paid_at' => now(),
        ]);

        $filtered = $this->svc->forCompany($a);
        $mine     = $this->svc->forUser($a);

        // Inilah yang mengunci janji "kode yang sama", bukan sekadar mirip.
        $this->assertSame($mine['pemasukan_tahun_ini'], $filtered['pemasukan_tahun_ini']);
        $this->assertSame($mine['jumlah_order_tahun_ini'], $filtered['jumlah_order_tahun_ini']);
        $this->assertSame(1_000_000, $filtered['pemasukan_tahun_ini']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=SalesDashboardServiceTest`
Expected: FAIL — `Call to undefined method App\Services\SalesDashboardService::forCompany()`.

- [ ] **Step 3: Ubah `forUser` jadi `build(?User)` + tambah `forCompany`**

Di `app/Services/SalesDashboardService.php`, ganti tanda tangan `forUser()` (baris 17) dan blok pembuka scope-nya. Ganti dari `public function forUser(User $user): array` sampai baris `$prog = fn () => ...` dengan:

```php
    /** KPI penjualan untuk satu marketing (ter-scope order miliknya). */
    public function forUser(User $user): array
    {
        return $this->build($user);
    }

    /**
     * KPI penjualan tingkat perusahaan.
     * $filter null = seluruh marketing; diisi = satu marketing (hasil identik dengan forUser).
     */
    public function forCompany(?User $filter = null): array
    {
        return $this->build($filter);
    }

    /** $scopeUser null = tanpa filter (seluruh perusahaan) — idiom yang sama dengan Payment::forOrdersOf(). */
    private function build(?User $scopeUser): array
    {
        $uid   = $scopeUser?->id;
        $today = Carbon::today();

        // Uang masuk = definisi kanonik Payment::income() (paid, bukan refund).
        $income = fn () => Payment::income()->forOrdersOf($scopeUser);

        $orders = fn () => Order::query()->when($uid, fn ($q) => $q->where('user_id', $uid));

        $prog = fn () => TitleProgress::query()
            ->when($uid, fn ($q) => $q->whereHas('orderDetail.order', fn ($o) => $o->where('user_id', $uid)));
```

- [ ] **Step 4: Ganti seluruh `Order::where('user_id', $uid)` dengan `$orders()`**

Masih di `build()`, ganti 5 pemakaian (baris ~32-40 pada berkas asal):

```php
        $jmlOrder    = $orders()->whereYear('ordered_at', $today->year)->count();
        $jmlOrderBln = $orders()->whereYear('ordered_at', $today->year)->whereMonth('ordered_at', $today->month)->count();
```

```php
        $jmlOrderPrev    = $orders()->whereBetween('ordered_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->count();
        $jmlOrderBlnPrev = $orders()->whereBetween('ordered_at', [$today->copy()->startOfMonth()->subMonthNoOverflow(), $today->copy()->endOfDay()->subMonthNoOverflow()])->count();
```

- [ ] **Step 5: Perbarui 4 key yang masih menerima `$user`/`$uid`**

Di array yang di-`return` oleh `build()`, ganti keempat baris ini:

```php
            'total_piutang'   => (int) ((new FinancialReportService())->piutang($scopeUser)['kpi']['sisa']),
            'rata_rata_order' => $this->avgOrderValue($uid, $today->year),
            'target'          => $scopeUser
                                    ? app(\App\Services\MarketingTargetService::class)->currentForMarketing($scopeUser)
                                    : null,
```

```php
            'order_trend'            => $this->dailyCount($orders(), 'ordered_at'),
```

```php
            'completion_trend'  => $this->completionTrend($uid),
            'deadline_rows'     => $this->deadlineRows($scopeUser),
```

`FinancialReportService::piutang(?User)` sudah menerima null = seluruh perusahaan (`app/Services/FinancialReportService.php:47-50`) — tak perlu diubah.

- [ ] **Step 6: Longgarkan `avgOrderValue`, `completionTrend`, `deadlineRows` agar menerima null**

Ganti tanda tangan + baris scope ketiga method privat ini:

```php
    /** Rata-rata nilai order (cost_amount) tahun berjalan; 0 bila tanpa order. $uid null = seluruh perusahaan. */
    private function avgOrderValue(?int $uid, int $year): int
    {
        $orders = Order::query()->when($uid, fn ($q) => $q->where('user_id', $uid))
            ->whereYear('ordered_at', $year)->with('details')->get();
```

```php
    /** Penyelesaian naskah per hari 90 hari. $uid null = seluruh perusahaan. */
    private function completionTrend(?int $uid): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->when($uid, fn ($q) => $q->whereHas('titleProgress.orderDetail.order', fn ($o) => $o->where('user_id', $uid)))
```

```php
    /** Baris naskah aktif mendekati/lewat deadline. $scopeUser null = seluruh perusahaan. */
    public function deadlineRows(?User $scopeUser): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return TitleProgress::query()
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->whereNotNull('target_date')
            ->when($scopeUser, fn ($q) => $q->whereHas('orderDetail.order', fn ($o) => $o->where('user_id', $scopeUser->id)))
            ->with('orderDetail.order')
```

- [ ] **Step 7: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=SalesDashboardServiceTest`
Expected: PASS — termasuk dua test baru.

- [ ] **Step 8: Jalankan seluruh suite (regresi marketing)**

Run: `php artisan test`
Expected: PASS semua — `forUser` berperilaku persis seperti sebelumnya.

- [ ] **Step 9: Commit**

```bash
git add app/Services/SalesDashboardService.php tests/Unit/SalesDashboardServiceTest.php
git commit -m "$(printf 'feat(dashboard): SalesDashboardService::forCompany via build(?User)\n\nScope jadi parameter mengikuti idiom repo (null = seluruh perusahaan),\nsehingga definisi Payment::income() tak tergandakan.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 4: Agregasi pindah ke SQL

**Files:**
- Modify: `app/Services/SalesDashboardService.php` (`dailySum`, `dailyCount`, `avgOrderValue`, `deadlineRows`)
- Test: `tests/Unit/SalesDashboardServiceTest.php`

Sebelum ini, `->get()` lalu `->groupBy()` menarik **semua** payment 90 hari dan **semua** order setahun ke memori PHP. Aman saat ter-scope satu marketing; berbahaya begitu `forCompany()` melepas filternya.

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function tren_dan_rata_rata_tidak_menarik_seluruh_baris_ke_php(): void
    {
        $a = $this->marketing();
        $o = $this->orderFor($a);
        \App\Models\Payment::factory()->create([
            'order_id' => $o->id, 'amount' => 500_000,
            'status' => 'paid', 'payment_type' => 'dp', 'paid_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->svc->forCompany();
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $agg = collect($log)->filter(fn ($q) => str_contains(strtolower($q['query']), 'group by'));
        $this->assertGreaterThanOrEqual(3, $agg->count(), 'dailySum/dailyCount/completionTrend harus GROUP BY di SQL');

        $avg = collect($log)->contains(fn ($q) => str_contains(strtolower($q['query']), 'avg('));
        $this->assertTrue($avg, 'avgOrderValue harus AVG di SQL');
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=tren_dan_rata_rata_tidak_menarik_seluruh_baris_ke_php`
Expected: FAIL — agregasi masih di PHP, tak ada `GROUP BY`/`AVG` di query log.

- [ ] **Step 3: Ganti `dailySum` dan `dailyCount` jadi GROUP BY**

```php
    /** Σ kolom per hari 90 hari → {labels, series}. Agregasi di SQL. */
    private function dailySum($query, string $dateCol, string $sumCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw("DATE($dateCol) as d, SUM($sumCol) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Count per hari 90 hari → {labels, series}. Agregasi di SQL. */
    private function dailyCount($query, string $dateCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw("DATE($dateCol) as d, COUNT(*) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
```

- [ ] **Step 4: Ganti `avgOrderValue` jadi AVG lewat join**

`Order::details` adalah `hasOne(OrderDetail)` (`app/Models/Order.php:26-29`); tabelnya `tb_orders` dan `tb_order_details`.

```php
    /** Rata-rata nilai order (cost_amount) tahun berjalan; 0 bila tanpa order. $uid null = seluruh perusahaan. */
    private function avgOrderValue(?int $uid, int $year): int
    {
        $avg = Order::query()
            ->when($uid, fn ($q) => $q->where('tb_orders.user_id', $uid))
            ->whereYear('tb_orders.ordered_at', $year)
            ->leftJoin('tb_order_details', 'tb_order_details.order_id', '=', 'tb_orders.id')
            ->avg(DB::raw('COALESCE(tb_order_details.cost_amount, 0)'));

        return (int) round((float) ($avg ?? 0));
    }
```

Tambahkan import di kepala berkas bila belum ada:

```php
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 5: Ganti `completionTrend` jadi GROUP BY**

```php
    /** Penyelesaian naskah per hari 90 hari. $uid null = seluruh perusahaan. Agregasi di SQL. */
    private function completionTrend(?int $uid): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->when($uid, fn ($q) => $q->whereHas('titleProgress.orderDetail.order', fn ($o) => $o->where('user_id', $uid)))
            ->where('created_at', '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
```

- [ ] **Step 6: Batasi `deadlineRows`**

Di `deadlineRows()`, tambahkan `->orderBy('target_date')->limit(200)` tepat sebelum `->get()`:

```php
            ->with('orderDetail.order')
            ->orderBy('target_date')
            ->limit(200)
            ->get()
```

Tabel deadline dipaginasi DataTables di sisi klien; 200 baris terdekat sudah melampaui apa pun yang berguna dibaca, dan mencegah dashboard perusahaan memuat ribuan baris.

- [ ] **Step 7: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=SalesDashboardServiceTest`
Expected: PASS.

- [ ] **Step 8: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua — angka tak berubah, hanya tempat penghitungannya.

- [ ] **Step 9: Commit**

```bash
git add app/Services/SalesDashboardService.php tests/Unit/SalesDashboardServiceTest.php
git commit -m "$(printf 'perf(dashboard): agregasi harian/rata-rata pindah ke SQL\n\nforCompany melepas filter user_id; get()+groupBy() di PHP akan memuat\nsemua payment 90 hari dan semua order setahun ke memori.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 5: `AdminDashboardService`

**Files:**
- Create: `app/Services/AdminDashboardService.php`
- Test: `tests/Unit/AdminDashboardServiceTest.php`

Hitungan yang dipilih hanya hal yang **admin berwenang mengerjakannya** menurut `routes/web.php`. "Judul menunggu approve" sengaja tidak ada: approve dijaga `role:superadmin|manager` (`routes/web.php:255-258`), jadi angka itu hanya jadi hitungan yang admin tak bisa apa-apakan.

Nilai status yang dipakai (semua diverifikasi dari model):
- `Title::STATUSES` = `draft|menunggu|disetujui|ditolak`; `jenis` = `artikel|buku`
- `TitleDocChecklist` status `diajukan` saat sudah diajukan (`DocChecklistService::submit()`)
- `TitleArchive::STATUSES` = `draft|diajukan|disetujui|ditolak`
- `JournalSubmission::STATUSES` = `submitted|loa|published`
- `BookIsbn::STATUSES` = `pendaftaran|ber_isbn|cetak`
- `Announcement` status + `published_at`

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
// tests/Unit/AdminDashboardServiceTest.php

namespace Tests\Unit;

use App\Models\Announcement;
use App\Models\BookIsbn;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleDocChecklist;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        $this->svc = new AdminDashboardService();
    }

    private function title(array $attrs = []): Title
    {
        return Title::create(array_merge([
            'title'  => 'Judul ' . uniqid(),
            'jenis'  => 'buku',
            'status' => 'disetujui',
            'slug'   => uniqid(),
        ], $attrs));
    }

    /** @test */
    public function menghitung_buku_yang_dokumennya_belum_diajukan(): void
    {
        $sudah = $this->title();
        TitleDocChecklist::create([
            'title_id' => $sudah->id, 'status' => 'diajukan', 'submitted_at' => now(),
        ]);
        $this->title();                          // buku, belum diajukan → dihitung
        $this->title(['jenis' => 'artikel']);    // artikel → tidak pernah punya checklist

        $this->assertSame(1, $this->svc->forAdmin()['doc_belum_lengkap']);
    }

    /** @test */
    public function menghitung_arsip_yang_masih_draft(): void
    {
        TitleArchive::create(['title_id' => $this->title()->id, 'status' => 'draft']);
        TitleArchive::create(['title_id' => $this->title()->id, 'status' => 'diajukan']);

        $d = $this->svc->forAdmin();
        $this->assertSame(1, $d['arsip_menunggu_artefak']);
        $this->assertSame(1, $d['arsip_diajukan']);
    }

    /** @test */
    public function menghitung_submission_jurnal_aktif_dan_isbn_per_status(): void
    {
        $t = $this->title();
        JournalSubmission::create(['title_id' => $t->id, 'status' => 'submitted']);
        JournalSubmission::create(['title_id' => $t->id, 'status' => 'published']);
        BookIsbn::create(['title_id' => $t->id, 'status' => 'pendaftaran']);
        BookIsbn::create(['title_id' => $t->id, 'status' => 'ber_isbn']);

        $d = $this->svc->forAdmin();
        $this->assertSame(1, $d['jurnal_submission_aktif']);  // submitted+loa, bukan published
        $this->assertSame(1, $d['isbn_pendaftaran']);
        $this->assertSame(1, $d['isbn_ber_isbn']);
        $this->assertSame(0, $d['isbn_cetak']);
    }

    /** @test */
    public function menghitung_pengumuman_aktif(): void
    {
        Announcement::create([
            'title' => 'A', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        Announcement::create([
            'title' => 'B', 'body' => 'x', 'status' => 'draft', 'published_at' => null,
        ]);

        $this->assertSame(1, $this->svc->forAdmin()['pengumuman_aktif']);
    }

    /** @test */
    public function tidak_pernah_mengembalikan_angka_uang(): void
    {
        $keys = array_keys($this->svc->forAdmin());
        foreach (['pemasukan', 'income', 'piutang', 'laba', 'saldo', 'komisi'] as $terlarang) {
            foreach ($keys as $k) {
                $this->assertStringNotContainsString($terlarang, $k);
            }
        }
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=AdminDashboardServiceTest`
Expected: FAIL — `Class "App\Services\AdminDashboardService" not found`.

- [ ] **Step 3: Buat service**

```php
<?php
// app/Services/AdminDashboardService.php

namespace App\Services;

use App\Models\Announcement;
use App\Models\BookIsbn;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleDocChecklist;

class AdminDashboardService
{
    /**
     * Hitungan pekerjaan dokumen/data admin.
     * Tanpa satu pun angka uang — admin tidak berwenang atas order/pembayaran.
     */
    public function forAdmin(): array
    {
        $diajukanIds = TitleDocChecklist::where('status', 'diajukan')->pluck('title_id');

        return [
            // Checklist dokumen hanya berlaku untuk buku (TitleDocCheckController abort_unless jenis==='buku').
            'doc_belum_lengkap' => Title::where('jenis', 'buku')
                                    ->whereNotIn('id', $diajukanIds)->count(),

            'arsip_menunggu_artefak' => TitleArchive::where('status', 'draft')->count(),
            'arsip_diajukan'         => TitleArchive::where('status', 'diajukan')->count(),

            // Aktif = belum terbit; 'published' sudah selesai.
            'jurnal_submission_aktif' => JournalSubmission::whereIn('status', ['submitted', 'loa'])->count(),

            'isbn_pendaftaran' => BookIsbn::where('status', 'pendaftaran')->count(),
            'isbn_ber_isbn'    => BookIsbn::where('status', 'ber_isbn')->count(),
            'isbn_cetak'       => BookIsbn::where('status', 'cetak')->count(),

            'pengumuman_aktif' => Announcement::where('status', 'published')
                                    ->whereNotNull('published_at')
                                    ->where('published_at', '<=', now())->count(),
        ];
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=AdminDashboardServiceTest`
Expected: PASS — 5 test hijau.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AdminDashboardService.php tests/Unit/AdminDashboardServiceTest.php
git commit -m "$(printf 'feat(dashboard): AdminDashboardService (pekerjaan dokumen, tanpa angka uang)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 6: Peta role di `DashboardController`

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard.blade.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

Partial `company`, `admin`, `accounting` dibuat kosong-berisi-penanda dulu agar routing bisa diuji lebih dahulu; Task 7-9 mengisinya.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php
// tests/Feature/DashboardRoleRoutingTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardRoleRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->create();
        foreach ($roles as $r) {
            $u->assignRole($r);
        }
        return $u;
    }

    /** @test */
    public function tiap_role_mendapat_dashboard_view_yang_benar(): void
    {
        $peta = [
            'superadmin' => 'company',
            'manager'    => 'company',
            'accounting' => 'accounting',
            'admin'      => 'admin',
            'marketing'  => 'sales',
            'production' => 'production',
        ];

        foreach ($peta as $role => $view) {
            $this->actingAs($this->user($role))->get(route('dashboard'))
                ->assertOk()
                ->assertViewHas('dashboardView', $view);
        }
    }

    /** @test */
    public function role_paling_tinggi_menang_untuk_user_multi_role(): void
    {
        // Superadmin yang juga marketing tetap dapat dashboard superadmin.
        $this->actingAs($this->user('marketing', 'superadmin'))->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardView', 'company');
    }

    /** @test */
    public function user_tanpa_role_tidak_error(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: FAIL — `dashboardView` bernilai `financial` untuk superadmin/manager/accounting/admin.

- [ ] **Step 3: Buat tiga partial penanda**

`resources/views/dashboard/partials/company.blade.php`:
```blade
{{-- resources/views/dashboard/partials/company.blade.php --}}
<h4 class="mb-0">Dashboard Perusahaan</h4>
```

`resources/views/dashboard/partials/admin.blade.php`:
```blade
{{-- resources/views/dashboard/partials/admin.blade.php --}}
<h4 class="mb-0">Dashboard Admin</h4>
```

`resources/views/dashboard/partials/accounting.blade.php`:
```blade
{{-- resources/views/dashboard/partials/accounting.blade.php --}}
<h4 class="mb-0">Dashboard Akuntansi</h4>
```

- [ ] **Step 4: Rename partial marketing → sales**

```bash
git mv resources/views/dashboard/partials/marketing.blade.php resources/views/dashboard/partials/sales.blade.php
```

Ganti baris 1 pada berkas hasil rename:
```blade
{{-- resources/views/dashboard/partials/sales.blade.php --}}
```

- [ ] **Step 5: Tulis ulang `DashboardController::index()`**

Ganti seluruh method `index()` di `app/Http/Controllers/DashboardController.php`:

```php
    public function index(Request $request)
    {
        $user = Auth::user();

        // Peta berurutan prioritas: role pertama yang cocok menang.
        // Menggantikan rentetan if yang tumpang tindih, yang membuat admin dan
        // accounting mendarat di kartu keuangan tanpa pernah dirancang ke sana.
        return match (true) {
            $user->hasRole('superadmin') => $this->company($request, true),
            $user->hasRole('manager')    => $this->company($request, false),
            $user->hasRole('accounting') => $this->accounting(),
            $user->hasRole('admin')      => $this->admin(),
            $user->hasRole('marketing')  => $this->sales($user),
            $user->hasRole('production') => $this->production($user),
            default                      => view('dashboard', ['dashboardView' => 'none']),
        };
    }

    private function sales($user)
    {
        return view('dashboard', [
            'dashboardView' => 'sales',
            'mkt' => app(SalesDashboardService::class)->forUser($user),
        ]);
    }

    private function production($user)
    {
        return view('dashboard', [
            'dashboardView' => 'production',
            'prod' => app(ProductionDashboardService::class)->forUser($user),
            'perf' => app(PerformanceService::class)->forEditor($user),
        ]);
    }

    private function admin()
    {
        return view('dashboard', [
            'dashboardView' => 'admin',
            'adm'    => app(AdminDashboardService::class)->forAdmin(),
            'global' => app(ProductionDashboardService::class)->global(),
        ]);
    }

    private function accounting()
    {
        $year = now()->year;

        return view('dashboard', [
            'dashboardView' => 'accounting',
            'year'  => $year,
            'recap' => app(CashRecapService::class)->monthlyRecap($year),
            'ytd'   => app(CashRecapService::class)->ytd($year),
            'gap'   => app(ExpenseGapService::class)->check($year),
        ]);
    }

    private function company(Request $request, bool $withCash)
    {
        // Id asing / bukan marketing → null = "Semua marketing", bukan error.
        $filter = null;
        if ($request->filled('marketing')) {
            $filter = User::role('marketing')->find((int) $request->query('marketing'));
        }

        $data = [
            'dashboardView' => 'company',
            'mkt'           => app(SalesDashboardService::class)->forCompany($filter),
            'global'        => app(ProductionDashboardService::class)->global(),
            'editors'       => app(PerformanceService::class)->allEditors(),
            'marketers'     => User::role('marketing')->orderBy('name')->get(['id', 'name']),
            'filterId'      => $filter?->id,
            'teamTargets'   => $filter ? collect() : app(MarketingTargetService::class)->adminList('aktif'),
            'cash'          => null,
        ];

        if ($withCash) {
            $data['cash'] = $this->cashSummary();
        }

        return view('dashboard', $data);
    }

    /** Blok kas superadmin. Kegagalan akuntansi tidak boleh menjatuhkan seluruh dashboard. */
    private function cashSummary(): ?array
    {
        try {
            $year = now()->year;
            return [
                'year' => $year,
                'ytd'  => app(CashRecapService::class)->ytd($year),
                'gap'  => app(ExpenseGapService::class)->check($year),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Blok kas dashboard gagal: ' . $e->getMessage());
            return null;
        }
    }
```

- [ ] **Step 6: Perbarui import di `DashboardController`**

Ganti blok `use` di kepala berkas (baris 5-14) dengan:

```php
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ProductionDashboardService;
use App\Services\PerformanceService;
use App\Services\SalesDashboardService;
use App\Services\AdminDashboardService;
use App\Services\MarketingTargetService;
use App\Services\CashRecapService;
use App\Services\ExpenseGapService;
```

Import lama (`Carbon`, `Order`, `Payment`, `PaymentApproval`, `DB`) tak lagi dipakai — seluruh query `financial` hilang bersama method `index()` yang lama.

- [ ] **Step 7: Perbarui router partial di `dashboard.blade.php`**

Ganti baris 10-55 (`@if(...) ... @endif`) dengan:

```blade
@switch($dashboardView ?? 'none')
    @case('production') @include('dashboard.partials.production') @break
    @case('sales')      @include('dashboard.partials.sales') @break
    @case('company')    @include('dashboard.partials.company') @break
    @case('admin')      @include('dashboard.partials.admin') @break
    @case('accounting') @include('dashboard.partials.accounting') @break
    @default
        <div class="card"><div class="card-body">
            <h4 class="mb-1">Dashboard</h4>
            <p class="text-muted mb-0">Belum ada ringkasan untuk akun ini. Hubungi admin bila ini keliru.</p>
        </div></div>
@endswitch
```

Lalu hapus blok `@push('custom-scripts')` di baris 63-109 seluruhnya (skrip `renderChart` milik partial `financial` yang sudah tidak ada). Sisakan `@push('plugin-scripts')` — flatpickr/apexcharts masih dipakai partial lain.

- [ ] **Step 8: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS — 3 test hijau.

- [ ] **Step 9: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua. Bila `MarketingDashboardTest`/`MarketingTargetDashboardTest` merah, penyebabnya partial `sales` — periksa nama include, bukan datanya.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php resources/views/dashboard/partials/ tests/Feature/DashboardRoleRoutingTest.php
git commit -m "$(printf 'feat(dashboard): peta role eksplisit + hapus cabang financial\n\nCabang else lama membuat admin dan accounting mendarat di kartu\nkeuangan tanpa pernah dirancang ke sana.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 7: Opsi chart bersama + tabel deadline bersama

**Files:**
- Create: `public/assets/js/dashboard-charts.js`
- Create: `resources/views/dashboard/partials/deadline-table.blade.php`
- Modify: `resources/views/dashboard/partials/sales.blade.php`

Sekarang tiap partial menulis hex-nya sendiri (`#6571ff`, `#05a34a`, `#fbbc06`, `#ff3366` diulang di marketing dan production). Dengan enam dashboard itu dijamin melenceng.

- [ ] **Step 1: Buat berkas JS bersama**

```js
// public/assets/js/dashboard-charts.js
// Palet & opsi ApexCharts bersama untuk seluruh dashboard.
// "Hijau" harus berarti hal yang sama di mana pun.
window.SimapaCharts = (function () {
    var PALETTE = {
        primary: '#6571ff',
        success: '#05a34a',
        warning: '#fbbc06',
        danger:  '#ff3366',
        dark:    '#0c1427',
        info:    '#0dcaf0',
    };

    function rp(v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); }

    // Batasi jumlah label sumbu-X biar tidak "semut" di rentang panjang.
    function tickFor(n) { return n <= 14 ? n : (n <= 31 ? 10 : 12); }

    function slice(o, n) { return { labels: o.labels.slice(-n), series: o.series.slice(-n) }; }

    function isEmpty(series) {
        return !series || series.length === 0 || series.every(function (v) { return !v; });
    }

    function emptyState(el, msg) {
        el.innerHTML = '<div class="text-center text-muted py-5" style="font-size:13px">'
            + (msg || 'Belum ada data') + '</div>';
    }

    function area(name, d, color, isCurrency) {
        return {
            chart: { type: 'area', height: 240, toolbar: { show: false } },
            series: [{ name: name, data: d.series }],
            xaxis: {
                categories: d.labels,
                tickAmount: tickFor(d.series.length),
                tickPlacement: 'on',
                labels: { rotate: -45, rotateAlways: false, hideOverlappingLabels: true, trim: false, style: { fontSize: '11px' } },
                axisTicks: { show: false },
            },
            yaxis: { labels: { formatter: function (v) { return isCurrency ? Number(v).toLocaleString('id-ID') : Math.round(v); } } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            colors: [color],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
            markers: { size: 0, hover: { size: 5 } },
            grid: { borderColor: '#f1f1f1', strokeDashArray: 4, padding: { left: 8, right: 8 } },
            tooltip: isCurrency ? { y: { formatter: rp } } : {},
        };
    }

    function donut(data, totalLabel) {
        return {
            chart: { type: 'donut', height: 260 },
            series: data.series,
            labels: data.labels,
            colors: [PALETTE.primary, PALETTE.success, PALETTE.warning, PALETTE.danger, PALETTE.info, PALETTE.dark],
            legend: { position: 'bottom' },
            dataLabels: { enabled: true, formatter: function (v) { return Math.round(v) + '%'; } },
            plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: totalLabel || 'Total' } } } } },
        };
    }

    /** Render dengan penjagaan keadaan kosong. Mengembalikan chart atau null. */
    function render(selector, opts, series) {
        var el = document.querySelector(selector);
        if (!el) return null;
        if (isEmpty(series)) { emptyState(el); return null; }
        var c = new ApexCharts(el, opts);
        c.render();
        return c;
    }

    /** Pasang toggle 7/30/90 pada sekumpulan chart area. */
    function rangeToggle(toggleSelector, charts, full) {
        document.querySelectorAll(toggleSelector + ' [data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var n = +this.dataset.range;
                charts.forEach(function (pair) {
                    if (!pair.chart) return;
                    var s = slice(full[pair.key], n);
                    pair.chart.updateOptions({
                        xaxis: { categories: s.labels, tickAmount: tickFor(s.series.length) },
                        series: [{ data: s.series }],
                    });
                });
                document.querySelectorAll(toggleSelector + ' [data-range]').forEach(function (b) {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary');
                this.classList.add('btn-primary', 'active');
            });
        });
    }

    return {
        PALETTE: PALETTE, rp: rp, slice: slice, tickFor: tickFor,
        area: area, donut: donut, render: render, rangeToggle: rangeToggle,
        isEmpty: isEmpty, emptyState: emptyState,
    };
})();
```

- [ ] **Step 2: Buat partial tabel deadline bersama**

Menerima `$rows` (bentuk `SalesDashboardService::deadlineRows()`) dan `$tableId`.

```blade
{{-- resources/views/dashboard/partials/deadline-table.blade.php
     Butuh: $rows (collection dari deadlineRows()), $tableId (unik per halaman). --}}
@php $tid = $tableId ?? 'deadlineTable'; @endphp

<div class="col-12 grid-margin stretch-card">
    <div class="card"><div class="card-body">
        @if($rows->isEmpty())
            <p class="text-muted mb-0">Belum ada naskah dengan target tanggal.</p>
        @else
            <ul class="nav nav-pills mb-3" id="{{ $tid }}Tabs">
                <li class="nav-item"><a class="nav-link active" href="#" data-bucket="all">Semua</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="overdue">Lewat target</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="d7">≤7 hari</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="month">Bulan ini</a></li>
            </ul>
            <div class="table-responsive">
                <table class="table table-hover" id="{{ $tid }}" style="width:100%">
                    <thead>
                        <tr>
                            <th>Judul</th><th>Kode Order</th><th>Tahap</th>
                            <th>Target</th><th>Sisa Hari</th><th>Prioritas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr data-overdue="{{ $r['overdue'] }}" data-d7="{{ $r['d7'] }}" data-month="{{ $r['month'] }}">
                                <td>{{ $r['title'] }}</td>
                                <td><a href="{{ route('order.indexJudul.progress', $r['order_detail_id']) }}">{{ $r['code_order'] }}</a></td>
                                <td><span class="badge bg-secondary">{{ $r['stage'] }}</span></td>
                                <td data-order="{{ $r['target_date'] }}">{{ $r['target_label'] }}</td>
                                <td data-order="{{ $r['days'] }}">
                                    <span class="badge {{ $r['overdue'] ? 'bg-danger' : ($r['d7'] ? 'bg-warning' : 'bg-light text-dark') }}">{{ $r['days_label'] }}</span>
                                </td>
                                <td>
                                    @php $pc = ['low' => 'bg-secondary', 'normal' => 'bg-info', 'high' => 'bg-danger'][$r['priority']] ?? 'bg-secondary'; @endphp
                                    <span class="badge {{ $pc }}">{{ $r['priority'] ? ucfirst($r['priority']) : '-' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div></div>
</div>

@push('custom-scripts')
<script>
$(function () {
    if (!$.fn.DataTable || !document.getElementById('{{ $tid }}')) return;
    var bucket = 'all';
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== '{{ $tid }}') return true;
        if (bucket === 'all') return true;
        return settings.aoData[dataIndex].nTr.getAttribute('data-' + bucket) === '1';
    });
    var table = $('#{{ $tid }}').DataTable({ pageLength: 10, order: [[4, 'asc']] });
    $('#{{ $tid }}_wrapper .dataTables_length select, #{{ $tid }}_wrapper .dataTables_filter input').addClass('form-control mb-2');
    $('#{{ $tid }}Tabs a').on('click', function (e) {
        e.preventDefault();
        $('#{{ $tid }}Tabs a').removeClass('active');
        $(this).addClass('active');
        bucket = $(this).data('bucket');
        table.draw();
    });
});
</script>
@endpush
```

- [ ] **Step 3: Pakai partial bersama di `sales.blade.php`**

Di `resources/views/dashboard/partials/sales.blade.php`, ganti blok "Naskah Mendekati Deadline" (baris ~189-228, dari `<h6 ...>Naskah Mendekati Deadline</h6>` sampai `</div>` penutup `.row`) dengan:

```blade
<h6 class="text-muted mb-2 mt-2">Naskah Mendekati Deadline</h6>
<div class="row">
    @include('dashboard.partials.deadline-table', ['rows' => $mkt['deadline_rows'], 'tableId' => 'salesDeadline'])
</div>
```

Lalu hapus blok `$(function () { ... })` untuk DataTables di bagian `@push('custom-scripts')` (baris ~302-321) — sudah pindah ke partial bersama.

- [ ] **Step 4: Muat JS bersama di `sales.blade.php`**

Di `@push('plugin-scripts')`, tambahkan setelah baris apexcharts:

```blade
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
```

- [ ] **Step 5: Jalankan test marketing**

Run: `php artisan test --filter="MarketingDashboardTest|MarketingTargetDashboardTest"`
Expected: PASS — tampilan marketing tak berubah.

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/dashboard-charts.js resources/views/dashboard/partials/deadline-table.blade.php resources/views/dashboard/partials/sales.blade.php
git commit -m "$(printf 'refactor(dashboard): opsi chart + tabel deadline jadi komponen bersama\n\nHex warna terulang di tiap partial; dengan enam dashboard itu dijamin\nmelenceng.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 8: Partial `company` + filter marketing + Target Tim

**Files:**
- Modify: `resources/views/dashboard/partials/company.blade.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

`MarketingTargetService::adminList('aktif')` sudah mengembalikan baris berisi `name`, `target`, `realisasi`, `capaian_persen`, `komisi`, `status`, `start_date`, `end_date` (`app/Services/MarketingTargetService.php:87-103`) — persis isi tabel Target Tim.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/DashboardRoleRoutingTest.php`:

```php
    /** @test */
    public function superadmin_melihat_tabel_target_tim_dan_dropdown_filter(): void
    {
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'Marketing Satu']);
        \App\Models\MarketingTarget::create([
            'user_id' => $mkt->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10_000_000, 'commission_rate' => 5,
        ]);

        $this->actingAs($this->user('superadmin'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target Tim')
            ->assertSee('Marketing Satu')
            ->assertSee('Semua marketing');
    }

    /** @test */
    public function filter_marketing_asing_jatuh_ke_semua(): void
    {
        $this->actingAs($this->user('superadmin'))->get(route('dashboard', ['marketing' => 999999]))
            ->assertOk()
            ->assertViewHas('filterId', null);
    }

    /** @test */
    public function filter_menolak_id_user_yang_bukan_marketing(): void
    {
        $prod = $this->user('production');

        $this->actingAs($this->user('superadmin'))->get(route('dashboard', ['marketing' => $prod->id]))
            ->assertOk()
            ->assertViewHas('filterId', null);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: FAIL — partial `company` masih penanda, teks "Target Tim" tak ada.

- [ ] **Step 3: Tulis partial `company`**

Ganti seluruh isi `resources/views/dashboard/partials/company.blade.php`:

```blade
{{-- resources/views/dashboard/partials/company.blade.php --}}
@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Perusahaan</h4>
    <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-center">
        <label class="text-muted me-2 mb-0" style="font-size:13px">Marketing</label>
        <select name="marketing" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua marketing</option>
            @foreach($marketers as $m)
                <option value="{{ $m->id }}" @selected($filterId === $m->id)>{{ $m->name }}</option>
            @endforeach
        </select>
    </form>
</div>

<h6 class="text-muted mb-2">Ringkasan Pemasukan</h6>
<div class="row">
    @php
        $income = [
            ['Pemasukan Hari Ini', $mkt['pemasukan_hari_ini'], 'success', 'dollar-sign', $mkt['pemasukan_hari_ini_delta']],
            ['Pemasukan Minggu Ini', $mkt['pemasukan_minggu_ini'], 'primary', 'calendar', $mkt['pemasukan_minggu_ini_delta']],
            ['Pemasukan Tahun Ini', $mkt['pemasukan_tahun_ini'], 'info', 'trending-up', $mkt['pemasukan_tahun_ini_delta']],
        ];
    @endphp
    @foreach($income as [$label, $val, $tone, $icon, $delta])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format($val, 0, ',', '.') }}</h4>
                    </div>
                    <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                        <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                    </div>
                </div>
                @include('dashboard.partials.delta', ['delta' => $delta])
            </div></div>
        </div>
    @endforeach
</div>

<h6 class="text-muted mb-2 mt-2">Target Tim</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            @if($filterId && $mkt['target'])
                @php
                    $t = $mkt['target'];
                    $tcls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                    <span>Capaian: <strong>{{ $t['capaian_persen'] }}%</strong></span>
                    <span class="text-muted">Periode: <strong>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</strong></span>
                </div>
                <div class="progress mb-3" style="height:18px">
                    <div class="progress-bar {{ $tcls }}" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="row text-center">
                    <div class="col-md-3"><small class="text-muted d-block">Target</small><strong>Rp {{ number_format($t['target'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Realisasi</small><strong class="text-primary">Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Sisa</small><strong class="{{ $t['sisa'] > 0 ? 'text-danger' : 'text-success' }}">Rp {{ number_format($t['sisa'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Komisi</small><strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></div>
                </div>
            @elseif($filterId)
                <p class="text-muted mb-0">Marketing ini tidak punya target berjalan.</p>
            @elseif($teamTargets->isEmpty())
                <p class="text-muted mb-0">Belum ada target berjalan untuk tim.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover" id="teamTargetTable" style="width:100%">
                        <thead>
                            <tr>
                                <th>Marketing</th><th>Periode</th><th>Target</th>
                                <th>Realisasi</th><th>Capaian</th><th>Komisi</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($teamTargets as $t)
                                @php
                                    $badge = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                                @endphp
                                <tr>
                                    <td>{{ $t['name'] }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</td>
                                    <td>Rp {{ number_format($t['target'], 0, ',', '.') }}</td>
                                    <td>Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</td>
                                    <td data-order="{{ $t['capaian_persen'] }}"><span class="badge {{ $badge }}">{{ $t['capaian_persen'] }}%</span></td>
                                    <td>Rp {{ number_format($t['komisi'], 0, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $t['commission_paid'] ? 'bg-success' : ($t['tertunggak'] ? 'bg-danger' : 'bg-secondary') }}">
                                            {{ $t['commission_paid'] ? 'Komisi dibayar' : ($t['tertunggak'] ? 'Tertunggak' : 'Berjalan') }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Statistik Order &amp; Tagihan</h6>
<div class="row">
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (bulan ini)</h6>
            <h4 class="mt-2 mb-0 text-primary">{{ $mkt['jumlah_order_bulan_ini'] }}</h4>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_bulan_ini_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
            <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Total Piutang</h6>
            <h4 class="mt-2 mb-0 text-warning">Rp {{ number_format($mkt['total_piutang'], 0, ',', '.') }}</h4>
            <small class="text-muted mt-2 d-block">Sisa tagihan order belum lunas</small>
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Rata-rata Nilai Order</h6>
            <h4 class="mt-2 mb-0 text-dark">Rp {{ number_format($mkt['rata_rata_order'], 0, ',', '.') }}</h4>
            <small class="text-muted mt-2 d-block">Tahun ini</small>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-2">
    <h6 class="text-muted mb-0">Traffic</h6>
    <div class="btn-group btn-group-sm" id="coRangeToggle">
        <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
        <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
        <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
    </div>
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan</h6><div id="coIncomeChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order</h6><div id="coOrderChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Produksi Global</h6>
@include('dashboard.partials.progress-global')

<h6 class="text-muted mb-2 mt-2">Naskah Mendekati Deadline</h6>
<div class="row">
    @include('dashboard.partials.deadline-table', ['rows' => $mkt['deadline_rows'], 'tableId' => 'coDeadline'])
</div>

@if($cash)
    @include('dashboard.partials.cash-block')
@elseif(auth()->user()->hasRole('superadmin'))
    <div class="card grid-margin"><div class="card-body">
        <h6 class="card-title mb-1">Kas</h6>
        <p class="text-muted mb-0">Data kas tidak tersedia saat ini.</p>
    </div></div>
@endif

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var C = window.SimapaCharts;
    var full = {
        inc: { labels: @json($mkt['income_trend']['labels']), series: @json($mkt['income_trend']['series']) },
        ord: { labels: @json($mkt['order_trend']['labels']),  series: @json($mkt['order_trend']['series']) },
    };
    var n0 = 30;
    var si = C.slice(full.inc, n0), so = C.slice(full.ord, n0);
    var inc = C.render('#coIncomeChart', C.area('Pemasukan', si, C.PALETTE.success, true), si.series);
    var ord = C.render('#coOrderChart',  C.area('Order', so, C.PALETTE.primary, false), so.series);
    C.rangeToggle('#coRangeToggle', [{ chart: inc, key: 'inc' }, { chart: ord, key: 'ord' }], full);
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

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS. Test `superadmin_melihat_tabel_target_tim_dan_dropdown_filter` masih akan gagal pada blok kas — lanjut ke Task 9 bila pesannya menyebut `cash-block`; bila tidak, perbaiki di sini.

- [ ] **Step 5: Commit**

```bash
git add resources/views/dashboard/partials/company.blade.php tests/Feature/DashboardRoleRoutingTest.php
git commit -m "$(printf 'feat(dashboard): partial company + filter per marketing + Target Tim\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 9: Blok kas superadmin + uji kebocoran ke manager

**Files:**
- Create: `resources/views/dashboard/partials/cash-block.blade.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

Route akuntansi dijaga `role:superadmin|accounting` (`routes/web.php:318`). Dashboard tidak boleh membocorkan apa yang route-nya tutup.

- [ ] **Step 1: Tulis test kebocoran yang gagal**

```php
    /** @test */
    public function manager_tidak_menerima_data_kas_sama_sekali(): void
    {
        $this->actingAs($this->user('manager'))->get(route('dashboard'))
            ->assertOk()
            // Bukan sekadar tersembunyi CSS — datanya memang tidak diambil.
            ->assertViewHas('cash', null)
            ->assertDontSee('Saldo Akhir')
            ->assertDontSee('Laba Tahun Berjalan');
    }

    /** @test */
    public function superadmin_menerima_blok_kas(): void
    {
        $res = $this->actingAs($this->user('superadmin'))->get(route('dashboard'))->assertOk();

        $this->assertNotNull($res->viewData('cash'));
        $res->assertSee('Saldo Akhir')->assertSee('Laba Tahun Berjalan');
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter="manager_tidak_menerima_data_kas_sama_sekali|superadmin_menerima_blok_kas"`
Expected: FAIL pada `superadmin_menerima_blok_kas` — partial `cash-block` belum ada.

- [ ] **Step 3: Buat partial blok kas**

`CashRecapService::ytd($year)` mengembalikan `totalIn`, `totalOut`, `laba`, `saldoAkhir` (dipakai persis begitu di `AccountingDashboardController::exportCsv`).

```blade
{{-- resources/views/dashboard/partials/cash-block.blade.php — superadmin saja.
     Butuh: $cash = ['year' => int, 'ytd' => [...], 'gap' => [...]] --}}
<h6 class="text-muted mb-2 mt-2">Kas &amp; Laba ({{ $cash['year'] }})</h6>
<div class="row">
    @php
        $cards = [
            ['Saldo Akhir', $cash['ytd']['saldoAkhir'], 'primary', 'credit-card'],
            ['Laba Tahun Berjalan', $cash['ytd']['laba'], 'success', 'trending-up'],
            ['Pemasukan YTD', $cash['ytd']['totalIn'], 'info', 'arrow-down-circle'],
            ['Pengeluaran YTD', $cash['ytd']['totalOut'], 'warning', 'arrow-up-circle'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone, $icon])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format((int) $val, 0, ',', '.') }}</h4>
                    </div>
                    <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                        <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                    </div>
                </div>
            </div></div>
        </div>
    @endforeach
</div>

@if(! empty($cash['gap']['warn']))
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap">
        <span>{{ $cash['gap']['message'] ?? 'Ada celah pengeluaran yang perlu diperiksa.' }}</span>
        <a href="{{ route('accounting.journal') }}" class="btn btn-sm btn-outline-dark">Buka Jurnal Kas</a>
    </div>
@else
    <div class="text-end grid-margin">
        <a href="{{ route('accounting.journal') }}" class="small">Buka Jurnal Kas →</a>
    </div>
@endif
```

- [ ] **Step 4: Selaraskan kunci `gap` dengan `ExpenseGapService`**

Buka `app/Services/ExpenseGapService.php` dan periksa kunci yang dikembalikan `check()`. Bila bukan `warn`/`message`, sesuaikan `cash-block.blade.php` ke kunci yang sebenarnya — jangan sebaliknya. Bandingkan dengan pemakaian di `resources/views/accounting/dashboard.blade.php` yang sudah menampilkan `$gap`.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS — seluruh test routing hijau, termasuk uji kebocoran manager.

- [ ] **Step 6: Commit**

```bash
git add resources/views/dashboard/partials/cash-block.blade.php tests/Feature/DashboardRoleRoutingTest.php
git commit -m "$(printf 'feat(dashboard): blok kas superadmin + uji kebocoran ke manager\n\nRoute akuntansi menutup manager; dashboard tak boleh membocorkan\napa yang route-nya tutup. Datanya tak diambil, bukan disembunyikan CSS.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 10: Partial `admin` + uji tanpa angka uang

**Files:**
- Modify: `resources/views/dashboard/partials/admin.blade.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function admin_melihat_papan_dokumen_tanpa_angka_uang(): void
    {
        $res = $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk();

        $res->assertSee('Dokumen Belum Lengkap')
            ->assertSee('Arsip Menunggu Artefak')
            ->assertSee('Pengumuman Aktif');

        // Cacat asal yang diperbaiki spec ini: admin tak pernah lagi melihat angka uang.
        $res->assertDontSee('Total Pemasukan')
            ->assertDontSee('Total Piutang')
            ->assertDontSee('Rp ');

        $this->assertNull($res->viewData('mkt') ?? null);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=admin_melihat_papan_dokumen_tanpa_angka_uang`
Expected: FAIL — partial `admin` masih penanda.

- [ ] **Step 3: Tulis partial `admin`**

```blade
{{-- resources/views/dashboard/partials/admin.blade.php
     Papan kerja dokumen/data. Tanpa angka uang: admin tidak berwenang atas order/pembayaran.
     "Judul menunggu approve" sengaja tidak ada — approve dijaga role:superadmin|manager,
     jadi angka itu hanya jadi hitungan yang admin tak bisa apa-apakan. --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Admin</h4>
</div>

<h6 class="text-muted mb-2">Pekerjaan Dokumen</h6>
<div class="row">
    @php
        $cards = [
            ['Dokumen Belum Lengkap', $adm['doc_belum_lengkap'], 'danger', 'file-text', route('title.index')],
            ['Arsip Menunggu Artefak', $adm['arsip_menunggu_artefak'], 'warning', 'archive', route('archive.index')],
            ['Arsip Diajukan', $adm['arsip_diajukan'], 'info', 'send', route('archive.index')],
            ['Submission Jurnal Aktif', $adm['jurnal_submission_aktif'], 'primary', 'book-open', route('journal.index')],
            ['Pengumuman Aktif', $adm['pengumuman_aktif'], 'success', 'bell', route('announcement.index')],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone, $icon, $url])
        <div class="col-md-4 col-xl grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                    <i data-feather="{{ $icon }}" class="icon-sm text-{{ $tone }}"></i>
                </div>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                <a href="{{ $url }}" class="small">Buka →</a>
            </div></div>
        </div>
    @endforeach
</div>

<h6 class="text-muted mb-2 mt-2">Registri ISBN</h6>
<div class="row">
    @php
        $isbn = [
            ['Pendaftaran', $adm['isbn_pendaftaran'], 'warning'],
            ['Ber-ISBN', $adm['isbn_ber_isbn'], 'primary'],
            ['Cetak/Terbit', $adm['isbn_cetak'], 'success'],
        ];
    @endphp
    @foreach($isbn as [$label, $val, $tone])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
    <div class="col-12 text-end grid-margin">
        <a href="{{ route('isbn.index') }}" class="small">Buka Direktori ISBN →</a>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Produksi Global</h6>
<div class="row">
    @php
        $prodCards = [
            ['Naskah Dalam Produksi', $global['total_in_production'], 'primary'],
            ['Lewat Target', $global['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $global['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $global['selesai_bulan_ini'], 'success'],
        ];
    @endphp
    @foreach($prodCards as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah per Tahap</h6>
            <div id="admStageChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var C = window.SimapaCharts;
    var d = { labels: @json($global['per_stage']['labels']), series: @json($global['per_stage']['series']) };
    C.render('#admStageChart', C.donut(d, 'Dalam Produksi'), d.series);
});
</script>
@endpush
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=DashboardRoleRoutingTest`
Expected: PASS. Bila `assertDontSee('Rp ')` merah, cari sumbernya di `layouts/master` atau partial announcements/deadlines — bila memang dari luar partial admin, longgarkan assertion itu jadi `assertDontSee('Total Pemasukan')` saja dan catat alasannya di komentar test.

- [ ] **Step 5: Commit**

```bash
git add resources/views/dashboard/partials/admin.blade.php tests/Feature/DashboardRoleRoutingTest.php
git commit -m "$(printf 'feat(dashboard): papan dokumen admin (tanpa angka uang)\n\nAdmin sebelumnya melihat Total Pemasukan perusahaan lewat cabang else.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 11: Partial `accounting` + pakai ulang di halaman akuntansi

**Files:**
- Modify: `resources/views/dashboard/partials/accounting.blade.php`
- Modify: `resources/views/accounting/dashboard.blade.php`
- Test: `tests/Feature/DashboardRoleRoutingTest.php`

Satu partial dipakai `/dashboard` **dan** `/accounting/dashboard`, jadi tidak ada dua versi yang bisa berbeda.

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function accounting_mendapat_rekap_kas_di_dashboard_utama(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardView', 'accounting')
            ->assertViewHas('recap')
            ->assertViewHas('ytd');
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=accounting_mendapat_rekap_kas_di_dashboard_utama`
Expected: FAIL — partial `accounting` masih penanda (routing sudah benar sejak Task 6, tapi partial belum menampilkan apa pun).

- [ ] **Step 3: Baca view akuntansi yang ada**

Run: `cat resources/views/accounting/dashboard.blade.php`

Catat blok mana yang murni menampilkan `$recap`/`$ytd`/`$gap` (itu yang pindah ke partial) dan mana yang khusus halaman (judul halaman, pemilih tahun, tombol ekspor — itu **tetap** di halaman).

- [ ] **Step 4: Pindahkan blok tampilan ke partial**

Isi `resources/views/dashboard/partials/accounting.blade.php` dengan blok tampilan `$recap`/`$ytd`/`$gap` hasil Step 3, apa adanya. Jangan mendesain ulang — ini ekstraksi, bukan penulisan ulang. Partial menerima `$year`, `$recap`, `$ytd`, `$gap`; ketiganya sudah dioper `DashboardController::accounting()` (Task 6) dengan nama yang sama seperti `AccountingDashboardController::index()`.

Tambahkan tautan ekspor di kaki partial:

```blade
<div class="text-end grid-margin">
    <a href="{{ route('accounting.recap.export.csv', ['year' => $year]) }}" class="btn btn-sm btn-outline-secondary">Ekspor CSV</a>
    <a href="{{ route('accounting.recap.export.pdf', ['year' => $year]) }}" class="btn btn-sm btn-outline-secondary">Ekspor PDF</a>
    <a href="{{ route('accounting.journal') }}" class="btn btn-sm btn-outline-primary">Jurnal Kas</a>
</div>
```

- [ ] **Step 5: Buat halaman akuntansi memakai partial yang sama**

Di `resources/views/accounting/dashboard.blade.php`, ganti blok tampilan yang tadi dipindah dengan:

```blade
@include('dashboard.partials.accounting')
```

Pertahankan judul halaman, pemilih tahun, dan `$periodeLabel` yang khusus halaman itu.

- [ ] **Step 6: Jalankan test akuntansi + routing**

Run: `php artisan test --filter="AccountingDashboardTest|ExpenseGapTest|DashboardRoleRoutingTest"`
Expected: PASS — halaman lama tetap utuh karena datanya sama, hanya tempat blade-nya bergeser.

- [ ] **Step 7: Commit**

```bash
git add resources/views/dashboard/partials/accounting.blade.php resources/views/accounting/dashboard.blade.php tests/Feature/DashboardRoleRoutingTest.php
git commit -m "$(printf 'feat(dashboard): accounting pakai partial kas di dashboard utama\n\nSatu partial dipakai /dashboard dan /accounting/dashboard, jadi tak ada\ndua versi yang bisa berbeda.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 12: Produksi disamakan dengan marketing

**Files:**
- Modify: `app/Services/ProductionDashboardService.php`
- Modify: `resources/views/dashboard/partials/production.blade.php`
- Modify: `app/Http/Controllers/DashboardController.php` (method `production`)
- Test: `tests/Unit/ProductionDashboardServiceTest.php`

Produksi kehilangan tabel deadline yang marketing punya — padahal deadline justru pekerjaan produksi.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Unit/ProductionDashboardServiceTest.php`:

```php
    /** @test */
    public function for_user_menyertakan_total_selesai_dan_baris_deadline(): void
    {
        $editor = User::factory()->create();
        $tp = $this->naskah(['assigned_user_id' => $editor->id, 'status' => 'editing',
                             'target_date' => now()->addDays(3)->toDateString()]);
        $this->naskah(['assigned_user_id' => $editor->id, 'status' => 'terbit',
                       'started_at' => now()->subMonths(6)]);

        $d = app(ProductionDashboardService::class)->forUser($editor);

        $this->assertSame(1, $d['total_selesai']);
        $this->assertCount(1, $d['deadline_rows']);
        $this->assertSame($tp->order_detail_id, $d['deadline_rows']->first()['order_detail_id']);
    }
```

Bila helper `naskah()` belum ada di berkas test ini, salin bentuknya dari `tests/Unit/SalesDashboardServiceTest.php`.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=for_user_menyertakan_total_selesai_dan_baris_deadline`
Expected: FAIL — `Undefined array key "total_selesai"`.

- [ ] **Step 3: Tambahkan dua key + delta di `ProductionDashboardService::forUser`**

Di `app/Services/ProductionDashboardService.php`, tambahkan di array yang di-`return` oleh `forUser()`, setelah baris `'selesai_bulan_ini' => ...`:

```php
            'total_selesai'     => TitleProgress::where('assigned_user_id', $user->id)
                                    ->whereIn('status', TitleProgress::FINAL_STAGES)->count(),
            'selesai_bulan_ini_delta' => $this->delta(
                                    TitleProgress::where('assigned_user_id', $user->id)
                                        ->whereIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereYear('started_at', $today->year)
                                        ->whereMonth('started_at', $today->month)->count(),
                                    TitleProgress::where('assigned_user_id', $user->id)
                                        ->whereIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereBetween('started_at', [
                                            $today->copy()->startOfMonth()->subMonthNoOverflow(),
                                            $today->copy()->endOfDay()->subMonthNoOverflow(),
                                        ])->count()
                                   ),
            'deadline_rows'     => app(SalesDashboardService::class)->deadlineRowsForEditor($user),
```

Tambahkan method `delta()` privat (bentuknya sama dengan `SalesDashboardService`, termasuk `capped`, agar partial `delta` menerima kontrak yang sama):

```php
    /** Indikator naik/turun: pct (null bila pembanding 0) + arah + penanda lonjakan ekstrem. */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat', 'capped' => false];
        }
        $pct = round(($current - $previous) / $previous * 100, 1);

        return [
            'pct'    => abs($pct),
            'dir'    => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'capped' => abs($pct) > 999,
        ];
    }
```

Tambahkan import:
```php
use App\Services\SalesDashboardService;
```

- [ ] **Step 4: Tambahkan `deadlineRowsForEditor` di `SalesDashboardService`**

`deadlineRows()` men-scope lewat `order.user_id` (kepemilikan marketing); produksi men-scope lewat `assigned_user_id`. Bentuk barisnya identik, jadi pemetaannya dipakai ulang. Di `app/Services/SalesDashboardService.php`, ubah `deadlineRows()` agar mendelegasikan ke satu builder:

```php
    /** Baris naskah aktif mendekati/lewat deadline. $scopeUser null = seluruh perusahaan. */
    public function deadlineRows(?User $scopeUser): \Illuminate\Support\Collection
    {
        return $this->deadlineFrom(
            TitleProgress::query()->when($scopeUser,
                fn ($q) => $q->whereHas('orderDetail.order', fn ($o) => $o->where('user_id', $scopeUser->id)))
        );
    }

    /** Baris deadline milik satu editor (scope assigned_user_id, bukan kepemilikan order). */
    public function deadlineRowsForEditor(User $editor): \Illuminate\Support\Collection
    {
        return $this->deadlineFrom(
            TitleProgress::query()->where('assigned_user_id', $editor->id)
        );
    }

    private function deadlineFrom($query): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return $query
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->whereNotNull('target_date')
            ->with('orderDetail.order')
            ->orderBy('target_date')
            ->limit(200)
            ->get()
            ->map(function (TitleProgress $tp) use ($today) {
                $target  = Carbon::parse($tp->target_date)->startOfDay();
                $overdue = $target->lt($today);
                $days    = $today->diffInDays($target);
                $signed  = $overdue ? -$days : $days;
                $isD7    = ! $overdue && $target->lte($today->copy()->addDays(7));
                $isMonth = ! $overdue && $target->lte($today->copy()->endOfMonth());

                return [
                    'order_detail_id' => $tp->order_detail_id,
                    'title'        => $tp->orderDetail->title,
                    'code_order'   => $tp->orderDetail->order->code_order,
                    'stage'        => Str::title(str_replace('_', ' ', $tp->status)),
                    'target_date'  => $target->format('Y-m-d'),
                    'target_label' => $target->format('d M Y'),
                    'days'         => $signed,
                    'days_label'   => $overdue
                        ? 'Lewat ' . $days . ' hari'
                        : ($days === 0 ? 'Hari ini' : $days . ' hari lagi'),
                    'priority'     => $tp->priority,
                    'overdue'      => $overdue ? 1 : 0,
                    'd7'           => $isD7 ? 1 : 0,
                    'month'        => $isMonth ? 1 : 0,
                ];
            })
            ->sortBy('days')
            ->values();
    }
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="ProductionDashboardServiceTest|SalesDashboardServiceTest"`
Expected: PASS.

- [ ] **Step 6: Tambahkan kartu, delta, toggle, dan tabel ke partial produksi**

Di `resources/views/dashboard/partials/production.blade.php`, tambahkan `Total Selesai` ke array `$cards` (setelah `Selesai Bulan Ini`):

```php
            ['Total Selesai', $prod['total_selesai'], 'check-square', 'info'],
```

Tambahkan delta pada kartu "Selesai Bulan Ini" — ganti isi `@foreach($cards ...)` agar merender delta bila ada:

```blade
    @foreach($cards as [$label, $val, $icon, $tone])
        <div class="col-md-4 col-xl grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <i data-feather="{{ $icon }}" class="icon-sm text-{{ $tone }}"></i>
                    </div>
                    <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                    @if($label === 'Selesai Bulan Ini')
                        @include('dashboard.partials.delta', ['delta' => $prod['selesai_bulan_ini_delta']])
                    @elseif($label === 'Lewat Target')
                        @include('dashboard.partials.delta', ['delta' => ['dir' => 'flat', 'pct' => null, 'capped' => false], 'invertGood' => true])
                    @endif
                </div>
            </div>
        </div>
    @endforeach
```

Tambahkan tabel deadline sebelum `@push('plugin-scripts')`:

```blade
<h6 class="text-muted mb-2 mt-2">Naskah Saya Mendekati Deadline</h6>
<div class="row">
    @include('dashboard.partials.deadline-table', ['rows' => $prod['deadline_rows'], 'tableId' => 'prodDeadline'])
</div>
```

Tambahkan CSS + JS DataTables di `@push('plugin-styles')` / `@push('plugin-scripts')` (salin dari `sales.blade.php`), plus:

```blade
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
```

- [ ] **Step 7: Jalankan test produksi**

Run: `php artisan test --filter="ProductionWorkspaceTest|DashboardRoleRoutingTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/ProductionDashboardService.php app/Services/SalesDashboardService.php resources/views/dashboard/partials/production.blade.php tests/Unit/ProductionDashboardServiceTest.php
git commit -m "$(printf 'feat(dashboard): produksi dapat tabel deadline, Total Selesai, delta\n\nDeadline justru pekerjaan produksi, tapi hanya marketing yang punya\ntabelnya.\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 13: Verifikasi menyeluruh

**Files:** tidak ada perubahan kode kecuali temuan.

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua — 41 test lama + yang baru. **Jangan** klaim selesai tanpa keluaran ini terbaca hijau.

- [ ] **Step 2: Pastikan partial `financial` benar-benar lenyap**

Run: `grep -rn "financial\|total_approve\|total_reject\|series_income" resources/views/dashboard.blade.php app/Http/Controllers/DashboardController.php`
Expected: tidak ada keluaran.

- [ ] **Step 3: Pastikan tak ada hex chart yang tercecer**

Run: `grep -rn "#6571ff\|#05a34a\|#fbbc06\|#ff3366" resources/views/dashboard/`
Expected: tidak ada keluaran — semua warna lewat `SimapaCharts.PALETTE`.

- [ ] **Step 4: Buka tiap dashboard di aplikasi nyata**

Login bergantian sebagai keenam role dan buka `/dashboard`. Periksa: tak ada error 500, chart ter-render (atau menampilkan "Belum ada data"), dan **admin/manager tidak melihat angka uang yang seharusnya tertutup**.

Catatan: DB dev `avidpedi_simapa` terpisah dari DB test. Task ini tak menambah migrasi, jadi tak perlu `php artisan migrate`.

- [ ] **Step 5: Commit bila ada perbaikan**

```bash
git add -A
git commit -m "$(printf 'fix(dashboard): perbaikan hasil verifikasi menyeluruh\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Self-Review

**Cakupan spec:**

| Bagian spec | Task |
|---|---|
| Peta role berurutan prioritas + fallback | 6 |
| `SalesDashboardService` rename | 2 |
| Scope jadi parameter (`forUser`/`forCompany`) | 3 |
| `AdminDashboardService` | 5 |
| Partial `accounting` diekstrak, dipakai dua tempat | 11 |
| Partial `financial` dihapus | 6 (Step 7), diverifikasi 13 (Step 2) |
| Company: 7 blok + dropdown filter + Target Tim | 8 |
| Blok kas superadmin saja + try/catch | 6 (`cashSummary`), 9 |
| Admin tanpa angka uang | 5, 10 |
| Produksi: Total Selesai, delta, toggle, tabel deadline | 12 |
| Marketing tak berubah | 7 (regresi diuji Step 5) |
| Satu sumber warna/komponen | 7, diverifikasi 13 (Step 3) |
| Delta `invertGood` | 1 |
| Cap >999%, `pct` null = "baru" | 1 |
| `on_time_rate` null → "—" | **lihat catatan di bawah** |
| Keadaan kosong | 7 (`SimapaCharts.render`), 8, 10, 12 |
| Tidak ada query di view | 6 |
| Filter `?marketing=` + id asing → "Semua" | 6, 8 |
| Agregasi ke SQL | 4 |
| Bentuk array konsisten saat kosong | 3, 5 |
| Test: partial per role, kebocoran, filter identik, id asing | 6, 8, 9, 10, 11 |
| Test unit: `forCompany`, `delta`, `invertGood` | 1, 3, 5 |

**Celah yang ditemukan dan ditutup:** spec meminta `on_time_rate` null tampil "—" bukan "0%". `PerformanceService::forEditor()` sudah mengembalikan `null` (`app/Services/PerformanceService.php:31-33`), tapi tak ada task yang menyentuh tampilannya. Ditambahkan sebagai Task 12 Step 6 tambahan:

- [ ] **Task 12, Step 6b: Tampilkan `on_time_rate` null sebagai "—"**

Di `resources/views/dashboard/partials/production.blade.php`, pada kartu "Performa Saya", ganti baris ringkasan:

```blade
            <div class="text-center text-muted" style="font-size:12px">
                Selesai {{ $perf['completed'] }} · Antrian {{ $perf['active_queue'] }} ·
                Tepat waktu {{ $perf['on_time_rate'] === null ? '—' : $perf['on_time_rate'] . '%' }}
            </div>
```

Nol berarti semua telat — tuduhan yang salah ke editor yang belum punya naskah bertarget. Terapkan hal yang sama di blok Performa Editor pada `dashboard/partials/progress-global.blade.php` bila ia menampilkan `on_time_rate`; periksa berkas itu saat mengerjakan Task 8.

**Konsistensi tipe:**

- `build(?User $scopeUser)` privat; `forUser(User): array`; `forCompany(?User = null): array` — dipanggil konsisten di Task 3, 6, 8.
- `delta(): array{pct: ?float, dir: string, capped: bool}` — bentuk sama di `SalesDashboardService` (Task 1) dan `ProductionDashboardService` (Task 12), sehingga partial `delta` menerima kontrak tunggal.
- `deadlineRows(?User)` / `deadlineRowsForEditor(User)` / `deadlineFrom($query)` privat — nama konsisten Task 3, 4, 12.
- `AdminDashboardService::forAdmin(): array` dengan 8 key — dipakai test Task 5 dan partial Task 10 dengan nama key yang sama persis.
- Partial `deadline-table` menerima `$rows` + `$tableId` — dipanggil dengan kontrak itu di Task 7 (`salesDeadline`), 8 (`coDeadline`), 12 (`prodDeadline`).
- `$cash = ['year', 'ytd', 'gap']` — dibentuk `cashSummary()` (Task 6) dan dibaca `cash-block` (Task 9).

**Placeholder:** tidak ada. Satu-satunya langkah "periksa dulu" adalah Task 9 Step 4 (kunci `ExpenseGapService::check()`) dan Task 11 Step 3 (blok mana yang diekstrak) — keduanya menyebut berkas persis dan aturan keputusannya, karena keduanya harus menyesuaikan diri dengan kode yang ada, bukan menebaknya.
