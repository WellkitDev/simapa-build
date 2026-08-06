# Impor Data v1 → v2 (Cutover) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun Artisan command `php artisan simapa:import-v1` yang me-reset DB v2 dan mengisinya penuh dari dump SiMAPA v1 (`avidpedi_simapa.sql`) — data bisnis mentah + semua layer turunan (Keuangan, Title Directory, Progress) — tanpa data yang miss.

**Architecture:** Satu command class `App\Console\Commands\ImportV1Command` yang mengorkestrasi: `migrate:fresh` → seed `PermissionSeed` → impor 10 tabel bisnis (eksekusi INSERT literal dari dump) → rekonsiliasi kredensial user → fix invoices → regenerasi via service v2 (`TitleBackfillService`, `PaymentCashBackfillService`) → generate `title_progress`+`invoice_logs` → reset AUTO_INCREMENT → cetak ringkasan verifikasi. Ekstraksi INSERT dari teks dump diisolasi sebagai method statik murni agar bisa di-unit-test.

**Tech Stack:** Laravel 10, PHP 8.4, MariaDB 10.6, Spatie Permission, PHPUnit. Command auto-registered via `App\Console\Kernel::commands()` (`$this->load(__DIR__.'/Commands')`).

**Referensi spec:** `docs/superpowers/specs/2026-07-20-import-v1-cutover-design.md`

---

## Catatan penting sebelum mulai

- **Sumber data:** file `avidpedi_simapa.sql` HARUS ada di root project (`base_path('avidpedi_simapa.sql')`). File ini gitignored/lokal — jangan commit.
- **Destruktif:** command menjalankan `migrate:fresh` (drop semua tabel). `.env` menunjuk DB dev `avidpedi_simapa`. Sebelum run pertama, **backup dulu**:
  `mysqldump -u root avidpedi_simapa > backup-avidpedi_simapa-2026-07-20.sql`
- **Model events tidak menyala** saat impor (pakai raw `DB::unprepared`). Itu disengaja — layer turunan diregenerasi eksplisit oleh service v2 sesudahnya.
- **TDD:** hanya `extractInserts()` (fungsi murni) yang di-unit-test merah→hijau. Orkestrasi command diverifikasi lewat **run end-to-end + assert jumlah baris** (Task 9) — sifatnya integrasi/acceptance, bukan unit.
- **Seeder lama `database/seeders/ProductionDataSeeder.php`** usang & bercacat; dihapus di Task 9.

## File Structure

- **Create:** `app/Console/Commands/ImportV1Command.php` — seluruh command + method statik `extractInserts()`.
- **Create:** `tests/Unit/ImportV1CommandExtractTest.php` — unit test untuk `extractInserts()`.
- **Delete:** `database/seeders/ProductionDataSeeder.php` — digantikan command.
- **Read-only (dipakai, tidak diubah):** `database/seeders/PermissionSeed.php`, `app/Services/TitleBackfillService.php`, `app/Services/PaymentCashBackfillService.php`.

---

## Task 1: Method statik `extractInserts()` (parser dump) — TDD

Fungsi murni: dari teks SQL dump, kembalikan array statement `INSERT` untuk satu tabel. Robust terhadap CRLF/EOF dan tidak salah menangkap tabel lain yang namanya berprefiks sama (backtick pembatas).

**Files:**
- Create: `app/Console/Commands/ImportV1Command.php`
- Test: `tests/Unit/ImportV1CommandExtractTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/ImportV1CommandExtractTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Console\Commands\ImportV1Command;
use PHPUnit\Framework\TestCase;

class ImportV1CommandExtractTest extends TestCase
{
    private string $sql = <<<'SQL'
INSERT INTO `tb_orders` (`id`, `code_order`) VALUES
(4, 'ORD-4'),
(5, 'ORD-5');

INSERT INTO `tb_order_details` (`id`, `title`) VALUES
(4, 'Judul; dengan titik koma');

SQL;

    public function test_extracts_single_multirow_insert_for_table(): void
    {
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_orders');

        $this->assertCount(1, $stmts);
        $this->assertStringContainsString("(4, 'ORD-4')", $stmts[0]);
        $this->assertStringContainsString("(5, 'ORD-5')", $stmts[0]);
        // Tidak bocor ke tabel lain
        $this->assertStringNotContainsString('tb_order_details', $stmts[0]);
    }

    public function test_prefix_table_name_not_matched(): void
    {
        // Minta 'tb_order' (tidak ada) tidak boleh menangkap tb_orders / tb_order_details
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_order');
        $this->assertCount(0, $stmts);
    }

    public function test_returns_empty_when_table_absent(): void
    {
        $stmts = ImportV1Command::extractInserts($this->sql, 'tb_authors');
        $this->assertSame([], $stmts);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=ImportV1CommandExtractTest`
Expected: FAIL — `Class "App\Console\Commands\ImportV1Command" not found`.

- [ ] **Step 3: Buat command + method statik minimal**

Create `app/Console/Commands/ImportV1Command.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportV1Command extends Command
{
    protected $signature = 'simapa:import-v1 {--force : Lewati konfirmasi destruktif}';
    protected $description = 'Cutover: reset DB v2 dan impor penuh dari dump SiMAPA v1 (avidpedi_simapa.sql)';

    /**
     * Ekstrak semua statement INSERT untuk satu tabel dari teks dump SQL.
     * Backtick pembatas mencegah salah tangkap tabel berprefiks sama.
     * Terminator ';' diikuti newline (CR/LF) atau akhir file.
     *
     * @return string[]
     */
    public static function extractInserts(string $sql, string $table): array
    {
        $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '` \(.*?\) VALUES.*?;(?=[\r\n]|$)/s';

        if (preg_match_all($pattern, $sql, $m)) {
            return array_map('trim', $m[0]);
        }

        return [];
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ImportV1CommandExtractTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php tests/Unit/ImportV1CommandExtractTest.php
git commit -m "feat(import): command skeleton + tested SQL INSERT extractor"
```

---

## Task 2: Guard destruktif + reset skema + seed dasar

Command memeriksa file dump, minta konfirmasi (kecuali `--force`), lalu `migrate:fresh` + seed `PermissionSeed`.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php` (isi `handle()`, tambah method)

- [ ] **Step 1: Isi `handle()` + method reset/seed**

Ganti method `handle()` dan tambahkan method berikut di class `ImportV1Command`:

```php
    public function handle(): int
    {
        $sqlPath = base_path('avidpedi_simapa.sql');
        if (! is_file($sqlPath)) {
            $this->error("File dump tidak ditemukan: {$sqlPath}");
            $this->line('Letakkan avidpedi_simapa.sql di root project lalu ulangi.');
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Ini akan MENGHAPUS SELURUH database ' . config('database.connections.'.config('database.default').'.database') .
            ' lalu impor ulang dari dump v1. Lanjut?'
        )) {
            $this->warn('Dibatalkan.');
            return self::FAILURE;
        }

        $sql = file_get_contents($sqlPath);

        $this->resetAndSeed();

        $this->info('Reset + seed dasar selesai.');
        return self::SUCCESS;
    }

    private function resetAndSeed(): void
    {
        $this->line('→ migrate:fresh ...');
        $this->call('migrate:fresh', ['--force' => true]);

        $this->line('→ seed PermissionSeed ...');
        $this->call('db:seed', ['--class' => 'PermissionSeed', '--force' => true]);
    }
```

- [ ] **Step 2: Jalankan command untuk verifikasi reset+seed**

Run: `php artisan simapa:import-v1 --force`
Expected: keluaran `migrate:fresh` sukses, `PermissionSeed` sukses, lalu `Reset + seed dasar selesai.` Exit 0.

- [ ] **Step 3: Verifikasi user & role dasar terbentuk**

Run: `php artisan tinker --execute="echo App\Models\User::count().' users; roles='.Spatie\Permission\Models\Role::pluck('name')->implode(',');"`
Expected: `5 users; roles=marketing,superadmin,manager,production,admin,accounting`
(Role `accounting` HARUS ada — dibuat migrasi `add_accounting_role`.)

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): destructive guard + migrate:fresh + PermissionSeed"
```

---

## Task 3: Impor 10 tabel bisnis dari dump

Eksekusi INSERT literal dari dump, urut aman-FK, dengan FK checks dimatikan sementara.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah method `importBusinessData()` + panggil dari `handle()`**

Tambahkan pemanggilan di `handle()` tepat setelah `$this->resetAndSeed();`:

```php
        $this->importBusinessData($sql);
```

Tambahkan method (gunakan konstanta daftar tabel, urut parent→child):

```php
    /** Tabel bisnis yang diimpor apa adanya, urut aman-FK. */
    private const BUSINESS_TABLES = [
        'tb_scopes',
        'tb_authors',
        'tb_orders',
        'tb_order_contacts',
        'tb_order_details',
        'tb_scope_orders',
        'tb_author_orders',
        'tb_payments',
        'tb_payment_approvals',
        'tb_invoices',
    ];

    private function importBusinessData(string $sql): void
    {
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::BUSINESS_TABLES as $table) {
                $stmts = self::extractInserts($sql, $table);
                if ($stmts === []) {
                    $this->warn("  ! Tidak ada INSERT untuk {$table} di dump.");
                    continue;
                }
                foreach ($stmts as $stmt) {
                    \DB::unprepared($stmt);
                }
                $this->line("  ✓ {$table}: " . \DB::table($table)->count() . ' baris');
            }
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
```

- [ ] **Step 2: Jalankan command penuh sejauh ini**

Run: `php artisan simapa:import-v1 --force`
Expected: tiap tabel bisnis mencetak jumlah baris. Nilai target:
`tb_scopes: 102`, `tb_authors: 173`, `tb_orders: 126`, `tb_order_contacts: 126`, `tb_order_details: 126`, `tb_payments: 178`, `tb_payment_approvals: 178`, `tb_invoices: 178` (scope_orders & author_orders sesuai dump).

- [ ] **Step 3: Verifikasi integritas FK order→payment→invoice**

Run: `php artisan tinker --execute="echo 'orphan invoices='.App\Models\Invoice::whereDoesntHave('order')->count();"`
Expected: `orphan invoices=0`
(Jika model Invoice tak punya relasi `order`, pakai: `DB::table('tb_invoices')->whereNotIn('order_id', DB::table('tb_orders')->pluck('id'))->count()` → 0.)

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): import 10 business tables from v1 dump (FK-safe order)"
```

---

## Task 4: Rekonsiliasi kredensial 5 user dari dump

`PermissionSeed` membuat user dengan password "password". Timpa dengan hash asli v1 agar login lama tetap jalan.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah method `reconcileUsers()` + panggil dari `handle()`**

Tambahkan di `handle()` setelah `$this->importBusinessData($sql);`:

```php
        $this->reconcileUsers($sql);
```

Tambahkan method. Kita impor baris `users` dump ke tabel sementara di memori PHP dengan mengeksekusi INSERT-nya ke tabel bayangan? Tidak — lebih sederhana: parse nilai via kueri balik. Karena `PermissionSeed` sudah membuat user id 1–5 dengan email identik, kita ambil kredensial dari dump lewat tabel sementara:

```php
    private function reconcileUsers(string $sql): void
    {
        // Buat tabel sementara berstruktur sama, isi dari dump, lalu salin kolom kredensial.
        \DB::statement('CREATE TEMPORARY TABLE _v1_users LIKE users');
        try {
            foreach (self::extractInserts($sql, 'users') as $stmt) {
                \DB::unprepared(str_replace('INSERT INTO `users`', 'INSERT INTO `_v1_users`', $stmt));
            }

            $rows = \DB::table('_v1_users')->get(['id', 'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at']);
            foreach ($rows as $r) {
                \DB::table('users')->where('id', $r->id)->update([
                    'password'          => $r->password,
                    'remember_token'    => $r->remember_token,
                    'email_verified_at' => $r->email_verified_at,
                    'created_at'        => $r->created_at,
                    'updated_at'        => $r->updated_at,
                ]);
            }
            $this->line('  ✓ kredensial ' . count($rows) . ' user disinkron dari v1');
        } finally {
            \DB::statement('DROP TEMPORARY TABLE IF EXISTS _v1_users');
        }
    }
```

- [ ] **Step 2: Jalankan command penuh**

Run: `php artisan simapa:import-v1 --force`
Expected: baris `✓ kredensial 5 user disinkron dari v1`.

- [ ] **Step 3: Verifikasi hash user = hash dump (bukan "password")**

Run: `php artisan tinker --execute="echo App\Models\User::find(1)->password;"`
Expected: `$2y$12$eux/Nxd7u0cNp.1/tXSISe1ga1Oqodu6dFouJzcwvIRJSgIRDfQku` (hash asli dump user id 1), BUKAN hash dari kata "password".

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): reconcile 5 user credentials from v1 dump"
```

---

## Task 5: Fix invoices ke skema v2

Semua invoice impor `status='pending'`; skema v2 memetakan `pending → diterbitkan` dan `type='regular'`.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah method `fixInvoices()` + panggil dari `handle()`**

Tambahkan di `handle()` setelah `$this->reconcileUsers($sql);`:

```php
        $this->fixInvoices();
```

Tambahkan method:

```php
    private function fixInvoices(): void
    {
        \DB::table('tb_invoices')->update(['type' => 'regular']);
        $n = \DB::table('tb_invoices')->where('status', 'pending')->update(['status' => 'diterbitkan']);
        $this->line("  ✓ invoices: type=regular, {$n} status pending→diterbitkan");
    }
```

- [ ] **Step 2: Jalankan command penuh**

Run: `php artisan simapa:import-v1 --force`
Expected: baris `✓ invoices: type=regular, 178 status pending→diterbitkan`.

- [ ] **Step 3: Verifikasi tak ada invoice status pending tersisa**

Run: `php artisan tinker --execute="echo DB::table('tb_invoices')->where('status','pending')->count();"`
Expected: `0`

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): map invoice status pending->diterbitkan, type=regular"
```

---

## Task 6: Regenerasi Title Directory + Kas via service v2

Panggil service v2 yang sudah ada — sanksioned & idempotent — untuk membangkitkan `tb_titles`/`title_id`/`group_key`/`code` dan entri kas pemasukan.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah method `regenerateDerived()` + panggil dari `handle()`**

Tambahkan `use` di atas class:

```php
use App\Services\TitleBackfillService;
use App\Services\PaymentCashBackfillService;
```

Tambahkan di `handle()` setelah `$this->fixInvoices();`:

```php
        $this->regenerateDerived();
```

Tambahkan method:

```php
    private function regenerateDerived(): void
    {
        $titles = (new TitleBackfillService())->run();
        $this->line("  ✓ Title Directory: {$titles} order detail ter-backfill ke Title");

        $cash = (new PaymentCashBackfillService())->run();
        $this->line("  ✓ Kas: {$cash['synced']} payment 'paid' → entri kas");
    }
```

- [ ] **Step 2: Jalankan command penuh**

Run: `php artisan simapa:import-v1 --force`
Expected: `✓ Title Directory: 126 order detail ter-backfill...` dan `✓ Kas: 176 payment 'paid' → entri kas` (178 payment − 2 rejected id 21 & 84 = 176).

- [ ] **Step 3: Verifikasi title_id & entri kas terisi**

Run: `php artisan tinker --execute="echo 'detail tanpa title_id='.DB::table('tb_order_details')->whereNull('title_id')->count().'; cash='.DB::table('tb_cash_entries')->count().'; titles='.DB::table('tb_titles')->count();"`
Expected: `detail tanpa title_id=0; cash=176; titles=<jumlah judul unik>` (cash = 176; tak ada detail tanpa title_id).

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): regenerate titles + cash via v2 backfill services"
```

---

## Task 7: Generate `title_progress` + `invoice_logs`

Layer turunan yang tak punya sumber v1: satu progress per order detail (tahap awal) dan satu log awal per invoice.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah method `seedProgressAndLogs()` + panggil dari `handle()`**

Tambahkan di `handle()` setelah `$this->regenerateDerived();`:

```php
        $this->seedProgressAndLogs();
```

Tambahkan method:

```php
    private function seedProgressAndLogs(): void
    {
        // Title progress: 1 per order detail, tahap awal.
        $progress = [];
        foreach (\DB::table('tb_order_details')->orderBy('id')->get(['id', 'created_at']) as $d) {
            $progress[] = [
                'order_detail_id' => $d->id,
                'status'          => 'menunggu_proses',
                'assigned_role'   => 'marketing',
                'updated_by'      => 1,
                'started_at'      => $d->created_at,
                'created_at'      => $d->created_at,
                'updated_at'      => $d->created_at,
            ];
        }
        foreach (array_chunk($progress, 100) as $chunk) {
            \DB::table('tb_title_progress')->insert($chunk);
        }
        $this->line('  ✓ title_progress: ' . count($progress) . ' baris');

        // Invoice logs: 1 entri awal per invoice.
        $logs = [];
        foreach (\DB::table('tb_invoices')->orderBy('id')->get(['id', 'created_at']) as $inv) {
            $logs[] = [
                'invoice_id'  => $inv->id,
                'from_status' => '',
                'to_status'   => 'diterbitkan',
                'changed_by'  => 1,
                'note'        => 'Import data produksi v1.',
                'created_at'  => $inv->created_at,
            ];
        }
        foreach (array_chunk($logs, 100) as $chunk) {
            \DB::table('tb_invoice_logs')->insert($chunk);
        }
        $this->line('  ✓ invoice_logs: ' . count($logs) . ' baris');
    }
```

- [ ] **Step 2: Jalankan command penuh**

Run: `php artisan simapa:import-v1 --force`
Expected: `✓ title_progress: 126 baris` dan `✓ invoice_logs: 178 baris`.

- [ ] **Step 3: Verifikasi 1:1 dengan induknya**

Run: `php artisan tinker --execute="echo 'progress='.DB::table('tb_title_progress')->count().'/'.DB::table('tb_order_details')->count().'; logs='.DB::table('tb_invoice_logs')->count().'/'.DB::table('tb_invoices')->count();"`
Expected: `progress=126/126; logs=178/178`

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): seed title_progress + invoice_logs for imported rows"
```

---

## Task 8: Reset AUTO_INCREMENT dinamis + ringkasan verifikasi

Pastikan insert baru tidak bentrok ID (belt-and-suspenders), dan cetak ringkasan akhir.

**Files:**
- Modify: `app/Console/Commands/ImportV1Command.php`

- [ ] **Step 1: Tambah `resetAutoIncrements()` + `printSummary()` + panggil dari `handle()`**

Tambahkan di `handle()` setelah `$this->seedProgressAndLogs();`, sebelum `return`:

```php
        $this->resetAutoIncrements();
        $this->printSummary();
```

Ubah baris akhir `handle()` menjadi:

```php
        $this->info('Impor v1 → v2 selesai.');
        return self::SUCCESS;
```

Tambahkan method:

```php
    private function resetAutoIncrements(): void
    {
        $tables = array_merge(self::BUSINESS_TABLES, [
            'tb_title_progress', 'tb_invoice_logs', 'tb_titles',
        ]);
        foreach ($tables as $t) {
            $next = ((int) \DB::table($t)->max('id')) + 1;
            \DB::statement("ALTER TABLE `{$t}` AUTO_INCREMENT = {$next}");
        }
        $this->line('  ✓ AUTO_INCREMENT direset dinamis (MAX(id)+1)');
    }

    private function printSummary(): void
    {
        $rows = [];
        foreach (array_merge(self::BUSINESS_TABLES, [
            'tb_titles', 'tb_cash_entries', 'tb_title_progress', 'tb_invoice_logs', 'users',
        ]) as $t) {
            $rows[] = [$t, \DB::table($t)->count()];
        }
        $this->table(['Tabel', 'Baris'], $rows);
    }
```

- [ ] **Step 2: Jalankan command penuh**

Run: `php artisan simapa:import-v1 --force`
Expected: tabel ringkasan tercetak; baris akhir `Impor v1 → v2 selesai.`; exit 0.

- [ ] **Step 3: Verifikasi AUTO_INCREMENT authors = 174**

Run: `php artisan tinker --execute="echo DB::selectOne('SHOW TABLE STATUS LIKE \'tb_authors\'')->Auto_increment;"`
Expected: `174` (MAX id 173 + 1).

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportV1Command.php
git commit -m "feat(import): dynamic AUTO_INCREMENT reset + verification summary"
```

---

## Task 9: Hapus seeder usang + acceptance run end-to-end

**Files:**
- Delete: `database/seeders/ProductionDataSeeder.php`

- [ ] **Step 1: Hapus seeder lama**

```bash
git rm database/seeders/ProductionDataSeeder.php
```

(Jika file untracked, hapus manual: `rm database/seeders/ProductionDataSeeder.php`.)

- [ ] **Step 2: Pastikan tidak ada referensi tersisa**

Run: `php artisan test --filter=ImportV1CommandExtractTest`
Expected: PASS (unit test masih hijau).
Run (grep): pastikan tak ada yang memanggil `ProductionDataSeeder` di `database/` atau `app/`.
Expected: tidak ada hasil.

- [ ] **Step 3: Acceptance run penuh + checklist verifikasi**

Run: `php artisan simapa:import-v1 --force`

Verifikasi semua kriteria (semua HARUS benar):

```bash
php artisan tinker --execute="
echo 'scopes='.DB::table('tb_scopes')->count().PHP_EOL;      // 102
echo 'authors='.DB::table('tb_authors')->count().PHP_EOL;     // 173
echo 'orders='.DB::table('tb_orders')->count().PHP_EOL;       // 126
echo 'details='.DB::table('tb_order_details')->count().PHP_EOL;// 126
echo 'payments='.DB::table('tb_payments')->count().PHP_EOL;   // 178
echo 'invoices='.DB::table('tb_invoices')->count().PHP_EOL;   // 178
echo 'no_title_id='.DB::table('tb_order_details')->whereNull('title_id')->count().PHP_EOL; // 0
echo 'cash='.DB::table('tb_cash_entries')->count().PHP_EOL;   // 176
echo 'inv_pending='.DB::table('tb_invoices')->where('status','pending')->count().PHP_EOL;  // 0
echo 'login=' . (Hash::check('', App\Models\User::find(1)->password) ? 'n/a' : 'hash-v1-ok') . PHP_EOL;
"
```
Expected: nilai sesuai komentar di atas.

- [ ] **Step 4: Smoke test halaman inti (tanpa 500)**

Jalankan app (`php artisan serve` atau XAMPP), login sebagai `super`, buka: daftar Order, Arsip Judul/Title Directory, Keuangan/Jurnal Kas, Dashboard. Semua tampil tanpa error 500 dan menampilkan data v1.

- [ ] **Step 5: Commit**

```bash
git add -A database/seeders/ProductionDataSeeder.php
git commit -m "chore(import): remove stale ProductionDataSeeder, superseded by simapa:import-v1"
```

---

## Self-Review Notes (untuk implementer)

- Jika `PaymentCashBackfillService::run()` melempar "saldo awal sudah diisi": berarti ada migrasi/seed yang mengisi opening balance. Karena `migrate:fresh` memulai dari nol, ini seharusnya tidak terjadi; bila terjadi, cek `tb_cash_accounts.opening_balance` dan `tb_cash_settings.saldo_awal`, nolkan dulu.
- Jika sebuah tabel bisnis gagal INSERT karena kolom non-nullable tak terduga: cek migrasi kolom itu; semua kolom tambahan yang relevan sudah dikonfirmasi nullable (`group_key`, `title_id`, refund fields) — jadi ini tidak diharapkan.
- Nama tabel entri kas diasumsikan `tb_cash_entries` (dari migrasi `create_tb_cash_entries_table`). Bila berbeda, sesuaikan di `printSummary()` dan langkah verifikasi.
