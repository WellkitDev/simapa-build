# Kunci Periode + Audit Log Jurnal Kas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bulan yang sudah final tak bisa diubah lewat jalur manusia; setiap perubahan entri kas meninggalkan jejak; dan entri otomatis dari pembayaran tak bisa lagi dihapus lewat URL.

**Architecture:** `tb_cash_period_locks` (ada baris = terkunci) + `tb_cash_logs` (jejak). Penjaga dipasang di `CashEntryController` (jalur manusia) — **bukan** di model, agar sinkron payment (`PaymentObserver`) tetap tembus sesuai keputusan user. Log ditulis dari `CashEntryObserver` supaya menangkap **semua** jalur, termasuk yang lolos kunci (ditandai catatan `periode terkunci`). Penolakan disajikan sebagai alert lewat `CashEntryGuardException::render()` (pola `DataAssetAccessException`).

**Tech Stack:** Laravel 11, PHPUnit, DataTables + Bootstrap (sudah ada). Tanpa dependency baru.

**Spec:** `docs/superpowers/specs/2026-07-17-cash-period-lock-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit.
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell (`@'...'@`) di dalam tool Bash.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`. **Migrasi dev dijalankan di Task 5** — wajib, atau aplikasi dev 500 karena tabel baru belum ada.
- Semua daftar/tabel pakai **DataTables** (konvensi repo).

## File Structure

| File | Tanggung jawab |
|---|---|
| `database/migrations/2026_07_17_000002_create_cash_period_locks_table.php` (**baru**) | Skema kunci. |
| `database/migrations/2026_07_17_000003_create_cash_logs_table.php` (**baru**) | Skema jejak. |
| `app/Models/CashPeriodLock.php`, `app/Models/CashLog.php` (**baru**) | Model tipis. |
| `app/Exceptions/CashEntryGuardException.php` (**baru**) | Satu tempat semua pesan penolakan + cara menyajikannya. |
| `app/Services/CashPeriodService.php` (**baru**) | Aturan kunci: `isLocked`/`assertUnlocked`/`lock`/`unlock`. |
| `app/Observers/CashEntryObserver.php` (**baru**) | Menulis jejak dari SEMUA jalur. |
| `app/Http/Controllers/Pages/CashPeriodController.php` (**baru**) | `lock`/`unlock`/`audit`. |
| `resources/views/accounting/audit.blade.php` (**baru**) | Halaman Riwayat Perubahan. |
| `CashEntryController` (**diubah**) | 4 penjaga. |

---

## Task 1: Skema + model

**Files:**
- Create: `database/migrations/2026_07_17_000002_create_cash_period_locks_table.php`
- Create: `database/migrations/2026_07_17_000003_create_cash_logs_table.php`
- Create: `app/Models/CashPeriodLock.php`
- Create: `app/Models/CashLog.php`

- [ ] **Step 1: Migrasi kunci periode**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_period_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_period_locks');
    }
};
```

- [ ] **Step 2: Migrasi log**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_logs', function (Blueprint $table) {
            $table->id();
            // SENGAJA tanpa foreign key: audit log harus hidup lebih lama dari
            // baris yang dicatatnya. FK cascade akan menghapus bukti bersama
            // barang buktinya; nullOnDelete membuang tautannya.
            $table->unsignedBigInteger('cash_entry_id')->nullable();
            $table->string('action', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('cash_entry_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_logs');
    }
};
```

- [ ] **Step 3: Model**

`app/Models/CashPeriodLock.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPeriodLock extends Model
{
    protected $table = 'tb_cash_period_locks';

    protected $fillable = ['year', 'month', 'locked_by', 'locked_at'];

    protected $casts = ['year' => 'integer', 'month' => 'integer', 'locked_at' => 'datetime'];

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
```

`app/Models/CashLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashLog extends Model
{
    protected $table = 'tb_cash_logs';

    protected $fillable = ['cash_entry_id', 'action', 'user_id', 'changes', 'note'];

    protected $casts = ['changes' => 'array'];

    public const ACTIONS = [
        'created'  => 'Dibuat',
        'updated'  => 'Diubah',
        'deleted'  => 'Dihapus',
        'locked'   => 'Periode dikunci',
        'unlocked' => 'Periode dibuka',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** Pelaku; null = perubahan otomatis tanpa user login (mis. sinkron dari console). */
    public function actorName(): string
    {
        return $this->user?->name ?? 'sistem';
    }
}
```

- [ ] **Step 4: Migrasi test DB jalan**

Run: `php artisan test --filter=CashJournalServiceTest`
Expected: PASS (membuktikan kedua migrasi baru tidak merusak apa pun).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_17_000002_create_cash_period_locks_table.php database/migrations/2026_07_17_000003_create_cash_logs_table.php app/Models/CashPeriodLock.php app/Models/CashLog.php
git commit -F <path-pesan>   # feat(accounting): skema kunci periode + audit log kas
```

---

## Task 2: Penjaga — kunci periode + tutup lubang entri auto (TDD)

**Files:**
- Create: `tests/Feature/CashPeriodLockTest.php`
- Create: `app/Exceptions/CashEntryGuardException.php`
- Create: `app/Services/CashPeriodService.php`
- Modify: `app/Http/Controllers/Pages/CashEntryController.php` (store/transfer/update/destroy)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/CashPeriodLockTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kunci periode menjaga JALUR MANUSIA (controller). Sinkron payment (observer)
 * sengaja tembus — lihat spec §Keputusan — tapi wajib tercatat.
 */
class CashPeriodLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function entri(string $tanggal, string $source = 'manual'): CashEntry
    {
        return CashEntry::create([
            'tanggal'          => $tanggal,
            'kode'             => 'K' . uniqid(),
            'keterangan'       => 'Uji',
            'jenis'            => 'pengeluaran',
            'amount'           => 500_000,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
            'source'           => $source,
            'is_transfer'      => false,
        ]);
    }

    private function payloadEntri(string $tanggal): array
    {
        return [
            'tanggal'          => $tanggal,
            'keterangan'       => 'Biaya uji',
            'jenis'            => 'pengeluaran',
            'amount'           => 250_000,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
        ];
    }

    private function kunci(int $year, int $month): void
    {
        app(CashPeriodService::class)->lock($year, $month, null);
    }

    /** @test */
    public function manual_entry_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);

        $this->actingAs($this->superadmin())
            ->post(route('accounting.entry.store'), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('error');

        $this->assertSame(0, CashEntry::count(), 'Entri tak boleh tercipta di periode terkunci.');
    }

    /** @test */
    public function update_into_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-07-10');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('error');

        $this->assertSame('2026-07-10', $entry->fresh()->tanggal->format('Y-m-d'), 'Entri tak boleh diseret ke bulan terkunci.');
    }

    /** @test */
    public function update_out_of_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-06-10');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-07-10'))
            ->assertSessionHas('error');

        $this->assertSame('2026-06-10', $entry->fresh()->tanggal->format('Y-m-d'), 'Entri beku tak boleh dikeluarkan dari bulan terkunci.');
    }

    /** @test */
    public function destroy_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-06-10');

        $this->actingAs($this->superadmin())
            ->delete(route('accounting.entry.destroy', $entry->id))
            ->assertSessionHas('error');

        $this->assertNotNull(CashEntry::find($entry->id));
    }

    /** @test */
    public function transfer_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $akun = CashAccount::orderBy('id')->take(2)->pluck('id');

        $this->actingAs($this->superadmin())->post(route('accounting.transfer.store'), [
            'from_account_id' => $akun[0], 'to_account_id' => $akun[1],
            'amount' => 1_000_000, 'tanggal' => '2026-06-10',
        ])->assertSessionHas('error');

        $this->assertSame(0, CashEntry::count(), 'Transfer tak boleh membuat SATU sisi pun.');
    }

    /** @test */
    public function unlock_restores_permission(): void
    {
        $this->kunci(2026, 6);
        app(CashPeriodService::class)->unlock(2026, 6, null);

        $this->actingAs($this->superadmin())
            ->post(route('accounting.entry.store'), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('success');

        $this->assertSame(1, CashEntry::count());
    }

    /** @test */
    public function only_superadmin_can_lock(): void
    {
        $acc = User::factory()->create();
        $acc->assignRole('accounting');

        $this->actingAs($acc)->post(route('accounting.period.lock'), ['year' => 2026, 'month' => 6])
            ->assertForbidden();

        $this->actingAs($this->superadmin())->post(route('accounting.period.lock'), ['year' => 2026, 'month' => 6]);
        $this->assertTrue(app(CashPeriodService::class)->isLocked(2026, 6));
    }

    /** @test */
    public function payment_sync_passes_lock(): void
    {
        // Kompromi yang DISENGAJA: jurnal cuma cerminan tb_payments.
        $this->kunci(2026, 6);

        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul', 'slug' => 's-' . uniqid(),
            'chapters' => 1, 'cost_amount' => 5_000_000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5_000_000, 'status' => 'paid', 'paid_at' => '2026-06-15']);

        $this->assertSame(1, CashEntry::where('source', 'payment')->count(), 'Sinkron payment harus tetap tembus kunci.');
    }

    /** @test */
    public function auto_entry_cannot_be_deleted(): void
    {
        // Lubang yang ditemukan lewat probe: UI menyembunyikan tombol,
        // server tak menegakkan apa pun. Kini jadi penjaga permanen.
        $entry = $this->entri('2026-07-10', 'payment');

        $this->actingAs($this->superadmin())
            ->delete(route('accounting.entry.destroy', $entry->id))
            ->assertSessionHas('error');

        $this->assertNotNull(CashEntry::find($entry->id), 'Entri auto tak boleh dihapus dari jurnal.');
    }

    /** @test */
    public function auto_entry_cannot_be_updated(): void
    {
        $entry = $this->entri('2026-07-10', 'payment');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-07-11'))
            ->assertSessionHas('error');

        $this->assertSame(500_000.0, (float) $entry->fresh()->amount, 'Nilai entri auto tak boleh berubah dari jurnal.');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=CashPeriodLockTest`
Expected: **FAIL** — `Class "App\Services\CashPeriodService" not found`; `Route [accounting.period.lock] not defined`; dan `auto_entry_cannot_be_deleted` gagal karena entri **terhapus** (lubang yang terbukti lewat probe).

- [ ] **Step 3: Exception**

Buat `app/Exceptions/CashEntryGuardException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

class CashEntryGuardException extends Exception
{
    public static function periodLocked(int $year, int $month): self
    {
        return new self("Periode {$month}/{$year} sudah dikunci. Buka kunci dulu bila memang perlu diubah.");
    }

    public static function autoEntry(): self
    {
        return new self('Entri ini otomatis dari pembayaran dan tak bisa diubah di sini. Ubah pembayarannya — jurnal ikut menyesuaikan.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
```

- [ ] **Step 4: Service**

Buat `app/Services/CashPeriodService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\CashEntryGuardException;
use App\Models\CashLog;
use App\Models\CashPeriodLock;
use App\Models\User;
use Carbon\Carbon;

class CashPeriodService
{
    public function isLocked(int $year, int $month): bool
    {
        return CashPeriodLock::where('year', $year)->where('month', $month)->exists();
    }

    /** @param string|\DateTimeInterface|null $tanggal */
    public function assertUnlocked($tanggal): void
    {
        if (! $tanggal) {
            return;
        }
        $d = Carbon::parse($tanggal);

        if ($this->isLocked((int) $d->year, (int) $d->month)) {
            throw CashEntryGuardException::periodLocked((int) $d->year, (int) $d->month);
        }
    }

    public function lock(int $year, int $month, ?User $actor): void
    {
        CashPeriodLock::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['locked_by' => $actor?->id, 'locked_at' => now()]
        );

        CashLog::create([
            'action'  => 'locked',
            'user_id' => $actor?->id,
            'note'    => "Periode {$month}/{$year}",
        ]);
    }

    public function unlock(int $year, int $month, ?User $actor): void
    {
        CashPeriodLock::where('year', $year)->where('month', $month)->delete();

        CashLog::create([
            'action'  => 'unlocked',
            'user_id' => $actor?->id,
            'note'    => "Periode {$month}/{$year}",
        ]);
    }

    public function lockFor(int $year, int $month): ?CashPeriodLock
    {
        return CashPeriodLock::with('lockedBy')->where('year', $year)->where('month', $month)->first();
    }
}
```

- [ ] **Step 5: Pasang 4 penjaga di `CashEntryController`**

Tambah import:

```php
use App\Exceptions\CashEntryGuardException;
use App\Services\CashPeriodService;
```

`store()` — sisipkan **setelah** `$data = $this->validated($request);`:

```php
        app(CashPeriodService::class)->assertUnlocked($data['tanggal']);
```

`transfer()` — sisipkan **setelah** blok `$data = $request->validate([...]);`:

```php
        app(CashPeriodService::class)->assertUnlocked($data['tanggal']);
```

`update()` — ganti dua baris pertama menjadi:

```php
        $entry = CashEntry::findOrFail($id);
        if ($entry->source === 'payment') {
            throw CashEntryGuardException::autoEntry();
        }
        $data = $this->validated($request);
        $periode = app(CashPeriodService::class);
        $periode->assertUnlocked($entry->tanggal);   // tanggal LAMA
        $periode->assertUnlocked($data['tanggal']);  // tanggal BARU
```

`destroy()` — ganti baris pertama menjadi:

```php
        $entry = CashEntry::findOrFail($id);
        if ($entry->source === 'payment') {
            throw CashEntryGuardException::autoEntry();
        }
        app(CashPeriodService::class)->assertUnlocked($entry->tanggal);
```

> `update` **wajib** memeriksa dua tanggal. Kunci yang hanya memeriksa satu sisi bukan kunci: entri Juli bisa diseret ke Juni yang terkunci, atau entri Juni yang beku dikeluarkan ke Juli.

- [ ] **Step 6: Rute lock/unlock**

Di `routes/web.php`, **di dalam** grup `Route::middleware('role:superadmin|accounting')` yang memuat rute `accounting.*` (mulai baris ~318), tambahkan (peran diperketat per-rute):

```php
        Route::middleware('role:superadmin')->group(function () {
            Route::post('accounting/period/lock', [\App\Http\Controllers\Pages\CashPeriodController::class, 'lock'])->name('accounting.period.lock');
            Route::post('accounting/period/unlock', [\App\Http\Controllers\Pages\CashPeriodController::class, 'unlock'])->name('accounting.period.unlock');
        });
        Route::get('accounting/audit', [\App\Http\Controllers\Pages\CashPeriodController::class, 'audit'])->name('accounting.audit');
```

- [ ] **Step 7: Controller lock/unlock (audit menyusul di Task 4)**

Buat `app/Http/Controllers/Pages/CashPeriodController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\CashPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashPeriodController extends Controller
{
    public function __construct(private CashPeriodService $service) {}

    public function lock(Request $request)
    {
        $data = $this->validatePeriod($request);
        $this->service->lock($data['year'], $data['month'], Auth::user());

        return back()->with('success', "Periode {$data['month']}/{$data['year']} dikunci.");
    }

    public function unlock(Request $request)
    {
        $data = $this->validatePeriod($request);
        $this->service->unlock($data['year'], $data['month'], Auth::user());

        return back()->with('success', "Periode {$data['month']}/{$data['year']} dibuka.");
    }

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);
    }
}
```

- [ ] **Step 8: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=CashPeriodLockTest`
Expected: **PASS**, 10 test.

- [ ] **Step 9: Commit**

```bash
git add app/Exceptions/CashEntryGuardException.php app/Services/CashPeriodService.php app/Http/Controllers/Pages/CashPeriodController.php app/Http/Controllers/Pages/CashEntryController.php routes/web.php tests/Feature/CashPeriodLockTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
feat(accounting): kunci periode + tutup lubang entri auto

Kunci periode manual (superadmin) memblokir mutasi MANUAL pada bulan
terkunci; update memeriksa tanggal lama DAN baru supaya entri tak bisa
diseret masuk/keluar bulan beku. Sinkron payment sengaja tembus (jurnal
cuma cerminan tb_payments) - lihat spec.

Sekaligus menutup lubang terbukti: entri source=payment tak lagi bisa
disunting/dihapus lewat POST langsung. Sebelumnya UI menyembunyikan
tombolnya tapi server tak menegakkan apa pun, sehingga jurnal bisa
menyimpang dari tb_payments diam-diam.

Penolakan disajikan sebagai alert (pola DataAssetAccessException).

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 3: Audit log lewat observer (TDD)

**Files:**
- Create: `tests/Feature/CashAuditLogTest.php`
- Create: `app/Observers/CashEntryObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (baris ~24, setelah `Payment::observe`)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/CashAuditLogTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashLog;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Jejak perubahan entri kas — ditulis dari observer agar SEMUA jalur tertangkap. */
class CashAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function entri(string $tanggal = '2026-07-10', string $source = 'manual'): CashEntry
    {
        return CashEntry::create([
            'tanggal'          => $tanggal,
            'kode'             => 'K' . uniqid(),
            'keterangan'       => 'Uji',
            'jenis'            => 'pengeluaran',
            'amount'           => 500_000,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
            'source'           => $source,
            'is_transfer'      => false,
        ]);
    }

    /** @test */
    public function create_update_delete_are_logged(): void
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        $this->actingAs($u);

        $entry = $this->entri();
        $entry->update(['amount' => 700_000]);
        $entry->delete();

        $this->assertSame(1, CashLog::where('action', 'created')->count());
        $this->assertSame(1, CashLog::where('action', 'updated')->count());
        $this->assertSame(1, CashLog::where('action', 'deleted')->count());
        $this->assertSame($u->id, CashLog::where('action', 'created')->first()->user_id);
    }

    /** @test */
    public function update_log_records_before_and_after(): void
    {
        $entry = $this->entri();
        $entry->update(['amount' => 700_000]);

        $log = CashLog::where('action', 'updated')->firstOrFail();

        $this->assertSame(500_000.0, (float) $log->changes['before']['amount']);
        $this->assertSame(700_000.0, (float) $log->changes['after']['amount']);
    }

    /** @test */
    public function deleted_log_survives_the_entry(): void
    {
        // Membuktikan keputusan "tanpa FK": bukti tak boleh ikut terhapus.
        $entry = $this->entri();
        $id = $entry->id;
        $entry->delete();

        $log = CashLog::where('action', 'deleted')->where('cash_entry_id', $id)->firstOrFail();

        $this->assertNull(CashEntry::find($id));
        $this->assertSame(500_000.0, (float) $log->changes['amount'], 'Log harus tetap memuat nominalnya.');
    }

    /** @test */
    public function lock_and_unlock_are_logged(): void
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        app(CashPeriodService::class)->lock(2026, 6, $u);
        app(CashPeriodService::class)->unlock(2026, 6, $u);

        $this->assertSame(1, CashLog::where('action', 'locked')->count());
        $this->assertSame(1, CashLog::where('action', 'unlocked')->count());
        $this->assertSame($u->id, CashLog::where('action', 'locked')->first()->user_id);
    }

    /** @test */
    public function system_actor_is_null(): void
    {
        // Tanpa actingAs: tak ada user login (mis. sinkron dari console/migrasi).
        $this->entri();

        $log = CashLog::where('action', 'created')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('sistem', $log->actorName());
    }

    /** @test */
    public function payment_sync_into_locked_period_is_noted(): void
    {
        // Kompromi yang disengaja HARUS terlihat di log.
        app(CashPeriodService::class)->lock(2026, 6, null);

        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul', 'slug' => 's-' . uniqid(),
            'chapters' => 1, 'cost_amount' => 5_000_000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5_000_000, 'status' => 'paid', 'paid_at' => '2026-06-15']);

        $log = CashLog::where('action', 'created')->latest('id')->firstOrFail();

        $this->assertSame('periode terkunci', $log->note);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=CashAuditLogTest`
Expected: **FAIL** — nol baris `tb_cash_logs` (observer belum ada), jadi `count()` = 0 dan `firstOrFail()` melempar `ModelNotFoundException`.

- [ ] **Step 3: Observer**

Buat `app/Observers/CashEntryObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\CashEntry;
use App\Models\CashLog;
use App\Services\CashPeriodService;
use Illuminate\Support\Facades\Auth;

class CashEntryObserver
{
    /** Field yang bermakna dicatat (sisanya derau: kode, timestamps). */
    private const FIELDS = ['tanggal', 'jenis', 'amount', 'keterangan', 'account_id', 'cash_category_id'];

    public function created(CashEntry $entry): void
    {
        $this->log($entry, 'created', $this->snapshot($entry));
    }

    public function updated(CashEntry $entry): void
    {
        $changed = array_intersect_key($entry->getChanges(), array_flip(self::FIELDS));
        if (empty($changed)) {
            return; // hanya updated_at/kode → bukan perubahan bermakna
        }

        $before = [];
        foreach (array_keys($changed) as $field) {
            $before[$field] = $entry->getOriginal($field);
        }

        $this->log($entry, 'updated', ['before' => $before, 'after' => $changed]);
    }

    public function deleted(CashEntry $entry): void
    {
        $this->log($entry, 'deleted', $this->snapshot($entry));
    }

    private function snapshot(CashEntry $entry): array
    {
        return array_intersect_key($entry->getAttributes(), array_flip(self::FIELDS));
    }

    /**
     * Catatan "periode terkunci" menandai perubahan yang lolos kunci — satu-satunya
     * jalur yang bisa mengubah bulan beku adalah sinkron payment (disengaja, spec
     * §Keputusan). Kompromi itu harus terlihat, bukan tersembunyi.
     */
    private function log(CashEntry $entry, string $action, array $changes): void
    {
        $note = null;
        if ($entry->tanggal) {
            $d = $entry->tanggal instanceof \DateTimeInterface ? $entry->tanggal : \Carbon\Carbon::parse($entry->tanggal);
            if (app(CashPeriodService::class)->isLocked((int) $d->format('Y'), (int) $d->format('n'))) {
                $note = 'periode terkunci';
            }
        }

        CashLog::create([
            'cash_entry_id' => $entry->id,
            'action'        => $action,
            'user_id'       => Auth::id(),
            'changes'       => $changes,
            'note'          => $note,
        ]);
    }
}
```

- [ ] **Step 4: Daftarkan observer**

Di `app/Providers/AppServiceProvider.php`, tepat **setelah** baris `\App\Models\Payment::observe(\App\Observers\PaymentObserver::class);` (±24):

```php
        \App\Models\CashEntry::observe(\App\Observers\CashEntryObserver::class);
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=CashAuditLogTest`
Expected: **PASS**, 6 test.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/CashEntryObserver.php app/Providers/AppServiceProvider.php tests/Feature/CashAuditLogTest.php
git commit -F <path-pesan>   # feat(accounting): audit log entri kas lewat observer
```

---

## Task 4: UI — tombol kunci + halaman Riwayat Perubahan

**Files:**
- Modify: `app/Http/Controllers/Pages/CashPeriodController.php` (+`audit`)
- Modify: `app/Http/Controllers/Pages/CashEntryController.php` (`index` kirim status kunci)
- Create: `resources/views/accounting/audit.blade.php`
- Modify: `resources/views/accounting/journal.blade.php` (badge + tombol)
- Modify: `resources/views/layouts/sidebar.blade.php` (menu, setelah blok `accounting.target` ±b.163)
- Modify: `tests/Feature/CashAuditLogTest.php` (+test render)

- [ ] **Step 1: Method `audit`**

Tambahkan ke `CashPeriodController`, dan import `use App\Models\CashLog;`:

```php
    public function audit(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $logs = CashLog::with('user')
            ->whereYear('created_at', $year)
            ->latest('id')
            ->get();

        return view('accounting.audit', compact('logs', 'year'));
    }
```

- [ ] **Step 2: `CashEntryController@index` kirim status kunci**

Tambah import `use App\Services\CashPeriodService;` (bila Step Task 2 belum menambahkannya). Pada `return view('accounting.journal', array_merge($data, [...]))`, tambahkan dua kunci:

```php
            'periodLock'   => $month ? app(CashPeriodService::class)->lockFor($year, $month) : null,
            'canLock'      => (bool) optional(auth()->user())->hasRole('superadmin'),
```

> `$month` null saat filter "semua bulan" → tombol kunci tak relevan, jadi `null`.

- [ ] **Step 3: Badge + tombol di Jurnal Kas**

Di `resources/views/accounting/journal.blade.php`, sisipkan sebagai blok mandiri **di atas** baris `@foreach($balances['rows'] as $row)` (kartu saldo per akun, ±b.44) — jadi status kunci terbaca sebelum angkanya:

```blade
@if($month)
    <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
        @if($periodLock)
            <span class="badge bg-secondary">🔒 Periode {{ $month }}/{{ $year }} terkunci</span>
            <span class="text-muted small">dikunci {{ $periodLock->lockedBy?->name ?? 'sistem' }} · {{ optional($periodLock->locked_at)->format('d/m/Y') }}</span>
            @if($canLock)
                <form method="POST" action="{{ route('accounting.period.unlock') }}" data-confirm="Buka kunci periode ini? Perubahan setelahnya tercatat di Riwayat Perubahan." class="m-0">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="month" value="{{ $month }}">
                    <button class="btn btn-xs btn-outline-secondary">Buka kunci</button>
                </form>
            @endif
        @elseif($canLock)
            <form method="POST" action="{{ route('accounting.period.lock') }}" data-confirm="Kunci periode ini? Entri manual bulan ini tak bisa lagi ditambah/diubah/dihapus." class="m-0">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="month" value="{{ $month }}">
                <button class="btn btn-xs btn-outline-dark">🔒 Kunci periode {{ $month }}/{{ $year }}</button>
            </form>
        @endif
    </div>
@endif
```

> Verifikasi titik sisip dgn `grep -n "balances\['rows'\]" resources/views/accounting/journal.blade.php` — letakkan blok ini **di atas** baris tersebut.

- [ ] **Step 4: Halaman Riwayat Perubahan**

Buat `resources/views/accounting/audit.blade.php` (pola DataTables seperti view daftar lain):

```blade
@extends('layouts.master')
@section('title', 'Riwayat Perubahan - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Riwayat Perubahan Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <button class="btn btn-sm btn-primary">Tampilkan</button>
    </form>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table id="tabelAudit" class="table table-sm table-hover">
            <thead><tr><th>Waktu</th><th>Aksi</th><th>Entri</th><th>Pelaku</th><th>Perubahan</th><th>Catatan</th></tr></thead>
            <tbody>
                @foreach($logs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('d/m/y H:i') }}</td>
                        <td>{{ $log->actionLabel() }}</td>
                        <td>{{ $log->cash_entry_id ? '#' . $log->cash_entry_id : '—' }}</td>
                        <td>{{ $log->actorName() }}</td>
                        <td class="small text-muted">{{ $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : '—' }}</td>
                        <td>@if($log->note)<span class="badge bg-warning text-dark">{{ $log->note }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/datatables-net/jquery.dataTables.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables-net-bs5/dataTables.bootstrap5.js') }}"></script>
@endpush

@push('custom-scripts')
<script>$(function () { $('#tabelAudit').DataTable({ order: [[0, 'desc']] }); });</script>
@endpush
```

> **Verifikasi dulu** path plugin & nama stack dengan `grep -n "datatables\|@push" resources/views/titles/index.blade.php` (atau view index lain yang sudah pakai DataTables) dan **tiru persis** — jangan menebak.

- [ ] **Step 5: Menu sidebar**

Di `resources/views/layouts/sidebar.blade.php`, tepat **setelah** blok `<li>` menu `accounting.target` (±b.158-163) dan **sebelum** `@endrole`:

```blade
                <li class="nav-item {{ nav_active('accounting.audit') }}">
                    <a href="{{ route('accounting.audit') }}" class="nav-link">
                        <i class="link-icon" data-feather="clock"></i>
                        <span class="link-title">Riwayat Perubahan</span>
                    </a>
                </li>
```

- [ ] **Step 6: Test render**

Tambahkan ke `tests/Feature/CashAuditLogTest.php`:

```php
    /** @test */
    public function audit_page_renders(): void
    {
        $this->entri();

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');
        $this->actingAs($sa)->get(route('accounting.audit', ['year' => now()->year]))
            ->assertOk()->assertSee('Riwayat Perubahan');

        $acc = User::factory()->create();
        $acc->assignRole('accounting');
        $this->actingAs($acc)->get(route('accounting.audit'))->assertOk();

        $mk = User::factory()->create();
        $mk->assignRole('marketing');
        $this->actingAs($mk)->get(route('accounting.audit'))->assertForbidden();
    }
```

- [ ] **Step 7: Jalankan test**

Run: `php artisan test --filter=CashAuditLogTest`
Expected: **PASS**, 7 test.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/CashPeriodController.php app/Http/Controllers/Pages/CashEntryController.php resources/views/accounting/audit.blade.php resources/views/accounting/journal.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/CashAuditLogTest.php
git commit -F <path-pesan>   # feat(accounting): UI kunci periode + halaman Riwayat Perubahan
```

---

## Task 5: Regresi + migrasi dev + verifikasi sungguhan

- [ ] **Step 1: Suite penuh**

Run: `php artisan test`
Expected: PASS semua (**553** = 536 + 10 + 7).

**Bila test lama GAGAL** (mis. test yang menghapus/menyunting entri kas kini tertolak penjaga baru, atau menghitung baris `tb_cash_logs`): itu **temuan** — baca, pastikan perilaku barunya benar, perbaiki, **sebutkan di laporan**. Jangan sesuaikan diam-diam.

- [ ] **Step 2: Blade sehat**

Run: `php artisan view:cache && php artisan view:clear`
Expected: tanpa error.

- [ ] **Step 3: Migrasi DB dev — WAJIB**

Run: `php artisan migrate`
Expected: kedua migrasi `DONE`. Tanpa ini, `/accounting/journal` di dev **500** (tabel `tb_cash_period_locks` tak ada).

- [ ] **Step 4: Verifikasi lewat HTTP**

`php artisan serve --port=8127` di background; superadmin sementara; login via curl. Buktikan **empat** hal:

1. `/accounting/journal?year=2026&month=6` → tombol "🔒 Kunci periode 6/2026" tampil.
2. POST `accounting.period.lock` (year=2026, month=6) → badge berubah jadi "terkunci"; POST `accounting.entry.store` tanggal 2026-06-10 → **ditolak** (`swalError` memuat "sudah dikunci"), `CashEntry` tak bertambah.
3. DELETE entri `source=payment` mana pun → **ditolak**, entri tetap ada (lubang yang ditemukan probe, kini tertutup di data nyata).
4. `/accounting/audit` → memuat baris log `locked`.

- [ ] **Step 5: Bersihkan**

Buka kunci 2026-6 lagi, hapus user sementara, matikan server. Pastikan `CashEntry::count()` **132**, `CashPeriodLock::count()` **0**, dan `git status` bersih dari sampah uji. (Baris `tb_cash_logs` dari uji **boleh** tertinggal — itu jejak sah; sebutkan jumlahnya di laporan.)

- [ ] **Step 6: Centang plan + commit**

```bash
git add docs/superpowers/plans/2026-07-17-cash-period-lock.md
git commit -F <path-pesan>   # docs(plan): tandai kunci periode + audit log selesai
```

---

## Self-Review

- **Cakupan spec:** skema §1 (T1) · exception §2 (T2 S3) · service §3 (T2 S4) · 4 penjaga §4 (T2 S5) · observer log §5 (T3) · rute+UI §6 (T2 S6, T4) · test §7 (T2 S1, T3 S1, T4 S6) · migrasi dev + verifikasi nyata (T5). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `assertUnlocked($tanggal)` dipanggil dgn string (`$data['tanggal']`) dan Carbon (`$entry->tanggal`) — `Carbon::parse` menerima keduanya; `lock/unlock(int,int,?User)` dipanggil dgn `Auth::user()` (controller) dan `null` (test) — nullable sesuai; `lockFor()` dipakai `CashEntryController@index` (T4 S2) dan dibaca view sbg `$periodLock->lockedBy?->name` / `locked_at`.
- **Risiko diketahui:** dua titik sisip view (`journal.blade.php`, `sidebar.blade.php`) berbasis nomor baris yang bisa bergeser — tiap step menyertakan penanda `grep` untuk verifikasi, bukan nomor baris telanjang.
- **Catatan:** `payment_sync_passes_lock` (T2) dan `payment_sync_into_locked_period_is_noted` (T3) menguji hal berbeda: yang pertama bahwa sinkron **tembus**, yang kedua bahwa ia **tercatat**. Keduanya menjaga kompromi tetap disengaja dan terlihat.
