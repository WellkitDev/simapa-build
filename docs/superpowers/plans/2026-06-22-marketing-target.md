# Marketing Target Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tetapkan target pemasukan bulanan + rate komisi per marketing, lalu tampilkan progres (realisasi vs target + komisi) di halaman admin "Target Marketing" dan kartu di dashboard marketing.

**Architecture:** Tabel `tb_marketing_targets` (per marketing/bulan). `MarketingTargetService` menghitung realisasi (pakai ulang definisi kanonik `Payment::approved()->forOrdersOf()`), capaian %, komisi (rate × realisasi), sisa — semua on-the-fly. Halaman admin (manager/superadmin) untuk set target + laporan; kartu dashboard menarik target bulan berjalan.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie roles (`User::role()`), Blade + Bootstrap 5, PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-06-22-marketing-target-design.md`

**Catatan test:** `APP_ENV=testing` (phpunit.xml) → `.env.testing` → DB `avidpedi_simapa_test`. `RefreshDatabase` memigrasi tabel baru. Filter: `php artisan test --filter=<NamaMethod>`.

---

## File Structure

**Dibuat:**
- `database/migrations/2026_06_22_000001_create_tb_marketing_targets_table.php`
- `app/Models/MarketingTarget.php`
- `app/Services/MarketingTargetService.php`
- `app/Http/Controllers/Pages/MarketingTargetController.php`
- `resources/views/marketing-target/index.blade.php`
- `tests/Unit/MarketingTargetServiceTest.php`, `tests/Feature/MarketingTargetTest.php`

**Dimodifikasi:**
- `routes/web.php` — grup route admin target.
- `resources/views/layouts/sidebar.blade.php` — menu "Target Marketing".
- `app/Services/MarketingDashboardService.php` — key `target` di `forUser`.
- `resources/views/dashboard/partials/marketing.blade.php` — section "Target Bulan Ini".

---

## Task 1: Tabel + Model

**Files:**
- Create: `database/migrations/2026_06_22_000001_create_tb_marketing_targets_table.php`
- Create: `app/Models/MarketingTarget.php`

- [ ] **Step 1: Migrasi**

Create `database/migrations/2026_06_22_000001_create_tb_marketing_targets_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_marketing_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('target_amount')->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_marketing_targets');
    }
};
```

- [ ] **Step 2: Model**

Create `app/Models/MarketingTarget.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingTarget extends Model
{
    use HasFactory;

    protected $table = 'tb_marketing_targets';

    protected $fillable = [
        'user_id', 'year', 'month', 'target_amount', 'commission_rate', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'year'            => 'integer',
        'month'           => 'integer',
        'target_amount'   => 'integer',
        'commission_rate' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Verifikasi migrasi sehat**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS (RefreshDatabase memigrasi tabel baru tanpa error).

- [ ] **Step 4: Commit**

```
git add database/migrations/2026_06_22_000001_create_tb_marketing_targets_table.php app/Models/MarketingTarget.php
git commit -m "feat(target): tb_marketing_targets table + MarketingTarget model

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `MarketingTargetService` (TDD)

**Files:**
- Create: `app/Services/MarketingTargetService.php`
- Test: `tests/Unit/MarketingTargetServiceTest.php`

- [ ] **Step 1: Tulis unit test yang gagal**

Create `tests/Unit/MarketingTargetServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\MarketingTarget;
use App\Services\MarketingTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingTargetService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new MarketingTargetService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function paidThisMonth(User $u, int $amount): void
    {
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => now(), 'status' => 'paid']);
    }

    private function setTarget(User $u, int $target, float $rate): void
    {
        MarketingTarget::create([
            'user_id' => $u->id, 'year' => now()->year, 'month' => now()->month,
            'target_amount' => $target, 'commission_rate' => $rate,
        ]);
    }

    /** @test */
    public function progress_full_achievement_with_commission(): void
    {
        $u = $this->marketing();
        $this->setTarget($u, 10000000, 5);
        $this->paidThisMonth($u, 10000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertTrue($p['has_target']);
        $this->assertSame(10000000, $p['target']);
        $this->assertSame(10000000, $p['realisasi']);
        $this->assertSame(100.0, $p['capaian_persen']);
        $this->assertSame(500000, $p['komisi']);
        $this->assertSame(0, $p['sisa']);
    }

    /** @test */
    public function progress_partial_achievement(): void
    {
        $u = $this->marketing();
        $this->setTarget($u, 10000000, 5);
        $this->paidThisMonth($u, 6000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertSame(60.0, $p['capaian_persen']);
        $this->assertSame(4000000, $p['sisa']);
        $this->assertSame(300000, $p['komisi']);
    }

    /** @test */
    public function progress_without_target_is_safe(): void
    {
        $u = $this->marketing();
        $this->paidThisMonth($u, 2000000);

        $p = $this->svc->progressFor($u, now()->year, now()->month);

        $this->assertFalse($p['has_target']);
        $this->assertSame(0, $p['target']);
        $this->assertSame(0.0, $p['rate']);
        $this->assertSame(0, $p['komisi']);
        $this->assertSame(2000000, $p['realisasi']);
        $this->assertSame(0.0, $p['capaian_persen']);
    }

    /** @test */
    public function realisasi_scoped_to_marketing_and_month(): void
    {
        $u = $this->marketing();
        $other = $this->marketing();
        $this->paidThisMonth($u, 1000000);
        $this->paidThisMonth($other, 9999999);
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5000000, 'paid_at' => now()->subMonthNoOverflow(), 'status' => 'paid']);

        $p = $this->svc->progressFor($u, now()->year, now()->month);
        $this->assertSame(1000000, $p['realisasi']);
    }

    /** @test */
    public function monthly_overview_lists_all_marketing_with_realisasi(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();
        $this->setTarget($a, 5000000, 10);
        $this->paidThisMonth($a, 2000000);

        $rows = $this->svc->monthlyOverview(now()->year, now()->month);

        $this->assertCount(2, $rows);
        $rowA = $rows->firstWhere('user_id', $a->id);
        $this->assertSame(5000000, $rowA['target']);
        $this->assertSame(2000000, $rowA['realisasi']);
        $this->assertSame(200000, $rowA['komisi']);
        $rowB = $rows->firstWhere('user_id', $b->id);
        $this->assertFalse($rowB['has_target']);
        $this->assertSame(0, $rowB['realisasi']);
    }

    /** @test */
    public function upsert_many_creates_then_updates(): void
    {
        $u = $this->marketing();
        $actor = $this->marketing();

        $this->svc->upsertMany(now()->year, now()->month, [
            ['user_id' => $u->id, 'target' => 7000000, 'rate' => 5],
        ], $actor);

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $u->id, 'year' => now()->year, 'month' => now()->month, 'target_amount' => 7000000,
        ]);

        $this->svc->upsertMany(now()->year, now()->month, [
            ['user_id' => $u->id, 'target' => 8000000, 'rate' => 7],
        ], $actor);

        $this->assertSame(1, MarketingTarget::where('user_id', $u->id)->count());
        $this->assertSame(8000000, (int) MarketingTarget::where('user_id', $u->id)->first()->target_amount);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=MarketingTargetServiceTest`
Expected: FAIL — `Class "App\Services\MarketingTargetService" not found`.

- [ ] **Step 3: Implement service**

Create `app/Services/MarketingTargetService.php`:

```php
<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

class MarketingTargetService
{
    /** Progres target satu marketing untuk satu bulan. */
    public function progressFor(User $marketing, int $year, int $month): array
    {
        $target = MarketingTarget::where('user_id', $marketing->id)
            ->where('year', $year)->where('month', $month)->first();

        // Definisi kanonik (sama dengan dashboard & laporan): pembayaran paid, scoped order user.
        $realisasi = (int) Payment::approved()->forOrdersOf($marketing)
            ->whereYear('paid_at', $year)->whereMonth('paid_at', $month)->sum('amount');

        return $this->buildProgress(
            $target ? (int) $target->target_amount : 0,
            $target ? (float) $target->commission_rate : 0.0,
            $realisasi,
            (bool) $target,
        );
    }

    /** Satu baris per marketing untuk halaman admin / laporan. */
    public function monthlyOverview(int $year, int $month): Collection
    {
        $marketers = User::role('marketing')->orderBy('name')->get();

        $targets = MarketingTarget::where('year', $year)->where('month', $month)
            ->get()->keyBy('user_id');

        // Realisasi seluruh marketing dalam SATU query grouped (hindari N+1).
        // Kolom di-qualify (tb_payments.status / paid_at) karena join ke tb_orders
        // yang juga punya kolom 'status' — kalau pakai scope approved() yang unqualified akan ambigu.
        $realisasiByUser = Payment::query()
            ->where('tb_payments.status', 'paid')
            ->whereYear('tb_payments.paid_at', $year)
            ->whereMonth('tb_payments.paid_at', $month)
            ->join('tb_orders', 'tb_payments.order_id', '=', 'tb_orders.id')
            ->selectRaw('tb_orders.user_id as uid, SUM(tb_payments.amount) as total')
            ->groupBy('tb_orders.user_id')
            ->pluck('total', 'uid');

        return $marketers->map(function (User $u) use ($targets, $realisasiByUser) {
            $t = $targets->get($u->id);
            $realisasi = (int) ($realisasiByUser[$u->id] ?? 0);
            $progress = $this->buildProgress(
                $t ? (int) $t->target_amount : 0,
                $t ? (float) $t->commission_rate : 0.0,
                $realisasi,
                (bool) $t,
            );
            return array_merge(['user_id' => $u->id, 'name' => $u->name], $progress);
        })->values();
    }

    /** Simpan massal target bulan terpilih. Baris target kosong dilewati; user non-marketing diabaikan. */
    public function upsertMany(int $year, int $month, array $rows, User $actor): void
    {
        $marketingIds = User::role('marketing')->pluck('id')->all();

        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $target = $row['target'] ?? null;

            if ($target === null || $target === '' || ! in_array($uid, $marketingIds, true)) {
                continue;
            }

            $existingCreatedBy = MarketingTarget::where(['user_id' => $uid, 'year' => $year, 'month' => $month])->value('created_by');

            MarketingTarget::updateOrCreate(
                ['user_id' => $uid, 'year' => $year, 'month' => $month],
                [
                    'target_amount'   => (int) $target,
                    'commission_rate' => (float) ($row['rate'] ?? 0),
                    'created_by'      => $existingCreatedBy ?? $actor->id,
                    'updated_by'      => $actor->id,
                ]
            );
        }
    }

    private function buildProgress(int $target, float $rate, int $realisasi, bool $hasTarget): array
    {
        return [
            'has_target'     => $hasTarget,
            'target'         => $target,
            'rate'           => $rate,
            'realisasi'      => $realisasi,
            'capaian_persen' => $target > 0 ? round($realisasi / $target * 100, 1) : 0.0,
            'komisi'         => (int) round($rate / 100 * $realisasi),
            'sisa'           => max($target - $realisasi, 0),
        ];
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingTargetServiceTest`
Expected: PASS (6 test).

- [ ] **Step 5: Commit**

```
git add app/Services/MarketingTargetService.php tests/Unit/MarketingTargetServiceTest.php
git commit -m "feat(target): MarketingTargetService (progress, overview, upsert)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Halaman admin "Target Marketing" (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/MarketingTargetController.php`
- Create: `resources/views/marketing-target/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/MarketingTargetTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/MarketingTargetTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetTest extends TestCase
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
    public function manager_can_view_and_save_targets(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'MARKETING SATU']);

        $this->actingAs($manager)->get(route('marketing-target.index'))
            ->assertOk()
            ->assertSee('MARKETING SATU');

        $this->actingAs($manager)->post(route('marketing-target.save'), [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [$mkt->id => ['target' => 9000000, 'rate' => 5]],
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $mkt->id, 'year' => now()->year, 'month' => now()->month, 'target_amount' => 9000000,
        ]);
    }

    /** @test */
    public function marketing_cannot_access_target_admin(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('marketing-target.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=MarketingTargetTest`
Expected: FAIL — route `marketing-target.index` belum ada.

- [ ] **Step 3: Controller**

Create `app/Http/Controllers/Pages/MarketingTargetController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\MarketingTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketingTargetController extends Controller
{
    public function index(Request $request, MarketingTargetService $service)
    {
        $year  = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $rows  = $service->monthlyOverview($year, $month);

        return view('marketing-target.index', compact('rows', 'year', 'month'));
    }

    public function save(Request $request, MarketingTargetService $service)
    {
        $data = $request->validate([
            'year'             => 'required|integer|min:2000|max:2100',
            'month'            => 'required|integer|min:1|max:12',
            'targets'          => 'array',
            'targets.*.target' => 'nullable|numeric|min:0',
            'targets.*.rate'   => 'nullable|numeric|min:0|max:100',
        ]);

        $rows = collect($data['targets'] ?? [])
            ->map(fn ($v, $uid) => ['user_id' => (int) $uid, 'target' => $v['target'] ?? null, 'rate' => $v['rate'] ?? 0])
            ->values()->all();

        $service->upsertMany((int) $data['year'], (int) $data['month'], $rows, Auth::user());

        return redirect()->route('marketing-target.index', ['year' => $data['year'], 'month' => $data['month']])
            ->with('success', 'Target marketing tersimpan.');
    }
}
```

- [ ] **Step 4: Routes**

In `routes/web.php`, add the import near the other `use App\Http\Controllers\Pages\...` imports:

```php
use App\Http\Controllers\Pages\MarketingTargetController;
```

Inside the `Route::middleware('auth')->group(function () {` block (e.g. after the `income` prefix group), add:

```php
    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('marketing-target', [MarketingTargetController::class, 'index'])->name('marketing-target.index');
        Route::post('marketing-target', [MarketingTargetController::class, 'save'])->name('marketing-target.save');
    });
```

- [ ] **Step 5: View**

Create `resources/views/marketing-target/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Target Marketing - SiMAPA')

@section('content')
@php
    $bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                <h6 class="card-title mb-0">Target Marketing — {{ $bulanNama[$month] }} {{ $year }}</h6>
                <form method="GET" class="d-flex" style="gap:.5rem">
                    <select name="month" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
                        @foreach($bulanNama as $m => $nm)
                            <option value="{{ $m }}" {{ $m == $month ? 'selected' : '' }}>{{ $nm }}</option>
                        @endforeach
                    </select>
                    <select name="year" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
                        @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                            <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </form>
            </div>

            <form method="POST" action="{{ route('marketing-target.save') }}">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Marketing</th>
                                <th>Target Pemasukan (Rp)</th>
                                <th>Rate Komisi (%)</th>
                                <th>Realisasi</th>
                                <th>Capaian</th>
                                <th>Komisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                @php $cls = $r['capaian_persen'] >= 100 ? 'bg-success' : ($r['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger'); @endphp
                                <tr>
                                    <td>{{ $r['name'] }}</td>
                                    <td><input type="number" min="0" step="1000" name="targets[{{ $r['user_id'] }}][target]" value="{{ $r['has_target'] ? $r['target'] : '' }}" class="form-control form-control-sm" placeholder="0"></td>
                                    <td><input type="number" min="0" max="100" step="0.5" name="targets[{{ $r['user_id'] }}][rate]" value="{{ $r['has_target'] ? $r['rate'] : '' }}" class="form-control form-control-sm" style="width:90px" placeholder="0"></td>
                                    <td>Rp {{ number_format($r['realisasi'], 0, ',', '.') }}</td>
                                    <td><span class="badge {{ $r['has_target'] ? $cls : 'bg-secondary' }}">{{ $r['has_target'] ? $r['capaian_persen'].'%' : '—' }}</span></td>
                                    <td>Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Belum ada user marketing.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Simpan</button>
            </form>
        </div></div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Menu sidebar**

In `resources/views/layouts/sidebar.blade.php`, find the end of the "Laporan" block — the income menu closing then `@endrole` then the "Akun" category:

```blade
                </li>
            @endrole

            <li class="nav-item nav-category">Akun</li>
```

Replace it with (inserts the Target Marketing item before the Laporan block's `@endrole`):

```blade
                </li>
                @role(['superadmin', 'manager'])
                    <li class="nav-item {{ active_class(['marketing-target']) }}">
                        <a href="{{ route('marketing-target.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Target Marketing</span>
                        </a>
                    </li>
                @endrole
            @endrole

            <li class="nav-item nav-category">Akun</li>
```

- [ ] **Step 7: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingTargetTest`
Expected: PASS (2 test).

- [ ] **Step 8: Commit**

```
git add app/Http/Controllers/Pages/MarketingTargetController.php resources/views/marketing-target/index.blade.php routes/web.php resources/views/layouts/sidebar.blade.php tests/Feature/MarketingTargetTest.php
git commit -m "feat(target): Target Marketing admin page (set targets + report)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Kartu target di dashboard marketing (TDD)

**Files:**
- Modify: `app/Services/MarketingDashboardService.php`
- Modify: `resources/views/dashboard/partials/marketing.blade.php`
- Test: `tests/Feature/MarketingTargetTest.php` (tambah 1 test)

- [ ] **Step 1: Tulis feature test yang gagal**

Tambahkan method ini ke `tests/Feature/MarketingTargetTest.php` (di dalam class, setelah test terakhir):

```php
    /** @test */
    public function marketing_dashboard_shows_target_card(): void
    {
        $mkt = $this->user('marketing');
        MarketingTarget::create([
            'user_id' => $mkt->id, 'year' => now()->year, 'month' => now()->month,
            'target_amount' => 10000000, 'commission_rate' => 5,
        ]);

        $this->actingAs($mkt)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target Bulan Ini')
            ->assertSee('Capaian');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=marketing_dashboard_shows_target_card`
Expected: FAIL — string "Target Bulan Ini" belum ada di dashboard.

- [ ] **Step 3: Tambah key `target` di `MarketingDashboardService::forUser`**

In `app/Services/MarketingDashboardService.php`, in the array returned by `forUser()`, add the `target` key right after the `'rata_rata_order' => ...,` line:

```php
            'total_piutang'   => (int) ((new FinancialReportService())->piutang($user)['kpi']['sisa']),
            'rata_rata_order' => $this->avgOrderValue($uid, $today->year),
            'target'          => app(\App\Services\MarketingTargetService::class)->progressFor($user, $today->year, $today->month),
```

(`MarketingTargetService` di namespace `App\Services` yang sama; pemakaian `app(\App\Services\MarketingTargetService::class)` tidak butuh import tambahan.)

- [ ] **Step 4: Tambah section di partial marketing**

In `resources/views/dashboard/partials/marketing.blade.php`, find this anchor (the income row closes, then the "Statistik" heading):

```blade
</div>

<h6 class="text-muted mb-2 mt-2">Statistik Order &amp; Tagihan</h6>
```

Replace it with (inserts the "Target Bulan Ini" section between them):

```blade
</div>

<h6 class="text-muted mb-2 mt-2">Target Bulan Ini</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            @if($mkt['target']['has_target'])
                @php
                    $t = $mkt['target'];
                    $tcls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>Capaian: <strong>{{ $t['capaian_persen'] }}%</strong></span>
                    <span class="text-muted">Komisi diperoleh: <strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></span>
                </div>
                <div class="progress mb-3" style="height:18px">
                    <div class="progress-bar {{ $tcls }}" role="progressbar" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="row text-center">
                    <div class="col-md-4"><small class="text-muted d-block">Target</small><strong>Rp {{ number_format($t['target'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Realisasi</small><strong class="text-primary">Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Sisa</small><strong class="text-danger">Rp {{ number_format($t['sisa'], 0, ',', '.') }}</strong></div>
                </div>
            @else
                <p class="text-muted mb-0">Target belum ditetapkan untuk bulan ini.</p>
            @endif
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Statistik Order &amp; Tagihan</h6>
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MarketingTargetTest`
Expected: PASS (3 test). Juga pastikan dashboard lama tetap hijau: `php artisan test --filter=MarketingDashboardTest` (3 test PASS).

- [ ] **Step 6: Commit**

```
git add app/Services/MarketingDashboardService.php resources/views/dashboard/partials/marketing.blade.php tests/Feature/MarketingTargetTest.php
git commit -m "feat(target): Target Bulan Ini card on marketing dashboard

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (suite 193 sebelumnya + MarketingTargetServiceTest 6 + MarketingTargetTest 3 = 202). Test lama tetap hijau — `forUser` kini memanggil `progressFor` tetapi tanpa target tetap aman (nilai 0, tabel `tb_marketing_targets` sudah ter-migrate).

- [ ] **Step 2: Smoke manual (opsional)**

Login `manager` (`fitri`/`password`) → menu "Target Marketing" → pilih bulan, isi target & rate untuk seorang marketing, Simpan → cek realisasi/capaian/komisi muncul. Login marketing (`ika`/`password`) → dashboard menampilkan kartu "Target Bulan Ini" dengan progress bar.

---

## Catatan & Risiko

- Migrasi `tb_marketing_targets` harus dijalankan di dev & produksi saat rilis (`php artisan migrate`) — sama seperti fitur sebelumnya, jika tidak halaman/komponen yang query tabel ini akan error.
- `progressFor` (satu marketing) memakai scope kanonik `Payment::approved()->forOrdersOf()`. `monthlyOverview` memakai query grouped dengan kolom di-qualify (`tb_payments.status`) untuk menghindari ambiguitas join dengan `tb_orders.status` — definisi realisasi tetap sama (status `paid`, per `paid_at`).
- Komisi & capaian tidak disimpan (turunan rate × realisasi), jadi perubahan rate langsung tercermin.
- Penambahan key `target` di `forUser` bersifat aditif; test dashboard lama tidak terpengaruh.
