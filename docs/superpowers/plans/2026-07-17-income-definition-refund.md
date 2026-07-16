# Definisi Kanonik Pemasukan (Refund) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refund berhenti terhitung sebagai pemasukan di Laporan Keuangan, Dashboard Marketing, dan Target Marketing — lewat satu scope kanonik `Payment::income()`.

**Architecture:** Tambah `scopeIncome()` di `Payment` (= `status paid` DAN `payment_type != refund`), lalu 4 pemanggil yang bertanya "berapa uang masuk" dipindahkan dari `approved()` ke `income()`. `approved()` **dipertahankan** untuk konteks non-pemasukan (mis. cek lunas). Pemasukan tetap **kotor** agar cocok dengan `CashRecapService::ytd()['totalIn']`; refund tetap jadi pengeluaran di Jurnal Kas (sudah berjalan lewat `PaymentObserver`).

**Tech Stack:** Laravel 11, PHPUnit. Tanpa migrasi, tanpa dependency, tanpa perubahan UI.

**Spec:** `docs/superpowers/specs/2026-07-17-income-definition-refund-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit (repo punya file lokal yang tak boleh ter-commit: `avidpedi_simapa.sql`, `template-web/`, `.env.testing`, `ProductionDataSeeder.php`).
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell (`@'...'@`) di dalam tool Bash — sintaksnya tak dikenal dan masuk ke pesan.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`, **bukan** DB dev.
- Angka uang di test pakai underscore (`10_000_000`) agar terbaca.

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Models/Payment.php` (**diubah**) | Rumah definisi kanonik: `income()` (uang masuk) vs `approved()` (pembayaran disetujui). |
| `app/Services/FinancialReportService.php` (**diubah**) | Pemakai — pemasukan (`:23`) & piutang (`:52`). |
| `app/Services/MarketingDashboardService.php` (**diubah**) | Pemakai — KPI/tren (`:23`) + komentar definisi (`:22`). |
| `app/Services/MarketingTargetService.php` (**diubah**) | Pemakai — realisasi & komisi (`:23`). |
| `tests/Feature/IncomeDefinitionTest.php` (**baru**) | Mengunci definisi di keempat permukaan + kecocokan dgn Jurnal Kas. |

---

## Task 1: Scope kanonik `Payment::income()` + 4 pemanggil (TDD)

**Files:**
- Create: `tests/Feature/IncomeDefinitionTest.php`
- Modify: `app/Models/Payment.php` (setelah `scopeApproved`, baris 44-47)
- Modify: `app/Services/FinancialReportService.php:23` dan `:52`
- Modify: `app/Services/MarketingDashboardService.php:22-23`
- Modify: `app/Services/MarketingTargetService.php:23`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/IncomeDefinitionTest.php`. Fixture meniru pola `PaymentCashSyncTest` (Order/OrderDetail dibuat eksplisit — factory OrderDetail tak dipakai di suite ini):

```php
<?php

namespace Tests\Feature;

use App\Models\MarketingTarget;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashRecapService;
use App\Services\FinancialReportService;
use App\Services\MarketingDashboardService;
use App\Services\MarketingTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengunci definisi kanonik "uang masuk": Payment paid yang BUKAN refund.
 * Skenario tetap: order 10jt dibayar penuh, lalu direfund 4jt.
 * Pemasukan harus 10jt (kotor) — bukan 14jt (refund ditambahkan = bug lama),
 * dan bukan 6jt (bersih = divergen dari Jurnal Kas).
 */
class IncomeDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');

        $this->order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $this->marketing->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $this->order->id, 'type' => 'bk_mandiri', 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => 10_000_000,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        // Pelanggan bayar 10jt.
        Payment::create([
            'order_id' => $this->order->id, 'payment_type' => 'dp', 'amount' => 10_000_000,
            'status' => 'paid', 'paid_at' => now(),
        ]);
        // Lalu direfund 4jt — uang KELUAR (Jurnal Kas mencatatnya sebagai pengeluaran).
        Payment::create([
            'order_id' => $this->order->id, 'payment_type' => 'refund', 'amount' => 4_000_000,
            'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /** @test */
    public function pemasukan_excludes_refund(): void
    {
        $kpi = app(FinancialReportService::class)->pemasukan(null)['kpi'];

        $this->assertSame(10_000_000, $kpi['total'], 'Refund tak boleh menambah pemasukan.');
    }

    /** @test */
    public function piutang_paid_excludes_refund(): void
    {
        // Order masih 'pending' (belum lunas) → masuk daftar piutang.
        $kpi = app(FinancialReportService::class)->piutang(null)['kpi'];

        $this->assertSame(10_000_000, $kpi['dibayar'], 'Refund tak boleh menambah "sudah dibayar".');
        $this->assertSame(0, $kpi['sisa'], 'Sisa piutang = 10jt nilai - 10jt dibayar.');
    }

    /** @test */
    public function marketing_dashboard_income_excludes_refund(): void
    {
        $kpi = app(MarketingDashboardService::class)->forUser($this->marketing);

        $this->assertSame(10_000_000, $kpi['pemasukan_tahun_ini'], 'Refund tak boleh menambah KPI dashboard.');
    }

    /** @test */
    public function marketing_target_realisasi_excludes_refund(): void
    {
        $target = MarketingTarget::create([
            'user_id' => $this->marketing->id,
            'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(),
            'target_amount' => 20_000_000, 'commission_type' => 'percent',
            'commission_rate' => 5, 'commission_flat' => 0,
            'created_by' => $this->marketing->id, 'updated_by' => $this->marketing->id,
        ]);

        $p = app(MarketingTargetService::class)->progressFor($target);

        $this->assertSame(10_000_000, $p['realisasi'], 'Refund tak boleh menggelembungkan realisasi.');
        $this->assertSame(500_000, $p['komisi'], 'Komisi 5% x 10jt — bukan 5% x 14jt.');
    }

    /** @test */
    public function laporan_keuangan_matches_jurnal_kas(): void
    {
        // PaymentObserver sudah menyinkron kedua Payment ke tb_cash_entries.
        $laporan = app(FinancialReportService::class)->pemasukan(null)['kpi']['total'];
        $jurnal  = app(CashRecapService::class)->ytd(now()->year)['totalIn'];

        $this->assertSame((float) $laporan, (float) $jurnal, 'Laporan Keuangan dan Jurnal Kas harus sepakat soal pemasukan.');
    }

    /** @test */
    public function refund_still_recorded_as_expense(): void
    {
        // Prinsip user: "jika ada refund maka ada pengeluaran" — perbaikan ini tak boleh menghapusnya.
        $ytd = app(CashRecapService::class)->ytd(now()->year);

        $this->assertSame(4_000_000.0, (float) $ytd['totalOut'], 'Refund harus tetap tercatat sebagai pengeluaran.');
        $this->assertSame(6_000_000.0, (float) $ytd['laba'], 'Laba = 10jt masuk - 4jt refund.');
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=IncomeDefinitionTest`
Expected: **FAIL**. `pemasukan_excludes_refund` → `14000000 is not identical to 10000000`; `piutang_paid_excludes_refund` → dibayar 14jt, sisa -4jt; `marketing_dashboard_income_excludes_refund` → 14jt; `marketing_target_realisasi_excludes_refund` → realisasi 14jt, komisi 700rb; `laporan_keuangan_matches_jurnal_kas` → 14jt vs 10jt. `refund_still_recorded_as_expense` **LULUS** sejak awal (Jurnal Kas memang sudah benar) — itu memang perannya: penjaga agar sisi yang sudah benar tak ikut rusak.

- [ ] **Step 3: Tambah scope kanonik**

Di `app/Models/Payment.php`, sisipkan tepat **setelah** `scopeApproved()` (yang berakhir di baris 47) dan **sebelum** `scopeForOrdersOf()`:

```php
    /**
     * Uang masuk kanonik: pembayaran diterima, BUKAN refund.
     * Refund = uang keluar → dicatat sebagai pengeluaran di Jurnal Kas
     * (PaymentCashSyncService). Dipakai semua tempat yang bertanya
     * "berapa uang masuk": Laporan Keuangan, Dashboard & Target Marketing.
     */
    public function scopeIncome($query)
    {
        return $query->where('status', 'paid')->where('payment_type', '!=', 'refund');
    }
```

**JANGAN** ubah atau hapus `scopeApproved()` — ia berarti "pembayaran sudah disetujui" dan sah dipakai di konteks non-pemasukan (mis. menghitung apakah order lunas, di mana refund justru relevan).

- [ ] **Step 4: Pindahkan `FinancialReportService` (2 baris)**

Baris 23, di `pemasukan()`:

```php
        $q = fn () => Payment::income()->forOrdersOf($scopeUser);
```

Baris 52, di `piutang()`:

```php
            ->withSum(['payments as total_paid' => fn ($q) => $q->income()], 'amount')
```

- [ ] **Step 5: Pindahkan `MarketingDashboardService` (komentar + 1 baris)**

Ganti baris 22-23:

```php
        // Uang masuk = definisi kanonik Payment::income() (paid, bukan refund), scoped order user.
        $income = fn () => Payment::income()->forOrdersOf($user);
```

- [ ] **Step 6: Pindahkan `MarketingTargetService` (1 baris)**

Baris 23, di `progressFor()`:

```php
        $realisasi = (int) Payment::income()->forOrdersOf($target->user)
```

- [ ] **Step 7: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=IncomeDefinitionTest`
Expected: **PASS**, 6 test.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Payment.php app/Services/FinancialReportService.php app/Services/MarketingDashboardService.php app/Services/MarketingTargetService.php tests/Feature/IncomeDefinitionTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
fix(income): refund berhenti terhitung sebagai pemasukan

Payment::approved() = status paid saja, sedangkan refund juga berstatus
paid - jadi refund lolos sebagai pemasukan. Bayar 10jt + refund 4jt
menghasilkan pemasukan 14jt: uang keluar malah ditambahkan.

Scope kanonik Payment::income() (paid AND bukan refund) dipakai Laporan
Keuangan (pemasukan+piutang), Dashboard Marketing, dan Target Marketing
(komisi tak lagi kelebihan bayar). Pemasukan tetap kotor agar cocok
dengan Jurnal Kas totalIn; refund tetap jadi pengeluaran. approved()
dipertahankan untuk konteks non-pemasukan (cek lunas).

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Regresi + tinjau `approved()` yang tersisa

**Files:** tak ada perubahan kode kecuali bila Step 2 menemukan sesuatu.

- [ ] **Step 1: Suite penuh**

Run: `php artisan test`
Expected: PASS semua (**514** = 508 + 6 test baru).

**Bila ada test lama yang GAGAL:** kemungkinan besar ia mengunci angka lama yang mengandung refund. Itu **temuan, bukan gangguan** — baca test itu, pastikan angka barunya memang benar (refund dikecualikan), perbaiki, dan **sebutkan di laporan**. Jangan sesuaikan angka diam-diam sampai hijau.

- [ ] **Step 2: Tinjau sisa pemakai `approved()`**

Run: `grep -rn "approved()" app/ --include=*.php`

Untuk tiap hasil, putuskan: apakah ia bertanya *"berapa uang masuk"* (harusnya `income()`) atau *"apakah pembayaran ini disetujui/lunas"* (tetap `approved()`)? Yang diketahui saat plan ditulis: `FinancialReportService`, `MarketingDashboardService`, `MarketingTargetService` — ketiganya sudah dipindah di Task 1. Bila muncul pemakai lain yang menghitung uang masuk, **laporkan ke user** sebelum mengubahnya (di luar scope spec, perlu keputusan).

- [ ] **Step 3: Blade tetap sehat**

Run: `php artisan view:cache && php artisan view:clear`
Expected: "Blade templates cached successfully." tanpa error.

- [ ] **Step 4: Centang plan + commit**

```bash
git add docs/superpowers/plans/2026-07-17-income-definition-refund.md
git commit -F <path-pesan>   # docs(plan): tandai definisi kanonik pemasukan selesai
```

---

## Self-Review

- **Cakupan spec:** scope `income()` + `approved()` dipertahankan (T1 S3) · 4 pemanggil (T1 S4-S6) · komentar `MarketingDashboardService:22` diperbarui (T1 S5) · `RefundController:24` dibiarkan ✓ (tak disentuh di task manapun) · 6 test spec §3 semuanya ada (T1 S1) · regresi (T2 S1). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `scopeIncome` → dipanggil `Payment::income()` / `$q->income()` (konvensi scope Laravel, sama seperti `scopeApproved` → `approved()`); kunci array yang dipakai test cocok dengan sumbernya — `pemasukan()['kpi']['total']`, `piutang()['kpi']['dibayar'|'sisa']`, `forUser()['pemasukan_tahun_ini']` (`MarketingDashboardService:46`), `progressFor()['realisasi'|'komisi']` (`MarketingTargetService:47,49`), `ytd()['totalIn'|'totalOut'|'laba']` (`CashRecapService:64`).
- **Catatan:** `refund_still_recorded_as_expense` sengaja hijau sejak awal — ia penjaga regresi untuk sisi yang sudah benar, bukan fase merah.
- **Angka:** `CashRecapService` mengembalikan float, `FinancialReportService` int → test membandingkan setelah cast `(float)` di kedua sisi.
