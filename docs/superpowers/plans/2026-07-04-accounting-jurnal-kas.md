# Akuntansi Fase A: Jurnal Kas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buku kas (Jurnal Kas): transaksi pemasukan/pengeluaran + kategori + produk + ref + saldo berjalan (turunan), CRUD, filter bulan/jenis, ringkasan; + role `accounting`.

**Architecture:** 2 tabel (`tb_cash_categories`, `tb_cash_entries`) + role `accounting` via migrasi idempotent. `CashJournalService` menghitung kode auto + saldo berjalan (opening + kumulatif) + ringkasan. `CashEntryController`/`CashCategoryController` (role superadmin|accounting) + view `accounting/journal`.

**Tech Stack:** Laravel 11, Eloquent, Spatie roles, Blade + Bootstrap 5 + DataTables. Test: PHPUnit `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_04_000001_add_accounting_role.php` (**create**)
- `database/migrations/2026_07_04_000002_create_tb_cash_categories_table.php` (**create**, +seed)
- `database/migrations/2026_07_04_000003_create_tb_cash_entries_table.php` (**create**)
- `app/Models/CashCategory.php`, `app/Models/CashEntry.php` (**create**)
- `app/Services/CashJournalService.php` (**create**)
- `app/Http/Controllers/Pages/CashEntryController.php`, `app/Http/Controllers/Pages/CashCategoryController.php` (**create**)
- `routes/web.php` (**modify**)
- `resources/views/accounting/journal.blade.php` (**create**)
- `resources/views/layouts/sidebar.blade.php` (**modify**)
- `tests/Unit/CashJournalServiceTest.php`, `tests/Feature/AccountingJournalTest.php` (**create**)

---

## Konteks untuk implementer

- Roles di-seed via `database/seeders/PermissionSeed.php` (sudah jalan di dev/live); role baru ditambah via migrasi idempotent `Role::firstOrCreate` + `app()[PermissionRegistrar::class]->forgetCachedPermissions()`. Guard = `web`.
- Migrasi terakhir di main: `2026_07_03_000008`. Nomor baru `2026_07_04_000001/2/3`.
- Test role setup pola: `foreach (['marketing','manager','superadmin','production','admin'] as $r) Role::create(['name'=>$r,'guard_name'=>'web']);` — role `accounting` datang dari migrasi (RefreshDatabase menjalankan migrasi), jadi cukup `assignRole('accounting')`.
- Rute pakai FQN controller inline (pola rute `archive.*` existing) untuk hindari kerumitan import.

---

### Task 1: Migrasi (role + 2 tabel + seed) + model

**Files:** 3 migrasi; `CashCategory.php`; `CashEntry.php`

- [ ] **Step 1: Migrasi role `2026_07_04_000001_add_accounting_role.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'accounting', 'guard_name' => 'web']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'accounting')->where('guard_name', 'web')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
```

- [ ] **Step 2: Migrasi `2026_07_04_000002_create_tb_cash_categories_table.php` (+seed)**

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
        Schema::create('tb_cash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('jenis'); // pemasukan | pengeluaran
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        $seed = [
            ['pemasukan', 'Order Artikel Kolaborasi', 1],
            ['pemasukan', 'Order Artikel Mandiri', 2],
            ['pemasukan', 'Order Buku Kolaborasi', 3],
            ['pemasukan', 'Order Buku Mandiri', 4],
            ['pengeluaran', 'Biaya APC Jurnal', 1],
            ['pengeluaran', 'Fee Tim/Freelancer', 2],
            ['pengeluaran', 'Operational', 3],
            ['pengeluaran', 'PPn Bank', 4],
            ['pengeluaran', 'Saving', 5],
            ['pengeluaran', 'Dana Tak Terduga', 6],
        ];
        $rows = [];
        foreach ($seed as [$jenis, $name, $pos]) {
            $rows[] = ['jenis' => $jenis, 'name' => $name, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tb_cash_categories')->insert($rows);
    }

    public function down(): void { Schema::dropIfExists('tb_cash_categories'); }
};
```

- [ ] **Step 3: Migrasi `2026_07_04_000003_create_tb_cash_entries_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('kode')->nullable();
            $table->string('keterangan');
            $table->string('jenis'); // pemasukan | pengeluaran
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_category_id')->nullable()->constrained('tb_cash_categories')->nullOnDelete();
            $table->string('produk')->nullable(); // artikel | buku | operasional
            $table->string('ref')->nullable();
            $table->text('catatan')->nullable();
            $table->string('source')->default('manual'); // manual | payment
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tanggal', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_cash_entries'); }
};
```

- [ ] **Step 4: Model `app/Models/CashCategory.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashCategory extends Model
{
    protected $table = 'tb_cash_categories';

    protected $fillable = ['name', 'jenis', 'active', 'position'];

    protected $casts = ['active' => 'boolean'];

    const JENIS = ['pemasukan' => 'Pemasukan', 'pengeluaran' => 'Pengeluaran'];

    public function scopeActive($query) { return $query->where('active', true); }

    public function entries() { return $this->hasMany(CashEntry::class); }
}
```

- [ ] **Step 5: Model `app/Models/CashEntry.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    protected $table = 'tb_cash_entries';

    protected $fillable = ['tanggal', 'kode', 'keterangan', 'jenis', 'amount', 'cash_category_id', 'produk', 'ref', 'catatan', 'source', 'created_by'];

    protected $casts = ['tanggal' => 'date', 'amount' => 'decimal:2'];

    const PRODUK = ['artikel' => 'Artikel', 'buku' => 'Buku', 'operasional' => 'Operasional'];

    public function isPemasukan(): bool { return $this->jenis === 'pemasukan'; }

    public function category() { return $this->belongsTo(CashCategory::class, 'cash_category_id'); }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
```

- [ ] **Step 6: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: 3 migrasi `DONE` (role + 2 tabel).

```bash
git add database/migrations/2026_07_04_000001_add_accounting_role.php database/migrations/2026_07_04_000002_create_tb_cash_categories_table.php database/migrations/2026_07_04_000003_create_tb_cash_entries_table.php app/Models/CashCategory.php app/Models/CashEntry.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): role accounting + tabel cash_categories(+seed)/cash_entries + model

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: CashJournalService + unit test

**Files:** `app/Services/CashJournalService.php`; `tests/Unit/CashJournalServiceTest.php`

- [ ] **Step 1: Unit test (gagal dulu)** — `tests/Unit/CashJournalServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class CashJournalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $tanggal, string $jenis, $amount): CashEntry
    {
        return CashEntry::create(['tanggal' => $tanggal, 'jenis' => $jenis, 'amount' => $amount, 'keterangan' => 'x', 'source' => 'manual']);
    }

    /** @test */
    public function derive_kode_matches_data_format(): void
    {
        $svc = new CashJournalService();
        $this->assertSame('B1025', $svc->deriveKode(Carbon::create(2025, 10, 15)));
        $this->assertSame('B126', $svc->deriveKode(Carbon::create(2026, 1, 5)));
    }

    /** @test */
    public function compute_running_saldo_with_opening_and_summary(): void
    {
        $this->entry('2026-05-20', 'pemasukan', 1000000); // prior month
        $this->entry('2026-06-05', 'pemasukan', 500000);
        $this->entry('2026-06-10', 'pengeluaran', 200000);

        $r = (new CashJournalService())->compute(2026, 6, null);

        $this->assertSame(1000000.0, $r['opening']);
        $this->assertSame(500000.0, $r['totalIn']);
        $this->assertSame(200000.0, $r['totalOut']);
        $this->assertSame(1300000.0, $r['saldoAkhir']);
        $saldos = $r['entries']->pluck('saldo')->all();
        $this->assertSame([1500000.0, 1300000.0], $saldos); // running: 1jt+500rb, -200rb
    }

    /** @test */
    public function jenis_filter_keeps_summary_and_saldo(): void
    {
        $this->entry('2026-06-05', 'pemasukan', 500000);
        $this->entry('2026-06-10', 'pengeluaran', 200000);

        $r = (new CashJournalService())->compute(2026, 6, 'pemasukan');
        $this->assertSame(1, $r['entries']->count());        // hanya pemasukan tampil
        $this->assertSame(500000.0, $r['totalIn']);           // ringkasan tetap penuh
        $this->assertSame(200000.0, $r['totalOut']);
        $this->assertSame(500000.0, $r['entries']->first()->saldo); // saldo berjalan penuh
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (service belum ada).
Run: `php artisan test --env=testing tests/Unit/CashJournalServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Buat `app/Services/CashJournalService.php`**

```php
<?php

namespace App\Services;

use App\Models\CashEntry;
use Carbon\Carbon;

class CashJournalService
{
    /** Kode transaksi otomatis: B{bulan}{yy} (Okt 2025 → B1025; Jan 2026 → B126). */
    public function deriveKode(Carbon $tanggal): string
    {
        return 'B' . $tanggal->month . substr((string) $tanggal->year, -2);
    }

    /**
     * Hitung jurnal periode: saldo berjalan (opening + kumulatif) + ringkasan.
     * @return array{entries:\Illuminate\Support\Collection,opening:float,totalIn:float,totalOut:float,saldoAkhir:float}
     */
    public function compute(int $year, ?int $month, ?string $jenis = null): array
    {
        $start = $month ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();

        $priorIn  = (float) CashEntry::where('tanggal', '<', $start)->where('jenis', 'pemasukan')->sum('amount');
        $priorOut = (float) CashEntry::where('tanggal', '<', $start)->where('jenis', 'pengeluaran')->sum('amount');
        $opening  = $priorIn - $priorOut;

        $q = CashEntry::with('category')->whereYear('tanggal', $year);
        if ($month) { $q->whereMonth('tanggal', $month); }
        $all = $q->orderBy('tanggal')->orderBy('id')->get();

        $running = $opening;
        foreach ($all as $e) {
            $running += $e->isPemasukan() ? (float) $e->amount : -(float) $e->amount;
            $e->saldo = $running;
        }

        $totalIn  = (float) $all->where('jenis', 'pemasukan')->sum('amount');
        $totalOut = (float) $all->where('jenis', 'pengeluaran')->sum('amount');
        $saldoAkhir = $opening + $totalIn - $totalOut;

        $entries = $jenis ? $all->where('jenis', $jenis)->values() : $all;

        return compact('entries', 'opening', 'totalIn', 'totalOut', 'saldoAkhir');
    }
}
```

- [ ] **Step 4: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/CashJournalServiceTest.php`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CashJournalService.php tests/Unit/CashJournalServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): CashJournalService (deriveKode + saldo berjalan/ringkasan)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Controllers + rute + feature test

**Files:** `CashEntryController.php`; `CashCategoryController.php`; `routes/web.php`; `tests/Feature/AccountingJournalTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/AccountingJournalTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashCategory;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        // role 'accounting' berasal dari migrasi 2026_07_04_000001
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function accounting_and_superadmin_can_store_entry(): void
    {
        $cat = CashCategory::where('jenis', 'pemasukan')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'cash_category_id' => $cat->id,
            'amount' => 500000, 'produk' => 'artikel', 'keterangan' => 'Order test',
        ])->assertRedirect();

        $e = CashEntry::where('keterangan', 'Order test')->first();
        $this->assertNotNull($e);
        $this->assertSame('B126', $e->kode);
        $this->assertSame('manual', $e->source);

        $expCat = CashCategory::where('jenis', 'pengeluaran')->first();
        $this->actingAs($this->user('superadmin'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-10', 'jenis' => 'pengeluaran', 'cash_category_id' => $expCat->id,
            'amount' => 200000, 'keterangan' => 'Bayar APC',
        ])->assertRedirect();
        $this->assertSame(1, CashEntry::where('jenis', 'pengeluaran')->count());
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.journal'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 1, 'keterangan' => 'x',
        ])->assertForbidden();
    }

    /** @test */
    public function index_shows_entries_and_summary(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'Masuk Juni', 'source' => 'manual']);
        $this->actingAs($this->user('accounting'))->get(route('accounting.journal', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee('Masuk Juni');
    }

    /** @test */
    public function category_crud(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.category.store'), ['name' => 'Kategori Baru', 'jenis' => 'pengeluaran'])->assertRedirect();
        $c = CashCategory::where('name', 'Kategori Baru')->first();
        $this->assertNotNull($c);
        $this->actingAs($sa)->put(route('accounting.category.update', $c->id), ['name' => 'Kategori Baru', 'jenis' => 'pengeluaran'])->assertRedirect();
        $this->assertFalse($c->fresh()->active); // tanpa checkbox active → nonaktif
    }

    /** @test */
    public function update_and_delete_entry(): void
    {
        $e = CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'A', 'source' => 'manual']);
        $this->actingAs($this->user('accounting'))->put(route('accounting.entry.update', $e->id), [
            'tanggal' => '2026-06-06', 'jenis' => 'pemasukan', 'amount' => 600000, 'keterangan' => 'A2',
        ])->assertRedirect();
        $this->assertSame('A2', $e->fresh()->keterangan);

        $this->actingAs($this->user('accounting'))->delete(route('accounting.entry.destroy', $e->id))->assertRedirect();
        $this->assertNull(CashEntry::find($e->id));
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [accounting.entry.store] not defined`).
Run: `php artisan test --env=testing tests/Feature/AccountingJournalTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Pages/CashEntryController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashEntryController extends Controller
{
    public function __construct(private CashJournalService $service) {}

    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $jenis = in_array($request->query('jenis'), ['pemasukan', 'pengeluaran'], true) ? $request->query('jenis') : null;

        $data = $this->service->compute($year, $month, $jenis);

        return view('accounting.journal', array_merge($data, [
            'year'          => $year,
            'month'         => $month,
            'jenis'         => $jenis,
            'categories'    => CashCategory::active()->orderBy('jenis')->orderBy('position')->get(),
            'allCategories' => CashCategory::orderBy('jenis')->orderBy('position')->get(),
        ]));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tanggal'          => 'required|date',
            'jenis'            => 'required|in:pemasukan,pengeluaran',
            'cash_category_id' => 'nullable|exists:tb_cash_categories,id',
            'amount'           => 'required|numeric|min:0',
            'produk'           => 'nullable|in:artikel,buku,operasional',
            'keterangan'       => 'required|string|max:255',
            'ref'              => 'nullable|string|max:100',
            'catatan'          => 'nullable|string',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['kode']       = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $data['source']     = 'manual';
        $data['created_by'] = Auth::id();
        CashEntry::create($data);

        return back()->with('success', 'Transaksi kas ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $entry = CashEntry::findOrFail($id);
        $data = $this->validated($request);
        $data['kode'] = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $entry->update($data);

        return back()->with('success', 'Transaksi kas diperbarui.');
    }

    public function destroy(int $id)
    {
        CashEntry::findOrFail($id)->delete();

        return back()->with('success', 'Transaksi kas dihapus.');
    }
}
```

- [ ] **Step 4: `app/Http/Controllers/Pages/CashCategoryController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashCategory;
use Illuminate\Http\Request;

class CashCategoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'jenis' => 'required|in:pemasukan,pengeluaran']);
        $data['active'] = true;
        CashCategory::create($data);

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $cat = CashCategory::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:100', 'jenis' => 'required|in:pemasukan,pengeluaran']);
        $data['active'] = $request->boolean('active');
        $cat->update($data);

        return back()->with('success', 'Kategori diperbarui.');
    }

    public function destroy(int $id)
    {
        CashCategory::findOrFail($id)->delete();

        return back()->with('success', 'Kategori dihapus.');
    }
}
```

- [ ] **Step 5: Rute di `routes/web.php`** — sisipkan dalam grup auth (mis. setelah rute `archive.*`):

```php
    // Akuntansi — Jurnal Kas (superadmin/accounting)
    Route::middleware('role:superadmin|accounting')->group(function () {
        Route::get('accounting/journal', [\App\Http\Controllers\Pages\CashEntryController::class, 'index'])->name('accounting.journal');
        Route::post('accounting/entry', [\App\Http\Controllers\Pages\CashEntryController::class, 'store'])->name('accounting.entry.store');
        Route::put('accounting/entry/{id}', [\App\Http\Controllers\Pages\CashEntryController::class, 'update'])->name('accounting.entry.update')->whereNumber('id');
        Route::delete('accounting/entry/{id}', [\App\Http\Controllers\Pages\CashEntryController::class, 'destroy'])->name('accounting.entry.destroy')->whereNumber('id');
        Route::post('accounting/category', [\App\Http\Controllers\Pages\CashCategoryController::class, 'store'])->name('accounting.category.store');
        Route::put('accounting/category/{id}', [\App\Http\Controllers\Pages\CashCategoryController::class, 'update'])->name('accounting.category.update')->whereNumber('id');
        Route::delete('accounting/category/{id}', [\App\Http\Controllers\Pages\CashCategoryController::class, 'destroy'])->name('accounting.category.destroy')->whereNumber('id');
    });
```

- [ ] **Step 6: View minimal `resources/views/accounting/journal.blade.php`** (agar route render; lengkap di Task 4):

```blade
@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')
@section('content')
<h5 class="mb-3">Jurnal Kas</h5>
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p>Total Pemasukan: {{ number_format($totalIn, 0, ',', '.') }} · Total Pengeluaran: {{ number_format($totalOut, 0, ',', '.') }} · Saldo: {{ number_format($saldoAkhir, 0, ',', '.') }}</p>
    <ul class="list-unstyled mb-0">
        @foreach($entries as $e)
            <li>{{ $e->keterangan }} — {{ number_format($e->amount, 0, ',', '.') }}</li>
        @endforeach
    </ul>
</div></div></div></div>
@endsection
```

- [ ] **Step 7: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Feature/AccountingJournalTest.php`
Expected: 5 passed.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/CashEntryController.php app/Http/Controllers/Pages/CashCategoryController.php routes/web.php resources/views/accounting/journal.blade.php tests/Feature/AccountingJournalTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): CashEntry/CashCategory controller + rute accounting.* + view stub

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: View lengkap Jurnal Kas + sidebar menu

**Files:** `resources/views/accounting/journal.blade.php`; `resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1: `resources/views/accounting/journal.blade.php` (lengkap)**

```blade
@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Jurnal Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            <option value="all" {{ $month === null ? 'selected' : '' }}>Semua bulan</option>
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endfor
        </select>
        <select name="jenis" class="form-select form-select-sm" style="width:130px">
            <option value="">Semua jenis</option>
            <option value="pemasukan" {{ $jenis === 'pemasukan' ? 'selected' : '' }}>Pemasukan</option>
            <option value="pengeluaran" {{ $jenis === 'pengeluaran' ? 'selected' : '' }}>Pengeluaran</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
</div>

<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan</div><div class="h5 mb-0 text-success">{{ $rp($totalIn) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran</div><div class="h5 mb-0 text-danger">{{ $rp($totalOut) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Akhir</div><div class="h5 mb-0">{{ $rp($saldoAkhir) }}</div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small">Saldo awal periode: {{ $rp($opening) }}</span>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#entryForm">+ Tambah Transaksi</button>
    </div>

    <div class="collapse mb-3" id="entryForm">
        <form method="POST" action="{{ route('accounting.entry.store') }}" class="border rounded p-3">
            @csrf
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Jenis</label><select name="jenis" class="form-select form-select-sm"><option value="pemasukan">Pemasukan</option><option value="pengeluaran">Pengeluaran</option></select></div>
                <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
                    <select name="cash_category_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach($categories as $c)<option value="{{ $c->id }}" data-jenis="{{ $c->jenis }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="number" name="amount" class="form-control form-control-sm" min="0" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Produk</label><select name="produk" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small mb-1">Keterangan</label><input name="keterangan" class="form-control form-control-sm" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Ref (INV/Order)</label><input name="ref" class="form-control form-control-sm"></div>
                <div class="col-md-5"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-primary mt-2">Simpan</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm datatable" style="width:100%">
            <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Kategori</th><th>Produk</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Saldo</th><th>Ref</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($entries as $e)
                    <tr>
                        <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
                        <td>{{ $e->kode }}</td>
                        <td>{{ $e->keterangan }}</td>
                        <td>{{ $e->category?->name ?? '—' }}</td>
                        <td>{{ \App\Models\CashEntry::PRODUK[$e->produk] ?? '—' }}</td>
                        <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
                        <td>{{ $e->ref ?? '—' }}</td>
                        <td><form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#catForm">Kelola Kategori</button>
        <div class="collapse mt-2" id="catForm">
            @foreach(\App\Models\CashCategory::JENIS as $jk => $jl)
                <div class="text-muted small fw-semibold mt-2">{{ $jl }}</div>
                @foreach($allCategories->where('jenis', $jk) as $c)
                    <form method="POST" action="{{ route('accounting.category.update', $c->id) }}" class="d-flex gap-1 mb-1 align-items-center">
                        @csrf @method('PUT')
                        <input type="hidden" name="jenis" value="{{ $c->jenis }}">
                        <input name="name" value="{{ $c->name }}" class="form-control form-control-sm" style="max-width:280px">
                        <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $c->active ? 'checked' : '' }}> aktif</label>
                        <button class="btn btn-xs btn-outline-primary">Simpan</button>
                    </form>
                @endforeach
                <form method="POST" action="{{ route('accounting.category.store') }}" class="d-flex gap-1 mt-1">
                    @csrf
                    <input type="hidden" name="jenis" value="{{ $jk }}">
                    <input name="name" placeholder="Kategori {{ $jl }} baru…" class="form-control form-control-sm" style="max-width:280px">
                    <button class="btn btn-xs btn-outline-success">+ Tambah</button>
                </form>
            @endforeach
        </div>
    </div>
</div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [], language: { emptyTable: 'Belum ada transaksi.' } }); });</script>
@endpush
```

- [ ] **Step 2: Menu sidebar `resources/views/layouts/sidebar.blade.php`** — tambah blok menu (letakkan dekat menu manajemen lain, mis. setelah blok "Arsip Judul" `@endrole` atau di grup produksi/keuangan):

```blade
            @role(['superadmin', 'accounting'])
                <li class="nav-item nav-category">Keuangan</li>
                <li class="nav-item {{ active_class(['accounting/journal', 'accounting/*']) }}">
                    <a href="{{ route('accounting.journal') }}" class="nav-link">
                        <i class="link-icon" data-feather="book"></i>
                        <span class="link-title">Jurnal Kas</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 3: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/AccountingJournalTest.php`
Expected: 5 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 4: Commit**

```bash
git add resources/views/accounting/journal.blade.php resources/views/layouts/sidebar.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): view Jurnal Kas (ringkasan+saldo berjalan+form+kategori) + menu sidebar

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 5: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_04_000001` (role) / `000002` (categories) / `000003` (entries) `DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 426 + 8 baru = 434 passed).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 role accounting (migrasi idempotent) → Task 1 Step 1; test pakai role dari migrasi (Task 3). ✓
- §3 model/tabel/seed → Task 1. ✓
- §4 service (deriveKode + compute opening/saldo/summary) → Task 2 + unit test. ✓
- §5 controller+rute (index filter, store/update/destroy, category CRUD, role gate) → Task 3 + feature test (store, 403, index, category, update/delete). ✓
- §6 view (filter bulan, ringkasan, tabel saldo, form, kelola kategori) + sidebar → Task 4. ✓
- §7 test → Task 2/3. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step. (View stub Task 3 → lengkap Task 4, staging TDD.)

**3. Type/nama konsistensi:** tabel `tb_cash_categories`/`tb_cash_entries`; model `CashCategory`(JENIS, scopeActive)/`CashEntry`(PRODUK, isPemasukan). `CashJournalService::deriveKode/compute` konsisten controller↔test. compute return keys `entries/opening/totalIn/totalOut/saldoAkhir` dipakai controller+view+test. Rute `accounting.journal/entry.store|update|destroy/category.store|update|destroy` konsisten controller↔view↔test↔sidebar. Role `accounting` (migrasi) dipakai gate + test.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 5). Test via `.env.testing`.
