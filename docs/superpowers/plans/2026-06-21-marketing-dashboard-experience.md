# Marketing Dashboard Experience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buat dashboard marketing lebih informatif (statistik+delta, KPI baru, grafik dengan toggle periode, donut), tambahkan tabel naskah mendekati deadline, kunci aksi admin dari marketing, dan bersihkan UI mati di halaman pembayaran.

**Architecture:** Laravel 11 + Blade. Semua data dashboard berasal dari `MarketingDashboardService::forUser()` yang diperluas (server-render). Grafik pakai ApexCharts (toggle periode = slice array 90 hari di sisi klien, tanpa endpoint baru); tabel deadline pakai DataTables sisi-klien dengan filter tab via atribut `data-*`. Hak akses ditegakkan lewat middleware `role:` per-route di `routes/web.php`.

**Tech Stack:** PHP 8.2 / Laravel, Blade, Spatie laravel-permission (middleware `role:`), ApexCharts, DataTables (datatables.net-bs4), PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-06-21-marketing-dashboard-experience-design.md`

**Catatan menjalankan test:** suite memakai `APP_ENV=testing` (phpunit.xml) → `.env.testing` → DB `avidpedi_simapa_test`. Jalankan dengan `php artisan test`. Filter satu test: `php artisan test --filter=<nama_method>`.

---

## File Structure

**Dibuat:**
- `tests/Feature/MarketingAccessTest.php` — feature test hak akses (marketing/production 403, marketing 200).
- `tests/Feature/PaymentBookCleanupTest.php` — feature test judul/tombol halaman pembayaran.
- `resources/views/dashboard/partials/delta.blade.php` — partial kecil badge indikator naik/turun (DRY, dipakai tiap kartu).

**Dimodifikasi:**
- `routes/web.php` — middleware `role:` per-route (Task 1).
- `resources/views/payments/book/index.blade.php` — hapus tombol mati, ganti judul (Task 2).
- `app/Services/MarketingDashboardService.php` — method `deadlineRows()` (Task 3); `delta()`, `avgOrderValue()`, KPI piutang & rata-rata order, indikator delta, seri tren 90 hari, wiring `deadline_rows` (Task 4).
- `resources/views/dashboard/partials/marketing.blade.php` — kartu+ikon+delta, KPI baru, toggle periode, donut center-total, section tabel deadline + init DataTables (Task 5).
- `tests/Unit/MarketingDashboardServiceTest.php` — tambah test untuk method/keys baru (Task 3 & 4).
- `tests/Feature/MarketingDashboardTest.php` — tambah test render dashboard baru (Task 5).

---

## Task 1: Hak akses — middleware role per-route

**Files:**
- Create: `tests/Feature/MarketingAccessTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/MarketingAccessTest.php`:

```php
<?php
// tests/Feature/MarketingAccessTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingAccessTest extends TestCase
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
    public function marketing_cannot_approve_or_reject_payments(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->post(route('payment.approve', 1))->assertForbidden();
        $this->post(route('payment.reject', 1))->assertForbidden();
    }

    /** @test */
    public function marketing_cannot_mutate_invoice(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('invoice.edit', 1))->assertForbidden();
        $this->put(route('invoice.update', 1))->assertForbidden();
        $this->post(route('invoice.updateStatus', 1))->assertForbidden();
        $this->post(route('invoice.cancel', 1))->assertForbidden();
        $this->post(route('invoice.refund', 1))->assertForbidden();
    }

    /** @test */
    public function production_cannot_reach_marketing_listings(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('payment.index'))->assertForbidden();
        $this->get(route('order.book.index'))->assertForbidden();
        $this->get(route('invoice.index'))->assertForbidden();
    }

    /** @test */
    public function production_keeps_read_access_to_title_detail(): void
    {
        // Papan Manuscript produksi menaut ke order.indexJudul.detail — route TIDAK boleh 403.
        $this->actingAs($this->user('production'));
        $response = $this->get(route('order.indexJudul.detail', 999999));
        $this->assertNotEquals(403, $response->getStatusCode()); // 404/200 boleh; 403 berarti ter-gate salah
    }

    /** @test */
    public function marketing_can_reach_allowed_pages(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('payment.index'))->assertOk();
        $this->get(route('order.book.index'))->assertOk();
        $this->get(route('invoice.index'))->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=MarketingAccessTest`
Expected: FAIL — `marketing_cannot_approve_or_reject_payments` & `marketing_cannot_mutate_invoice` & `production_cannot_reach_marketing_listings` mengembalikan 200/302/404 alih-alih 403 (route belum dijaga).

> Catatan: `production_keeps_read_access_to_title_detail` mengharap 404 (controller mencari id tak ada). Bila controller mengembalikan kode lain selain 403, anggap lulus untuk maksud "bukan 403"; sesuaikan assert ke `assertStatus(404)` bila itu yang dihasilkan. Yang dilarang hanyalah 403.

- [ ] **Step 3: Tambah middleware role di `routes/web.php`**

Edit grup payments — ubah baris grup & tambah middleware approve/reject:

```php
    Route::prefix('payments')->name('payment.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('list', [PaymentBookController::class, 'index'])->name('index');
        Route::get('{code_order}/create', [PaymentBookController::class, 'create'])->name('create');
        Route::post('{code_order}/create', [PaymentBookController::class, 'store'])->name('store');
        Route::post('approve/{id}', [PaymentBookController::class, 'approve'])->name('approve')->middleware('role:manager|superadmin');
        Route::post('reject/{id}', [PaymentBookController::class, 'reject'])->name('reject')->middleware('role:manager|superadmin');
        Route::put('{id}', [PaymentBookController::class, 'update'])
            ->name('update')
            ->middleware('role:manager|superadmin');

        //dp
        Route::get('dp', [DebtBookController::class, 'index'])->name('dp.index');

        //lunas
        Route::get('full', [FullPaymentBookController::class, 'index'])->name('fp.index');
    });
```

Edit grup invoices — ubah baris grup & kunci mutasi (termasuk `edit`):

```php
    Route::prefix('invoices')->name('invoice.')->middleware('role:marketing|manager|superadmin')->group(function () {
        Route::get('',             [InvoiceController::class, 'index'])->name('index');
        Route::get('{id}',         [InvoiceController::class, 'show'])->name('show');
        Route::get('{id}/edit',    [InvoiceController::class, 'edit'])->name('edit')->middleware('role:manager|superadmin');
        Route::put('{id}',         [InvoiceController::class, 'update'])->name('update')->middleware('role:manager|superadmin');
        Route::post('{id}/status', [InvoiceController::class, 'updateStatus'])->name('updateStatus')->middleware('role:manager|superadmin');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->name('cancel')->middleware('role:manager|superadmin');
        Route::post('{id}/refund', [InvoiceController::class, 'refund'])->name('refund')->middleware('role:manager|superadmin');
        Route::get('{id}/logs',    [InvoiceController::class, 'logs'])->name('logs');
        Route::get('{id}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
    });
```

Edit grup `order` (prefix `order`) — gate buat/ubah, biarkan `show` terbuka untuk produksi:

```php
    //Order book
    Route::prefix('order')->name('order.')->group(function () {

        Route::get('buku/create', [OrderBookController::class, 'create'])->name('book.create')->middleware('role:marketing|manager|superadmin');
        Route::post('buku/create', [OrderBookController::class, 'store'])->name('book.store')->middleware('role:marketing|manager|superadmin');
        Route::get('buku/show/{code_order}', [OrderBookController::class, 'show'])->name('book.show');
        Route::get('buku/update/{code_order}', [OrderBookController::class, 'edit'])->name('book.edit')->middleware('role:marketing|manager|superadmin');
        Route::put('buku/update/{code_order}', [OrderBookController::class, 'update'])->name('book.update')->middleware('role:marketing|manager|superadmin');

        Route::get('jurnal/create', [OrderJournalController::class, 'create'])->name('journal.create')->middleware('role:marketing|manager|superadmin');
        Route::post('jurnal/create', [OrderJournalController::class, 'store'])->name('journal.store')->middleware('role:marketing|manager|superadmin');
        Route::get('jurnal/show/{code_order}', [OrderJournalController::class, 'show'])->name('journal.show');
    });
```

Edit grup `management` order/title — gate hanya index & arsip, biarkan detail/progress terbuka (dipakai papan Manuscript produksi):

```php
    //order  journal
    Route::prefix('management')->name('order.')->group(function () {
        Route::get('order', [OrderBookController::class, 'index'])->name('book.index')->middleware('role:marketing|manager|superadmin');
        Route::get('title', [OrderBookController::class, 'indexJudul'])->name('book.indexJudul')->middleware('role:marketing|manager|superadmin');
        Route::get('title/details/{id}', [OrderBookController::class, 'detailJudul'])->name('indexJudul.detail');
        Route::get('title/order/{id}', [OrderBookController::class, 'progressDetail'])->name('indexJudul.progress');
    });
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingAccessTest`
Expected: PASS (5 test).

> Jika `marketing_can_reach_allowed_pages` gagal dengan 500 (bukan 403), itu menandakan masalah controller yang sudah ada sebelumnya — laporkan, jangan tutupi dengan mengubah middleware.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/MarketingAccessTest.php
git commit -m "feat(access): gate admin actions & marketing listings via role middleware

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Bersih-bersih UI halaman pembayaran

**Files:**
- Create: `tests/Feature/PaymentBookCleanupTest.php`
- Modify: `resources/views/payments/book/index.blade.php:17-26`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/PaymentBookCleanupTest.php`:

```php
<?php
// tests/Feature/PaymentBookCleanupTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PaymentBookCleanupTest extends TestCase
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

    /** @test */
    public function approved_payments_page_has_clean_title_and_no_dead_buttons(): void
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');

        $this->actingAs($u);
        $this->get(route('payment.index'))
            ->assertOk()
            ->assertSee('Pembayaran Disetujui')
            ->assertDontSee('Management Order Books')
            ->assertDontSee('Trash');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: FAIL — halaman masih memuat "Management Order Books" & "Trash".

- [ ] **Step 3: Edit view — hapus tombol mati & ganti judul**

In `resources/views/payments/book/index.blade.php`, replace lines 17–26:

```blade
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Management Order Books</h6>
                        @role(['marketing'])
                            <div class="btn-group" role="group">
                                <a href="#" class="btn btn-primary">Trash</a>
                                <a href="#" class="btn btn-outline-primary">Export</a>
                                <a href="#" class="btn btn-primary">Create</a>
                            </div>
                        @endrole
                    </div>
```

with:

```blade
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Pembayaran Disetujui</h6>
                    </div>
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/payments/book/index.blade.php tests/Feature/PaymentBookCleanupTest.php
git commit -m "fix(payments): rename title to 'Pembayaran Disetujui' and remove dead buttons

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Service — `deadlineRows()`

**Files:**
- Modify: `app/Services/MarketingDashboardService.php`
- Test: `tests/Unit/MarketingDashboardServiceTest.php`

- [ ] **Step 1: Tulis unit test yang gagal**

Tambahkan method test ini ke `tests/Unit/MarketingDashboardServiceTest.php` (di dalam class, setelah test terakhir). Helper `marketing()`, `orderFor()`, `naskah()` sudah ada di file.

```php
    /** @test */
    public function deadline_rows_are_scoped_flagged_and_sorted(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt);
        $this->naskah($o, 'editing',      ['target_date' => now()->subDays(3)->toDateString()]); // overdue
        $this->naskah($o, 'layout',       ['target_date' => now()->addDays(5)->toDateString()]); // d7 + month
        $this->naskah($o, 'proofreading', ['target_date' => now()->endOfMonth()->toDateString()]); // month
        $this->naskah($o, 'isbn',         ['target_date' => now()->addMonth()->toDateString()]);  // tidak ada flag
        $this->naskah($o, 'editing');                                                              // tanpa target → keluar
        $this->naskah($o, 'terbit',       ['target_date' => now()->addDay()->toDateString()]);     // final → keluar
        $this->naskah($this->orderFor($this->marketing()), 'editing', ['target_date' => now()->toDateString()]); // marketing lain → keluar

        $rows = $this->svc->deadlineRows($mkt);

        $this->assertCount(4, $rows);                               // scoping + exclusion
        $this->assertSame(1, $rows->first()['overdue']);           // overdue paling atas (sort)
        $this->assertSame(1, $rows->firstWhere('stage', 'Layout')['d7']);        // +5 hari selalu <= 7
        $this->assertSame(1, $rows->firstWhere('stage', 'Proofreading')['month']); // akhir bulan ini → selalu month
        $this->assertSame(0, $rows->firstWhere('stage', 'Isbn')['month']);       // +1 bulan → bukan bulan ini
        $this->assertSame(0, $rows->firstWhere('stage', 'Isbn')['d7']);
        $this->assertSame('Lewat 3 hari', $rows->first()['days_label']);
        // Catatan: flag 'month' untuk row 'Layout' (+5 hari) sengaja TIDAK di-assert —
        // dekat akhir bulan +5 hari bisa jatuh ke bulan depan (sensitif tanggal jalan test).
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=deadline_rows_are_scoped_flagged_and_sorted`
Expected: FAIL — `Call to undefined method App\Services\MarketingDashboardService::deadlineRows()`.

- [ ] **Step 3: Tambah method `deadlineRows()`**

Di `app/Services/MarketingDashboardService.php`, tambahkan method publik ini (mis. tepat setelah `forUser()`):

```php
    /** Baris tabel naskah aktif mendekati/lewat deadline (ter-scope order marketing). */
    public function deadlineRows(User $user): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return TitleProgress::query()
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->whereNotNull('target_date')
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $user->id))
            ->with('orderDetail.order')
            ->get()
            ->map(function (TitleProgress $tp) use ($today) {
                $target  = Carbon::parse($tp->target_date)->startOfDay();
                $overdue = $target->lt($today);
                $days    = $today->diffInDays($target);     // absolut (>= 0)
                $signed  = $overdue ? -$days : $days;       // negatif bila lewat
                $isD7    = ! $overdue && $target->lte($today->copy()->addDays(7));
                $isMonth = ! $overdue && $target->lte($today->copy()->endOfMonth());

                return [
                    'id'           => $tp->id,
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

(`Carbon`, `Str`, `User`, `TitleProgress` sudah di-`use` di file.)

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=deadline_rows_are_scoped_flagged_and_sorted`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/MarketingDashboardService.php tests/Unit/MarketingDashboardServiceTest.php
git commit -m "feat(dashboard): MarketingDashboardService::deadlineRows for deadline table

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Service — delta, KPI piutang & rata-rata order, seri 90 hari, wiring

**Files:**
- Modify: `app/Services/MarketingDashboardService.php`
- Test: `tests/Unit/MarketingDashboardServiceTest.php`

- [ ] **Step 1: Tulis unit test yang gagal**

Tambahkan ke `tests/Unit/MarketingDashboardServiceTest.php` (perlu import `OrderDetail` — sudah ada di file):

```php
    /** @test */
    public function exposes_new_kpis_and_delta_keys(): void
    {
        $mkt = $this->marketing();
        $o = $this->orderFor($mkt, ['status' => 'pending']);
        OrderDetail::factory()->create(['order_id' => $o->id, 'cost_amount' => 1000000]);
        $this->paid($o, 400000, 'dp'); // approved 400rb → sisa piutang 600rb

        $d = $this->svc->forUser($mkt);

        $this->assertSame(600000, $d['total_piutang']);    // 1.000.000 - 400.000
        $this->assertSame(1000000, $d['rata_rata_order']); // 1 order, cost 1.000.000

        foreach ([
            'pemasukan_hari_ini_delta', 'pemasukan_minggu_ini_delta',
            'pemasukan_tahun_ini_delta', 'jumlah_order_delta',
        ] as $key) {
            $this->assertArrayHasKey($key, $d);
            $this->assertArrayHasKey('dir', $d[$key]);
        }

        $this->assertArrayHasKey('deadline_rows', $d);
        $this->assertCount(90, $d['income_trend']['series']); // tren diperpanjang ke 90 hari
    }

    /** @test */
    public function average_order_value_is_zero_without_orders(): void
    {
        $d = $this->svc->forUser($this->marketing());
        $this->assertSame(0, $d['rata_rata_order']);
        $this->assertSame(0, $d['total_piutang']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=exposes_new_kpis_and_delta_keys`
Expected: FAIL — key `total_piutang` belum ada (undefined index).

- [ ] **Step 3: Perbarui `forUser()` + tambah helper, perpanjang seri ke 90 hari**

Ganti seluruh isi method `forUser()` di `app/Services/MarketingDashboardService.php` dengan:

```php
    /** KPI pemasukan + order + progres naskah + data chart untuk satu marketing (ter-scope order.user_id). */
    public function forUser(User $user): array
    {
        $uid   = $user->id;
        $today = Carbon::today();

        // Definisi kanonik (sama dengan FinancialReportService): uang masuk = Payment status paid, scoped order user.
        $income = fn () => Payment::approved()->forOrdersOf($user);

        $prog = fn () => TitleProgress::query()
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $uid));

        // Nilai periode berjalan (dipakai kartu + sebagai 'current' delta).
        $incHari   = (int) $income()->whereDate('paid_at', $today)->sum('amount');
        $incMinggu = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount');
        $incTahun  = (int) $income()->whereYear('paid_at', $today->year)->sum('amount');
        $jmlOrder  = Order::where('user_id', $uid)->whereYear('ordered_at', $today->year)->count();

        // Pembanding setara (period-to-date pada periode sebelumnya).
        $incHariPrev   = (int) $income()->whereDate('paid_at', $today->copy()->subDay())->sum('amount');
        $incMingguPrev = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek()->subWeek(), $today->copy()->endOfDay()->subWeek()])->sum('amount');
        $incTahunPrev  = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->sum('amount');
        $jmlOrderPrev  = Order::where('user_id', $uid)->whereBetween('ordered_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->count();

        return [
            // Pemasukan (tiap Payment paid dihitung — termasuk DP/parsial/pelunasan)
            'pemasukan_hari_ini'     => $incHari,
            'pemasukan_minggu_ini'   => $incMinggu,
            'pemasukan_tahun_ini'    => $incTahun,
            'jumlah_order_tahun_ini' => $jmlOrder,

            // Indikator delta vs periode sebelumnya (period-to-date setara)
            'pemasukan_hari_ini_delta'   => $this->delta($incHari, $incHariPrev),
            'pemasukan_minggu_ini_delta' => $this->delta($incMinggu, $incMingguPrev),
            'pemasukan_tahun_ini_delta'  => $this->delta($incTahun, $incTahunPrev),
            'jumlah_order_delta'         => $this->delta($jmlOrder, $jmlOrderPrev),

            // KPI baru
            'total_piutang'   => (int) ((new FinancialReportService())->piutang($user)['kpi']['sisa']),
            'rata_rata_order' => $this->avgOrderValue($uid, $today->year),

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
                                    (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->where('status', '!=', 'menunggu_proses')->get(['status'])->groupBy('status')->map->count()
                                   ),
            'completion_trend'  => $this->completionTrend($uid),
            'deadline_rows'     => $this->deadlineRows($user),
        ];
    }

    /** Indikator naik/turun: pct (null bila pembanding 0) + arah up/down/flat. */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat'];
        }
        $pct = round(($current - $previous) / $previous * 100, 1);
        return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
    }

    /** Rata-rata nilai order (cost_amount) tahun berjalan; 0 bila tanpa order. */
    private function avgOrderValue(int $uid, int $year): int
    {
        $orders = Order::where('user_id', $uid)->whereYear('ordered_at', $year)->with('details')->get();
        $count = $orders->count();
        if ($count === 0) {
            return 0;
        }
        $sum = (int) $orders->sum(fn ($o) => (int) ($o->details->cost_amount ?? 0));
        return intdiv($sum, $count);
    }
```

(`FinancialReportService` berada di namespace `App\Services` yang sama, jadi `new FinancialReportService()` valid tanpa `use` tambahan.)

- [ ] **Step 4: Perpanjang seri tren dari 30 → 90 hari**

Di file yang sama, pada method `dailySum()`, `dailyCount()`, dan `completionTrend()`, ganti tiap `range(29, 0)` menjadi `range(89, 0)` dan tiap `subDays(29)` menjadi `subDays(89)`.

`dailySum()` — dua baris berubah:

```php
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
```

`dailyCount()` — dua baris berubah:

```php
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
```

`completionTrend()` — dua baris berubah:

```php
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        // ...
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->whereHas('titleProgress.orderDetail.order', fn ($q) => $q->where('user_id', $uid))
            ->where('created_at', '>=', Carbon::now()->subDays(89)->startOfDay())
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS (semua test lama + 2 test baru). Test lama (`income_sums_...`, `progress_kpis_...`, `jatuh_tempo_7_...`) tetap hijau karena key & nilai lama tidak diubah.

- [ ] **Step 6: Commit**

```bash
git add app/Services/MarketingDashboardService.php tests/Unit/MarketingDashboardServiceTest.php
git commit -m "feat(dashboard): KPI piutang & avg order, period deltas, 90-day trends

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: View — dashboard marketing diperkaya + tabel deadline

**Files:**
- Create: `resources/views/dashboard/partials/delta.blade.php`
- Modify: `resources/views/dashboard/partials/marketing.blade.php` (ganti penuh)
- Test: `tests/Feature/MarketingDashboardTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Tambahkan ke `tests/Feature/MarketingDashboardTest.php` (helper `user()` sudah ada, `GoogleDriveService` sudah di-mock di setUp):

```php
    /** @test */
    public function marketing_dashboard_shows_new_kpis_and_deadline_table(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'NASKAH DEADLINE',
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production',
            'started_at' => now(), 'target_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Piutang')
            ->assertSee('Rata-rata Nilai Order')
            ->assertSee('Naskah Mendekati Deadline')
            ->assertSee('Lewat target')          // label tab
            ->assertSee('NASKAH DEADLINE')        // baris naskah overdue
            ->assertSee('Lewat 1 hari');          // badge sisa hari
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=marketing_dashboard_shows_new_kpis_and_deadline_table`
Expected: FAIL — string "Total Piutang"/"Naskah Mendekati Deadline" belum ada.

- [ ] **Step 3: Buat partial `delta.blade.php`**

Create `resources/views/dashboard/partials/delta.blade.php`:

```blade
{{-- resources/views/dashboard/partials/delta.blade.php — indikator naik/turun --}}
@php
    $dir  = $delta['dir'] ?? 'flat';
    $dcls = $dir === 'up' ? 'text-success' : ($dir === 'down' ? 'text-danger' : 'text-muted');
    $dic  = $dir === 'up' ? 'arrow-up' : ($dir === 'down' ? 'arrow-down' : 'minus');
    $dtxt = (isset($delta['pct']) && $delta['pct'] !== null)
        ? $delta['pct'] . '% vs periode lalu'
        : ($dir === 'up' ? 'baru' : '—');
@endphp
<p class="{{ $dcls }} mb-0 mt-2" style="font-size:12px">
    <i data-feather="{{ $dic }}" class="icon-sm mb-1"></i> {{ $dtxt }}
</p>
```

- [ ] **Step 4: Ganti penuh `marketing.blade.php`**

Replace the entire contents of `resources/views/dashboard/partials/marketing.blade.php` with:

```blade
{{-- resources/views/dashboard/partials/marketing.blade.php --}}
@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Marketing</h4>
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
        <div class="col-md-3 grid-margin stretch-card">
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
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
                    <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
                </div>
                <div class="bg-dark bg-opacity-10 rounded p-2">
                    <i data-feather="shopping-bag" class="text-dark"></i>
                </div>
            </div>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_delta']])
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Total Piutang</h6>
            <h4 class="mt-2 mb-0 text-warning">Rp {{ number_format($mkt['total_piutang'], 0, ',', '.') }}</h4>
            <small class="text-muted">Sisa tagihan order belum lunas</small>
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Rata-rata Nilai Order</h6>
            <h4 class="mt-2 mb-0 text-dark">Rp {{ number_format($mkt['rata_rata_order'], 0, ',', '.') }}</h4>
            <small class="text-muted">Tahun ini</small>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-2">
    <h6 class="text-muted mb-0">Traffic</h6>
    <div class="btn-group btn-group-sm" role="group" id="mktRangeToggle">
        <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
        <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
        <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
    </div>
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan</h6>
            <div id="mktIncomeChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order</h6>
            <div id="mktOrderChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Progres Naskah Saya</h6>
<div class="row">
    @php
        $progCards = [
            ['Naskah Aktif', $mkt['naskah_aktif'], 'primary'],
            ['Belum Diproses', $mkt['belum_diproses'], 'secondary'],
            ['Lewat Target', $mkt['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $mkt['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $mkt['selesai_bulan_ini'], 'success'],
            ['Total Selesai', $mkt['total_selesai'], 'info'],
        ];
    @endphp
    @foreach($progCards as [$label, $val, $tone])
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

<h6 class="text-muted mb-2 mt-2">Naskah Mendekati Deadline</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <ul class="nav nav-pills mb-3" id="deadlineTabs">
                <li class="nav-item"><a class="nav-link active" href="#" data-bucket="all">Semua</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="overdue">Lewat target</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="d7">≤7 hari</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="month">Bulan ini</a></li>
            </ul>
            <div class="table-responsive">
                <table class="table table-hover" id="deadlineTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Judul</th><th>Kode Order</th><th>Tahap</th>
                            <th>Target</th><th>Sisa Hari</th><th>Prioritas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mkt['deadline_rows'] as $r)
                            <tr data-overdue="{{ $r['overdue'] }}" data-d7="{{ $r['d7'] }}" data-month="{{ $r['month'] }}">
                                <td>{{ $r['title'] }}</td>
                                <td><a href="{{ route('order.indexJudul.progress', $r['id']) }}">{{ $r['code_order'] }}</a></td>
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
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // ---- Traffic: data 90 hari, ditampilkan per slice di sisi klien ----
        var full = {
            inc: { labels: @json($mkt['income_trend']['labels']), series: @json($mkt['income_trend']['series']) },
            ord: { labels: @json($mkt['order_trend']['labels']),  series: @json($mkt['order_trend']['series']) },
        };
        function slice(o, n) { return { labels: o.labels.slice(-n), series: o.series.slice(-n) }; }
        function areaOpts(name, d, color, isCurrency) {
            return {
                chart: { type: 'area', height: 240, toolbar: { show: false } },
                series: [{ name: name, data: d.series }],
                xaxis: { categories: d.labels, labels: { rotate: -45, style: { fontSize: '9px' } } },
                dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: [color],
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
                markers: { size: 0, hover: { size: 4 } },
                tooltip: isCurrency ? { y: { formatter: function (v) { return 'Rp ' + v.toLocaleString('id-ID'); } } } : {},
            };
        }
        var n0 = 30;
        var incChart = new ApexCharts(document.querySelector('#mktIncomeChart'), areaOpts('Pemasukan', slice(full.inc, n0), '#05a34a', true));
        var ordChart = new ApexCharts(document.querySelector('#mktOrderChart'), areaOpts('Order', slice(full.ord, n0), '#6571ff', false));
        incChart.render(); ordChart.render();

        document.querySelectorAll('#mktRangeToggle [data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var n = +this.dataset.range;
                var si = slice(full.inc, n), so = slice(full.ord, n);
                incChart.updateOptions({ xaxis: { categories: si.labels }, series: [{ data: si.series }] });
                ordChart.updateOptions({ xaxis: { categories: so.labels }, series: [{ data: so.series }] });
                document.querySelectorAll('#mktRangeToggle [data-range]').forEach(function (b) {
                    b.classList.remove('btn-primary', 'active'); b.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary'); this.classList.add('btn-primary', 'active');
            });
        });

        // ---- Donut: Naskah per Tahap (total di tengah + persentase) ----
        new ApexCharts(document.querySelector('#mktStageChart'), {
            chart: { type: 'donut', height: 260 },
            series: @json($mkt['per_stage']['series']),
            labels: @json($mkt['per_stage']['labels']),
            legend: { position: 'bottom' },
            dataLabels: { enabled: true, formatter: function (v) { return Math.round(v) + '%'; } },
            plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Naskah Aktif' } } } } },
        }).render();

        // ---- Completion (30 hari terakhir) ----
        new ApexCharts(
            document.querySelector('#mktCompletionChart'),
            areaOpts('Terbit/Publish', slice({ labels: @json($mkt['completion_trend']['labels']), series: @json($mkt['completion_trend']['series']) }, n0), '#fbbc06', false)
        ).render();
    });

    // ---- Tabel deadline: DataTables + filter tab ----
    $(function () {
        if (!$.fn.DataTable) return;
        window.deadlineBucket = 'all';
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'deadlineTable') return true;
            if (window.deadlineBucket === 'all') return true;
            var node = settings.aoData[dataIndex].nTr;
            return node.getAttribute('data-' + window.deadlineBucket) === '1';
        });
        var table = $('#deadlineTable').DataTable({ pageLength: 10, order: [[4, 'asc']] });
        $('.dataTables_length select, .dataTables_filter input').addClass('form-control mb-2');
        $('#deadlineTabs a').on('click', function (e) {
            e.preventDefault();
            $('#deadlineTabs a').removeClass('active');
            $(this).addClass('active');
            window.deadlineBucket = $(this).data('bucket');
            table.draw();
        });
    });
</script>
@endpush
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingDashboardTest`
Expected: PASS (test lama `marketing_sees_marketing_dashboard_not_generic`, `manager_dashboard_unchanged_generic_plus_global`, `arsip_judul_shows_target_column` tetap hijau + test baru).

- [ ] **Step 6: Commit**

```bash
git add resources/views/dashboard/partials/delta.blade.php resources/views/dashboard/partials/marketing.blade.php tests/Feature/MarketingDashboardTest.php
git commit -m "feat(dashboard): richer marketing dashboard + deadline DataTables

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 6: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (suite lama 41 test + test baru pada `MarketingAccessTest`, `PaymentBookCleanupTest`, `MarketingDashboardServiceTest`, `MarketingDashboardTest`). Tidak ada yang merah.

- [ ] **Step 2: Smoke manual (opsional tapi disarankan)**

Login sebagai user role `marketing` (seeder: `ika` / `password`) → buka `/dashboard`:
- Kartu pemasukan menampilkan ikon + indikator delta.
- Kartu "Total Piutang" & "Rata-rata Nilai Order" tampil.
- Toggle 7/30/90 hari mengubah rentang kedua grafik tren.
- Donut menampilkan total "Naskah Aktif" di tengah.
- Tabel "Naskah Mendekati Deadline" tampil; tab Lewat target / ≤7 hari / Bulan ini / Semua memfilter baris.

Login sebagai `production` (`pia` / `password`): buka papan Manuscript → klik "Detail" sebuah kartu → halaman detail judul terbuka (tidak 403).

- [ ] **Step 3: Commit (bila ada penyesuaian dari smoke test)**

Jika tidak ada perubahan, lewati. Jika ada, commit dengan pesan deskriptif + `Co-authored-by: Mira <admin@avidpedia.com>`.

---

## Catatan & Risiko

- **Test menyentuh DB test**, bukan DB asli (phpunit.xml `APP_ENV=testing` → `.env.testing` → `avidpedi_simapa_test`).
- **Read-only detail routes sengaja dibiarkan terbuka** (`order.book.show`, `order.indexJudul.detail`, `order.indexJudul.progress`) karena papan Manuscript (produksi) menaut ke sana; mengetatkannya akan mematahkan navigasi produksi.
- **Role `admin`** (user `nurul`) tidak termasuk set izin payments/orders/invoices → setelah Task 1 ia 403 di area itu. Ini konsisten dengan sidebar (menu tsb hanya tampil untuk superadmin|manager|marketing). Jika `admin` perlu akses, tambahkan ke daftar role middleware.
- **ApexCharts** dimuat dua kali untuk marketing (dari `dashboard.blade.php` dan partial) — tidak masalah secara fungsional; biarkan partial mandiri.
- **Delta minggu/tahun**: nilai kartu memakai periode penuh (startOfWeek..endOfWeek / whereYear) sedangkan "current" delta memakai period-to-date; keduanya identik dalam praktik karena tidak ada `paid_at` di masa depan.
```