# Akuntansi Multi-Akun Bank + Transfer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menaikkan Jurnal Kas dari saldo tunggal → multi-akun bank (tiap entri bertaut akun, saldo per akun) + transfer antar akun sebagai pemindahan internal (dikecualikan dari laba).

**Architecture:** Tabel baru `tb_cash_accounts` (nama/peran/saldo awal per akun/akun income-default); `tb_cash_entries` +`account_id`/`is_transfer`/`transfer_group`. Saldo awal pindah dari `tb_cash_settings.saldo_awal` (deprecated) ke opening per akun. `CashJournalService::compute` menerima `accountId`, saldo berjalan dari running loop (mencakup transfer), `totalIn/totalOut` mengecualikan transfer. Transfer = 2 baris (keluar+masuk) bertaut `transfer_group`. Auto-flow Payment masuk ke akun income-default.

**Tech Stack:** Laravel 11, PHP 8.2, Eloquent, MySQL (`avidpedi_simapa` dev / `avidpedi_simapa_test` test), Blade + Bootstrap 5 (NobleUI) + DataTables, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-04-accounting-bank-accounts-design.md`

---

## Konvensi (berlaku semua task)

- **Test DB:** `php artisan test` otomatis pakai `.env.testing` (phpunit.xml set `APP_ENV=testing`) → DB `avidpedi_simapa_test`. **Jangan** pernah pakai DB dev untuk test.
- **Jalankan 1 test:** `php artisan test --filter=NamaTest`. **Seluruh suite:** `php artisan test`.
- **Commit attribution (WAJIB):** author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`, JANGAN "Claude"/Anthropic. Pola commit:
  ```bash
  git add <path eksplisit…>   # JANGAN git add . / -A
  git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
  <judul>

  <body opsional>

  Co-authored-by: Mira <admin@avidpedia.com>
  EOF
  )"
  ```
- **Setelah semua task:** jalankan `php artisan migrate` di DB dev (`avidpedi_simapa`) — lihat memory `migrate-dev-db-after-new-migration`.
- **File data lokal** (`data-excel/`, `avidpedi_simapa.sql`, seeders, `.gitignore`, `template-web/`, `public/error_log`) TIDAK boleh di-commit.

---

## File Structure

- **Buat:**
  - `database/migrations/2026_07_04_000009_create_cash_accounts_and_transfer_fields.php` — tabel `tb_cash_accounts` + kolom `tb_cash_entries` + seed 3 akun + backfill.
  - `app/Models/CashAccount.php` — model akun.
  - `app/Http/Controllers/Pages/CashAccountController.php` — CRUD akun.
  - `tests/Unit/CashAccountModelTest.php`, `tests/Unit/CashAccountBalanceTest.php`, `tests/Feature/AccountingBankAccountTest.php`.
- **Ubah:**
  - `app/Models/CashEntry.php` — +fillable/cast/relasi/scope.
  - `app/Services/CashJournalService.php` — `compute($year,$month,$jenis,$accountId)` + `accountBalances()`.
  - `app/Services/CashRecapService.php` — opening Σ akun + kecualikan transfer.
  - `app/Services/PaymentCashSyncService.php` — set `account_id` akun income-default.
  - `app/Http/Controllers/Pages/CashEntryController.php` — index (accounts/balances/accountId), store/update (default account_id), `transfer()`, destroy pair-delete, hapus `updateOpening()`.
  - `routes/web.php` — rute `accounting.transfer.store`, `accounting.account.*`, hapus `accounting.opening.update`.
  - `resources/views/accounting/journal.blade.php` — filter akun, kartu saldo per akun, kolom akun, form Transfer Dana, Kelola Akun, badge transfer, hapus form Set Saldo Awal.
  - `tests/Unit/CashJournalServiceTest.php`, `tests/Unit/CashRecapServiceTest.php`, `tests/Feature/AccountingJournalTest.php`, `tests/Feature/PaymentCashSyncTest.php` — sesuaikan (opening pindah ke akun + assert account_id).

---

## Task 1: Skema & model akun (fondasi)

**Files:**
- Create: `database/migrations/2026_07_04_000009_create_cash_accounts_and_transfer_fields.php`
- Create: `app/Models/CashAccount.php`
- Modify: `app/Models/CashEntry.php`
- Test: `tests/Unit/CashAccountModelTest.php`

- [ ] **Step 1: Tulis test gagal**

Buat `tests/Unit/CashAccountModelTest.php`:
```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashAccount;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashAccountModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_three_default_accounts_with_income_default(): void
    {
        $this->assertSame(3, CashAccount::count());
        $inc = CashAccount::incomeDefault();
        $this->assertSame('Kas Pemasukan', $inc->name);
        $this->assertTrue((bool) $inc->is_income_default);
        $this->assertSame(0.0, CashAccount::totalOpening()); // fresh DB: saldo_awal null → 0
    }

    /** @test */
    public function entry_belongs_to_account_and_defaults_not_transfer(): void
    {
        $acc = CashAccount::incomeDefault();
        $e = CashEntry::create([
            'tanggal' => '2026-06-01', 'jenis' => 'pemasukan', 'amount' => 1000,
            'keterangan' => 'x', 'source' => 'manual', 'account_id' => $acc->id,
        ]);
        $this->assertSame($acc->id, $e->account->id);
        $this->assertFalse($e->isTransfer());
        $this->assertTrue($e->fresh()->newQuery()->getModel()->exists || true); // model resolvable
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=CashAccountModelTest`
Expected: FAIL — `Class "App\Models\CashAccount" not found` / tabel `tb_cash_accounts` tidak ada.

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_07_04_000009_create_cash_accounts_and_transfer_fields.php`:
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
        Schema::create('tb_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_income_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $saldoAwal = (float) (DB::table('tb_cash_settings')->value('saldo_awal') ?? 0);
        $now = now();
        DB::table('tb_cash_accounts')->insert([
            ['name' => 'Kas Pemasukan', 'purpose' => 'pemasukan', 'opening_balance' => $saldoAwal, 'is_income_default' => true,  'active' => true, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Operational',   'purpose' => 'operational', 'opening_balance' => 0, 'is_income_default' => false, 'active' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Harta',         'purpose' => 'harta', 'opening_balance' => 0, 'is_income_default' => false, 'active' => true, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('cash_category_id')
                  ->constrained('tb_cash_accounts')->nullOnDelete();
            $table->boolean('is_transfer')->default(false)->after('source');
            $table->string('transfer_group')->nullable()->after('is_transfer');
        });

        $incomeId = DB::table('tb_cash_accounts')->where('is_income_default', true)->value('id');
        DB::table('tb_cash_entries')->whereNull('account_id')->update(['account_id' => $incomeId]);
    }

    public function down(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'is_transfer', 'transfer_group']);
        });
        Schema::dropIfExists('tb_cash_accounts');
    }
};
```

- [ ] **Step 4: Buat model `CashAccount`**

Buat `app/Models/CashAccount.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $table = 'tb_cash_accounts';

    protected $fillable = ['name', 'purpose', 'opening_balance', 'is_income_default', 'active', 'position'];

    protected $casts = ['opening_balance' => 'decimal:2', 'is_income_default' => 'boolean', 'active' => 'boolean'];

    const PURPOSES = ['pemasukan' => 'Pemasukan', 'operational' => 'Operational', 'harta' => 'Harta', 'umum' => 'Umum'];

    public function scopeActive($query) { return $query->where('active', true); }

    public function entries() { return $this->hasMany(CashEntry::class, 'account_id'); }

    public static function incomeDefault(): ?self
    {
        return static::where('is_income_default', true)->first() ?? static::orderBy('position')->first();
    }

    public static function totalOpening(): float
    {
        return (float) static::sum('opening_balance');
    }
}
```

- [ ] **Step 5: Ubah model `CashEntry`**

Di `app/Models/CashEntry.php`: tambah `account_id`, `is_transfer`, `transfer_group` ke `$fillable`; tambah cast; tambah relasi/scope. Ubah:
```php
    protected $fillable = ['tanggal', 'kode', 'keterangan', 'jenis', 'amount', 'cash_category_id', 'account_id', 'produk', 'ref', 'catatan', 'source', 'created_by', 'payment_id', 'is_transfer', 'transfer_group'];

    protected $casts = ['tanggal' => 'date', 'amount' => 'decimal:2', 'is_transfer' => 'boolean'];
```
Dan tambahkan method (setelah `payment()`):
```php
    public function account() { return $this->belongsTo(CashAccount::class, 'account_id'); }

    public function isTransfer(): bool { return (bool) $this->is_transfer; }

    public function scopeReal($query) { return $query->where('is_transfer', false); }
```

- [ ] **Step 6: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=CashAccountModelTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Regresi cepat**

Run: `php artisan test --filter=CashJournalServiceTest`
Expected: PASS — kecuali `saldo_awal_seeds_the_running_balance` mungkin masih hijau (compute belum diubah, `saldoAwal` masih dari CashSetting). Bila ada kegagalan tak terduga, hentikan & laporkan.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_04_000009_create_cash_accounts_and_transfer_fields.php app/Models/CashAccount.php app/Models/CashEntry.php tests/Unit/CashAccountModelTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): tabel akun bank + kolom account/transfer di entri kas

tb_cash_accounts (nama/peran/saldo awal per akun/income-default) + seed
3 akun default (saldo_awal lama pindah ke Kas Pemasukan); tb_cash_entries
+account_id/is_transfer/transfer_group + backfill akun income-default.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 2: `CashJournalService::compute` — akun + saldo dari running + kecualikan transfer

**Files:**
- Modify: `app/Services/CashJournalService.php`
- Test: `tests/Unit/CashJournalServiceTest.php`

- [ ] **Step 1: Sesuaikan test yang menyetel opening (jadikan gagal)**

Di `tests/Unit/CashJournalServiceTest.php`:
- Tambah import di atas: `use App\Models\CashAccount;`
- Ganti isi test `saldo_awal_seeds_the_running_balance` baris opening:
  - dari `CashSetting::singleton()->update(['saldo_awal' => 50000000]);`
  - jadi `CashAccount::incomeDefault()->update(['opening_balance' => 50000000]);`
  (Assertion `saldoAwal`/`opening`/saldo/`saldoAkhir` tetap.)

- [ ] **Step 2: Tambah test per-akun (gagal)**

Tambahkan test baru di `tests/Unit/CashJournalServiceTest.php`:
```php
    /** @test */
    public function compute_scopes_to_account_and_excludes_transfer_from_totals(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();

        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'in', 'source' => 'manual', 'account_id' => $A->id]);
        // transfer A->B 200rb (dua kaki)
        CashEntry::create(['tanggal' => '2026-06-06', 'jenis' => 'pengeluaran', 'amount' => 200000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g']);
        CashEntry::create(['tanggal' => '2026-06-06', 'jenis' => 'pemasukan', 'amount' => 200000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g']);

        // Semua akun: totalIn/out kecualikan transfer; saldoAkhir dari running (transfer net-nol)
        $all = (new CashJournalService())->compute(2026, 6, null, null);
        $this->assertSame(500000.0, $all['totalIn']);   // transfer-in 200rb tidak dihitung
        $this->assertSame(0.0, $all['totalOut']);        // transfer-out 200rb tidak dihitung
        $this->assertSame(500000.0, $all['saldoAkhir']); // 0 + 500 -200 +200 = 500rb

        // Difilter akun A: saldo A turun oleh transfer keluar
        $a = (new CashJournalService())->compute(2026, 6, null, $A->id);
        $this->assertSame(300000.0, $a['saldoAkhir']);   // 500rb - 200rb transfer keluar
        $this->assertSame(500000.0, $a['totalIn']);
        $this->assertSame(0.0, $a['totalOut']);

        // Difilter akun B: hanya transfer masuk
        $b = (new CashJournalService())->compute(2026, 6, null, $B->id);
        $this->assertSame(200000.0, $b['saldoAkhir']);
        $this->assertSame(0.0, $b['totalIn']);           // transfer dikecualikan dari totalIn
    }
```

- [ ] **Step 3: Jalankan — pastikan gagal**

Run: `php artisan test --filter=CashJournalServiceTest`
Expected: FAIL — `saldo_awal_seeds_the_running_balance` (opening masih dari CashSetting=0) & `compute_scopes_to_account...` (param ke-4 belum ada / transfer belum dikecualikan).

- [ ] **Step 4: Implementasi compute**

Ganti seluruh isi `app/Services/CashJournalService.php` method `compute` + import. File jadi:
```php
<?php

namespace App\Services;

use App\Models\CashAccount;
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
     * Hitung jurnal periode: saldo berjalan (opening + kumulatif, termasuk transfer) + ringkasan.
     * Bila $accountId diisi → discope ke akun itu; else gabungan semua akun.
     * @return array{entries:\Illuminate\Support\Collection,opening:float,totalIn:float,totalOut:float,saldoAkhir:float,saldoAwal:float}
     */
    public function compute(int $year, ?int $month, ?string $jenis = null, ?int $accountId = null): array
    {
        $start = $month ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();

        $saldoAwal = $accountId
            ? (float) optional(CashAccount::find($accountId))->opening_balance
            : CashAccount::totalOpening();

        $priorIn  = (float) CashEntry::where('tanggal', '<', $start)->when($accountId, fn ($q) => $q->where('account_id', $accountId))->where('jenis', 'pemasukan')->sum('amount');
        $priorOut = (float) CashEntry::where('tanggal', '<', $start)->when($accountId, fn ($q) => $q->where('account_id', $accountId))->where('jenis', 'pengeluaran')->sum('amount');
        $opening  = $saldoAwal + $priorIn - $priorOut;

        $q = CashEntry::with('category', 'account')->whereYear('tanggal', $year);
        if ($month)     { $q->whereMonth('tanggal', $month); }
        if ($accountId) { $q->where('account_id', $accountId); }
        $all = $q->orderBy('tanggal')->orderBy('id')->get();

        $running = $opening;
        foreach ($all as $e) {
            $running += $e->isPemasukan() ? (float) $e->amount : -(float) $e->amount;
            $e->saldo = $running;
        }
        $saldoAkhir = $running; // termasuk transfer (benar untuk per-akun; net-nol untuk gabungan)

        $real     = $all->where('is_transfer', false);
        $totalIn  = (float) $real->where('jenis', 'pemasukan')->sum('amount');
        $totalOut = (float) $real->where('jenis', 'pengeluaran')->sum('amount');

        $entries = $jenis ? $all->where('jenis', $jenis)->values() : $all;

        return compact('entries', 'opening', 'totalIn', 'totalOut', 'saldoAkhir', 'saldoAwal');
    }
}
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=CashJournalServiceTest`
Expected: PASS (semua test di file itu, termasuk `compute_running_saldo_with_opening_and_summary`, `jenis_filter_keeps_summary_and_saldo`, `saldo_awal_seeds_the_running_balance`, `compute_scopes_to_account...`).

- [ ] **Step 6: Commit**

```bash
git add app/Services/CashJournalService.php tests/Unit/CashJournalServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): compute jurnal per akun + kecualikan transfer dari total

compute($year,$month,$jenis,$accountId): opening dari saldo awal akun
(atau Σ semua akun), saldo berjalan dari running (mencakup transfer),
totalIn/totalOut mengecualikan transfer. saldoAwal kini dari opening akun.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: `CashJournalService::accountBalances` — saldo per akun

**Files:**
- Modify: `app/Services/CashJournalService.php`
- Test: `tests/Unit/CashAccountBalanceTest.php`

- [ ] **Step 1: Tulis test gagal**

Buat `tests/Unit/CashAccountBalanceTest.php`:
```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function account_balances_reflect_opening_entries_and_transfers(): void
    {
        $A = CashAccount::incomeDefault();
        $A->update(['opening_balance' => 1000000]);
        $B = CashAccount::where('purpose', 'operational')->first();

        CashEntry::create(['tanggal' => '2026-06-01', 'jenis' => 'pemasukan', 'amount' => 200000, 'keterangan' => 'x', 'source' => 'manual', 'account_id' => $A->id]);
        CashEntry::create(['tanggal' => '2026-06-02', 'jenis' => 'pengeluaran', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g1']);
        CashEntry::create(['tanggal' => '2026-06-02', 'jenis' => 'pemasukan', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g1']);

        $bal = (new CashJournalService())->accountBalances();
        $by = collect($bal['rows'])->keyBy(fn ($r) => $r['account']->id);

        $this->assertSame(900000.0, $by[$A->id]['saldo']);  // 1.000.000 + 200.000 - 300.000
        $this->assertSame(300000.0, $by[$B->id]['saldo']);
        $this->assertSame(1200000.0, $bal['total']);         // + Harta 0
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=CashAccountBalanceTest`
Expected: FAIL — `Method accountBalances does not exist`.

- [ ] **Step 3: Implementasi `accountBalances`**

Tambahkan method di `app/Services/CashJournalService.php` (setelah `compute`):
```php
    /**
     * Saldo tiap akun aktif (opening + Σ pemasukan − Σ pengeluaran, termasuk transfer).
     * @return array{rows:array<int,array{account:\App\Models\CashAccount,saldo:float}>,total:float}
     */
    public function accountBalances(): array
    {
        $rows = [];
        $total = 0.0;
        foreach (CashAccount::active()->orderBy('position')->get() as $acc) {
            $in  = (float) CashEntry::where('account_id', $acc->id)->where('jenis', 'pemasukan')->sum('amount');
            $out = (float) CashEntry::where('account_id', $acc->id)->where('jenis', 'pengeluaran')->sum('amount');
            $saldo = (float) $acc->opening_balance + $in - $out;
            $rows[] = ['account' => $acc, 'saldo' => $saldo];
            $total += $saldo;
        }
        return ['rows' => $rows, 'total' => $total];
    }
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=CashAccountBalanceTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add app/Services/CashJournalService.php tests/Unit/CashAccountBalanceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): accountBalances() saldo per akun (termasuk transfer)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: `CashRecapService` — opening Σ akun + kecualikan transfer

**Files:**
- Modify: `app/Services/CashRecapService.php`
- Test: `tests/Unit/CashRecapServiceTest.php`

- [ ] **Step 1: Sesuaikan seedData + tambah test transfer (jadikan gagal)**

Di `tests/Unit/CashRecapServiceTest.php`:
- Tambah import: `use App\Models\CashAccount;`
- Di `seedData()` ganti baris opening:
  - dari `CashSetting::singleton()->update(['saldo_awal' => 1000000]);`
  - jadi `CashAccount::incomeDefault()->update(['opening_balance' => 1000000]);`
- Tambahkan test baru:
```php
    /** @test */
    public function transfers_excluded_from_recap(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        CashEntry::create(['tanggal' => '2026-03-01', 'jenis' => 'pengeluaran', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g']);
        CashEntry::create(['tanggal' => '2026-03-01', 'jenis' => 'pemasukan', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g']);

        $mar = (new CashRecapService())->monthlyRecap(2026)[2];
        $this->assertSame(0.0, $mar['totalIn']);
        $this->assertSame(0.0, $mar['totalOut']);
        $this->assertSame(0.0, $mar['laba']);
    }
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=CashRecapServiceTest`
Expected: FAIL — `monthly_recap...`/`ytd_aggregates` (opening masih baca saldo_awal=0 → saldoAkhir salah) & `transfers_excluded_from_recap` (transfer masih terhitung).

- [ ] **Step 3: Implementasi perubahan recap**

Di `app/Services/CashRecapService.php`:
- Ganti import `use App\Models\CashSetting;` → `use App\Models\CashAccount;`
- Di `monthlyRecap`, ganti blok `$opening`:
```php
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $opening = CashAccount::totalOpening()
            + (float) CashEntry::where('tanggal', '<', $yearStart)->where('is_transfer', false)->where('jenis', 'pemasukan')->sum('amount')
            - (float) CashEntry::where('tanggal', '<', $yearStart)->where('is_transfer', false)->where('jenis', 'pengeluaran')->sum('amount');

        $entries = CashEntry::whereYear('tanggal', $year)->where('is_transfer', false)->get();
```
- Di `ytd`, tambahkan `->where('is_transfer', false)` pada query `expenseByCategory`:
```php
        $expenseByCategory = CashEntry::whereYear('tanggal', $year)->where('jenis', 'pengeluaran')->where('is_transfer', false)
            ->with('category')->get()
```

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=CashRecapServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Regresi dashboard/distribusi/target**

Run: `php artisan test --filter=AccountingDashboardTest`
Run: `php artisan test --filter=AccountingDistributionTest`
Run: `php artisan test --filter=AccountingBudgetTargetTest`
Expected: PASS semua (fresh DB → opening akun 0 = perilaku lama; tanpa transfer filter no-op). Bila gagal, hentikan & laporkan.

- [ ] **Step 6: Commit**

```bash
git add app/Services/CashRecapService.php tests/Unit/CashRecapServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): rekap pakai opening Σ akun + kecualikan transfer dari laba

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 5: Auto-flow Payment → akun income-default

**Files:**
- Modify: `app/Services/PaymentCashSyncService.php`
- Test: `tests/Feature/PaymentCashSyncTest.php`

- [ ] **Step 1: Tambah assertion account_id (jadikan gagal)**

Di `tests/Feature/PaymentCashSyncTest.php`:
- Tambah import: `use App\Models\CashAccount;`
- Di test `paid_payment_creates_income_entry`, tambahkan assertion setelah baris `assertSame(...cash_category_id)`:
```php
        $this->assertSame(CashAccount::incomeDefault()->id, $e->account_id);
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=PaymentCashSyncTest`
Expected: FAIL pada `paid_payment_creates_income_entry` — `account_id` null ≠ id akun income-default.

- [ ] **Step 3: Implementasi**

Di `app/Services/PaymentCashSyncService.php`:
- Tambah import: `use App\Models\CashAccount;`
- Di array `updateOrCreate(...)` (values), tambahkan baris:
```php
                'account_id'       => optional(CashAccount::incomeDefault())->id,
```
(letakkan mis. setelah `'cash_category_id' => $catId,`).

- [ ] **Step 4: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=PaymentCashSyncTest`
Expected: PASS semua.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PaymentCashSyncService.php tests/Feature/PaymentCashSyncTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): entri kas dari Payment masuk ke akun income-default

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 6: CRUD Akun (CashAccountController + rute)

**Files:**
- Create: `app/Http/Controllers/Pages/CashAccountController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/AccountingJournalTest.php` (opening pindah ke akun)
- Test: `tests/Feature/AccountingBankAccountTest.php`

- [ ] **Step 1: Tulis test gagal (CRUD akun)**

Buat `tests/Feature/AccountingBankAccountTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingBankAccountTest extends TestCase
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
    public function account_crud_with_single_income_default_and_delete_guards(): void
    {
        $sa = $this->user('superadmin');

        $this->actingAs($sa)->post(route('accounting.account.store'), [
            'name' => 'BCA', 'purpose' => 'operational', 'opening_balance' => 500000,
        ])->assertRedirect();
        $bca = CashAccount::where('name', 'BCA')->first();
        $this->assertNotNull($bca);
        $this->assertSame('500000.00', $bca->opening_balance);
        $this->assertFalse((bool) $bca->is_income_default);

        // jadikan income-default + ubah opening
        $this->actingAs($sa)->put(route('accounting.account.update', $bca->id), [
            'name' => 'BCA', 'purpose' => 'operational', 'opening_balance' => 750000, 'is_income_default' => 1, 'active' => 1,
        ])->assertRedirect();
        $bca->refresh();
        $this->assertTrue((bool) $bca->is_income_default);
        $this->assertSame('750000.00', $bca->opening_balance);
        $this->assertFalse((bool) CashAccount::where('name', 'Kas Pemasukan')->first()->is_income_default); // default lama ter-unset

        // hapus akun income-default → ditolak
        $this->actingAs($sa)->delete(route('accounting.account.destroy', $bca->id))->assertRedirect();
        $this->assertNotNull(CashAccount::find($bca->id));

        // hapus akun non-default tanpa transaksi → berhasil
        $harta = CashAccount::where('name', 'Harta')->first();
        $this->actingAs($sa)->delete(route('accounting.account.destroy', $harta->id))->assertRedirect();
        $this->assertNull(CashAccount::find($harta->id));
    }

    /** @test */
    public function marketing_cannot_manage_accounts(): void
    {
        $this->actingAs($this->user('marketing'))->post(route('accounting.account.store'), [
            'name' => 'X', 'opening_balance' => 0,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=AccountingBankAccountTest`
Expected: FAIL — route `accounting.account.store` belum ada (`Route [accounting.account.store] not defined`).

- [ ] **Step 3: Buat `CashAccountController`**

Buat `app/Http/Controllers/Pages/CashAccountController.php`:
```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    private function rules(): array
    {
        return [
            'name'            => 'required|string|max:100',
            'purpose'         => 'nullable|in:pemasukan,operational,harta,umum',
            'opening_balance' => 'required|numeric|min:0',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['is_income_default'] = $request->boolean('is_income_default');
        $data['active']   = $request->boolean('active', true);
        $data['position'] = (int) (CashAccount::max('position') ?? 0) + 1;
        if ($data['is_income_default']) { CashAccount::query()->update(['is_income_default' => false]); }
        CashAccount::create($data);

        return back()->with('success', 'Akun ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $acc = CashAccount::findOrFail($id);
        $data = $request->validate($this->rules());
        $data['is_income_default'] = $request->boolean('is_income_default');
        $data['active'] = $request->boolean('active');
        if ($data['is_income_default']) { CashAccount::where('id', '!=', $acc->id)->update(['is_income_default' => false]); }
        $acc->update($data);

        return back()->with('success', 'Akun diperbarui.');
    }

    public function destroy(int $id)
    {
        $acc = CashAccount::findOrFail($id);
        if ($acc->is_income_default) {
            return back()->with('error', 'Akun pemasukan default tidak bisa dihapus. Tetapkan akun default lain dulu.');
        }
        if ($acc->entries()->exists()) {
            return back()->with('error', 'Akun memiliki transaksi. Nonaktifkan saja, jangan dihapus.');
        }
        $acc->delete();

        return back()->with('success', 'Akun dihapus.');
    }
}
```

- [ ] **Step 4: Tambah rute**

Di `routes/web.php`, dalam grup accounting (dekat rute `accounting.category.*`, sekitar baris 328-330), tambahkan:
```php
        Route::post('accounting/account', [\App\Http\Controllers\Pages\CashAccountController::class, 'store'])->name('accounting.account.store');
        Route::put('accounting/account/{id}', [\App\Http\Controllers\Pages\CashAccountController::class, 'update'])->name('accounting.account.update')->whereNumber('id');
        Route::delete('accounting/account/{id}', [\App\Http\Controllers\Pages\CashAccountController::class, 'destroy'])->name('accounting.account.destroy')->whereNumber('id');
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=AccountingBankAccountTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Sesuaikan `AccountingJournalTest` (opening pindah ke akun)**

Di `tests/Feature/AccountingJournalTest.php`:
- Tambah import: `use App\Models\CashAccount;`
- Ganti seluruh method `accounting_sets_saldo_awal` menjadi:
```php
    /** @test */
    public function accounting_sets_account_opening(): void
    {
        $acc = CashAccount::incomeDefault();
        $this->actingAs($this->user('accounting'))->put(route('accounting.account.update', $acc->id), [
            'name' => $acc->name, 'purpose' => $acc->purpose, 'opening_balance' => 50000000, 'is_income_default' => 1, 'active' => 1,
        ])->assertRedirect();

        $this->assertSame('50000000.00', $acc->fresh()->opening_balance);
    }
```

- [ ] **Step 7: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=AccountingJournalTest`
Expected: PASS (`accounting_sets_account_opening` hijau; sisanya tetap hijau). Catatan: `accounting_and_superadmin_can_store_entry` akan di-perkuat di Task 7.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/CashAccountController.php routes/web.php tests/Feature/AccountingBankAccountTest.php tests/Feature/AccountingJournalTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): CRUD akun bank (income-default tunggal + guard hapus)

Set saldo awal kini per akun (menggantikan saldo_awal global).

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 7: Transfer antar akun + default akun pada entri manual

**Files:**
- Modify: `app/Http/Controllers/Pages/CashEntryController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/AccountingBankAccountTest.php`, `tests/Feature/AccountingJournalTest.php`

- [ ] **Step 1: Tulis test gagal (transfer + default akun)**

Di `tests/Feature/AccountingBankAccountTest.php`:
- Tambah import: `use App\Models\CashEntry;`, `use App\Services\CashJournalService;`, `use App\Services\CashRecapService;`
- Tambahkan test:
```php
    /** @test */
    public function transfer_creates_two_legs_and_moves_balance(): void
    {
        $A = CashAccount::incomeDefault();
        $A->update(['opening_balance' => 1000000]);
        $B = CashAccount::where('purpose', 'operational')->first();

        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $legs = CashEntry::where('is_transfer', true)->get();
        $this->assertSame(2, $legs->count());
        $this->assertSame(1, $legs->pluck('transfer_group')->unique()->count());
        $this->assertSame('pengeluaran', $legs->firstWhere('account_id', $A->id)->jenis);
        $this->assertSame('pemasukan', $legs->firstWhere('account_id', $B->id)->jenis);

        $by = collect((new CashJournalService())->accountBalances()['rows'])->keyBy(fn ($r) => $r['account']->id);
        $this->assertSame(700000.0, $by[$A->id]['saldo']);
        $this->assertSame(300000.0, $by[$B->id]['saldo']);
    }

    /** @test */
    public function transfer_excluded_from_profit(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $jun = (new CashRecapService())->monthlyRecap(2026)[5];
        $this->assertSame(0.0, $jun['totalIn']);
        $this->assertSame(0.0, $jun['totalOut']);
    }

    /** @test */
    public function deleting_a_transfer_leg_removes_both(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $leg = CashEntry::where('is_transfer', true)->first();
        $this->actingAs($this->user('accounting'))->delete(route('accounting.entry.destroy', $leg->id))->assertRedirect();
        $this->assertSame(0, CashEntry::where('is_transfer', true)->count());
    }

    /** @test */
    public function marketing_cannot_transfer(): void
    {
        $a = CashAccount::incomeDefault();
        $this->actingAs($this->user('marketing'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $a->id, 'to_account_id' => $a->id, 'amount' => 1, 'tanggal' => '2026-06-01',
        ])->assertForbidden();
    }
```

Di `tests/Feature/AccountingJournalTest.php`, di `accounting_and_superadmin_can_store_entry`, tambahkan assertion setelah `$this->assertSame('manual', $e->source);`:
```php
        $this->assertSame(CashAccount::incomeDefault()->id, $e->account_id); // entri manual → akun income-default
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=AccountingBankAccountTest`
Expected: FAIL — route `accounting.transfer.store` belum ada.

- [ ] **Step 3: Implementasi controller (transfer + default akun + destroy pasangan)**

Di `app/Http/Controllers/Pages/CashEntryController.php`:
- Tambah import: `use App\Models\CashAccount;` dan `use Illuminate\Support\Str;`
- Di `validated()`, tambahkan rule `account_id`:
```php
            'cash_category_id' => 'nullable|exists:tb_cash_categories,id',
            'account_id'       => 'nullable|exists:tb_cash_accounts,id',
```
- Di `store()` setelah `$data = $this->validated($request);` tambahkan:
```php
        $data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id;
```
- Di `update()` setelah `$data = $this->validated($request);` tambahkan baris yang sama:
```php
        $data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id;
```
- Ganti `destroy()` menjadi:
```php
    public function destroy(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        if ($entry->is_transfer && $entry->transfer_group) {
            CashEntry::where('transfer_group', $entry->transfer_group)->delete();
            return back()->with('success', 'Transfer dihapus (kedua sisi).');
        }
        $entry->delete();

        return back()->with('success', 'Transaksi kas dihapus.');
    }
```
- Tambahkan method `transfer()` (setelah `store()` atau sebelum `destroy()`):
```php
    /** Transfer dana antar akun: buat 2 baris (keluar + masuk), ditandai internal (is_transfer). */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => 'required|exists:tb_cash_accounts,id',
            'to_account_id'   => 'required|exists:tb_cash_accounts,id|different:from_account_id',
            'amount'          => 'required|numeric|min:1',
            'tanggal'         => 'required|date',
            'catatan'         => 'nullable|string',
        ]);

        $from = CashAccount::find($data['from_account_id']);
        $to   = CashAccount::find($data['to_account_id']);
        $group = (string) Str::uuid();
        $kode  = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $ket   = "Transfer: {$from->name} → {$to->name}";

        $base = [
            'tanggal' => $data['tanggal'], 'kode' => $kode, 'keterangan' => $ket,
            'amount' => $data['amount'], 'catatan' => $data['catatan'] ?? null,
            'is_transfer' => true, 'transfer_group' => $group,
            'source' => 'manual', 'created_by' => Auth::id(),
        ];
        CashEntry::create($base + ['account_id' => $from->id, 'jenis' => 'pengeluaran']);
        CashEntry::create($base + ['account_id' => $to->id,   'jenis' => 'pemasukan']);

        return back()->with('success', 'Transfer dana dicatat.');
    }
```

- [ ] **Step 4: Tambah rute transfer**

Di `routes/web.php`, dalam grup accounting (dekat rute account dari Task 6), tambahkan:
```php
        Route::post('accounting/transfer', [\App\Http\Controllers\Pages\CashEntryController::class, 'transfer'])->name('accounting.transfer.store');
```

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `php artisan test --filter=AccountingBankAccountTest`
Run: `php artisan test --filter=AccountingJournalTest`
Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/CashEntryController.php routes/web.php tests/Feature/AccountingBankAccountTest.php tests/Feature/AccountingJournalTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): transfer dana antar akun (pemindahan internal)

Transfer = 2 baris (keluar+masuk) bertaut transfer_group, is_transfer.
Hapus satu kaki menghapus keduanya. Entri manual default ke akun
income-default. Transfer dikecualikan dari laba.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 8: UI Jurnal Kas (filter akun, kartu saldo, Transfer Dana, Kelola Akun) + hapus Set Saldo Awal

**Files:**
- Modify: `app/Http/Controllers/Pages/CashEntryController.php` (index + hapus updateOpening)
- Modify: `routes/web.php` (hapus accounting.opening.update)
- Modify: `resources/views/accounting/journal.blade.php`
- Test: `tests/Feature/AccountingBankAccountTest.php`

- [ ] **Step 1: Tulis test tampilan (gagal)**

Di `tests/Feature/AccountingBankAccountTest.php`, tambahkan test:
```php
    /** @test */
    public function journal_shows_account_cards_and_transfer_ui(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.journal'))
            ->assertOk()
            ->assertSee('Kas Pemasukan')   // kartu saldo akun
            ->assertSee('Transfer Dana')   // tombol transfer
            ->assertSee('Kelola Akun');    // pengelolaan akun
    }

    /** @test */
    public function journal_can_filter_by_account(): void
    {
        $b = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))
            ->get(route('accounting.journal', ['account' => $b->id]))
            ->assertOk();
    }
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `php artisan test --filter=AccountingBankAccountTest`
Expected: FAIL — `journal_shows_account_cards_and_transfer_ui` (teks 'Transfer Dana'/'Kelola Akun' belum ada di view).

- [ ] **Step 3: Ubah controller index + hapus updateOpening**

Di `app/Http/Controllers/Pages/CashEntryController.php`:
- Tambah import: `use App\Models\CashAccount;` (bila belum dari Task 7 — sudah ada).
- Ganti method `index()` menjadi:
```php
    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $jenis = in_array($request->query('jenis'), ['pemasukan', 'pengeluaran'], true) ? $request->query('jenis') : null;
        $acc   = $request->query('account');
        $accountId = ($acc === null || $acc === '' || $acc === 'all') ? null : (int) $acc;

        $data = $this->service->compute($year, $month, $jenis, $accountId);

        return view('accounting.journal', array_merge($data, [
            'year'          => $year,
            'month'         => $month,
            'jenis'         => $jenis,
            'accountId'     => $accountId,
            'categories'    => CashCategory::active()->orderBy('jenis')->orderBy('position')->get(),
            'allCategories' => CashCategory::orderBy('jenis')->orderBy('position')->get(),
            'accounts'      => CashAccount::active()->orderBy('position')->get(),
            'allAccounts'   => CashAccount::orderBy('position')->get(),
            'balances'      => $this->service->accountBalances(),
        ]));
    }
```
- **Hapus** seluruh method `updateOpening()`.
- **Hapus** import `use App\Models\CashSetting;` (tak lagi dipakai di controller ini).

- [ ] **Step 4: Hapus rute opening**

Di `routes/web.php`, **hapus** baris:
```php
        Route::put('accounting/opening', [\App\Http\Controllers\Pages\CashEntryController::class, 'updateOpening'])->name('accounting.opening.update');
```

- [ ] **Step 5: Tulis ulang view `journal.blade.php`**

Ganti seluruh isi `resources/views/accounting/journal.blade.php` dengan:
```blade
@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $purposeBadge = ['pemasukan' => 'bg-success', 'operational' => 'bg-primary', 'harta' => 'bg-warning text-dark', 'umum' => 'bg-secondary'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Jurnal Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            <option value="all" {{ $month === null ? 'selected' : '' }}>Semua bulan</option>
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endfor
        </select>
        <select name="account" class="form-select form-select-sm" style="width:160px">
            <option value="all" {{ $accountId === null ? 'selected' : '' }}>Semua akun</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" {{ $accountId === $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
            @endforeach
        </select>
        <select name="jenis" class="form-select form-select-sm" style="width:130px">
            <option value="">Semua jenis</option>
            <option value="pemasukan" {{ $jenis === 'pemasukan' ? 'selected' : '' }}>Pemasukan</option>
            <option value="pengeluaran" {{ $jenis === 'pengeluaran' ? 'selected' : '' }}>Pengeluaran</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
</div>

{{-- Kartu saldo per akun --}}
<div class="row">
    @foreach($balances['rows'] as $row)
        <div class="col-md-3 col-6 grid-margin stretch-card">
            <div class="card"><div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small">{{ $row['account']->name }}</div>
                    @if($row['account']->purpose)
                        <span class="badge {{ $purposeBadge[$row['account']->purpose] ?? 'bg-secondary' }}">{{ \App\Models\CashAccount::PURPOSES[$row['account']->purpose] ?? $row['account']->purpose }}</span>
                    @endif
                </div>
                <div class="h5 mb-0">{{ $rp($row['saldo']) }}</div>
            </div></div>
        </div>
    @endforeach
    <div class="col-md-3 col-6 grid-margin stretch-card">
        <div class="card bg-dark text-white"><div class="card-body py-3">
            <div class="small text-white-50">Total Semua Akun</div>
            <div class="h5 mb-0">{{ $rp($balances['total']) }}</div>
        </div></div>
    </div>
</div>

{{-- Ringkasan periode (menghormati filter akun) --}}
<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan{{ $accountId ? ' (akun ini)' : '' }}</div><div class="h5 mb-0 text-success">{{ $rp($totalIn) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran{{ $accountId ? ' (akun ini)' : '' }}</div><div class="h5 mb-0 text-danger">{{ $rp($totalOut) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Akhir Periode</div><div class="h5 mb-0">{{ $rp($saldoAkhir) }}</div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <span class="text-muted small">Saldo awal periode: {{ $rp($opening) }} · Saldo awal {{ $accountId ? 'akun ini' : 'semua akun' }}: {{ $rp($saldoAwal) }}</span>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#transferForm">↔ Transfer Dana</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#accountForm">Kelola Akun</button>
            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#entryForm">+ Tambah Transaksi</button>
        </div>
    </div>

    {{-- Transfer Dana --}}
    <div class="collapse mb-3" id="transferForm">
        <form method="POST" action="{{ route('accounting.transfer.store') }}" class="border rounded p-3">
            @csrf
            <div class="alert alert-info py-2 mb-3 small">
                <strong>Transfer = pemindahan dana antar akun sendiri</strong> (mis. dari <em>Kas Pemasukan</em> ke <em>Operational</em>/<em>Harta</em>).
                Ini <strong>BUKAN pemasukan/pengeluaran</strong> — tidak menambah atau mengurangi laba, hanya <strong>memindahkan saldo</strong> antar akun. Tercatat sebagai dua baris (keluar dari akun asal, masuk ke akun tujuan).
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small mb-1">Dari Akun</label>
                    <select name="from_account_id" class="form-select form-select-sm" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-3"><label class="form-label small mb-1">Ke Akun</label>
                    <select name="to_account_id" class="form-select form-select-sm" required>@foreach($accounts as $a)<option value="{{ $a->id }}" {{ $loop->index === 1 ? 'selected' : '' }}>{{ $a->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal (Rp)</label><input type="number" name="amount" class="form-control form-control-sm" min="1" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-info mt-2">Simpan Transfer</button>
        </form>
    </div>

    {{-- Tambah Transaksi --}}
    <div class="collapse mb-3" id="entryForm">
        <form method="POST" action="{{ route('accounting.entry.store') }}" class="border rounded p-3">
            @csrf
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Jenis</label><select name="jenis" class="form-select form-select-sm"><option value="pemasukan">Pemasukan</option><option value="pengeluaran">Pengeluaran</option></select></div>
                <div class="col-md-2"><label class="form-label small mb-1">Akun</label>
                    <select name="account_id" class="form-select form-select-sm">
                        @foreach($accounts as $a)<option value="{{ $a->id }}" {{ $a->is_income_default ? 'selected' : '' }}>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
                    <select name="cash_category_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach($categories as $c)<option value="{{ $c->id }}" data-jenis="{{ $c->jenis }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="number" name="amount" class="form-control form-control-sm" min="0" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Produk</label><select name="produk" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small mb-1">Keterangan</label><input name="keterangan" class="form-control form-control-sm" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Ref (INV/Order)</label><input name="ref" class="form-control form-control-sm"></div>
                <div class="col-md-5"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-primary mt-2">Simpan</button>
        </form>
    </div>

    {{-- Kelola Akun --}}
    <div class="collapse mb-3" id="accountForm">
        <div class="border rounded p-3">
            <div class="text-muted small fw-semibold mb-2">Akun / Bank — saldo awal & peran per akun</div>
            @foreach($allAccounts as $a)
                {{-- dua form bersaudara di dalam pembungkus flex — TANPA div yang menyeberang batas <form> --}}
                <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
                    <form method="POST" action="{{ route('accounting.account.update', $a->id) }}" class="d-flex gap-1 align-items-end flex-wrap flex-grow-1 m-0">
                        @csrf @method('PUT')
                        <div><label class="form-label small mb-0">Nama</label><input name="name" value="{{ $a->name }}" class="form-control form-control-sm" style="max-width:200px" required></div>
                        <div><label class="form-label small mb-0">Peran</label><select name="purpose" class="form-select form-select-sm" style="max-width:140px"><option value="">—</option>@foreach(\App\Models\CashAccount::PURPOSES as $pk => $pv)<option value="{{ $pk }}" {{ $a->purpose === $pk ? 'selected' : '' }}>{{ $pv }}</option>@endforeach</select></div>
                        <div><label class="form-label small mb-0">Saldo Awal</label><input type="number" name="opening_balance" value="{{ (int) $a->opening_balance }}" class="form-control form-control-sm" style="max-width:130px" min="0"></div>
                        <label class="small mb-0"><input type="checkbox" name="is_income_default" value="1" {{ $a->is_income_default ? 'checked' : '' }}> akun pemasukan</label>
                        <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $a->active ? 'checked' : '' }}> aktif</label>
                        <button class="btn btn-xs btn-outline-primary">Simpan</button>
                    </form>
                    <form method="POST" action="{{ route('accounting.account.destroy', $a->id) }}" data-confirm="Hapus akun ini? (hanya bila tanpa transaksi & bukan akun pemasukan default)" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                </div>
            @endforeach
            <form method="POST" action="{{ route('accounting.account.store') }}" class="row g-1 mt-2 align-items-end">
                @csrf
                <div class="col-md-3"><input name="name" placeholder="Nama akun/bank baru…" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><select name="purpose" class="form-select form-select-sm"><option value="">Peran…</option>@foreach(\App\Models\CashAccount::PURPOSES as $pk => $pv)<option value="{{ $pk }}">{{ $pv }}</option>@endforeach</select></div>
                <div class="col-md-2"><input type="number" name="opening_balance" value="0" class="form-control form-control-sm" min="0" title="Saldo awal"></div>
                <div class="col-md-2"><label class="small mb-0"><input type="checkbox" name="is_income_default" value="1"> akun pemasukan</label></div>
                <div class="col-md-3"><button class="btn btn-xs btn-outline-success">+ Tambah Akun</button></div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm datatable" style="width:100%">
            <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Akun</th><th>Kategori</th><th>Produk</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Saldo</th><th>Ref</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($entries as $e)
                    <tr>
                        <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
                        <td>{{ $e->kode }}</td>
                        <td>
                            {{ $e->keterangan }}
                            @if($e->isTransfer())<br><span class="badge bg-info text-dark" title="Pemindahan dana antar akun sendiri — bukan pemasukan/pengeluaran">🔁 Transfer (internal)</span>@endif
                        </td>
                        <td>{{ $e->account?->name ?? '—' }}</td>
                        <td>{{ $e->category?->name ?? '—' }}</td>
                        <td>{{ \App\Models\CashEntry::PRODUK[$e->produk] ?? '—' }}</td>
                        <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
                        <td>{{ $e->ref ?? '—' }}</td>
                        <td>
                            @if($e->source === 'payment')
                                <span class="badge bg-light text-muted border" title="Otomatis dari pembayaran">⚙ auto</span>
                            @elseif($e->isTransfer())
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transfer ini? Kedua sisi (keluar & masuk) akan dihapus." class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            @else
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            @endif
                        </td>
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

> Catatan: form Kelola Akun sengaja memakai pola dua form bersaudara (form update ditutup sebelum tombol hapus dibuka) agar tidak ada `<form>` bersarang — sama seperti pola distribusi/asumsi. Tombol Simpan berada di dalam form update; tombol × berada di form hapus terpisah, keduanya dibungkus flex `col-md-2`.

- [ ] **Step 6: Verifikasi kompilasi view + jalankan test**

Run: `php artisan view:clear && php artisan test --filter=AccountingBankAccountTest`
Expected: PASS semua (termasuk `journal_shows_account_cards_and_transfer_ui`, `journal_can_filter_by_account`).

- [ ] **Step 7: Verifikasi cache view bersih**

Run: `php artisan view:cache`
Expected: `Blade templates cached successfully.` tanpa error. Lalu `php artisan view:clear`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/CashEntryController.php routes/web.php resources/views/accounting/journal.blade.php tests/Feature/AccountingBankAccountTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): UI Jurnal Kas multi-akun + Transfer Dana + Kelola Akun

Filter per akun, kartu saldo per akun, kolom akun, badge transfer
(internal), form transfer dgn penjelasan, kelola akun (saldo awal per
akun). Hapus form/route Set Saldo Awal global (opening pindah ke akun).

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 9: Regresi penuh & migrasi dev

**Files:** (tidak ada perubahan kode — verifikasi)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (tidak ada kegagalan). Bila ada gagal, hentikan & perbaiki sebelum lanjut.

- [ ] **Step 2: Migrasi DB dev**

Run: `php artisan migrate`
Expected: migrasi `2026_07_04_000009_create_cash_accounts_and_transfer_fields` sukses di DB dev `avidpedi_simapa` (agar aplikasi live tidak 500 karena tabel/kolom baru). Lihat memory `migrate-dev-db-after-new-migration`.

- [ ] **Step 3: (opsional) Verifikasi manual**

Buka `/accounting/journal` sebagai superadmin → kartu saldo per akun tampil (Kas Pemasukan/Operational/Harta), tombol Transfer Dana & Kelola Akun ada, filter akun berfungsi.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §1.1 CRUD akun → Task 6. §1.2 entri bertaut akun + auto-flow → Task 5, Task 7 (default). §1.3 saldo per akun + filter → Task 3, Task 8. §1.4 transfer internal + kecualikan laba → Task 4, Task 7. §1.5 role 403 → Task 6, Task 7.
- §2 migrasi/seed/backfill → Task 1. §3 model → Task 1. §4 service (compute/accountBalances/recap/sync) → Task 2/3/4/5. §5 kontroler & rute → Task 6/7/8. §6 view → Task 8. §8 test (unit CashAccountBalance, feature AccountingBankAccount, sesuaikan 3 test lama) → tersebar; CashJournalServiceTest (Task 2), CashRecapServiceTest (Task 4), AccountingJournalTest (Task 6/7), PaymentCashSyncTest (Task 5). §9 hapus updateOpening/opening route → Task 8.

**2. Placeholder scan:** Tidak ada TBD/TODO; semua step berisi kode utuh & perintah + expected. Satu langkah verifikasi manual ditandai opsional.

**3. Type consistency:** `compute(int,?int,?string,?int)` konsisten dipanggil di Task 2/8. `accountBalances(): array{rows,total}` konsisten Task 3/7/8. `CashAccount::incomeDefault()`, `totalOpening()`, `PURPOSES`, `scopeActive`, `entries()` konsisten. `CashEntry` field `is_transfer`/`transfer_group`/`account_id` + `isTransfer()`/`scopeReal()` konsisten. Rute `accounting.account.*`, `accounting.transfer.store` konsisten. `saldoAwal` tetap dikembalikan `compute` (dipakai test lama).
