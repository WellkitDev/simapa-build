# Uang Bersih per Order (Refund) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refund mengurangi "sudah dibayar", sehingga order yang uangnya dikembalikan tak lagi dianggap lunas (dan tak lolos ke Arsip Judul).

**Architecture:** `Order::paidNet()` = `payments()->income()` − `payments()->refund()`, dipakai 6 titik PHP; titik ke-7 memfilter lewat SQL mentah sehingga memakai konstanta `Order::PAID_NET_SQL` yang hidup bersebelahan dengan `paidNet()` — kesetaraan keduanya dikunci test. `Payment::scopeApproved()` dihapus (nol pemanggil, dan namanya jebakan yang melahirkan bug ini).

**Tech Stack:** Laravel 11, PHPUnit. Tanpa migrasi, tanpa dependency, tanpa perubahan UI.

**Spec:** `docs/superpowers/specs/2026-07-17-paid-net-refund-design.md`

---

## Konvensi

- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — path eksplisit.
- Pesan commit: tulis ke file lalu `git commit -F <file>`. **JANGAN** here-string PowerShell (`@'...'@`) di dalam tool Bash.
- Test lewat `.env.testing` → DB `avidpedi_simapa_test`.
- Branch: `income-definition-refund` (lanjutan; keluarga bug yang sama).

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Models/Payment.php` (**diubah**) | +`scopeRefund` (pasangan `income`); −`scopeApproved` (jebakan). |
| `app/Models/Order.php` (**diubah**) | Rumah `paidNet()` + `PAID_NET_SQL` (dua versi definisi yang sama, bersebelahan); `isLunas()` memakainya. |
| `InvoiceController` / `PaymentBookController` / `OrderBookController` / `FullPaymentBookController` (**diubah**) | Pemakai — 7 titik. |
| `tests/Feature/PaidNetTest.php` (**baru**) | Kunci: pembatalan, refund penuh, kelebihan bayar, arsip, jalan pintas invoice, kesetaraan SQL↔PHP, `approved()` hilang. |

---

## Task 1: `paidNet()` + `scopeRefund` + hapus `approved()` (TDD)

**Files:**
- Create: `tests/Feature/PaidNetTest.php`
- Modify: `app/Models/Payment.php` (setelah `scopeIncome`; hapus `scopeApproved` baris 44-47)
- Modify: `app/Models/Order.php` (`isLunas()` baris 51-59)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/PaidNetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengunci definisi "sudah dibayar" = BERSIH (pembayaran masuk - refund).
 * Beda dari Payment::income() (pelaporan, refund dikecualikan) — di sini
 * refund DIKURANGKAN, karena satu aturan harus benar untuk dua kasus:
 * pembatalan (belum lunas) dan kelebihan bayar (tetap lunas).
 */
class PaidNetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function order(int $cost = 10_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /** @test */
    public function partial_refund_makes_order_not_lunas(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');

        $this->assertSame(6_000_000, $order->fresh()->paidNet());
        $this->assertFalse($order->fresh()->isLunas(), 'Uang dikembalikan 4jt → belum lunas.');
    }

    /** @test */
    public function full_refund_makes_order_not_lunas(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 10_000_000, 'refund');

        $this->assertSame(0, $order->fresh()->paidNet());
        $this->assertFalse($order->fresh()->isLunas(), 'Uang kembali semua → jelas belum lunas.');
    }

    /** @test */
    public function overpayment_refund_stays_lunas(): void
    {
        // Kasus kedua yang harus benar dengan aturan yang SAMA.
        $order = $this->order(10_000_000);
        $this->pay($order, 14_000_000);
        $this->pay($order, 4_000_000, 'refund');

        $this->assertSame(10_000_000, $order->fresh()->paidNet());
        $this->assertTrue($order->fresh()->isLunas(), 'Kelebihan bayar dikembalikan → tetap lunas.');
    }

    /** @test */
    public function no_refund_is_unaffected(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);

        $this->assertSame(10_000_000, $order->fresh()->paidNet());
        $this->assertTrue($order->fresh()->isLunas());
    }

    /** @test */
    public function lunas_invoice_shortcut_still_wins(): void
    {
        // Jalan pintas invoice 'lunas' sengaja DIPERTAHANKAN (di luar scope).
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');
        Invoice::create([
            'order_id' => $order->id, 'invoice_no' => 'INV-' . uniqid(),
            'status' => 'lunas', 'amount' => 10_000_000,
        ]);

        $this->assertTrue($order->fresh()->isLunas(), 'Invoice lunas tetap menang — perilaku lama dipertahankan.');
    }

    /** @test */
    public function sql_and_php_agree_on_paid_net(): void
    {
        // Dua versi definisi yang sama tak boleh berpisah diam-diam.
        $kombinasi = [
            [10_000_000, 0],           // tanpa refund
            [10_000_000, 4_000_000],   // refund sebagian
            [10_000_000, 10_000_000],  // refund penuh
            [14_000_000, 4_000_000],   // kelebihan bayar
        ];

        foreach ($kombinasi as [$bayar, $refund]) {
            $order = $this->order(10_000_000);
            $this->pay($order, $bayar);
            if ($refund > 0) {
                $this->pay($order, $refund, 'refund');
            }

            $php = $order->fresh()->paidNet();
            $sql = (int) DB::table('tb_orders')->where('id', $order->id)
                ->selectRaw(Order::PAID_NET_SQL . ' as net')->value('net');

            $this->assertSame($php, $sql, "SQL dan PHP harus sepakat (bayar $bayar, refund $refund).");
        }
    }

    /** @test */
    public function approved_scope_is_gone(): void
    {
        // Jebakan lama: nama "approved" mengundang pemakaian utk menjumlahkan uang.
        $this->expectException(\BadMethodCallException::class);
        Payment::query()->approved();
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=PaidNetTest`
Expected: **FAIL**. `paidNet()` belum ada → `BadMethodCallException`/`Error: Call to undefined method`; `Order::PAID_NET_SQL` belum ada → `Undefined constant`; `approved_scope_is_gone` gagal karena scope masih ada. `no_refund_is_unaffected` & `lunas_invoice_shortcut_still_wins` juga gagal (memanggil `paidNet()`).

- [ ] **Step 3: Tambah `scopeRefund`, hapus `scopeApproved`**

Di `app/Models/Payment.php`: **hapus seluruh** method `scopeApproved()` (baris 44-47, termasuk docblock bila ada). Lalu tambahkan `scopeRefund` tepat setelah `scopeIncome()`:

```php
    /** Refund yang sudah dieksekusi (uang keluar). Pasangan income(). */
    public function scopeRefund($query)
    {
        return $query->where('status', 'paid')->where('payment_type', 'refund');
    }
```

- [ ] **Step 4: Tambah `paidNet()` + `PAID_NET_SQL` + pakai di `isLunas()`**

Di `app/Models/Order.php`, ganti seluruh `isLunas()` (baris 50-59) dengan:

```php
    /**
     * Uang bersih yang diterima untuk order ini: pembayaran masuk - refund.
     * Dipakai semua pertanyaan "sudah dibayar berapa" (lunas, sisa, arsip).
     * BEDA dari Payment::income() (pelaporan, refund dikecualikan) — lihat
     * docs/superpowers/specs/2026-07-17-paid-net-refund-design.md.
     */
    public function paidNet(): int
    {
        return (int) $this->payments()->income()->sum('amount')
             - (int) $this->payments()->refund()->sum('amount');
    }

    /** Versi SQL dari paidNet() untuk filter di query (harus setara — dikunci PaidNetTest). */
    public const PAID_NET_SQL = "(SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) FROM tb_payments WHERE tb_payments.order_id = tb_orders.id AND tb_payments.status = 'paid')";

    /** Lunas bila ada invoice berstatus 'lunas' atau uang bersih >= biaya. */
    public function isLunas(): bool
    {
        if ($this->invoices()->where('status', 'lunas')->exists()) {
            return true;
        }

        return $this->paidNet() >= (int) optional($this->details)->cost_amount;
    }
```

> Konstanta harus berada di dalam class `Order` (boleh di atas method — PHP tak peduli urutan), namun letakkan bersebelahan dengan `paidNet()` agar keduanya dibaca bersamaan.

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=PaidNetTest`
Expected: **PASS**, 7 test.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Payment.php app/Models/Order.php tests/Feature/PaidNetTest.php
git commit -F <path-pesan>
```

Isi pesan:

```
fix(payment): refund mengurangi "sudah dibayar" (Order::paidNet)

Refund berstatus paid ikut terjumlah sebagai pembayaran, sehingga order
10jt yang dibayar 10jt lalu direfund 4jt terbaca "sudah bayar 14jt" dan
dianggap LUNAS - padahal bersihnya 6jt.

Order::paidNet() = income - refund, dipakai isLunas(). Definisi KETIGA
(bukan income()): satu aturan benar utk pembatalan (belum lunas) DAN
kelebihan bayar (tetap lunas). Versi SQL jadi konstanta bersebelahan,
kesetaraannya dikunci test.

Payment::scopeApproved() dihapus - nol pemanggil, dan namanya jebakan
yang melahirkan bug ini.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Pindahkan 6 pemanggil tersisa

**Files:**
- Modify: `app/Http/Controllers/Pages/InvoiceController.php:50` dan `:100`
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php:72` dan `:249`
- Modify: `app/Http/Controllers/Pages/OrderBookController.php:336-340`
- Modify: `app/Http/Controllers/Pages/FullPaymentBookController.php:21`

- [ ] **Step 1: `InvoiceController` (2 titik)**

Baris 50 (`edit`):

```php
        $alreadyPaid      = $invoice->order->paidNet();
```

Baris 100 (penetapan status order):

```php
                $paid  = $order->paidNet();
```

- [ ] **Step 2: `PaymentBookController` (2 titik)**

Baris 72:

```php
        $alreadyPaid = $order->paidNet();
```

Baris 249:

```php
            $paid  = $order->paidNet();
```

- [ ] **Step 3: `OrderBookController` (1 titik)**

Ganti baris 336-340 (`$alreadyPaid = $order->payments->where(...)->sum('amount');` yang terpecah beberapa baris) menjadi:

```php
        $alreadyPaid = $order->paidNet();
```

Hapus juga komentar usang di atasnya ("Pastikan hanya menjumlahkan pembayaran yang statusnya 'paid' atau 'approved'") — menyesatkan, dan `approved()` sudah tak ada.

- [ ] **Step 4: `FullPaymentBookController` (SQL mentah)**

Tambah import di bagian `use` bila belum ada: `use App\Models\Order;` (periksa dulu — kemungkinan sudah ada karena file ini memakai `Order::with(...)`).

Ganti baris 21:

```php
                $query->whereRaw(Order::PAID_NET_SQL . ' >= tb_order_details.cost_amount')
```

- [ ] **Step 5: Jalankan suite penuh**

Run: `php artisan test`
Expected: PASS semua (**521** = 514 + 7 test baru).

**Bila test lama GAGAL:** kemungkinan ia mengunci perilaku lama (refund bikin lunas). Itu **temuan, bukan gangguan** — baca test itu, pastikan perilaku barunya memang benar, perbaiki, dan **sebutkan di laporan**. Jangan sesuaikan diam-diam sampai hijau.

- [ ] **Step 6: Pastikan tak ada sisa penjumlahan mentah**

Run: `grep -rn "where('status', 'paid')" app/ --include=*.php`

Yang **boleh tersisa**: `InvoiceController:47` (daftar pilihan, bukan penjumlahan), `RefundController:24` (batas maksimal refund — sengaja kotor), `Payment::scopeIncome`/`scopeRefund` (definisinya sendiri). Bila ada penjumlahan `sum('amount')` lain yang menjawab "sudah dibayar berapa", **laporkan** sebelum mengubah.

- [ ] **Step 7: Blade tetap sehat**

Run: `php artisan view:cache && php artisan view:clear`
Expected: "Blade templates cached successfully." tanpa error.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/InvoiceController.php app/Http/Controllers/Pages/PaymentBookController.php app/Http/Controllers/Pages/OrderBookController.php app/Http/Controllers/Pages/FullPaymentBookController.php
git commit -F <path-pesan>
```

Isi pesan:

```
fix(payment): 6 pemanggil "sudah dibayar" pindah ke Order::paidNet()

Form invoice, penetapan status lunas, halaman bayar, detail order, dan
daftar Pelunasan (SQL mentah) semuanya menjumlahkan status=paid tanpa
memisahkan refund. Kini lewat paidNet() - satu definisi, refund
dikurangkan. Daftar Pelunasan pakai Order::PAID_NET_SQL agar versi SQL
dan PHP tak berpisah.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 3: Verifikasi arsip + tutup

**Files:** tak ada perubahan kode.

- [ ] **Step 1: Buktikan gerbang arsip ikut terkunci**

Tambahkan ke `tests/Feature/PaidNetTest.php`:

```php
    /** @test */
    public function refunded_order_not_archive_eligible(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');

        $title = Title::create([
            'name' => 'Judul Arsip Uji', 'jenis' => 'buku', 'status' => 'disetujui',
            'asal' => 'order', 'code' => 'JAU-' . uniqid(),
        ]);
        $order->details->update(['title_id' => $title->id]);

        $this->assertFalse($title->fresh()->isPaidOff(), 'Order direfund → judul belum lunas → tak layak arsip.');
    }
```

Run: `php artisan test --filter=PaidNetTest`
Expected: PASS, 8 test.

> Bila `Title::create` menolak karena kolom wajib lain, baca `app/Models/Title.php` + migrasi `tb_titles` dan lengkapi — jangan melemahkan assertion-nya.

- [ ] **Step 2: Suite penuh**

Run: `php artisan test`
Expected: PASS semua (**522** = 514 + 8).

- [ ] **Step 3: Commit + centang kedua plan**

```bash
git add tests/Feature/PaidNetTest.php docs/superpowers/plans/2026-07-17-paid-net-refund.md docs/superpowers/plans/2026-07-17-income-definition-refund.md
git commit -F <path-pesan>   # test(payment): gerbang Arsip Judul menolak order yang direfund
```

---

## Self-Review

- **Cakupan spec:** `scopeRefund` (T1 S3) · hapus `scopeApproved` §3 (T1 S3 + test T1 S1) · `paidNet` + `PAID_NET_SQL` §2 (T1 S4) · `isLunas` (T1 S4) · 7 pemanggil §4 (T1 S4 utk isLunas + T2 S1-S4 utk 6 sisanya) · `InvoiceController:47` & `RefundController:24` dibiarkan (T2 S6 menegaskan) · 7 test §5 (T1 S1) + `refunded_order_not_archive_eligible` (T3 S1) · regresi (T2 S5). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `scopeRefund` → dipanggil `$this->payments()->refund()` (konvensi scope Laravel); `paidNet(): int` dipanggil tanpa argumen di 7 titik; `Order::PAID_NET_SQL` dipakai identik di `FullPaymentBookController` (T2 S4) dan test kesetaraan (T1 S1); nama test `sql_and_php_agree_on_paid_net` konsisten dgn spec §5.
- **Catatan:** `refunded_order_not_archive_eligible` ditaruh di Task 3 (bukan Task 1) karena butuh `paidNet()` sudah ada **dan** fixture `Title` yang lebih berat; memisahkannya menjaga fase merah Task 1 tetap fokus.
