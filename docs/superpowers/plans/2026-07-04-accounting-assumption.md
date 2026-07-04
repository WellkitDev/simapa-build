# Akuntansi Fase D-2: Asumsi (Margin + Biaya Tetap) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman Asumsi: kelola margin per produk (CRUD) + biaya tetap bulanan (CRUD, referensi, + total/bulan).

**Architecture:** 2 tabel referensi (`tb_cash_margins`, `tb_cash_fixed_expenses`) + `CashAssumptionController` + view `accounting/assumption`. Read/CRUD, tak menyentuh Jurnal Kas.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5. Test: PHPUnit `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_04_000007_create_cash_margins_and_fixed_expenses.php` (**create**)
- `app/Models/CashMargin.php`, `app/Models/CashFixedExpense.php` (**create**)
- `app/Http/Controllers/Pages/CashAssumptionController.php` (**create**)
- `resources/views/accounting/assumption.blade.php` (**create**)
- `routes/web.php` (**modify**); `resources/views/layouts/sidebar.blade.php` (**modify**)
- `tests/Unit/CashFixedExpenseTest.php`, `tests/Feature/AccountingAssumptionTest.php` (**create**)

---

## Konteks untuk implementer

- Rute `accounting.*` di grup `role:superadmin|accounting`. Role `accounting` sudah ada. Sidebar punya blok `@role(['superadmin','accounting'])` "Keuangan" (Jurnal Kas, Dashboard Keuangan, Distribusi Profit) — tambah item Asumsi.
- Migrasi terakhir: `2026_07_04_000006`. Baru: `2026_07_04_000007`.
- Pola form edit+hapus: dua `<form>` bersaudara dalam satu `<div class="d-flex">` (JANGAN nested — form edit ditutup sebelum form hapus).

---

### Task 1: Migrasi + model + unit test

**Files:** migrasi `2026_07_04_000007`; `CashMargin.php`; `CashFixedExpense.php`; `tests/Unit/CashFixedExpenseTest.php`

- [ ] **Step 1: Unit test (gagal dulu)** — `tests/Unit/CashFixedExpenseTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashFixedExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashFixedExpenseTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function monthly_amount_derives_from_period(): void
    {
        $tahunan = CashFixedExpense::create(['name' => 'Hosting', 'period' => 'tahunan', 'amount' => 1200000]);
        $bulanan = CashFixedExpense::create(['name' => 'Saving', 'period' => 'bulanan', 'amount' => 500000]);

        $this->assertSame(100000.0, $tahunan->monthlyAmount()); // 1.200.000 / 12
        $this->assertSame(500000.0, $bulanan->monthlyAmount());
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (model/tabel belum ada).
Run: `php artisan test --env=testing tests/Unit/CashFixedExpenseTest.php`
Expected: FAIL.

- [ ] **Step 3: Migrasi `2026_07_04_000007_create_cash_margins_and_fixed_expenses.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_margins', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('label');
            $table->decimal('margin_pct', 6, 2);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tb_cash_fixed_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('period'); // bulanan | tahunan
            $table->decimal('amount', 15, 2);
            $table->text('note')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        $margins = [
            ['M_ART_S2', 'Artikel Mandiri Sinta 2-3', 25, 1],
            ['M_ART_S4', 'Artikel Mandiri Sinta 4-6', 30, 2],
            ['M_KOL_S2', 'Artikel Kolaborasi Sinta 2-3', 25, 3],
            ['M_KOL_S4', 'Artikel Kolaborasi Sinta 4-6', 30, 4],
            ['M_BK_ALL', 'Buku (semua jenis)', 87, 5],
        ];
        foreach ($margins as [$code, $label, $pct, $pos]) {
            DB::table('tb_cash_margins')->insert(['code' => $code, 'label' => $label, 'margin_pct' => $pct, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now]);
        }

        $expenses = [
            ['Hosting Avidpedia', 'tahunan', 975000, 1],
            ['Hosting Jurnal', 'tahunan', 1755000, 2],
            ['Domain Avidpedia', 'tahunan', 205000, 3],
            ['Domain Jurnal', 'tahunan', 205000, 4],
            ['Keanggotaan DOI PubMEDIA', 'tahunan', 750000, 5],
            ['Saving Bulanan', 'bulanan', 500000, 6],
        ];
        foreach ($expenses as [$name, $period, $amount, $pos]) {
            DB::table('tb_cash_fixed_expenses')->insert(['name' => $name, 'period' => $period, 'amount' => $amount, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_fixed_expenses');
        Schema::dropIfExists('tb_cash_margins');
    }
};
```

- [ ] **Step 4: Model `app/Models/CashMargin.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMargin extends Model
{
    protected $table = 'tb_cash_margins';

    protected $fillable = ['code', 'label', 'margin_pct', 'active', 'position'];

    protected $casts = ['margin_pct' => 'decimal:2', 'active' => 'boolean'];

    public function scopeActive($query) { return $query->where('active', true); }
}
```

- [ ] **Step 5: Model `app/Models/CashFixedExpense.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashFixedExpense extends Model
{
    protected $table = 'tb_cash_fixed_expenses';

    protected $fillable = ['name', 'period', 'amount', 'note', 'active', 'position'];

    protected $casts = ['amount' => 'decimal:2', 'active' => 'boolean'];

    const PERIODS = ['bulanan' => 'Bulanan', 'tahunan' => 'Tahunan'];

    public function monthlyAmount(): float
    {
        return $this->period === 'tahunan' ? (float) $this->amount / 12 : (float) $this->amount;
    }
}
```

- [ ] **Step 6: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/CashFixedExpenseTest.php`
Expected: 1 passed.

- [ ] **Step 7: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: `2026_07_04_000007_create_cash_margins_and_fixed_expenses ... DONE`.

```bash
git add database/migrations/2026_07_04_000007_create_cash_margins_and_fixed_expenses.php app/Models/CashMargin.php app/Models/CashFixedExpense.php tests/Unit/CashFixedExpenseTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): tabel cash_margins + cash_fixed_expenses (+seed) + model

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: Controller + rute + view + sidebar + feature test

**Files:** `CashAssumptionController.php`; `routes/web.php`; `resources/views/accounting/assumption.blade.php`; `resources/views/layouts/sidebar.blade.php`; `tests/Feature/AccountingAssumptionTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/AccountingAssumptionTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashMargin;
use App\Models\CashFixedExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingAssumptionTest extends TestCase
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
    public function accounting_opens_assumption(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.assumption'))
            ->assertOk()->assertSee('Buku (semua jenis)')->assertSee('Hosting Avidpedia');
    }

    /** @test */
    public function crud_margin(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.assumption.margin.store'), ['code' => 'M_NEW', 'label' => 'Produk Baru', 'margin_pct' => 40])->assertRedirect();
        $m = CashMargin::where('code', 'M_NEW')->first();
        $this->assertSame('40.00', $m->margin_pct);

        $this->actingAs($sa)->put(route('accounting.assumption.margin.update', $m->id), ['code' => 'M_NEW', 'label' => 'Produk Ubah', 'margin_pct' => 45])->assertRedirect();
        $this->assertSame('Produk Ubah', $m->fresh()->label);

        $this->actingAs($sa)->delete(route('accounting.assumption.margin.destroy', $m->id))->assertRedirect();
        $this->assertNull(CashMargin::find($m->id));
    }

    /** @test */
    public function crud_expense(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.assumption.expense.store'), ['name' => 'Listrik', 'period' => 'bulanan', 'amount' => 1000000])->assertRedirect();
        $e = CashFixedExpense::where('name', 'Listrik')->first();
        $this->assertSame(1000000.0, $e->monthlyAmount());

        $this->actingAs($sa)->put(route('accounting.assumption.expense.update', $e->id), ['name' => 'Listrik', 'period' => 'tahunan', 'amount' => 1200000])->assertRedirect();
        $this->assertSame(100000.0, $e->fresh()->monthlyAmount());

        $this->actingAs($sa)->delete(route('accounting.assumption.expense.destroy', $e->id))->assertRedirect();
        $this->assertNull(CashFixedExpense::find($e->id));
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.assumption'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [accounting.assumption] not defined`).
Run: `php artisan test --env=testing tests/Feature/AccountingAssumptionTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Pages/CashAssumptionController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashMargin;
use Illuminate\Http\Request;

class CashAssumptionController extends Controller
{
    public function index()
    {
        $expenses = CashFixedExpense::orderBy('position')->get();

        return view('accounting.assumption', [
            'margins'      => CashMargin::orderBy('position')->get(),
            'expenses'     => $expenses,
            'totalMonthly' => (float) $expenses->where('active', true)->sum(fn ($e) => $e->monthlyAmount()),
        ]);
    }

    public function storeMargin(Request $request)
    {
        $data = $request->validate(['code' => 'nullable|string|max:50', 'label' => 'required|string|max:150', 'margin_pct' => 'required|numeric|min:0|max:100']);
        $data['active'] = true;
        CashMargin::create($data);

        return back()->with('success', 'Margin ditambahkan.');
    }

    public function updateMargin(Request $request, int $id)
    {
        $margin = CashMargin::findOrFail($id);
        $data = $request->validate(['code' => 'nullable|string|max:50', 'label' => 'required|string|max:150', 'margin_pct' => 'required|numeric|min:0|max:100']);
        $data['active'] = $request->boolean('active');
        $margin->update($data);

        return back()->with('success', 'Margin diperbarui.');
    }

    public function destroyMargin(int $id)
    {
        CashMargin::findOrFail($id)->delete();

        return back()->with('success', 'Margin dihapus.');
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'period' => 'required|in:bulanan,tahunan', 'amount' => 'required|numeric|min:0', 'note' => 'nullable|string']);
        $data['active'] = true;
        CashFixedExpense::create($data);

        return back()->with('success', 'Biaya tetap ditambahkan.');
    }

    public function updateExpense(Request $request, int $id)
    {
        $expense = CashFixedExpense::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:150', 'period' => 'required|in:bulanan,tahunan', 'amount' => 'required|numeric|min:0', 'note' => 'nullable|string']);
        $data['active'] = $request->boolean('active');
        $expense->update($data);

        return back()->with('success', 'Biaya tetap diperbarui.');
    }

    public function destroyExpense(int $id)
    {
        CashFixedExpense::findOrFail($id)->delete();

        return back()->with('success', 'Biaya tetap dihapus.');
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`** — di grup `role:superadmin|accounting`, setelah rute `accounting.distribution.*`, tambah:

```php
        Route::get('accounting/assumption', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'index'])->name('accounting.assumption');
        Route::post('accounting/assumption/margin', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'storeMargin'])->name('accounting.assumption.margin.store');
        Route::put('accounting/assumption/margin/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'updateMargin'])->name('accounting.assumption.margin.update')->whereNumber('id');
        Route::delete('accounting/assumption/margin/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'destroyMargin'])->name('accounting.assumption.margin.destroy')->whereNumber('id');
        Route::post('accounting/assumption/expense', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'storeExpense'])->name('accounting.assumption.expense.store');
        Route::put('accounting/assumption/expense/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'updateExpense'])->name('accounting.assumption.expense.update')->whereNumber('id');
        Route::delete('accounting/assumption/expense/{id}', [\App\Http\Controllers\Pages\CashAssumptionController::class, 'destroyExpense'])->name('accounting.assumption.expense.destroy')->whereNumber('id');
```

- [ ] **Step 5: View `resources/views/accounting/assumption.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Asumsi - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h5 class="mb-3">Asumsi Keuangan</h5>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Margin per Produk</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Kode</th><th>Label</th><th style="width:120px">Margin %</th><th style="width:70px">Aktif</th><th style="width:120px">Aksi</th></tr></thead>
            <tbody>
                @foreach($margins as $m)
                    <tr>
                        <td colspan="5" class="p-1">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <form method="POST" action="{{ route('accounting.assumption.margin.update', $m->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                                    @csrf @method('PUT')
                                    <input name="code" value="{{ $m->code }}" class="form-control form-control-sm" style="max-width:120px" placeholder="Kode">
                                    <input name="label" value="{{ $m->label }}" class="form-control form-control-sm" style="max-width:280px">
                                    <input type="number" step="0.01" name="margin_pct" value="{{ (float) $m->margin_pct }}" class="form-control form-control-sm" style="max-width:100px">
                                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $m->active ? 'checked' : '' }}> aktif</label>
                                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                                </form>
                                <form method="POST" action="{{ route('accounting.assumption.margin.destroy', $m->id) }}" data-confirm="Hapus margin ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <form method="POST" action="{{ route('accounting.assumption.margin.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
        @csrf
        <input name="code" placeholder="Kode" class="form-control form-control-sm" style="max-width:120px">
        <input name="label" placeholder="Label produk…" class="form-control form-control-sm" style="max-width:280px">
        <input type="number" step="0.01" name="margin_pct" placeholder="%" class="form-control form-control-sm" style="max-width:100px">
        <button class="btn btn-xs btn-outline-success">+ Tambah Margin</button>
    </form>
</div></div></div></div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Biaya Tetap Bulanan</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Nama</th><th>Periode</th><th class="text-end">Nominal</th><th class="text-end">Per Bulan</th><th style="width:70px">Aktif</th><th style="width:120px">Aksi</th></tr></thead>
            <tbody>
                @foreach($expenses as $e)
                    <tr>
                        <td colspan="6" class="p-1">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <form method="POST" action="{{ route('accounting.assumption.expense.update', $e->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $e->name }}" class="form-control form-control-sm" style="max-width:220px">
                                    <select name="period" class="form-select form-select-sm" style="max-width:110px">@foreach(\App\Models\CashFixedExpense::PERIODS as $pk => $pl)<option value="{{ $pk }}" {{ $e->period === $pk ? 'selected' : '' }}>{{ $pl }}</option>@endforeach</select>
                                    <input type="number" name="amount" value="{{ (int) $e->amount }}" class="form-control form-control-sm" style="max-width:140px">
                                    <span class="text-muted small">= {{ $rp($e->monthlyAmount()) }}/bln</span>
                                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $e->active ? 'checked' : '' }}> aktif</label>
                                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                                </form>
                                <form method="POST" action="{{ route('accounting.assumption.expense.destroy', $e->id) }}" data-confirm="Hapus biaya ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="3">Total Biaya Tetap / Bulan</td><td class="text-end">{{ $rp($totalMonthly) }}</td><td colspan="2"></td></tr></tfoot>
        </table>
    </div>
    <form method="POST" action="{{ route('accounting.assumption.expense.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
        @csrf
        <input name="name" placeholder="Nama biaya…" class="form-control form-control-sm" style="max-width:220px">
        <select name="period" class="form-select form-select-sm" style="max-width:110px"><option value="bulanan">Bulanan</option><option value="tahunan">Tahunan</option></select>
        <input type="number" name="amount" placeholder="Nominal" class="form-control form-control-sm" style="max-width:140px">
        <button class="btn btn-xs btn-outline-success">+ Tambah Biaya</button>
    </form>
</div></div></div></div>
@endsection
```

- [ ] **Step 6: Menu sidebar `resources/views/layouts/sidebar.blade.php`** — di blok `@role(['superadmin','accounting'])` "Keuangan", setelah item Distribusi Profit, tambah:

```blade
                <li class="nav-item {{ active_class(['accounting/assumption']) }}">
                    <a href="{{ route('accounting.assumption') }}" class="nav-link">
                        <i class="link-icon" data-feather="sliders"></i>
                        <span class="link-title">Asumsi</span>
                    </a>
                </li>
```

- [ ] **Step 7: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/AccountingAssumptionTest.php`
Expected: 4 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/CashAssumptionController.php routes/web.php resources/views/accounting/assumption.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/AccountingAssumptionTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): halaman Asumsi (kelola margin + biaya tetap + total/bulan) + menu

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_04_000007_create_cash_margins_and_fixed_expenses ... DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 453 + 5 baru = 458 passed).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 model/tabel/seed (margins + fixed expenses) → Task 1. ✓
- §2 `monthlyAmount` → Task 1 model + unit test. ✓
- §3 controller+rute (index+total, margin/expense CRUD, role gate) → Task 2 + feature test (buka, CRUD margin, CRUD expense, marketing 403). ✓
- §4 view (2 seksi editable + tambah + total/bulan) + sidebar → Task 2 Step 5-6. ✓
- §5 test → Task 1/2. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step. (Form edit+hapus 2 sibling, tak nested.)

**3. Type/nama konsistensi:** tabel `tb_cash_margins`/`tb_cash_fixed_expenses`; model `CashMargin`(scopeActive)/`CashFixedExpense`(PERIODS, monthlyAmount). Controller method margin/expense store/update/destroy + index (margins/expenses/totalMonthly) konsisten view↔test. Rute `accounting.assumption` + `.margin.*`/`.expense.*` konsisten controller↔view↔test↔sidebar.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 3). Test via `.env.testing`.
