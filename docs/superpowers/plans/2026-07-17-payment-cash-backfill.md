# Backfill Payment ke Jurnal Kas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modul Akuntansi berhenti buta — semua payment `paid` lama masuk Jurnal Kas, sehingga Jurnal/Dashboard/Rekap/Distribusi Profit/Target mencerminkan kenyataan (selisih vs Laporan Keuangan = 0).

**Architecture:** Migrasi data tipis memanggil `PaymentCashBackfillService::run()`, yang memanggil `PaymentCashSyncService::sync()` (sudah ada, idempotent `updateOrCreate` per `payment_id`) untuk tiap payment `paid`. Guard menolak keras bila saldo awal ≠ 0 (mencegah dobel-hitung). Pola mengikuti `2026_07_02_000010_backfill_order_title_id.php`.

**Tech Stack:** Laravel 11, PHPUnit. Tanpa dependency baru, tanpa perubahan UI, tanpa perubahan skema (migrasi ini **data**, bukan struktur).

**Spec:** `docs/superpowers/specs/2026-07-17-payment-cash-backfill-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit.
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell (`@'...'@`) di dalam tool Bash.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`. **Migrasi dev (`avidpedi_simapa`) dijalankan terpisah di Task 3** — itu justru inti pekerjaan ini.

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Services/PaymentCashBackfillService.php` (**baru**) | Menjalankan `sync()` untuk payment lama + guard saldo awal. Tak punya logika pemetaan sendiri. |
| `database/migrations/2026_07_17_000001_backfill_payments_to_cash_entries.php` (**baru**) | Pemicu sekali-jalan saat `migrate` (termasuk deploy live). |
| `tests/Feature/PaymentCashBackfillTest.php` (**baru**) | Kunci: backfill, idempotensi, refund→pengeluaran, guard, kecocokan laporan↔jurnal. |

---

## Task 1: `PaymentCashBackfillService` (TDD)

**Files:**
- Create: `tests/Feature/PaymentCashBackfillTest.php`
- Create: `app/Services/PaymentCashBackfillService.php`

- [x] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/PaymentCashBackfillTest.php`. Fixture meniru `PaymentCashSyncTest`. Catatan penting: `PaymentObserver` aktif, jadi membuat payment **otomatis** membuat entri kas — untuk meniru "payment lama tanpa entri", entri dikosongkan dulu dengan `CashEntry::query()->delete()`.

```php
<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashRecapService;
use App\Services\FinancialReportService;
use App\Services\PaymentCashBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Backfill payment lama ke Jurnal Kas. Sinkron hanya jalan lewat observer saat
 * payment disimpan, jadi payment yang ada sebelum fitur akuntansi tak pernah
 * tersentuh — di DB dev: 123 payment, 1 entri kas.
 */
class PaymentCashBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function order(string $type = 'at_kolab', int $cost = 5_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp', string $status = 'paid'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => $status, 'paid_at' => now(),
        ]);
    }

    /** Meniru keadaan "payment lama": ada payment, tapi entri kasnya tak pernah lahir. */
    private function lupakanEntriKas(): void
    {
        CashEntry::query()->delete();
    }

    /** @test */
    public function backfills_existing_payments(): void
    {
        $this->pay($this->order('at_kolab'), 5_000_000);
        $this->pay($this->order('at_mandiri'), 3_000_000);
        $this->pay($this->order('bk_mandiri'), 7_000_000);
        $this->lupakanEntriKas();
        $this->assertSame(0, CashEntry::count());

        $hasil = (new PaymentCashBackfillService())->run();

        $this->assertSame(3, $hasil['synced']);
        $this->assertSame(3, CashEntry::count());
        $this->assertSame(3, CashEntry::where('jenis', 'pemasukan')->count());
        $this->assertSame(2, CashEntry::where('produk', 'artikel')->count());
        $this->assertSame(1, CashEntry::where('produk', 'buku')->count());
    }

    /** @test */
    public function backfill_is_idempotent(): void
    {
        $this->pay($this->order(), 5_000_000);
        $this->pay($this->order(), 3_000_000);
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();
        (new PaymentCashBackfillService())->run();

        $this->assertSame(2, CashEntry::count(), 'Dijalankan dua kali tak boleh menggandakan entri.');
    }

    /** @test */
    public function backfills_refund_as_expense(): void
    {
        $order = $this->order('bk_mandiri', 10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();

        $this->assertSame(1, CashEntry::where('jenis', 'pemasukan')->count());
        $this->assertSame(1, CashEntry::where('jenis', 'pengeluaran')->count());
        $this->assertSame(4_000_000.0, (float) CashEntry::where('jenis', 'pengeluaran')->sum('amount'));
    }

    /** @test */
    public function skips_unpaid_payments(): void
    {
        $this->pay($this->order(), 5_000_000, 'dp', 'pending');
        $this->lupakanEntriKas();

        $hasil = (new PaymentCashBackfillService())->run();

        $this->assertSame(0, $hasil['synced']);
        $this->assertSame(0, CashEntry::count());
    }

    /** @test */
    public function refuses_when_opening_balance_set(): void
    {
        $this->pay($this->order(), 5_000_000);
        $this->lupakanEntriKas();
        CashAccount::query()->limit(1)->update(['opening_balance' => 1_000_000]);

        $this->expectException(\RuntimeException::class);

        try {
            (new PaymentCashBackfillService())->run();
        } finally {
            // Guard harus menolak SEBELUM menulis apa pun.
            $this->assertSame(0, CashEntry::count(), 'Guard menyala → tak boleh ada entri yang terlanjur dibuat.');
        }
    }

    /** @test */
    public function report_matches_journal_after_backfill(): void
    {
        $this->pay($this->order('at_kolab'), 5_000_000);
        $this->pay($this->order('bk_mandiri'), 7_000_000);
        $this->lupakanEntriKas();

        (new PaymentCashBackfillService())->run();

        $laporan = app(FinancialReportService::class)->pemasukan(null)['kpi']['total'];
        $jurnal  = app(CashRecapService::class)->ytd(now()->year)['totalIn'];

        $this->assertSame(12_000_000.0, (float) $laporan);
        $this->assertSame((float) $laporan, (float) $jurnal, 'Setelah backfill, laporan dan jurnal harus sepakat.');
    }
}
```

- [x] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=PaymentCashBackfillTest`
Expected: **FAIL** — `Class "App\Services\PaymentCashBackfillService" not found` di semua test.

- [x] **Step 3: Buat service**

Buat `app/Services/PaymentCashBackfillService.php`:

```php
<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashSetting;
use App\Models\Payment;
use RuntimeException;

class PaymentCashBackfillService
{
    /**
     * Tarik semua payment 'paid' jadi entri kas. Idempotent: sync() memakai
     * updateOrCreate per payment_id, jadi menjalankan ulang tak menggandakan.
     *
     * @return array{synced:int}
     */
    public function run(): array
    {
        $this->guardOpening();

        $sync = app(PaymentCashSyncService::class);
        $synced = 0;

        Payment::where('status', 'paid')->orderBy('id')
            ->chunkById(200, function ($payments) use ($sync, &$synced) {
                foreach ($payments as $payment) {
                    $sync->sync($payment);
                    $synced++;
                }
            });

        return ['synced' => $synced];
    }

    /**
     * Backfill aman HANYA bila saldo awal nol. Bila saldo awal sudah diisi, ia
     * mewakili transaksi lama secara ringkas — menarik payment-nya lagi =
     * dobel-hitung pembukuan. Berhenti keras; situasi ini butuh penilaian
     * manusia, jangan diam-diam menggandakan.
     */
    private function guardOpening(): void
    {
        $accounts = (float) CashAccount::sum('opening_balance');
        $global   = (float) (CashSetting::singleton()->saldo_awal ?? 0);

        if ($accounts != 0.0 || $global != 0.0) {
            throw new RuntimeException(
                'Backfill dibatalkan: saldo awal sudah diisi (akun: ' . $accounts . ', global: ' . $global . '). '
                . 'Menarik payment lama akan dobel-hitung. Nolkan saldo awal dulu, atau lewati backfill ini.'
            );
        }
    }
}
```

- [x] **Step 4: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=PaymentCashBackfillTest`
Expected: **PASS**, 6 test.

> Bila `refuses_when_opening_balance_set` gagal karena nama kolom saldo awal per akun bukan `opening_balance`, baca `database/migrations/2026_07_04_000009_create_cash_accounts_and_transfer_fields.php` + `app/Models/CashAccount.php` dan sesuaikan **service dan test** ke nama sebenarnya — jangan melemahkan guard-nya.

- [x] **Step 5: Commit**

```bash
git add app/Services/PaymentCashBackfillService.php tests/Feature/PaymentCashBackfillTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
feat(accounting): PaymentCashBackfillService (tarik payment lama ke kas)

Sinkron payment->kas hanya jalan lewat observer saat payment disimpan,
jadi payment yang ada sebelum fitur akuntansi tak pernah punya entri.
Service ini menjalankan sync() yang sudah ada untuk tiap payment paid -
tanpa logika pemetaan baru, idempotent per payment_id.

Guard: menolak keras bila saldo awal != 0, karena saldo awal mewakili
transaksi lama secara ringkas dan menariknya lagi = dobel-hitung.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Migrasi data + regresi

**Files:**
- Create: `database/migrations/2026_07_17_000001_backfill_payments_to_cash_entries.php`

- [x] **Step 1: Buat migrasi**

```php
<?php

use App\Services\PaymentCashBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new PaymentCashBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: entri kas hasil backfill dibiarkan saat rollback.
        // Menghapusnya berisiko membuang entri yang sudah disunting manual.
    }
};
```

- [x] **Step 2: Suite penuh**

Run: `php artisan test`
Expected: PASS semua (**529** = 523 + 6 test baru).

**Bila test lama GAGAL** karena kini ada entri kas yang dulu tak ada (migrasi ikut jalan di `RefreshDatabase`): itu **temuan** — baca test itu, pastikan perilaku barunya benar, perbaiki, **sebutkan di laporan**. Jangan sesuaikan diam-diam sampai hijau.

- [x] **Step 3: Commit**

```bash
git add database/migrations/2026_07_17_000001_backfill_payments_to_cash_entries.php
git commit -F <path-pesan>   # feat(accounting): migrasi data backfill payment ke Jurnal Kas
```

---

## Task 3: Jalankan di DB dev + buktikan selisihnya nol

**Files:** tak ada perubahan kode. **Ini inti pekerjaannya** — test membuktikan aturannya benar; langkah ini membuktikan data nyatanya sembuh.

- [x] **Step 1: Ukur SEBELUM**

```bash
php artisan tinker --execute="
\$lap = (int) \App\Models\Payment::income()->whereYear('paid_at', now()->year)->sum('amount');
\$kas = (float) app(\App\Services\CashRecapService::class)->ytd(now()->year)['totalIn'];
echo 'Laporan: '.number_format(\$lap).' | Jurnal: '.number_format(\$kas).' | Selisih: '.number_format(\$lap-\$kas).PHP_EOL;
echo 'Entri kas: '.\App\Models\CashEntry::count().PHP_EOL;"
```

Expected (per pengukuran 2026-07-17): Laporan 80.600.000 | Jurnal 6.500.000 | Selisih 74.100.000 | Entri kas 1.

- [x] **Step 2: Migrasi dev**

Run: `php artisan migrate`
Expected: `2026_07_17_000001_backfill_payments_to_cash_entries .... DONE`, tanpa exception (saldo awal dev = 0, guard tak menyala).

- [x] **Step 3: Ukur SESUDAH — selisih harus NOL**

Jalankan perintah tinker yang sama seperti Step 1.
Expected: **Selisih: 0**, entri kas ≈ 135 (semua payment `paid`).

**Bila selisih ≠ 0:** JANGAN dilanjutkan. Selidiki — kemungkinan ada payment `paid` dengan `paid_at` null (tak masuk `whereYear`) atau entri kas manual. Laporkan temuannya.

- [x] **Step 4: Periksa kategori tak terpetakan**

```bash
php artisan tinker --execute="
echo 'Entri tanpa kategori: '.\App\Models\CashEntry::whereNull('cash_category_id')->count().PHP_EOL;
echo 'Entri tanpa akun    : '.\App\Models\CashEntry::whereNull('account_id')->count().PHP_EOL;"
```

Bila banyak yang null, **laporkan** (tipe order tak dikenal / akun income-default belum ada) — bukan penggugur backfill, tapi user perlu tahu.

- [x] **Step 5: Lihat halaman Keuangan sungguhan**

`php artisan serve --port=8125` di background, login superadmin (buat user sementara bila perlu, hapus setelahnya), buka `/accounting/journal` dan `/accounting/dashboard`.
Expected: Jurnal Kas berisi banyak baris (bukan 1); Dashboard menampilkan tren beberapa bulan. Matikan server + bersihkan user sementara setelah selesai.

- [x] **Step 6: Centang plan + commit**

```bash
git add docs/superpowers/plans/2026-07-17-payment-cash-backfill.md
git commit -F <path-pesan>   # docs(plan): tandai backfill payment ke Jurnal Kas selesai
```

---

## Self-Review

- **Cakupan spec:** service + guard §1 (T1 S3) · migrasi §2 (T2 S1) · 6 test §3 (T1 S1) · verifikasi data nyata §4 (T3 S1-S3) · kategori tak terpetakan §5 (T3 S4). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `run(): array{synced:int}` dipakai `$hasil['synced']` di T1 S1 dan dipanggil tanpa argumen di migrasi T2 S1; `guardOpening()` privat, diuji lewat efeknya (exception), bukan langsung.
- **Catatan:** `report_matches_journal_after_backfill` menduplikasi semangat `laporan_keuangan_matches_jurnal_kas` (IncomeDefinitionTest) tapi berbeda maksud: yang itu mengunci *definisi* sepakat, yang ini mengunci *data* sepakat setelah backfill. Keduanya layak ada.
- **Risiko diketahui:** migrasi data ikut jalan tiap `RefreshDatabase` di test — aman karena tabel kosong (0 payment → 0 entri), tapi bila suite melambat drastis, itu penyebabnya.
