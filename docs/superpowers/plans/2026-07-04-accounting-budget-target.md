# Akuntansi Fase E: Anggaran & Target Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman Anggaran & Target: set target perusahaan bulanan (operasional & order) + realisasi vs target per bulan (dari pemasukan kas) + referensi biaya tetap & skenario.

**Architecture:** `tb_cash_settings` + kolom target. `BudgetTargetService::monthlyAchievement` menghitung realisasi (pemasukan kas dari `CashRecapService`) vs target + %. `BudgetTargetController` + view `accounting/target`.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5. Test: PHPUnit `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_04_000008_add_targets_to_cash_settings.php` (**create**)
- `app/Models/CashSetting.php` (**modify**) — +target fields + singleton defaults
- `app/Services/BudgetTargetService.php` (**create**)
- `app/Http/Controllers/Pages/BudgetTargetController.php` (**create**)
- `resources/views/accounting/target.blade.php` (**create**)
- `routes/web.php` (**modify**); `resources/views/layouts/sidebar.blade.php` (**modify**)
- `tests/Unit/BudgetTargetServiceTest.php`, `tests/Feature/AccountingBudgetTargetTest.php` (**create**)

---

## Konteks untuk implementer

- `CashSetting` (`tb_cash_settings`) singleton (`CashSetting::singleton()`), fillable saldo_awal/tanggal_awal/updated_by/team_members; `singleton()` saat ini `firstOrCreate([], ['saldo_awal'=>0,'team_members'=>8])` — perluas dengan target defaults.
- `CashRecapService::monthlyRecap(int $year): array` → 12 elemen, tiap punya `label` & `totalIn` (pemasukan bulan).
- `CashFixedExpense::monthlyAmount()` (Fase D-2) untuk total biaya tetap/bln.
- Rute `accounting.*` di grup `role:superadmin|accounting`. Sidebar blok `@role(['superadmin','accounting'])` "Keuangan" (Jurnal Kas, Dashboard, Distribusi, Asumsi) — tambah item Anggaran & Target.
- Migrasi terakhir: `2026_07_04_000007`. Baru: `2026_07_04_000008`.

---

### Task 1: Migrasi + CashSetting + BudgetTargetService + unit test

**Files:** migrasi `2026_07_04_000008`; `CashSetting.php`; `BudgetTargetService.php`; `tests/Unit/BudgetTargetServiceTest.php`

- [ ] **Step 1: Unit test (gagal dulu)** — `tests/Unit/BudgetTargetServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use App\Services\CashRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BudgetTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BudgetTargetService
    {
        return new BudgetTargetService(new CashRecapService());
    }

    private function income(string $tanggal, $amount): void
    {
        CashEntry::create(['tanggal' => $tanggal, 'jenis' => 'pemasukan', 'amount' => $amount, 'keterangan' => 'x', 'source' => 'manual']);
    }

    /** @test */
    public function monthly_achievement_computes_pct_and_status(): void
    {
        CashSetting::singleton()->update(['target_operasional' => 1000000]);
        $this->income('2026-06-05', 800000);
        $this->income('2026-07-05', 1200000);

        $m = $this->service()->monthlyAchievement(2026);

        $this->assertSame(800000.0, $m[5]['realisasi']); // indeks 5 = bulan 6 (Jun)
        $this->assertSame(80, $m[5]['pct']);
        $this->assertFalse($m[5]['achieved']);
        $this->assertSame(120, $m[6]['pct']); // Jul
        $this->assertTrue($m[6]['achieved']);
    }

    /** @test */
    public function target_zero_is_guarded(): void
    {
        $this->income('2026-06-05', 800000); // target default 0
        $m = $this->service()->monthlyAchievement(2026);
        $this->assertSame(0, $m[5]['pct']);
        $this->assertFalse($m[5]['achieved']);
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (kolom/service belum ada).
Run: `php artisan test --env=testing tests/Unit/BudgetTargetServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Migrasi `2026_07_04_000008_add_targets_to_cash_settings.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->decimal('target_operasional', 15, 2)->default(0)->after('team_members');
            $table->decimal('target_order', 15, 2)->default(0)->after('target_operasional');
        });
    }

    public function down(): void
    {
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->dropColumn(['target_operasional', 'target_order']);
        });
    }
};
```

- [ ] **Step 4: `app/Models/CashSetting.php` — +target fields + singleton defaults** — ubah fillable, casts, dan `singleton()`:

```php
    protected $fillable = ['saldo_awal', 'tanggal_awal', 'updated_by', 'team_members', 'target_operasional', 'target_order'];

    protected $casts = ['saldo_awal' => 'decimal:2', 'tanggal_awal' => 'date', 'team_members' => 'integer', 'target_operasional' => 'decimal:2', 'target_order' => 'decimal:2'];
```

Dan method `singleton()`:

```php
    public static function singleton(): self
    {
        return static::firstOrCreate([], ['saldo_awal' => 0, 'team_members' => 8, 'target_operasional' => 0, 'target_order' => 0]);
    }
```

- [ ] **Step 5: Buat `app/Services/BudgetTargetService.php`**

```php
<?php

namespace App\Services;

use App\Models\CashSetting;

class BudgetTargetService
{
    public function __construct(private CashRecapService $recap) {}

    /** @return array<int,array> 12 bulan: month,label,realisasi,target,pct,achieved */
    public function monthlyAchievement(int $year): array
    {
        $target = (float) CashSetting::singleton()->target_operasional;
        $recap  = $this->recap->monthlyRecap($year);

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $realisasi = (float) $recap[$m - 1]['totalIn'];
            $pct = $target > 0 ? (int) round($realisasi / $target * 100) : 0;
            $out[] = [
                'month'     => $m,
                'label'     => $recap[$m - 1]['label'],
                'realisasi' => $realisasi,
                'target'    => $target,
                'pct'       => $pct,
                'achieved'  => $target > 0 && $realisasi >= $target,
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 6: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/BudgetTargetServiceTest.php`
Expected: 2 passed.

- [ ] **Step 7: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: `2026_07_04_000008_add_targets_to_cash_settings ... DONE`.

```bash
git add database/migrations/2026_07_04_000008_add_targets_to_cash_settings.php app/Models/CashSetting.php app/Services/BudgetTargetService.php tests/Unit/BudgetTargetServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): target perusahaan (operasional/order) + BudgetTargetService realisasi

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: Controller + rute + view + sidebar + feature test

**Files:** `BudgetTargetController.php`; `routes/web.php`; `resources/views/accounting/target.blade.php`; `resources/views/layouts/sidebar.blade.php`; `tests/Feature/AccountingBudgetTargetTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/AccountingBudgetTargetTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingBudgetTargetTest extends TestCase
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
    public function accounting_opens_target_page(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.target', ['year' => 2026]))
            ->assertOk()->assertSee('Target Operasional')->assertSee('Minimum');
    }

    /** @test */
    public function set_target_saved(): void
    {
        $this->actingAs($this->user('superadmin'))->put(route('accounting.target.update'), [
            'target_operasional' => 80000000, 'target_order' => 200000000,
        ])->assertRedirect();

        $s = CashSetting::singleton();
        $this->assertSame('80000000.00', $s->target_operasional);
        $this->assertSame('200000000.00', $s->target_order);
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.target'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [accounting.target] not defined`).
Run: `php artisan test --env=testing tests/Feature/AccountingBudgetTargetTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Pages/BudgetTargetController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use Illuminate\Http\Request;

class BudgetTargetController extends Controller
{
    const SCENARIOS = ['Minimum' => 150000000, 'Aman' => 200000000, 'Ideal' => 250000000, 'Agresif' => 300000000];

    public function __construct(private BudgetTargetService $service) {}

    public function index(Request $request)
    {
        $year    = (int) $request->query('year', now()->year);
        $setting = CashSetting::singleton();
        $monthly = $this->service->monthlyAchievement($year);

        return view('accounting.target', [
            'year'         => $year,
            'setting'      => $setting,
            'monthly'      => $monthly,
            'ytdRealisasi' => (float) array_sum(array_column($monthly, 'realisasi')),
            'ytdTarget'    => (float) $setting->target_operasional * 12,
            'fixedMonthly' => (float) CashFixedExpense::where('active', true)->get()->sum(fn ($e) => $e->monthlyAmount()),
            'scenarios'    => self::SCENARIOS,
        ]);
    }

    public function updateTarget(Request $request)
    {
        $data = $request->validate(['target_operasional' => 'required|numeric|min:0', 'target_order' => 'required|numeric|min:0']);
        CashSetting::singleton()->update($data);

        return back()->with('success', 'Target diperbarui.');
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`** — di grup `role:superadmin|accounting`, setelah rute `accounting.assumption.*`, tambah:

```php
        Route::get('accounting/target', [\App\Http\Controllers\Pages\BudgetTargetController::class, 'index'])->name('accounting.target');
        Route::put('accounting/target', [\App\Http\Controllers\Pages\BudgetTargetController::class, 'updateTarget'])->name('accounting.target.update');
```

- [ ] **Step 5: View `resources/views/accounting/target.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Anggaran & Target - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Anggaran & Target</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
        <button class="btn btn-sm btn-outline-secondary">Tahun</button>
    </form>
</div>

<div class="row">
    <div class="col-md-7 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="card-title">Set Target Perusahaan (per Bulan)</h6>
        <form method="POST" action="{{ route('accounting.target.update') }}" class="d-flex gap-2 align-items-end flex-wrap">
            @csrf @method('PUT')
            <div><label class="form-label small mb-1">Target Operasional (Rp/bln)</label><input type="number" name="target_operasional" value="{{ (int) $setting->target_operasional }}" min="0" class="form-control form-control-sm" style="width:180px"></div>
            <div><label class="form-label small mb-1">Target Order (Rp/bln)</label><input type="number" name="target_order" value="{{ (int) $setting->target_order }}" min="0" class="form-control form-control-sm" style="width:180px"></div>
            <button class="btn btn-sm btn-primary">Simpan Target</button>
        </form>
        <p class="text-muted small mb-0 mt-2">Total Biaya Tetap/bln (Asumsi): <strong>{{ $rp($fixedMonthly) }}</strong> · Target order ≈ operasional ÷ 40%.</p>
    </div></div></div>
    <div class="col-md-5 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="card-title">Skenario Order/Bulan</h6>
        <table class="table table-sm mb-0">
            @foreach($scenarios as $label => $amount)
                <tr><td>{{ $label }}</td><td class="text-end">{{ $rp($amount) }}</td></tr>
            @endforeach
        </table>
    </div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Realisasi vs Target {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Bulan</th><th class="text-end">Pemasukan Kas (Realisasi)</th><th class="text-end">Target Operasional</th><th class="text-end">% Pencapaian</th><th>Status</th></tr></thead>
            <tbody>
                @foreach($monthly as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        <td class="text-end">{{ $rp($r['realisasi']) }}</td>
                        <td class="text-end">{{ $rp($r['target']) }}</td>
                        <td class="text-end"><span class="badge {{ $r['achieved'] ? 'bg-success' : ($r['pct'] > 0 ? 'bg-warning text-dark' : 'bg-light text-muted border') }}">{{ $r['pct'] }}%</span></td>
                        <td>{{ $r['achieved'] ? '✓ Tercapai' : ($r['target'] > 0 ? 'Kurang' : '—') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>YTD</td>
                <td class="text-end">{{ $rp($ytdRealisasi) }}</td>
                <td class="text-end">{{ $rp($ytdTarget) }}</td>
                <td class="text-end">{{ $ytdTarget > 0 ? (int) round($ytdRealisasi / $ytdTarget * 100) : 0 }}%</td>
                <td></td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div></div>
@endsection
```

- [ ] **Step 6: Menu sidebar `resources/views/layouts/sidebar.blade.php`** — di blok `@role(['superadmin','accounting'])` "Keuangan", setelah item Asumsi, tambah:

```blade
                <li class="nav-item {{ active_class(['accounting/target']) }}">
                    <a href="{{ route('accounting.target') }}" class="nav-link">
                        <i class="link-icon" data-feather="target"></i>
                        <span class="link-title">Anggaran & Target</span>
                    </a>
                </li>
```

- [ ] **Step 7: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/AccountingBudgetTargetTest.php`
Expected: 3 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/BudgetTargetController.php routes/web.php resources/views/accounting/target.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/AccountingBudgetTargetTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): halaman Anggaran & Target (set target + realisasi vs target) + menu

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_04_000008_add_targets_to_cash_settings ... DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 458 + 5 baru = 463 passed).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 kolom target + singleton defaults → Task 1. ✓
- §3 service monthlyAchievement (pct/achieved/guard) → Task 1 + unit test. ✓
- §4 controller+rute (index+ytd+fixed+scenarios, updateTarget, role gate) → Task 2 + feature test (buka, set target, marketing 403). ✓
- §5 view (set target + referensi + realisasi vs target + YTD) + sidebar → Task 2 Step 5-6. ✓
- §6 test → Task 1/2. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step.

**3. Type/nama konsistensi:** `CashSetting` +target_operasional/target_order (fillable/casts/singleton). `BudgetTargetService::monthlyAchievement` return keys month/label/realisasi/target/pct/achieved konsisten controller↔view↔test. `BudgetTargetController::SCENARIOS`, `index` vars (year/setting/monthly/ytdRealisasi/ytdTarget/fixedMonthly/scenarios) dikirim view. Rute `accounting.target`/`accounting.target.update` konsisten controller↔view↔test↔sidebar. `CashRecapService::monthlyRecap[$m-1]['totalIn']` sbg realisasi; `CashFixedExpense::monthlyAmount` utk fixedMonthly.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 3). Test via `.env.testing`.
