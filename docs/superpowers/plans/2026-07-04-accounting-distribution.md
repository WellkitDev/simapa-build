# Akuntansi Fase D: Distribusi Profit (Fleksibel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distribusi/bagi laba fleksibel (tiap pos percent atau flat, dapat di-CRUD) + kalkulator dengan profit default = laba kas bulan terpilih + pembagian per anggota tim.

**Architecture:** `tb_cash_distributions` (aturan percent/flat/per_member) + `tb_cash_settings.team_members`. `ProfitDistributionService::distribute` menghitung alokasi/per-orang/sisa. `ProfitDistributionController` + view `accounting/distribution`.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5. Test: PHPUnit `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_04_000006_create_cash_distributions_and_team.php` (**create**)
- `app/Models/CashDistribution.php` (**create**); `app/Models/CashSetting.php` (**modify**, +team_members)
- `app/Services/ProfitDistributionService.php` (**create**)
- `app/Http/Controllers/Pages/ProfitDistributionController.php` (**create**)
- `resources/views/accounting/distribution.blade.php` (**create**)
- `routes/web.php` (**modify**); `resources/views/layouts/sidebar.blade.php` (**modify**)
- `tests/Unit/ProfitDistributionServiceTest.php`, `tests/Feature/AccountingDistributionTest.php` (**create**)

---

## Konteks untuk implementer

- `CashSetting` (`tb_cash_settings`) singleton (`CashSetting::singleton()`), fillable saldo_awal/tanggal_awal/updated_by. Tambah `team_members`.
- `CashRecapService::monthlyRecap(int $year): array` → 12 elemen, tiap punya key `laba` (indeks 0=Jan … 11=Des).
- Rute `accounting.*` di grup `role:superadmin|accounting`. Role `accounting` sudah ada. Sidebar punya blok `@role(['superadmin','accounting'])` "Keuangan" (Jurnal Kas + Dashboard Keuangan) — tambah item Distribusi Profit di dalamnya.
- Migrasi terakhir: `2026_07_04_000005`. Baru: `2026_07_04_000006`.

---

### Task 1: Migrasi + model

**Files:** migrasi `2026_07_04_000006`; `CashDistribution.php`; `CashSetting.php`

- [ ] **Step 1: Migrasi `2026_07_04_000006_create_cash_distributions_and_team.php`**

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
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->unsignedInteger('team_members')->default(8)->after('tanggal_awal');
        });

        Schema::create('tb_cash_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');            // percent | flat
            $table->decimal('value', 15, 2);   // % bila percent; Rp bila flat
            $table->boolean('per_member')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('tb_cash_distributions')->insert([
            ['name' => 'Harta/Pemilik', 'type' => 'percent', 'value' => 5, 'per_member' => false, 'active' => true, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Saving + Dana Tak Terduga', 'type' => 'percent', 'value' => 10, 'per_member' => false, 'active' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Fee Tim', 'type' => 'percent', 'value' => 85, 'per_member' => true, 'active' => true, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_distributions');
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->dropColumn('team_members');
        });
    }
};
```

- [ ] **Step 2: Model `app/Models/CashDistribution.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDistribution extends Model
{
    protected $table = 'tb_cash_distributions';

    protected $fillable = ['name', 'type', 'value', 'per_member', 'active', 'position'];

    protected $casts = ['value' => 'decimal:2', 'per_member' => 'boolean', 'active' => 'boolean'];

    const TYPES = ['percent' => 'Persen', 'flat' => 'Flat'];

    public function scopeActive($query) { return $query->where('active', true); }
}
```

- [ ] **Step 3: `app/Models/CashSetting.php` — +team_members** — ubah fillable & casts:

```php
    protected $fillable = ['saldo_awal', 'tanggal_awal', 'updated_by', 'team_members'];

    protected $casts = ['saldo_awal' => 'decimal:2', 'tanggal_awal' => 'date', 'team_members' => 'integer'];
```

- [ ] **Step 4: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: `2026_07_04_000006_create_cash_distributions_and_team ... DONE`.

```bash
git add database/migrations/2026_07_04_000006_create_cash_distributions_and_team.php app/Models/CashDistribution.php app/Models/CashSetting.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): tabel cash_distributions (aturan percent/flat) + team_members

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: ProfitDistributionService + unit test

**Files:** `app/Services/ProfitDistributionService.php`; `tests/Unit/ProfitDistributionServiceTest.php`

- [ ] **Step 1: Unit test (gagal dulu)** — `tests/Unit/ProfitDistributionServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashDistribution;
use App\Services\ProfitDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProfitDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function distributes_percent_and_per_member(): void
    {
        // seed migrasi: Harta 5%, Saving 10%, Fee 85% per_member; team_members default 8
        $r = (new ProfitDistributionService())->distribute(1000000, null);
        $lines = $r['lines']->keyBy('name');

        $this->assertSame(8, $r['members']);
        $this->assertSame(50000.0, $lines['Harta/Pemilik']['amount']);
        $this->assertSame(100000.0, $lines['Saving + Dana Tak Terduga']['amount']);
        $this->assertSame(850000.0, $lines['Fee Tim']['amount']);
        $this->assertSame(106250.0, $lines['Fee Tim']['perPerson']); // 850000 / 8
        $this->assertNull($lines['Harta/Pemilik']['perPerson']);
        $this->assertSame(1000000.0, $r['totalAllocated']);
        $this->assertSame(0.0, $r['remainder']);
    }

    /** @test */
    public function flat_rule_is_fixed_and_members_min_one(): void
    {
        CashDistribution::create(['name' => 'PPn Bank', 'type' => 'flat', 'value' => 20000, 'per_member' => false, 'active' => true, 'position' => 4]);

        $r = (new ProfitDistributionService())->distribute(1000000, 0); // members 0 → min 1
        $lines = $r['lines']->keyBy('name');

        $this->assertSame(1, $r['members']);
        $this->assertSame(20000.0, $lines['PPn Bank']['amount']); // flat, tak tergantung profit
        $this->assertSame(850000.0, $lines['Fee Tim']['perPerson']); // members 1
        $this->assertSame(1020000.0, $r['totalAllocated']);
        $this->assertSame(-20000.0, $r['remainder']); // profit − alokasi (over-allocated)
    }

    /** @test */
    public function inactive_rule_excluded(): void
    {
        CashDistribution::where('name', 'Fee Tim')->update(['active' => false]);
        $r = (new ProfitDistributionService())->distribute(1000000, null);
        $this->assertFalse($r['lines']->contains('name', 'Fee Tim'));
        $this->assertSame(150000.0, $r['totalAllocated']); // 5% + 10%
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (service belum ada).
Run: `php artisan test --env=testing tests/Unit/ProfitDistributionServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Buat `app/Services/ProfitDistributionService.php`**

```php
<?php

namespace App\Services;

use App\Models\CashDistribution;
use App\Models\CashSetting;

class ProfitDistributionService
{
    /** @return array profit,members,lines(Collection),totalAllocated,remainder */
    public function distribute(float $profit, ?int $members = null): array
    {
        $members = $members ?? (int) CashSetting::singleton()->team_members;
        if ($members < 1) {
            $members = 1;
        }

        $lines = CashDistribution::active()->orderBy('position')->get()->map(function ($r) use ($profit, $members) {
            $amount = $r->type === 'percent' ? round((float) $r->value / 100 * $profit) : (float) $r->value;
            return [
                'name'       => $r->name,
                'type'       => $r->type,
                'value'      => (float) $r->value,
                'per_member' => (bool) $r->per_member,
                'amount'     => $amount,
                'perPerson'  => $r->per_member ? $amount / $members : null,
            ];
        })->values();

        $totalAllocated = (float) $lines->sum('amount');

        return [
            'profit'         => $profit,
            'members'        => $members,
            'lines'          => $lines,
            'totalAllocated' => $totalAllocated,
            'remainder'      => $profit - $totalAllocated,
        ];
    }
}
```

- [ ] **Step 4: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/ProfitDistributionServiceTest.php`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProfitDistributionService.php tests/Unit/ProfitDistributionServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): ProfitDistributionService (percent/flat + per-anggota + sisa)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Controller + rute + view + sidebar + feature test

**Files:** `ProfitDistributionController.php`; `routes/web.php`; `resources/views/accounting/distribution.blade.php`; `resources/views/layouts/sidebar.blade.php`; `tests/Feature/AccountingDistributionTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/AccountingDistributionTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashEntry;
use App\Models\CashDistribution;
use App\Models\CashSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingDistributionTest extends TestCase
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
    public function accounting_opens_with_month_laba_default_profit(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 800000, 'keterangan' => 'x', 'source' => 'manual']);

        $this->actingAs($this->user('accounting'))->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee('Harta/Pemilik')->assertSee('Fee Tim');
    }

    /** @test */
    public function crud_rule_and_members(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.distribution.rule.store'), ['name' => 'PPn Bank', 'type' => 'flat', 'value' => 20000])->assertRedirect();
        $rule = CashDistribution::where('name', 'PPn Bank')->first();
        $this->assertSame('flat', $rule->type);

        $this->actingAs($sa)->put(route('accounting.distribution.rule.update', $rule->id), ['name' => 'PPn Bank', 'type' => 'flat', 'value' => 25000])->assertRedirect();
        $this->assertSame('25000.00', $rule->fresh()->value);

        $this->actingAs($sa)->put(route('accounting.distribution.settings'), ['team_members' => 6])->assertRedirect();
        $this->assertSame(6, CashSetting::singleton()->team_members);

        $this->actingAs($sa)->delete(route('accounting.distribution.rule.destroy', $rule->id))->assertRedirect();
        $this->assertNull(CashDistribution::find($rule->id));
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.distribution'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [accounting.distribution] not defined`).
Run: `php artisan test --env=testing tests/Feature/AccountingDistributionTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Pages/ProfitDistributionController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashDistribution;
use App\Models\CashSetting;
use App\Services\CashRecapService;
use App\Services\ProfitDistributionService;
use Illuminate\Http\Request;

class ProfitDistributionController extends Controller
{
    public function __construct(private ProfitDistributionService $service, private CashRecapService $recap) {}

    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }

        if ($request->has('profit') && $request->query('profit') !== '') {
            $profit = (float) $request->query('profit');
        } else {
            $recap = $this->recap->monthlyRecap($year);
            $profit = (float) ($recap[$month - 1]['laba'] ?? 0);
        }

        return view('accounting.distribution', [
            'year'    => $year,
            'month'   => $month,
            'profit'  => $profit,
            'result'  => $this->service->distribute($profit, null),
            'rules'   => CashDistribution::orderBy('position')->get(),
            'setting' => CashSetting::singleton(),
        ]);
    }

    public function updateSetting(Request $request)
    {
        $data = $request->validate(['team_members' => 'required|integer|min:1']);
        CashSetting::singleton()->update($data);

        return back()->with('success', 'Jumlah anggota tim diperbarui.');
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'type' => 'required|in:percent,flat', 'value' => 'required|numeric|min:0']);
        $data['per_member'] = $request->boolean('per_member');
        $data['active'] = true;
        CashDistribution::create($data);

        return back()->with('success', 'Aturan distribusi ditambahkan.');
    }

    public function updateRule(Request $request, int $id)
    {
        $rule = CashDistribution::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:100', 'type' => 'required|in:percent,flat', 'value' => 'required|numeric|min:0']);
        $data['per_member'] = $request->boolean('per_member');
        $data['active'] = $request->boolean('active');
        $rule->update($data);

        return back()->with('success', 'Aturan distribusi diperbarui.');
    }

    public function destroyRule(int $id)
    {
        CashDistribution::findOrFail($id)->delete();

        return back()->with('success', 'Aturan distribusi dihapus.');
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`** — di grup `role:superadmin|accounting`, setelah `accounting.dashboard`, tambah:

```php
        Route::get('accounting/distribution', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'index'])->name('accounting.distribution');
        Route::put('accounting/distribution/settings', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'updateSetting'])->name('accounting.distribution.settings');
        Route::post('accounting/distribution/rule', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'storeRule'])->name('accounting.distribution.rule.store');
        Route::put('accounting/distribution/rule/{id}', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'updateRule'])->name('accounting.distribution.rule.update')->whereNumber('id');
        Route::delete('accounting/distribution/rule/{id}', [\App\Http\Controllers\Pages\ProfitDistributionController::class, 'destroyRule'])->name('accounting.distribution.rule.destroy')->whereNumber('id');
```

- [ ] **Step 5: View `resources/views/accounting/distribution.blade.php`**

```blade
@extends('layouts.master')
@section('title', 'Distribusi Profit - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Distribusi Profit</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>@endfor
        </select>
        <input type="number" name="profit" value="{{ (int) $profit }}" class="form-control form-control-sm" style="width:150px" placeholder="Profit (Rp)" title="Kosongkan untuk pakai laba kas bulan">
        <button class="btn btn-sm btn-outline-secondary">Hitung</button>
    </form>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Profit dihitung: <strong>{{ $rp($result['profit']) }}</strong> · Anggota tim: <strong>{{ $result['members'] }}</strong>
        <small class="text-muted">(kosongkan input profit untuk pakai laba kas bulan)</small></p>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Pos</th><th>Tipe</th><th class="text-end">Nilai</th><th class="text-end">Alokasi</th><th class="text-end">Per Orang</th></tr></thead>
            <tbody>
                @foreach($result['lines'] as $l)
                    <tr>
                        <td>{{ $l['name'] }}</td>
                        <td><span class="badge {{ $l['type'] === 'percent' ? 'bg-info' : 'bg-secondary' }}">{{ \App\Models\CashDistribution::TYPES[$l['type']] ?? $l['type'] }}</span></td>
                        <td class="text-end">{{ $l['type'] === 'percent' ? rtrim(rtrim(number_format($l['value'], 2), '0'), '.') . '%' : $rp($l['value']) }}</td>
                        <td class="text-end">{{ $rp($l['amount']) }}</td>
                        <td class="text-end">{{ $l['perPerson'] !== null ? $rp($l['perPerson']) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold"><td colspan="3">Total Teralokasi</td><td class="text-end">{{ $rp($result['totalAllocated']) }}</td><td></td></tr>
                <tr class="{{ $result['remainder'] < 0 ? 'text-danger' : 'text-muted' }}"><td colspan="3">Sisa / Selisih</td><td class="text-end">{{ $rp($result['remainder']) }}</td><td></td></tr>
            </tfoot>
        </table>
    </div>
</div></div></div></div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#distConfig">Kelola Aturan & Anggota</button>
    <div class="collapse mt-3" id="distConfig">
        <form method="POST" action="{{ route('accounting.distribution.settings') }}" class="d-flex gap-2 align-items-end mb-3">
            @csrf @method('PUT')
            <div><label class="form-label small mb-1">Jumlah Anggota Tim</label><input type="number" name="team_members" value="{{ $setting->team_members }}" min="1" class="form-control form-control-sm" style="width:120px"></div>
            <button class="btn btn-sm btn-primary">Simpan Anggota</button>
        </form>

        <div class="text-muted small fw-semibold mb-1">Aturan Distribusi</div>
        @foreach($rules as $r)
            <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                <form method="POST" action="{{ route('accounting.distribution.rule.update', $r->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $r->name }}" class="form-control form-control-sm" style="max-width:180px">
                    <select name="type" class="form-select form-select-sm" style="max-width:110px">@foreach(\App\Models\CashDistribution::TYPES as $tk => $tl)<option value="{{ $tk }}" {{ $r->type === $tk ? 'selected' : '' }}>{{ $tl }}</option>@endforeach</select>
                    <input type="number" step="0.01" name="value" value="{{ (float) $r->value }}" class="form-control form-control-sm" style="max-width:110px">
                    <label class="small mb-0"><input type="checkbox" name="per_member" value="1" {{ $r->per_member ? 'checked' : '' }}> per anggota</label>
                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $r->active ? 'checked' : '' }}> aktif</label>
                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                </form>
                <form method="POST" action="{{ route('accounting.distribution.rule.destroy', $r->id) }}" data-confirm="Hapus aturan ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
            </div>
        @endforeach

        <form method="POST" action="{{ route('accounting.distribution.rule.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
            @csrf
            <input name="name" placeholder="Pos baru…" class="form-control form-control-sm" style="max-width:180px">
            <select name="type" class="form-select form-select-sm" style="max-width:110px"><option value="percent">Persen</option><option value="flat">Flat</option></select>
            <input type="number" step="0.01" name="value" placeholder="Nilai" class="form-control form-control-sm" style="max-width:110px">
            <label class="small mb-0"><input type="checkbox" name="per_member" value="1"> per anggota</label>
            <button class="btn btn-xs btn-outline-success">+ Tambah</button>
        </form>
    </div>
</div></div></div></div>
@endsection

- [ ] **Step 6: Menu sidebar `resources/views/layouts/sidebar.blade.php`** — di blok `@role(['superadmin','accounting'])` "Keuangan", setelah item Dashboard Keuangan, tambah:

```blade
                <li class="nav-item {{ active_class(['accounting/distribution']) }}">
                    <a href="{{ route('accounting.distribution') }}" class="nav-link">
                        <i class="link-icon" data-feather="pie-chart"></i>
                        <span class="link-title">Distribusi Profit</span>
                    </a>
                </li>
```

- [ ] **Step 7: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/AccountingDistributionTest.php`
Expected: 3 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/ProfitDistributionController.php routes/web.php resources/views/accounting/distribution.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/AccountingDistributionTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): Distribusi Profit (kalkulator + kelola aturan/anggota) + menu

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_04_000006_create_cash_distributions_and_team ... DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 446 + 6 baru = 452 passed).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 model/tabel/seed (percent/flat/per_member + team_members) → Task 1. ✓
- §3 service (percent/flat/per-member/remainder, members min1) → Task 2 + unit test. ✓
- §4 controller+rute (index profit-default-laba-bulan/override, settings, rule CRUD, role gate) → Task 3 + feature test. ✓
- §5 view (filter+profit, tabel alokasi/perOrang/total/sisa, kelola aturan+anggota) + sidebar → Task 3. ✓
- §6 test → Task 2/3. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step.

**3. Type/nama konsistensi:** tabel `tb_cash_distributions`; model `CashDistribution`(TYPES percent/flat, scopeActive); `CashSetting`+team_members. `ProfitDistributionService::distribute` return keys `profit/members/lines/totalAllocated/remainder` (lines item: name/type/value/per_member/amount/perPerson) konsisten controller↔view↔test. Rute `accounting.distribution` + `.settings`/`.rule.store|update|destroy` konsisten controller↔view↔test↔sidebar. `CashRecapService::monthlyRecap[$m-1]['laba']` sbg profit default.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 4). Test via `.env.testing`.
