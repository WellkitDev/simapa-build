# Slip Gaji Karyawan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambahkan modul Slip Gaji Karyawan (CRUD + filter bulan, PDF, kirim email) yang berdiri sendiri dari Jurnal Kas, dengan komponen penghasilan/potongan fleksibel dan self-service untuk karyawan.

**Architecture:** Meniru pola modul Invoice/Refund — Controller di `app/Http/Controllers/Pages/`, view PDF DomPDF, Mailable + Job antrean (queue) dengan upload Google Drive, list DataTables, akses lewat `config/permissions.php` + `AccessMatrixSeeder`. Dua tabel baru (`tb_salary_slips`, `tb_salary_slip_lines`). Total (penghasilan/potongan/gaji bersih) disnapshot pada baris kepala agar list ringan.

**Tech Stack:** Laravel 10, PHP 8.1, `barryvdh/laravel-dompdf`, `spatie/laravel-permission`, DataTables (`datatables.net-bs4`), PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-07-21-employee-salary-slip-design.md`

**Catatan penting untuk pelaksana:**
- Test dijalankan pada DB `avidpedi_simapa_test` via `.env.testing` — JANGAN sentuh DB asli.
- `Tests\TestCase::setUp()` memasang listener: begitu sebuah `Role` dibuat di test, `AccessMatrixSeeder` otomatis jalan dan menghibahkan permission ke semua role. Karena itu setiap test Feature membuat role `['marketing','manager','superadmin','production','admin']` di `setUp()` (role `accounting` sudah ada dari migrasi). Ini WAJIB agar accounting punya `salary.*`.
- `EnforcePermission` fail-closed: GET tanpa izin → **403** (`assertForbidden`); POST/PUT/DELETE tanpa izin → **redirect + session `error`**.
- Perintah test contoh (PowerShell/bash sama): `php artisan test --filter=NamaTest`.
- Commit: akhiri pesan dengan baris `Co-Authored-By: Mira <admin@avidpedia.com>` (JANGAN sebut Claude/Anthropic). `git add` HANYA path eksplisit yang disebut tiap task.

**Keputusan implementasi (deviasi kecil dari spec, disengaja):** Input nominal pada baris dinamis memakai `<input type="number">` (bukan `jquery.inputmask`), karena masking pada baris yang ditambah/dihapus via JS rapuh. Sebagai gantinya, total (Penghasilan/Potongan/Gaji Bersih) ditampilkan live dengan pemisah ribuan (`toLocaleString('id-ID')`), dan controller menormalkan nominal secara defensif (buang non-digit) sebelum validasi. Nuansa "profesional" tetap terjaga, submit selalu bersih.

---

## File Structure

**Dibuat:**
- `database/migrations/2026_07_21_000001_create_tb_salary_slips_table.php`
- `database/migrations/2026_07_21_000002_create_tb_salary_slip_lines_table.php`
- `app/Models/SalarySlip.php` — kepala slip + `recalcTotals()` + relasi + label periode
- `app/Models/SalarySlipLine.php` — baris komponen
- `database/factories/SalarySlipFactory.php` — untuk test
- `app/Support/Terbilang.php` — angka rupiah → huruf (Indonesia)
- `app/Support/SalarySlipPdfData.php` — penyedia data view PDF
- `app/Http/Controllers/Pages/SalarySlipController.php` — CRUD admin + PDF + kirim
- `app/Http/Controllers/Pages/EmployeeSalarySlipController.php` — self-service karyawan
- `app/Mail/SalarySlipMail.php`
- `app/Jobs/SendSalarySlipJob.php`
- `resources/views/salary/slips/index.blade.php`
- `resources/views/salary/slips/form.blade.php` (dipakai create & edit)
- `resources/views/salary/slips/show.blade.php`
- `resources/views/salary/slips/me.blade.php`
- `resources/views/salary/slips/salary_slip_pdf.blade.php`
- `resources/views/pages/mails/salary_slip_mail.blade.php`
- Test: `tests/Feature/SalarySlipModelTest.php`, `tests/Unit/TerbilangTest.php`,
  `tests/Feature/SalarySlipAccessTest.php`, `tests/Feature/SalarySlipStoreTest.php`,
  `tests/Feature/SalarySlipCrudTest.php`, `tests/Feature/SalarySlipPdfTest.php`,
  `tests/Feature/SalarySlipMailTest.php`, `tests/Feature/EmployeeSalarySlipTest.php`,
  `tests/Feature/SalarySlipSidebarTest.php`

**Diubah:**
- `config/permissions.php` — tambah modul `salary` + route self-service ke `public`
- `database/seeders/AccessMatrixSeeder.php` — hibah `salary.*` ke role `accounting`
- `routes/web.php` — rute admin + self-service
- `app/Services/Notifier.php` — method `salarySlipIssued()`
- `resources/views/layouts/sidebar.blade.php` — menu "Slip Gaji" + "Slip Gaji Saya"

---

## Task 1: Migrasi, Model & Factory

**Files:**
- Create: `database/migrations/2026_07_21_000001_create_tb_salary_slips_table.php`
- Create: `database/migrations/2026_07_21_000002_create_tb_salary_slip_lines_table.php`
- Create: `app/Models/SalarySlip.php`
- Create: `app/Models/SalarySlipLine.php`
- Create: `database/factories/SalarySlipFactory.php`
- Test: `tests/Feature/SalarySlipModelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalarySlipModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function recalc_totals_computes_earnings_deductions_and_net(): void
    {
        $user = User::factory()->create();
        $slip = SalarySlip::create([
            'slip_no'       => 'SLP-202607-0001',
            'user_id'       => $user->id,
            'employee_name' => $user->name,
            'period_year'   => 2026,
            'period_month'  => 7,
            'status'        => 'draft',
        ]);
        $slip->lines()->createMany([
            ['type' => 'earning',   'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0],
            ['type' => 'earning',   'label' => 'Tunjangan',  'amount' => 1000000, 'position' => 1],
            ['type' => 'deduction', 'label' => 'BPJS',       'amount' => 300000,  'position' => 0],
        ]);

        $slip->recalcTotals();
        $slip->refresh();

        $this->assertEquals(6000000, $slip->total_earnings);
        $this->assertEquals(300000,  $slip->total_deductions);
        $this->assertEquals(5700000, $slip->net_pay);
        $this->assertCount(3, $slip->lines);
        $this->assertCount(2, $slip->earnings);
        $this->assertSame('Juli 2026', $slip->periodLabel());
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipModelTest`
Expected: FAIL ("Class 'App\Models\SalarySlip' not found").

- [ ] **Step 3: Buat migrasi kepala slip**

`database/migrations/2026_07_21_000001_create_tb_salary_slips_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_salary_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_no')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('employee_position')->nullable();
            $table->smallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status')->default('draft'); // draft | terbit
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'period_year', 'period_month']);
            $table->index(['period_year', 'period_month']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_salary_slips'); }
};
```

- [ ] **Step 4: Buat migrasi baris komponen**

`database/migrations/2026_07_21_000002_create_tb_salary_slip_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_salary_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_slip_id')->constrained('tb_salary_slips')->cascadeOnDelete();
            $table->string('type');  // earning | deduction
            $table->string('label');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['salary_slip_id', 'type']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_salary_slip_lines'); }
};
```

- [ ] **Step 5: Buat model `SalarySlipLine`**

`app/Models/SalarySlipLine.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalarySlipLine extends Model
{
    use HasFactory;

    protected $table = 'tb_salary_slip_lines';

    protected $fillable = ['salary_slip_id', 'type', 'label', 'amount', 'position'];

    protected $casts = ['amount' => 'decimal:2', 'position' => 'integer'];

    public function slip()
    {
        return $this->belongsTo(SalarySlip::class, 'salary_slip_id');
    }
}
```

- [ ] **Step 6: Buat model `SalarySlip`**

`app/Models/SalarySlip.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalarySlip extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_salary_slips';

    protected $fillable = [
        'slip_no', 'user_id', 'employee_name', 'employee_position',
        'period_year', 'period_month', 'status',
        'total_earnings', 'total_deductions', 'net_pay',
        'note', 'sent_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'period_year'      => 'integer',
        'period_month'     => 'integer',
        'total_earnings'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay'          => 'decimal:2',
        'sent_at'          => 'datetime',
    ];

    const STATUS = ['draft' => 'Draft', 'terbit' => 'Terbit'];

    const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function lines()
    {
        return $this->hasMany(SalarySlipLine::class)->orderBy('position')->orderBy('id');
    }

    public function earnings()
    {
        return $this->hasMany(SalarySlipLine::class)->where('type', 'earning')->orderBy('position')->orderBy('id');
    }

    public function deductions()
    {
        return $this->hasMany(SalarySlipLine::class)->where('type', 'deduction')->orderBy('position')->orderBy('id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function periodLabel(): string
    {
        return (self::MONTHS[$this->period_month] ?? $this->period_month) . ' ' . $this->period_year;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function recalcTotals(): void
    {
        $earn = (float) $this->lines()->where('type', 'earning')->sum('amount');
        $ded  = (float) $this->lines()->where('type', 'deduction')->sum('amount');
        $this->update([
            'total_earnings'   => $earn,
            'total_deductions' => $ded,
            'net_pay'          => $earn - $ded,
        ]);
    }
}
```

- [ ] **Step 7: Buat factory**

`database/factories/SalarySlipFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalarySlipFactory extends Factory
{
    protected $model = SalarySlip::class;

    public function definition(): array
    {
        return [
            'slip_no'           => 'SLP-' . fake()->unique()->numerify('######'),
            'user_id'           => User::factory(),
            'employee_name'     => fake()->name(),
            'employee_position' => 'Staf',
            'period_year'       => 2026,
            'period_month'      => 7,
            'status'            => 'draft',
            'total_earnings'    => 0,
            'total_deductions'  => 0,
            'net_pay'           => 0,
        ];
    }
}
```

- [ ] **Step 8: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipModelTest`
Expected: PASS (1 test).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_tb_salary_slips_table.php \
        database/migrations/2026_07_21_000002_create_tb_salary_slip_lines_table.php \
        app/Models/SalarySlip.php app/Models/SalarySlipLine.php \
        database/factories/SalarySlipFactory.php \
        tests/Feature/SalarySlipModelTest.php
git commit -m "feat(slip-gaji): tabel, model & recalcTotals slip gaji

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 2: Helper Terbilang (rupiah → huruf)

**Files:**
- Create: `app/Support/Terbilang.php`
- Test: `tests/Unit/TerbilangTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/TerbilangTest.php` (unit murni, tanpa DB):

```php
<?php

namespace Tests\Unit;

use App\Support\Terbilang;
use PHPUnit\Framework\TestCase;

class TerbilangTest extends TestCase
{
    /** @test */
    public function converts_rupiah_to_indonesian_words(): void
    {
        $this->assertSame('Nol rupiah', Terbilang::rupiah(0));
        $this->assertSame('Satu rupiah', Terbilang::rupiah(1));
        $this->assertSame('Sebelas rupiah', Terbilang::rupiah(11));
        $this->assertSame('Dua puluh satu rupiah', Terbilang::rupiah(21));
        $this->assertSame('Seratus rupiah', Terbilang::rupiah(100));
        $this->assertSame('Seribu rupiah', Terbilang::rupiah(1000));
        $this->assertSame('Dua ribu rupiah', Terbilang::rupiah(2000));
        $this->assertSame('Satu juta dua ratus lima puluh ribu rupiah', Terbilang::rupiah(1250000));
        $this->assertSame('Lima juta tujuh ratus ribu rupiah', Terbilang::rupiah(5700000));
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=TerbilangTest`
Expected: FAIL ("Class 'App\Support\Terbilang' not found").

- [ ] **Step 3: Buat helper**

`app/Support/Terbilang.php`:

```php
<?php

namespace App\Support;

class Terbilang
{
    private static array $satuan = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    public static function angka(int $n): string
    {
        $n = abs($n);
        if ($n < 12) {
            return self::$satuan[$n];
        }
        if ($n < 20) {
            return self::angka($n - 10) . ' belas';
        }
        if ($n < 100) {
            return self::angka(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . self::angka($n % 10) : '');
        }
        if ($n < 200) {
            return 'seratus' . ($n - 100 ? ' ' . self::angka($n - 100) : '');
        }
        if ($n < 1000) {
            return self::angka(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . self::angka($n % 100) : '');
        }
        if ($n < 2000) {
            return 'seribu' . ($n - 1000 ? ' ' . self::angka($n - 1000) : '');
        }
        if ($n < 1000000) {
            return self::angka(intdiv($n, 1000)) . ' ribu' . ($n % 1000 ? ' ' . self::angka($n % 1000) : '');
        }
        if ($n < 1000000000) {
            return self::angka(intdiv($n, 1000000)) . ' juta' . ($n % 1000000 ? ' ' . self::angka($n % 1000000) : '');
        }
        if ($n < 1000000000000) {
            return self::angka(intdiv($n, 1000000000)) . ' miliar' . ($n % 1000000000 ? ' ' . self::angka($n % 1000000000) : '');
        }
        return self::angka(intdiv($n, 1000000000000)) . ' triliun' . ($n % 1000000000000 ? ' ' . self::angka($n % 1000000000000) : '');
    }

    public static function rupiah(int|float $n): string
    {
        $n = (int) round($n);
        if ($n === 0) {
            return 'Nol rupiah';
        }
        $prefix = $n < 0 ? 'Minus ' : '';
        $words  = trim(preg_replace('/\s+/', ' ', self::angka(abs($n))));
        return $prefix . ucfirst($words) . ' rupiah';
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=TerbilangTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Terbilang.php tests/Unit/TerbilangTest.php
git commit -m "feat(slip-gaji): helper Terbilang rupiah ke huruf

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 3: Hak akses, rute & halaman daftar (index)

**Files:**
- Modify: `config/permissions.php`
- Modify: `database/seeders/AccessMatrixSeeder.php:82` (baris grant accounting)
- Modify: `routes/web.php` (sebelum `});` penutup grup di baris ~325)
- Create: `app/Http/Controllers/Pages/SalarySlipController.php`
- Create: `resources/views/salary/slips/index.blade.php`
- Test: `tests/Feature/SalarySlipAccessTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipAccessTest extends TestCase
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
    public function accounting_can_view_index(): void
    {
        $this->actingAs($this->user('accounting'))
            ->get(route('salary.slip.index'))
            ->assertOk()
            ->assertSee('Slip Gaji Karyawan');
    }

    /** @test */
    public function marketing_cannot_view_index(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('salary.slip.index'))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipAccessTest`
Expected: FAIL ("Route [salary.slip.index] not defined").

- [ ] **Step 3: Tambah modul `salary` ke `config/permissions.php`**

Di dalam array `'modules' => [ ... ]`, tambahkan blok (mis. setelah blok `'accounting.profit'`):

```php
        'salary' => [
            'label'   => 'Slip Gaji',
            'actions' => [
                'view'   => ['salary.slip.index', 'salary.slip.show'],
                'create' => ['salary.slip.create', 'salary.slip.store'],
                'edit'   => ['salary.slip.edit', 'salary.slip.update'],
                'delete' => ['salary.slip.destroy'],
                'send'   => ['salary.slip.send'],
                'export' => ['salary.slip.pdf'],
            ],
        ],
```

Dan di array `'public' => [ ... ]`, tambahkan dua rute self-service (own-data, seperti `report.daily`):

```php
        // Slip gaji milik-sendiri (self-service) — terbuka utk semua user login.
        'salary.slip.me', 'salary.slip.me.pdf',
```

- [ ] **Step 4: Hibahkan `salary.*` ke role accounting**

Di `database/seeders/AccessMatrixSeeder.php`, pada array `$grants['accounting']` (baris ~82), tambahkan `'salary.*'` ke daftar:

```php
        'accounting' => [
            'accounting.*',
            'salary.*',
            'title.view', 'journal.view', 'isbn.view', 'archive.view', 'manuscript.detail',
            'data.*',
        ],
```

- [ ] **Step 5: Daftarkan rute admin**

Di `routes/web.php`, tepat sebelum baris `});` penutup grup (baris ~325, setelah blok Gudang Data), tambahkan:

```php
    // Slip Gaji Karyawan (superadmin/accounting) — admin
    Route::get('salary/slip', [\App\Http\Controllers\Pages\SalarySlipController::class, 'index'])->name('salary.slip.index');
    Route::get('salary/slip/create', [\App\Http\Controllers\Pages\SalarySlipController::class, 'create'])->name('salary.slip.create');
    Route::post('salary/slip', [\App\Http\Controllers\Pages\SalarySlipController::class, 'store'])->name('salary.slip.store');
    Route::get('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'show'])->name('salary.slip.show')->whereNumber('id');
    Route::get('salary/slip/{id}/edit', [\App\Http\Controllers\Pages\SalarySlipController::class, 'edit'])->name('salary.slip.edit')->whereNumber('id');
    Route::put('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'update'])->name('salary.slip.update')->whereNumber('id');
    Route::delete('salary/slip/{id}', [\App\Http\Controllers\Pages\SalarySlipController::class, 'destroy'])->name('salary.slip.destroy')->whereNumber('id');
    Route::get('salary/slip/{id}/pdf', [\App\Http\Controllers\Pages\SalarySlipController::class, 'pdf'])->name('salary.slip.pdf')->whereNumber('id');
    Route::post('salary/slip/{id}/send', [\App\Http\Controllers\Pages\SalarySlipController::class, 'send'])->name('salary.slip.send')->whereNumber('id');
```

> Catatan: semua rute admin didaftarkan sekarang agar `route(...)` di view resolve. Method `create/store/show/edit/update/destroy/pdf/send` diisi pada Task 4–7; sampai saat itu belum ada test/smoke yang memanggilnya.

- [ ] **Step 6: Buat controller (skeleton + index)**

`app/Http/Controllers/Pages/SalarySlipController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Jobs\SendSalarySlipJob;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Notifier;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalarySlipController extends Controller
{
    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $eq    = $request->query('employee');
        $employeeId = ($eq === null || $eq === '' || $eq === 'all') ? null : (int) $eq;
        $status = in_array($request->query('status'), ['draft', 'terbit'], true) ? $request->query('status') : null;

        $slips = SalarySlip::with('employee')
            ->where('period_year', $year)
            ->when($month, fn ($q) => $q->where('period_month', $month))
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->get();

        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $years     = range($now->year, $now->year - 4);

        return view('salary.slips.index', compact('slips', 'employees', 'year', 'month', 'employeeId', 'status', 'years'));
    }
}
```

- [ ] **Step 7: Buat view daftar**

`resources/views/salary/slips/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Slip Gaji - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Slip Gaji Karyawan</h5>
    @can('salary.create')
        <a href="{{ route('salary.slip.create') }}" class="btn btn-sm btn-primary">+ Buat Slip</a>
    @endcan
</div>

<div class="card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small mb-1">Tahun</label>
            <select name="year" class="form-select form-select-sm">
                @foreach ($years as $y)
                    <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Bulan</label>
            <select name="month" class="form-select form-select-sm">
                <option value="all" @selected($month === null)>Semua</option>
                @foreach (\App\Models\SalarySlip::MONTHS as $num => $label)
                    <option value="{{ $num }}" @selected($month === $num)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Karyawan</label>
            <select name="employee" class="form-select form-select-sm">
                <option value="all">Semua</option>
                @foreach ($employees as $emp)
                    <option value="{{ $emp->id }}" @selected($employeeId === $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="all">Semua</option>
                <option value="draft"  @selected($status === 'draft')>Draft</option>
                <option value="terbit" @selected($status === 'terbit')>Terbit</option>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-primary">Filter</button>
            <a href="{{ route('salary.slip.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </form>
</div></div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr>
                <th>No. Slip</th><th>Karyawan</th><th>Periode</th>
                <th class="text-end">Penghasilan</th><th class="text-end">Potongan</th><th class="text-end">Gaji Bersih</th>
                <th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            @foreach ($slips as $slip)
                <tr>
                    <td>{{ $slip->slip_no }}</td>
                    <td class="dt-judul">{{ $slip->employee_name }}</td>
                    <td>{{ $slip->periodLabel() }}</td>
                    <td class="text-end">{{ $rp($slip->total_earnings) }}</td>
                    <td class="text-end">{{ $rp($slip->total_deductions) }}</td>
                    <td class="text-end fw-bold">{{ $rp($slip->net_pay) }}</td>
                    <td>
                        <span class="badge {{ $slip->status === 'terbit' ? 'bg-success' : 'bg-secondary' }}">
                            {{ \App\Models\SalarySlip::STATUS[$slip->status] ?? $slip->status }}
                        </span>
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('salary.slip.show', $slip->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                        @can('salary.edit')
                            @if ($slip->isDraft())
                                <a href="{{ route('salary.slip.edit', $slip->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                            @endif
                        @endcan
                        @can('salary.export')
                            <a href="{{ route('salary.slip.pdf', $slip->id) }}" target="_blank" class="btn btn-xs btn-outline-dark">PDF</a>
                        @endcan
                        @can('salary.send')
                            <form method="POST" action="{{ route('salary.slip.send', $slip->id) }}" class="d-inline" data-confirm="Kirim slip ke email karyawan?">
                                @csrf @idempotent
                                <button class="btn btn-xs btn-outline-info">Kirim</button>
                            </form>
                        @endcan
                        @can('salary.delete')
                            <form method="POST" action="{{ route('salary.slip.destroy', $slip->id) }}" class="d-inline" data-confirm="Hapus slip ini?">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger">Hapus</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, responsive: true, order: [] }); });</script>
@endpush
```

- [ ] **Step 8: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipAccessTest`
Expected: PASS (2 tests).

- [ ] **Step 9: Verifikasi peta izin tetap lengkap**

Run: `php artisan test --filter=PermissionMapCompletenessTest`
Expected: PASS (rute `salary.*` sudah terpeta; `salary.slip.me*` masuk `public`).

- [ ] **Step 10: Commit**

```bash
git add config/permissions.php database/seeders/AccessMatrixSeeder.php routes/web.php \
        app/Http/Controllers/Pages/SalarySlipController.php \
        resources/views/salary/slips/index.blade.php \
        tests/Feature/SalarySlipAccessTest.php
git commit -m "feat(slip-gaji): hak akses, rute & halaman daftar slip gaji

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 4: Buat & simpan slip (create + store + form)

**Files:**
- Modify: `app/Http/Controllers/Pages/SalarySlipController.php` (tambah `create`, `store`, helper privat)
- Create: `resources/views/salary/slips/form.blade.php`
- Test: `tests/Feature/SalarySlipStoreTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipStoreTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipStoreTest extends TestCase
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
    public function accounting_creates_slip_with_lines_and_totals(): void
    {
        $emp = User::factory()->create(['name' => 'Budi']);
        $emp->profile()->create(['job_name' => 'Editor']);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.store'), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings' => [
                ['label' => 'Gaji Pokok', 'amount' => 5000000],
                ['label' => 'Tunjangan',  'amount' => 1000000],
            ],
            'deductions' => [
                ['label' => 'BPJS', 'amount' => 300000],
            ],
        ])->assertRedirect(route('salary.slip.index'));

        $slip = SalarySlip::first();
        $this->assertNotNull($slip);
        $this->assertSame('Budi', $slip->employee_name);
        $this->assertSame('Editor', $slip->employee_position);
        $this->assertEquals(6000000, $slip->total_earnings);
        $this->assertEquals(300000,  $slip->total_deductions);
        $this->assertEquals(5700000, $slip->net_pay);
        $this->assertSame('SLP-202607-0001', $slip->slip_no);
        $this->assertCount(3, $slip->lines);
    }

    /** @test */
    public function duplicate_period_for_same_employee_is_rejected(): void
    {
        $emp = User::factory()->create();
        SalarySlip::factory()->create(['user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7]);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.store'), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings' => [['label' => 'Gaji Pokok', 'amount' => 1000000]],
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(1, SalarySlip::count());
    }

    /** @test */
    public function create_form_renders_for_accounting(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.create'))
            ->assertOk()->assertSee('Rincian Penghasilan')->assertSee('Rincian Potongan');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipStoreTest`
Expected: FAIL (method `create`/`store` belum ada → error / view tidak ada).

- [ ] **Step 3: Tambah `create`, `store`, dan helper privat ke controller**

Tambahkan method berikut ke `app/Http/Controllers/Pages/SalarySlipController.php` (di dalam kelas, setelah `index`):

```php
    public function create()
    {
        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $now = now();

        return view('salary.slips.form', [
            'slip'       => new SalarySlip(['period_year' => $now->year, 'period_month' => $now->month, 'status' => 'draft']),
            'employees'  => $employees,
            'earnings'   => collect(),
            'deductions' => collect(),
            'mode'       => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeAmounts($request);
        $data = $request->validate($this->baseRules());

        if ($this->periodTaken($data['user_id'], $data['period_year'], $data['period_month'])) {
            return back()->withInput()->withErrors(['user_id' => 'Slip untuk karyawan & periode ini sudah ada.']);
        }

        $employee = User::with('profile')->findOrFail($data['user_id']);

        DB::transaction(function () use ($data, $employee) {
            $slip = SalarySlip::create([
                'slip_no'           => $this->generateSlipNo($data['period_year'], $data['period_month']),
                'user_id'           => $employee->id,
                'employee_name'     => $employee->name,
                'employee_position' => optional($employee->profile)->job_name,
                'period_year'       => $data['period_year'],
                'period_month'      => $data['period_month'],
                'status'            => 'draft',
                'note'              => $data['note'] ?? null,
                'created_by'        => Auth::id(),
            ]);
            $this->syncLines($slip, $data);
            $slip->recalcTotals();
        });

        return redirect()->route('salary.slip.index')->with('success', 'Slip gaji dibuat.');
    }

    /** Aturan validasi bersama create & update. */
    private function baseRules(): array
    {
        return [
            'user_id'             => 'required|exists:users,id',
            'period_year'         => 'required|integer|min:2000|max:2100',
            'period_month'        => 'required|integer|min:1|max:12',
            'note'                => 'nullable|string',
            'earnings'            => 'required|array|min:1',
            'earnings.*.label'    => 'required|string|max:150',
            'earnings.*.amount'   => 'required|numeric|min:0',
            'deductions'          => 'nullable|array',
            'deductions.*.label'  => 'required|string|max:150',
            'deductions.*.amount' => 'required|numeric|min:0',
        ];
    }

    /** Buang pemisah ribuan (mis. "1.000.000" → "1000000") sebelum validasi. Defensif. */
    private function normalizeAmounts(Request $request): void
    {
        foreach (['earnings', 'deductions'] as $group) {
            $rows = $request->input($group, []);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (isset($row['amount'])) {
                    $rows[$i]['amount'] = preg_replace('/[^\d]/', '', (string) $row['amount']);
                }
            }
            $request->merge([$group => $rows]);
        }
    }

    private function periodTaken(int $userId, int $year, int $month, ?int $ignoreId = null): bool
    {
        return SalarySlip::where('user_id', $userId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function generateSlipNo(int $year, int $month): string
    {
        $prefix = sprintf('SLP-%04d%02d-', $year, $month);
        $seq    = SalarySlip::withTrashed()->where('slip_no', 'like', $prefix . '%')->count() + 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** Buat ulang baris earning & deduction dari input tervalidasi. */
    private function syncLines(SalarySlip $slip, array $data): void
    {
        $rows = [];
        $pos  = 0;
        foreach ($data['earnings'] ?? [] as $e) {
            if (($e['label'] ?? '') === '') {
                continue;
            }
            $rows[] = ['type' => 'earning', 'label' => $e['label'], 'amount' => $e['amount'] ?? 0, 'position' => $pos++];
        }
        $pos = 0;
        foreach ($data['deductions'] ?? [] as $d) {
            if (($d['label'] ?? '') === '') {
                continue;
            }
            $rows[] = ['type' => 'deduction', 'label' => $d['label'], 'amount' => $d['amount'] ?? 0, 'position' => $pos++];
        }
        $slip->lines()->createMany($rows);
    }
```

- [ ] **Step 4: Buat view form (create & edit)**

`resources/views/salary/slips/form.blade.php`:

```blade
@extends('layouts.master')
@section('title', ($mode === 'edit' ? 'Edit' : 'Buat') . ' Slip Gaji - SiMAPA')

@section('content')
@php
    $action = $mode === 'edit' ? route('salary.slip.update', $slip->id) : route('salary.slip.store');
    $oldEarnings = old('earnings', $earnings->map(fn ($l) => ['label' => $l->label, 'amount' => (int) $l->amount])->values()->all());
    $oldDeductions = old('deductions', $deductions->map(fn ($l) => ['label' => $l->label, 'amount' => (int) $l->amount])->values()->all());
@endphp

<div class="row"><div class="col-lg-9">
<div class="card"><div class="card-body">
    <h5 class="mb-3">{{ $mode === 'edit' ? 'Edit' : 'Buat' }} Slip Gaji</h5>

    <form method="POST" action="{{ $action }}" id="slipForm">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif
        @idempotent

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Karyawan</label>
                <select name="user_id" class="form-select @error('user_id') is-invalid @enderror" required>
                    <option value="">— pilih —</option>
                    @foreach ($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(old('user_id', $slip->user_id) == $emp->id)>{{ $emp->name }}</option>
                    @endforeach
                </select>
                @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label">Bulan</label>
                <select name="period_month" class="form-select" required>
                    @foreach (\App\Models\SalarySlip::MONTHS as $num => $label)
                        <option value="{{ $num }}" @selected(old('period_month', $slip->period_month) == $num)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tahun</label>
                <input type="number" name="period_year" value="{{ old('period_year', $slip->period_year) }}" min="2000" max="2100" class="form-control" required>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
            <h6 class="mb-0">Rincian Penghasilan</h6>
            <div>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="addRow('earnings')">+ Baris</button>
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="fillPreset('earnings')">Preset</button>
            </div>
        </div>
        @error('earnings')<div class="text-danger small">{{ $message }}</div>@enderror
        <table class="table table-sm mt-2" id="earnings-table">
            <thead><tr><th>Komponen</th><th style="width:190px">Nominal (Rp)</th><th style="width:40px"></th></tr></thead>
            <tbody></tbody>
        </table>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <h6 class="mb-0">Rincian Potongan</h6>
            <div>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="addRow('deductions')">+ Baris</button>
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="fillPreset('deductions')">Preset</button>
            </div>
        </div>
        <table class="table table-sm mt-2" id="deductions-table">
            <thead><tr><th>Komponen</th><th style="width:190px">Nominal (Rp)</th><th style="width:40px"></th></tr></thead>
            <tbody></tbody>
        </table>

        <div class="row mt-2">
            <div class="col-md-5 ms-auto">
                <table class="table table-sm">
                    <tr><td>Total Penghasilan</td><td class="text-end" id="sum-earnings">Rp 0</td></tr>
                    <tr><td>Total Potongan</td><td class="text-end" id="sum-deductions">Rp 0</td></tr>
                    <tr class="fw-bold"><td>Gaji Bersih</td><td class="text-end" id="sum-net">Rp 0</td></tr>
                </table>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Catatan (opsional)</label>
            <textarea name="note" rows="2" class="form-control">{{ old('note', $slip->note) }}</textarea>
        </div>

        <button class="btn btn-primary">{{ $mode === 'edit' ? 'Simpan Perubahan' : 'Simpan Slip' }}</button>
        <a href="{{ route('salary.slip.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection

@push('custom-scripts')
<script>
const INIT = {
    earnings: @json(array_values($oldEarnings ?: [['label' => 'Gaji Pokok', 'amount' => 0]])),
    deductions: @json(array_values($oldDeductions ?: [])),
};
const PRESET = {
    earnings: [
        { label: 'Gaji Pokok', amount: 0 },
        { label: 'Tunjangan Jabatan', amount: 0 },
        { label: 'Tunjangan Transport', amount: 0 },
    ],
    deductions: [
        { label: 'BPJS', amount: 0 },
        { label: 'PPh21', amount: 0 },
    ],
};
const rp = n => 'Rp ' + (Number(n) || 0).toLocaleString('id-ID');

function rowHtml(group, label, amount) {
    const safe = String(label || '').replace(/"/g, '&quot;');
    return `<tr>
        <td><input type="text" name="${group}[__i__][label]" class="form-control form-control-sm" value="${safe}" required></td>
        <td><input type="number" name="${group}[__i__][amount]" class="form-control form-control-sm amount" min="0" step="1" value="${amount || 0}" required></td>
        <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="delRow(this)">&times;</button></td>
    </tr>`;
}

function reindex(group) {
    const body = document.querySelector('#' + group + '-table tbody');
    [...body.querySelectorAll('tr')].forEach((tr, i) => {
        tr.querySelectorAll('input').forEach(inp => {
            inp.name = inp.name.replace(/\[(?:\d+|__i__)\]/, '[' + i + ']');
        });
    });
}

function addRow(group, label = '', amount = 0) {
    const body = document.querySelector('#' + group + '-table tbody');
    body.insertAdjacentHTML('beforeend', rowHtml(group, label, amount));
    reindex(group);
    recalc();
}

function delRow(btn) {
    const tr = btn.closest('tr');
    const group = tr.closest('table').id.replace('-table', '');
    tr.remove();
    reindex(group);
    recalc();
}

function fillPreset(group) {
    PRESET[group].forEach(r => addRow(group, r.label, r.amount));
}

function sumGroup(group) {
    return [...document.querySelectorAll('#' + group + '-table tbody .amount')]
        .reduce((s, i) => s + (Number(i.value) || 0), 0);
}

function recalc() {
    const e = sumGroup('earnings'), d = sumGroup('deductions');
    document.getElementById('sum-earnings').textContent = rp(e);
    document.getElementById('sum-deductions').textContent = rp(d);
    document.getElementById('sum-net').textContent = rp(e - d);
}

document.addEventListener('input', ev => { if (ev.target.classList.contains('amount')) recalc(); });

INIT.earnings.forEach(r => addRow('earnings', r.label, r.amount));
INIT.deductions.forEach(r => addRow('deductions', r.label, r.amount));
if (document.querySelector('#earnings-table tbody').children.length === 0) addRow('earnings', 'Gaji Pokok', 0);
recalc();
</script>
@endpush
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipStoreTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/SalarySlipController.php \
        resources/views/salary/slips/form.blade.php \
        tests/Feature/SalarySlipStoreTest.php
git commit -m "feat(slip-gaji): buat & simpan slip dengan baris dinamis

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 5: Detail, edit, update & hapus

**Files:**
- Modify: `app/Http/Controllers/Pages/SalarySlipController.php` (tambah `show`, `edit`, `update`, `destroy`)
- Create: `resources/views/salary/slips/show.blade.php`
- Test: `tests/Feature/SalarySlipCrudTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipCrudTest extends TestCase
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
    public function update_resyncs_lines_and_recomputes(): void
    {
        $emp  = User::factory()->create();
        $slip = SalarySlip::factory()->create(['user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7, 'status' => 'draft']);
        $slip->lines()->create(['type' => 'earning', 'label' => 'Lama', 'amount' => 1000000, 'position' => 0]);
        $slip->recalcTotals();

        $this->actingAs($this->user('accounting'))->put(route('salary.slip.update', $slip->id), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings'   => [['label' => 'Gaji Pokok', 'amount' => 8000000]],
            'deductions' => [['label' => 'PPh21', 'amount' => 500000]],
        ])->assertRedirect(route('salary.slip.show', $slip->id));

        $slip->refresh();
        $this->assertEquals(8000000, $slip->total_earnings);
        $this->assertEquals(500000,  $slip->total_deductions);
        $this->assertEquals(7500000, $slip->net_pay);
        $this->assertCount(2, $slip->lines);
        $this->assertFalse($slip->lines->contains('label', 'Lama'));
    }

    /** @test */
    public function terbit_slip_cannot_be_edited(): void
    {
        $slip = SalarySlip::factory()->create(['status' => 'terbit']);
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.edit', $slip->id))
            ->assertRedirect(route('salary.slip.show', $slip->id));
    }

    /** @test */
    public function destroy_soft_deletes(): void
    {
        $slip = SalarySlip::factory()->create();
        $this->actingAs($this->user('accounting'))->delete(route('salary.slip.destroy', $slip->id))->assertRedirect();
        $this->assertSoftDeleted('tb_salary_slips', ['id' => $slip->id]);
    }

    /** @test */
    public function show_displays_take_home_pay(): void
    {
        $slip = SalarySlip::factory()->create();
        $slip->lines()->create(['type' => 'earning', 'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0]);
        $slip->recalcTotals();
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.show', $slip->id))
            ->assertOk()->assertSee('TAKE HOME PAY');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipCrudTest`
Expected: FAIL (method `show`/`edit`/`update`/`destroy` belum ada).

- [ ] **Step 3: Tambah method ke controller**

Tambahkan ke `app/Http/Controllers/Pages/SalarySlipController.php` (setelah `store`):

```php
    public function show(int $id)
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee', 'creator')->findOrFail($id);
        return view('salary.slips.show', compact('slip'));
    }

    public function edit(int $id)
    {
        $slip = SalarySlip::with('earnings', 'deductions')->findOrFail($id);
        if (! $slip->isDraft()) {
            return redirect()->route('salary.slip.show', $slip->id)->with('error', 'Slip yang sudah terbit tidak bisa diedit.');
        }
        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('salary.slips.form', [
            'slip'       => $slip,
            'employees'  => $employees,
            'earnings'   => $slip->earnings,
            'deductions' => $slip->deductions,
            'mode'       => 'edit',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $slip = SalarySlip::findOrFail($id);
        if (! $slip->isDraft()) {
            return redirect()->route('salary.slip.show', $slip->id)->with('error', 'Slip yang sudah terbit tidak bisa diedit.');
        }

        $this->normalizeAmounts($request);
        $data = $request->validate($this->baseRules());

        if ($this->periodTaken($data['user_id'], $data['period_year'], $data['period_month'], $slip->id)) {
            return back()->withInput()->withErrors(['user_id' => 'Slip untuk karyawan & periode ini sudah ada.']);
        }

        $employee = User::with('profile')->findOrFail($data['user_id']);

        DB::transaction(function () use ($slip, $data, $employee) {
            $slip->update([
                'user_id'           => $employee->id,
                'employee_name'     => $employee->name,
                'employee_position' => optional($employee->profile)->job_name,
                'period_year'       => $data['period_year'],
                'period_month'      => $data['period_month'],
                'note'              => $data['note'] ?? null,
                'updated_by'        => Auth::id(),
            ]);
            $slip->lines()->delete();
            $this->syncLines($slip, $data);
            $slip->recalcTotals();
        });

        return redirect()->route('salary.slip.show', $slip->id)->with('success', 'Slip gaji diperbarui.');
    }

    public function destroy(int $id)
    {
        $slip = SalarySlip::findOrFail($id);
        $slip->delete();
        return redirect()->route('salary.slip.index')->with('success', 'Slip gaji dihapus.');
    }
```

- [ ] **Step 4: Buat view detail**

`resources/views/salary/slips/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Detail Slip Gaji - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Slip Gaji — {{ $slip->periodLabel() }}</h5>
            <div class="text-muted small">No. Slip: {{ $slip->slip_no }} ·
                <span class="badge {{ $slip->status === 'terbit' ? 'bg-success' : 'bg-secondary' }}">
                    {{ \App\Models\SalarySlip::STATUS[$slip->status] ?? $slip->status }}
                </span>
            </div>
        </div>
        <div class="text-nowrap">
            @can('salary.export')
                <a href="{{ route('salary.slip.pdf', $slip->id) }}" target="_blank" class="btn btn-sm btn-outline-dark">PDF</a>
            @endcan
            @can('salary.send')
                <form method="POST" action="{{ route('salary.slip.send', $slip->id) }}" class="d-inline" data-confirm="Kirim slip ke email karyawan?">
                    @csrf @idempotent
                    <button class="btn btn-sm btn-outline-info">Kirim Email</button>
                </form>
            @endcan
            @can('salary.edit')
                @if ($slip->isDraft())
                    <a href="{{ route('salary.slip.edit', $slip->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                @endif
            @endcan
        </div>
    </div>

    <table class="table table-sm">
        <tr><th style="width:180px">Karyawan</th><td>{{ $slip->employee_name }}</td></tr>
        <tr><th>Jabatan</th><td>{{ $slip->employee_position ?? '-' }}</td></tr>
    </table>

    <h6 class="mt-3">Rincian Penghasilan</h6>
    <table class="table table-sm">
        @foreach ($slip->earnings as $e)
            <tr><td>{{ $e->label }}</td><td class="text-end">{{ $rp($e->amount) }}</td></tr>
        @endforeach
        <tr class="fw-bold"><td>Subtotal</td><td class="text-end">{{ $rp($slip->total_earnings) }}</td></tr>
    </table>

    <h6 class="mt-3">Rincian Potongan</h6>
    <table class="table table-sm">
        @forelse ($slip->deductions as $d)
            <tr><td>{{ $d->label }}</td><td class="text-end">{{ $rp($d->amount) }}</td></tr>
        @empty
            <tr><td class="text-muted" colspan="2">Tidak ada.</td></tr>
        @endforelse
        <tr class="fw-bold"><td>Subtotal</td><td class="text-end">{{ $rp($slip->total_deductions) }}</td></tr>
    </table>

    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3">
        <span class="fw-bold">GAJI BERSIH / TAKE HOME PAY</span>
        <span class="fw-bold fs-5">{{ $rp($slip->net_pay) }}</span>
    </div>

    @if($slip->note)<div class="text-muted small">Catatan: {{ $slip->note }}</div>@endif

    <a href="{{ route('salary.slip.index') }}" class="btn btn-outline-secondary mt-2">&larr; Kembali</a>
</div></div>
</div></div>
@endsection
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipCrudTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/SalarySlipController.php \
        resources/views/salary/slips/show.blade.php \
        tests/Feature/SalarySlipCrudTest.php
git commit -m "feat(slip-gaji): detail, edit terkunci-saat-terbit, update & hapus

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 6: PDF slip gaji

**Files:**
- Create: `app/Support/SalarySlipPdfData.php`
- Create: `resources/views/salary/slips/salary_slip_pdf.blade.php`
- Modify: `app/Http/Controllers/Pages/SalarySlipController.php` (tambah `pdf`)
- Test: `tests/Feature/SalarySlipPdfTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipPdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use App\Support\SalarySlipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipPdfTest extends TestCase
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
    public function accounting_can_download_pdf(): void
    {
        $slip = SalarySlip::factory()->create();
        $slip->lines()->create(['type' => 'earning', 'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0]);
        $slip->recalcTotals();

        $res = $this->actingAs($this->user('accounting'))->get(route('salary.slip.pdf', $slip->id));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('content-type'));
    }

    /** @test */
    public function pdf_data_includes_terbilang(): void
    {
        $slip = SalarySlip::factory()->create(['net_pay' => 5700000]);
        $data = SalarySlipPdfData::for($slip);
        $this->assertSame('Lima juta tujuh ratus ribu rupiah', $data['terbilang']);
        $this->assertSame('Juli 2026', $data['periodLabel']);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipPdfTest`
Expected: FAIL ("Class 'App\Support\SalarySlipPdfData' not found").

- [ ] **Step 3: Buat penyedia data PDF**

`app/Support/SalarySlipPdfData.php`:

```php
<?php

namespace App\Support;

use App\Models\SalarySlip;

class SalarySlipPdfData
{
    public static function for(SalarySlip $slip): array
    {
        $slip->loadMissing('earnings', 'deductions', 'employee');

        return [
            'slip'        => $slip,
            'earnings'    => $slip->earnings,
            'deductions'  => $slip->deductions,
            'totalEarn'   => (float) $slip->total_earnings,
            'totalDed'    => (float) $slip->total_deductions,
            'netPay'      => (float) $slip->net_pay,
            'terbilang'   => Terbilang::rupiah($slip->net_pay),
            'periodLabel' => $slip->periodLabel(),
        ];
    }
}
```

- [ ] **Step 4: Buat view PDF**

`resources/views/salary/slips/salary_slip_pdf.blade.php`:

```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}.text-center{text-align:center}
.lbl{color:#666;width:180px;border:0}.big{font-size:16px;font-weight:bold}
.plain td{border:0;padding:3px 6px}
.sub td{background:#fafafa;font-weight:bold}
.thp{border:2px solid #333;padding:10px;margin-top:14px}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<h2>SLIP GAJI KARYAWAN</h2>
<div class="muted">Avidpedia &middot; Periode: {{ $periodLabel }} &middot; No. Slip: {{ $slip->slip_no }}</div>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Nama</td><td>{{ $slip->employee_name }}</td></tr>
    <tr><td class="lbl">Jabatan</td><td>{{ $slip->employee_position ?? '-' }}</td></tr>
    <tr><td class="lbl">Periode</td><td>{{ $periodLabel }}</td></tr>
</table>

<div style="margin-top:12px;font-weight:bold">Rincian Penghasilan</div>
<table>
    <thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
    <tbody>
    @forelse($earnings as $e)
        <tr><td>{{ $e->label }}</td><td class="text-end">{{ $rp($e->amount) }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted">Tidak ada.</td></tr>
    @endforelse
        <tr class="sub"><td>Subtotal Penghasilan</td><td class="text-end">{{ $rp($totalEarn) }}</td></tr>
    </tbody>
</table>

<div style="margin-top:12px;font-weight:bold">Rincian Potongan</div>
<table>
    <thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
    <tbody>
    @forelse($deductions as $d)
        <tr><td>{{ $d->label }}</td><td class="text-end">{{ $rp($d->amount) }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted">Tidak ada.</td></tr>
    @endforelse
        <tr class="sub"><td>Subtotal Potongan</td><td class="text-end">{{ $rp($totalDed) }}</td></tr>
    </tbody>
</table>

<div class="thp">
    <table class="plain">
        <tr><td class="lbl">GAJI BERSIH / TAKE HOME PAY</td><td class="big">{{ $rp($netPay) }}</td></tr>
        <tr><td class="lbl">Terbilang</td><td><em>{{ $terbilang }}</em></td></tr>
    </table>
</div>

@if($slip->note)
<div class="box"><strong>Catatan:</strong> {{ $slip->note }}</div>
@endif

<table class="plain" style="margin-top:40px">
    <tr>
        <td style="width:60%;border:0"></td>
        <td class="text-center" style="border:0">Hormat kami,<br><br><br>Bagian Keuangan<br>Avidpedia</td>
    </tr>
</table>
<p class="muted" style="margin-top:20px;font-size:10px">Dokumen ini bersifat rahasia dan hanya untuk karyawan bersangkutan.</p>
</body></html>
```

- [ ] **Step 5: Tambah method `pdf` ke controller**

Tambahkan ke `app/Http/Controllers/Pages/SalarySlipController.php` (setelah `destroy`):

```php
    public function pdf(int $id)
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee')->findOrFail($id);
        return Pdf::loadView('salary.slips.salary_slip_pdf', SalarySlipPdfData::for($slip))
            ->stream('SlipGaji_' . $slip->slip_no . '.pdf');
    }
```

- [ ] **Step 6: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipPdfTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Support/SalarySlipPdfData.php \
        resources/views/salary/slips/salary_slip_pdf.blade.php \
        app/Http/Controllers/Pages/SalarySlipController.php \
        tests/Feature/SalarySlipPdfTest.php
git commit -m "feat(slip-gaji): PDF slip gaji dengan terbilang

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 7: Email — Mailable, Job, kirim & notifikasi

**Files:**
- Create: `app/Mail/SalarySlipMail.php`
- Create: `resources/views/pages/mails/salary_slip_mail.blade.php`
- Create: `app/Jobs/SendSalarySlipJob.php`
- Modify: `app/Services/Notifier.php` (tambah `salarySlipIssued`)
- Modify: `app/Http/Controllers/Pages/SalarySlipController.php` (tambah `send`)
- Test: `tests/Feature/SalarySlipMailTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipMailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendSalarySlipJob;
use App\Mail\SalarySlipMail;
use App\Models\SalarySlip;
use App\Models\User;
use App\Support\SalarySlipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipMailTest extends TestCase
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
    public function send_publishes_slip_and_dispatches_job(): void
    {
        Queue::fake();
        $slip = SalarySlip::factory()->create(['status' => 'draft']);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.send', $slip->id))->assertRedirect();

        $slip->refresh();
        $this->assertSame('terbit', $slip->status);
        $this->assertNotNull($slip->sent_at);
        Queue::assertPushed(SendSalarySlipJob::class);
    }

    /** @test */
    public function mailable_has_subject_and_pdf_attachment(): void
    {
        $slip = SalarySlip::factory()->create(['period_year' => 2026, 'period_month' => 7]);
        $data = SalarySlipPdfData::for($slip);
        $mail = new SalarySlipMail($slip, $data, 'PDFBYTES');

        $this->assertStringContainsString('Slip Gaji', $mail->envelope()->subject);
        $this->assertStringContainsString('Juli 2026', $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }

    /** @test */
    public function marketing_cannot_send(): void
    {
        $slip = SalarySlip::factory()->create();
        $this->actingAs($this->user('marketing'))->post(route('salary.slip.send', $slip->id))
            ->assertRedirect()->assertSessionHas('error');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipMailTest`
Expected: FAIL ("Class 'App\Jobs\SendSalarySlipJob' not found" / method `send` belum ada).

- [ ] **Step 3: Buat Mailable**

`app/Mail/SalarySlipMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\SalarySlip;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalarySlipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SalarySlip $slip, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Slip Gaji — ' . $this->slip->periodLabel());
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.salary_slip_mail');
    }

    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }
        return [
            Attachment::fromData(fn () => $this->pdf, 'SlipGaji_' . $this->slip->slip_no . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
```

- [ ] **Step 4: Buat view email**

`resources/views/pages/mails/salary_slip_mail.blade.php`:

```blade
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<p>Yth. {{ $slip->employee_name }},</p>
<p>Berikut kami sampaikan <strong>slip gaji</strong> Anda untuk periode <strong>{{ $data['periodLabel'] }}</strong>.</p>
<ul>
    <li>No. Slip: {{ $slip->slip_no }}</li>
    <li>Total Penghasilan: {{ $rp($data['totalEarn']) }}</li>
    <li>Total Potongan: {{ $rp($data['totalDed']) }}</li>
    <li><strong>Gaji Bersih (Take Home Pay): {{ $rp($data['netPay']) }}</strong></li>
</ul>
<p>Rincian lengkap ada pada slip gaji terlampir (PDF).</p>
<p>Dokumen ini bersifat rahasia dan hanya ditujukan untuk Anda.</p>
<p>Terima kasih.</p>
```

- [ ] **Step 5: Buat Job**

`app/Jobs/SendSalarySlipJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\SalarySlipMail;
use App\Models\SalarySlip;
use App\Services\GoogleDriveService;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSalarySlipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $slipId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee')->find($this->slipId);
        if (! $slip || $slip->status !== 'terbit') {
            return;
        }

        $data   = SalarySlipPdfData::for($slip);
        $pdfOut = Pdf::loadView('salary.slips.salary_slip_pdf', $data)->output();

        try {
            $tempDir = storage_path('app/temp/salary-slips');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/SlipGaji_' . $slip->slip_no . '.pdf';
            file_put_contents($tempPath, $pdfOut);
            $folderId = $drive->getOrCreateFolderByPath('Application/SalarySlips/' . $slip->period_year);
            if ($folderId) {
                $drive->uploadFile($tempPath, $folderId, true);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendSalarySlipJob Drive gagal: ' . $e->getMessage());
        }

        $email = optional($slip->employee)->email;
        if ($email) {
            Mail::to($email)->send(new SalarySlipMail($slip, $data, $pdfOut));
        }
    }
}
```

- [ ] **Step 6: Tambah `salarySlipIssued` ke Notifier**

Tambahkan method ke `app/Services/Notifier.php` (mis. setelah `refundIssued`):

```php
    public function salarySlipIssued(\App\Models\SalarySlip $slip): void
    {
        $slip->loadMissing('employee');
        if (! $slip->employee) {
            return;
        }
        $this->send(collect([$slip->employee]), [
            'category' => 'salary',
            'title'    => 'Slip gaji tersedia',
            'message'  => 'Periode ' . $slip->periodLabel() . ' • Rp ' . $this->rp($slip->net_pay),
            'url'      => route('salary.slip.me'),
            'icon'     => 'file-text',
        ]);
    }
```

- [ ] **Step 7: Tambah method `send` ke controller**

Tambahkan ke `app/Http/Controllers/Pages/SalarySlipController.php` (setelah `pdf`):

```php
    public function send(int $id)
    {
        $slip = SalarySlip::with('employee')->findOrFail($id);

        $slip->update(['status' => 'terbit', 'sent_at' => now()]);

        SendSalarySlipJob::dispatch($slip->id);
        app(Notifier::class)->salarySlipIssued($slip);

        return back()->with('success', 'Slip gaji diterbitkan & dikirim ke email karyawan.');
    }
```

- [ ] **Step 8: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipMailTest`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Mail/SalarySlipMail.php resources/views/pages/mails/salary_slip_mail.blade.php \
        app/Jobs/SendSalarySlipJob.php app/Services/Notifier.php \
        app/Http/Controllers/Pages/SalarySlipController.php \
        tests/Feature/SalarySlipMailTest.php
git commit -m "feat(slip-gaji): kirim email PDF via job antrean + notifikasi karyawan

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 8: Self-service karyawan (Slip Gaji Saya)

**Files:**
- Create: `app/Http/Controllers/Pages/EmployeeSalarySlipController.php`
- Create: `resources/views/salary/slips/me.blade.php`
- Modify: `routes/web.php` (tambah 2 rute self-service)
- Test: `tests/Feature/EmployeeSalarySlipTest.php`

> `config/permissions.php` sudah menaruh `salary.slip.me` & `salary.slip.me.pdf` di `public` pada Task 3.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/EmployeeSalarySlipTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeSalarySlipTest extends TestCase
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
    public function employee_sees_only_own_terbit_slips(): void
    {
        $me    = $this->user('marketing');
        $other = User::factory()->create();
        $mine   = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'terbit']);
        $draft  = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'draft']);
        $theirs = SalarySlip::factory()->create(['user_id' => $other->id, 'status' => 'terbit']);

        $res = $this->actingAs($me)->get(route('salary.slip.me'))->assertOk();
        $res->assertSee($mine->slip_no);
        $res->assertDontSee($draft->slip_no);
        $res->assertDontSee($theirs->slip_no);
    }

    /** @test */
    public function employee_cannot_download_others_slip(): void
    {
        $me     = $this->user('marketing');
        $other  = User::factory()->create();
        $theirs = SalarySlip::factory()->create(['user_id' => $other->id, 'status' => 'terbit']);

        $this->actingAs($me)->get(route('salary.slip.me.pdf', $theirs->id))->assertNotFound();
    }

    /** @test */
    public function employee_can_download_own_terbit_slip(): void
    {
        $me   = $this->user('marketing');
        $mine = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'terbit']);
        $mine->lines()->create(['type' => 'earning', 'label' => 'Gaji', 'amount' => 1000000, 'position' => 0]);
        $mine->recalcTotals();

        $this->actingAs($me)->get(route('salary.slip.me.pdf', $mine->id))->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=EmployeeSalarySlipTest`
Expected: FAIL ("Route [salary.slip.me] not defined").

- [ ] **Step 3: Daftarkan rute self-service**

Di `routes/web.php`, tepat setelah blok rute admin slip gaji (dari Task 3), tambahkan:

```php
    // Slip Gaji — self-service (semua user login; akses own-data di controller)
    Route::get('slip-gaji-saya', [\App\Http\Controllers\Pages\EmployeeSalarySlipController::class, 'me'])->name('salary.slip.me');
    Route::get('slip-gaji-saya/{id}/pdf', [\App\Http\Controllers\Pages\EmployeeSalarySlipController::class, 'pdf'])->name('salary.slip.me.pdf')->whereNumber('id');
```

- [ ] **Step 4: Buat controller self-service**

`app/Http/Controllers/Pages/EmployeeSalarySlipController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\SalarySlip;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

class EmployeeSalarySlipController extends Controller
{
    public function me()
    {
        $slips = SalarySlip::where('user_id', Auth::id())
            ->where('status', 'terbit')
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->get();

        return view('salary.slips.me', compact('slips'));
    }

    public function pdf(int $id)
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee')
            ->where('user_id', Auth::id())
            ->where('status', 'terbit')
            ->findOrFail($id);

        return Pdf::loadView('salary.slips.salary_slip_pdf', SalarySlipPdfData::for($slip))
            ->stream('SlipGaji_' . $slip->slip_no . '.pdf');
    }
}
```

- [ ] **Step 5: Buat view "Slip Gaji Saya"**

`resources/views/salary/slips/me.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Slip Gaji Saya - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<h5 class="mb-3">Slip Gaji Saya</h5>
<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable" style="width:100%">
            <thead><tr><th>No. Slip</th><th>Periode</th><th class="text-end">Gaji Bersih</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($slips as $slip)
                <tr>
                    <td>{{ $slip->slip_no }}</td>
                    <td>{{ $slip->periodLabel() }}</td>
                    <td class="text-end fw-bold">{{ $rp($slip->net_pay) }}</td>
                    <td><a href="{{ route('salary.slip.me.pdf', $slip->id) }}" target="_blank" class="btn btn-xs btn-outline-primary">Unduh PDF</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [] }); });</script>
@endpush
```

- [ ] **Step 6: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=EmployeeSalarySlipTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Verifikasi peta izin masih konsisten**

Run: `php artisan test --filter=PermissionMapCompletenessTest`
Expected: PASS (rute `me`/`me.pdf` public, tidak bentrok).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/EmployeeSalarySlipController.php \
        resources/views/salary/slips/me.blade.php routes/web.php \
        tests/Feature/EmployeeSalarySlipTest.php
git commit -m "feat(slip-gaji): self-service Slip Gaji Saya (own-data)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 9: Menu sidebar

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/SalarySlipSidebarTest.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/SalarySlipSidebarTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipSidebarTest extends TestCase
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
    public function accounting_sees_admin_salary_menu(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('dashboard'))
            ->assertOk()->assertSee(route('salary.slip.index'));
    }

    /** @test */
    public function every_logged_in_user_sees_self_service_menu_but_not_admin_menu(): void
    {
        // route('salary.slip.index') = .../salary/slip ; route('salary.slip.me') = .../slip-gaji-saya
        // (tidak saling menjadi substring, jadi assertion tegas).
        $this->actingAs($this->user('marketing'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('salary.slip.me'))
            ->assertDontSee(route('salary.slip.index'));
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php artisan test --filter=SalarySlipSidebarTest`
Expected: FAIL (link belum ada di sidebar).

- [ ] **Step 3: Tambah menu admin di seksi Keuangan**

Di `resources/views/layouts/sidebar.blade.php`, di dalam blok `@canany([... 'accounting.*' ...])` seksi Keuangan (sebelum `@endcanany` di baris ~212), tambahkan item — TAPI karena item ini dijaga izin `salary.view` yang bukan bagian `accounting.*`, letakkan sebagai blok `@can` tersendiri **setelah** `@endcanany` seksi Keuangan (baris ~212), agar tetap muncul untuk accounting/superadmin:

```blade
            @can('salary.view')
                <li class="nav-item {{ nav_active('salary.slip.index') }}">
                    <a href="{{ route('salary.slip.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-text"></i>
                        <span class="link-title">Slip Gaji</span>
                    </a>
                </li>
            @endcan
```

- [ ] **Step 4: Tambah menu self-service di seksi Laporan**

Di `resources/views/layouts/sidebar.blade.php`, setelah item "Laporan Bulanan" (baris ~264, sebelum `@can('report.submissions.view')`), tambahkan link publik (tanpa `@can`, seperti Laporan Harian):

```blade
            {{-- salary.slip.me: route publik (own-data), terbuka utk semua role login. --}}
            <li class="nav-item {{ nav_active('salary.slip.me') }}">
                <a href="{{ route('salary.slip.me') }}" class="nav-link">
                    <i class="link-icon" data-feather="file-text"></i>
                    <span class="link-title">Slip Gaji Saya</span>
                </a>
            </li>
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=SalarySlipSidebarTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php tests/Feature/SalarySlipSidebarTest.php
git commit -m "feat(slip-gaji): menu sidebar Slip Gaji & Slip Gaji Saya

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 10: Verifikasi menyeluruh & migrasi DB dev

**Files:** (tidak ada berkas baru — verifikasi & sinkronisasi lingkungan)

- [ ] **Step 1: Jalankan SELURUH suite pada DB test**

Run: `php artisan test`
Expected: PASS semua (593 lama + test baru slip gaji). Perhatikan khusus:
- `PermissionMapCompletenessTest` — hijau (semua rute `salary.*` terpeta/public).
- `RouteSmokeTest` — hijau (index/create/me render tanpa 5xx).
- `AccessParityTest` / `PermissionButtonVisibilityTest` — hijau.

Jika ada yang merah, perbaiki sebelum lanjut (JANGAN longgarkan assertion test lama).

- [ ] **Step 2: Migrasikan DB dev (agar app live tidak 500)**

Jalankan migrasi pada DB dev `avidpedi_simapa` (memakai `.env` default, BUKAN `.env.testing`):

Run: `php artisan migrate`
Expected: Dua migrasi `..._create_tb_salary_slips_table` & `..._create_tb_salary_slip_lines_table` "DONE".

- [ ] **Step 3: Seed ulang matriks hak akses di DB dev**

Run: `php artisan db:seed --class=AccessMatrixSeeder`
Expected: Selesai tanpa error; role `accounting` kini punya permission `salary.*`.

- [ ] **Step 4: Verifikasi manual singkat di browser (opsional tapi disarankan)**

Login sebagai superadmin/accounting → buka menu **Keuangan → Slip Gaji** → Buat Slip (isi 1 karyawan, beberapa baris penghasilan & potongan) → simpan → buka detail → unduh PDF → klik Kirim Email → cek log/mailtrap. Login sebagai user biasa → menu **Laporan → Slip Gaji Saya** → hanya slip terbit miliknya, unduh PDF.

- [ ] **Step 5: Commit penutup (jika ada berkas tak-terlacak seperti dokumentasi)**

Jika tidak ada perubahan berkas, lewati. Jika ada catatan/dok, commit terpisah:

```bash
git status
# hanya bila ada perubahan relevan:
git commit -m "chore(slip-gaji): verifikasi akhir modul slip gaji

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Catatan Keamanan & Konsistensi

- **Standalone dari Keuangan**: tidak ada penulisan ke `tb_cash_entries`. Semantik kas tetap utuh.
- **Snapshot** `employee_name`/`employee_position` menjaga PDF historis tetap benar walau data user berubah.
- **Self-service aman**: `EmployeeSalarySlipController::pdf` men-scope query ke `user_id = Auth::id()` + `status = terbit`; slip milik orang lain → 404, draft → 404.
- **Edit terkunci** setelah `terbit` (koreksi = hapus lalu buat ulang) — menjaga integritas slip yang sudah dikirim.
- **Idempotency**: form kirim & buat memakai `@idempotent`.
- **Antrean + Drive**: kegagalan Google Drive tidak menggagalkan pengiriman email (dibungkus try/catch + `Log::warning`), sama seperti `SendRefundJob`.
