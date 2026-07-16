# Spec — Uang Bersih per Order (refund mengurangi "sudah dibayar")

- **Tanggal:** 2026-07-17
- **Branch:** `income-definition-refund` (lanjutan langsung; keluarga bug yang sama)
- **Scope:** `Order::paidNet()` = pembayaran masuk − refund, dipakai semua tempat yang bertanya *"pelanggan sudah bayar berapa untuk order ini?"* (7 titik, termasuk 1 lewat SQL mentah). Plus hapus `Payment::scopeApproved()` yang kini nol pemanggil.
- **Di luar scope:** aturan/alur refund; UI; audit refund bulanan; koreksi data historis.
- **Keputusan user:** order yang direfund sebagian **belum lunas** (pakai bersih) · "langsung garap karena masih terkait bug".

## Masalah (keluarga bug yang sama, ditemukan saat tinjauan pasca-perbaikan)

Setelah `Payment::income()` memperbaiki sisi *pelaporan*, tinjauan sisa pemakai menemukan bug kembar di sisi *kelunasan*. Tujuh tempat menjumlahkan `where('status','paid')` **tanpa** memisahkan refund — padahal refund juga berstatus `paid`. Akibatnya refund membuat order tampak **lebih terbayar**:

Order 10jt, dibayar 10jt, direfund 4jt → `paid` = **14jt** ≥ 10jt → **dianggap LUNAS**, padahal bersihnya 6jt.

| Titik | Akibat |
|---|---|
| `Order::isLunas():56` | Order direfund tetap "lunas" → **gerbang kelayakan Arsip Judul** (`Title::isPaidOff` → `archiveEligible`) meloloskannya |
| `InvoiceController:50` | `alreadyPaid` besar → `remainingBalance` kecil di form invoice |
| `InvoiceController:100` | Menetapkan `order.status = lunas` secara salah |
| `PaymentBookController:72` | `remainingBalance` kecil → halaman bayar redirect "sudah lunas" padahal belum |
| `PaymentBookController:249` | Menetapkan `order.status = lunas` secara salah |
| `OrderBookController:338` | `alreadyPaid`/`remainingBalance` salah di detail order |
| `FullPaymentBookController:21` | Daftar **Pelunasan** (SQL mentah) memuat order yang belum lunas |

Catatan cakupan: `isLunas()` lebih dulu memeriksa `invoices()->where('status','lunas')->exists()` dan langsung `true` — jadi bug ini menggigit order yang **belum** punya invoice lunas. Tetap salah, hanya lebih sempit dari kelihatannya.

## Keputusan: bersih (paid − refund), bukan kotor

Satu aturan menangani **dua** kasus dengan benar sekaligus — inilah alasan memilih bersih, bukan sekadar meniru `income()`:

| Kasus | Bersih | Hasil |
|---|---|---|
| **Pembatalan sebagian** — bayar 10jt, refund 4jt, biaya 10jt | 6jt < 10jt | belum lunas ✓ |
| **Kelebihan bayar** — bayar 14jt, refund 4jt, biaya 10jt | 10jt ≥ 10jt | tetap lunas ✓ |
| **Refund penuh** — bayar 10jt, refund 10jt | 0 < 10jt | belum lunas ✓ |

Aturan "kecualikan refund" (seperti `income()`) benar untuk kelebihan bayar tapi **salah** untuk pembatalan (refund penuh pun tetap "lunas" + bisa diarsipkan). Karena itu ini definisi **ketiga**, bukan pemakaian ulang `income()`.

## Tiga definisi, sengaja berbeda

| Pertanyaan | Alat | Refund |
|---|---|---|
| Berapa uang masuk? (Laporan Keuangan, Dashboard/Target Marketing) | `Payment::income()` | dikecualikan (kotor, cocok dgn Jurnal Kas) |
| Sudah bayar berapa? (lunas, sisa tagihan, arsip) | `Order::paidNet()` | **dikurangkan** |
| Pembayaran ini disetujui? | — | scope dihapus, lihat §3 |

Refund tetap jadi **pengeluaran** di Jurnal Kas (prinsip user: "jika ada refund maka ada pengeluaran") — tak tersentuh.

## 1. Komponen — `App\Models\Payment`

Tambah scope refund (pasangan `income()`), setelah `scopeIncome`:

```php
/** Refund yang sudah dieksekusi (uang keluar). Pasangan income(). */
public function scopeRefund($query)
{
    return $query->where('status', 'paid')->where('payment_type', 'refund');
}
```

## 2. Komponen — `App\Models\Order`

`paidNet()` **dan** ekspresi SQL-nya hidup bersebelahan, sengaja: `FullPaymentBookController` memfilter lewat SQL mentah (tak bisa memanggil method PHP), dan dua versi definisi yang sama yang tinggal berjauhan adalah persis penyakit yang sedang diobati.

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

/** Versi SQL dari paidNet() untuk filter di query (harus setara — dikunci test). */
public const PAID_NET_SQL = "(SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) FROM tb_payments WHERE tb_payments.order_id = tb_orders.id AND tb_payments.status = 'paid')";
```

`isLunas()` memakainya (jalan pintas invoice `lunas` **dipertahankan** — di luar scope):

```php
public function isLunas(): bool
{
    if ($this->invoices()->where('status', 'lunas')->exists()) {
        return true;
    }
    $cost = (int) optional($this->details)->cost_amount;
    return $this->paidNet() >= $cost;
}
```

## 3. Hapus `Payment::scopeApproved()`

Setelah 4 pemanggil pindah ke `income()`, `grep -rn "approved()" app/` = **nol**; `tests/` juga nol. Premis spec sebelumnya ("dipertahankan untuk konteks non-pemasukan seperti cek lunas") terbukti **keliru** — cek lunas memakai `where('status','paid')` inline, tak pernah lewat scope.

Dihapus karena **jebakan**: namanya ("disetujui") mengundang pemakaian untuk menjumlahkan uang, dan bug ini lahir persis begitu. Kode mati yang menyesatkan lebih berbahaya daripada tidak ada. Bila kelak butuh, `income()`/`refund()` sudah menyediakan makna yang tegas.

## 4. Pemanggil yang pindah ke `paidNet()`

| File | Baris | Lama | Baru |
|---|---|---|---|
| `Order::isLunas` | 56 | `payments()->where('status','paid')->sum('amount')` | `$this->paidNet()` |
| `InvoiceController` | 50 | `$invoice->order->payments()->where('status','paid')->sum('amount')` | `$invoice->order->paidNet()` |
| `InvoiceController` | 100 | `$order->payments()->where('status','paid')->sum('amount')` | `$order->paidNet()` |
| `PaymentBookController` | 72 | `$order->payments->where('status','paid')->sum('amount')` | `$order->paidNet()` |
| `PaymentBookController` | 249 | `$order->payments()->where('status','paid')->sum('amount')` | `$order->paidNet()` |
| `OrderBookController` | 336-340 | `$order->payments->where('status','paid')->sum('amount')` | `$order->paidNet()` |
| `FullPaymentBookController` | 21 | `whereRaw('(SELECT SUM(amount) ... status = "paid") >= ...')` | `whereRaw(Order::PAID_NET_SQL . ' >= tb_order_details.cost_amount')` |

`InvoiceController:47` (`Payment::where('status','paid')->get()` — daftar pilihan, bukan penjumlahan) **dibiarkan**. `RefundController:24` (`paidIn` = batas maksimal refund, sengaja kotor, refund sekali saja) **dibiarkan** — pertanyaannya beda.

## 5. Testing — `tests/Feature/PaidNetTest.php` (baru)

- `partial_refund_makes_order_not_lunas`: biaya 10jt, bayar 10jt, refund 4jt → `paidNet()` = 6jt, `isLunas()` **false**.
- `full_refund_makes_order_not_lunas`: bayar 10jt, refund 10jt → `paidNet()` = 0, `isLunas()` **false**.
- `overpayment_refund_stays_lunas`: biaya 10jt, bayar 14jt, refund 4jt → `paidNet()` = 10jt, `isLunas()` **true**. (Membuktikan satu aturan menangani dua kasus.)
- `refunded_order_not_archive_eligible`: `Title` bertaut order yang direfund sebagian → `isPaidOff()` **false**.
- `lunas_invoice_shortcut_still_wins`: order dgn invoice `lunas` → `isLunas()` **true** walau ada refund (jalan pintas dipertahankan, sengaja).
- **`sql_and_php_agree_on_paid_net`** (kunci): untuk beberapa kombinasi (tanpa refund / refund sebagian / refund penuh / kelebihan bayar), hasil `Order::PAID_NET_SQL` lewat query = `paidNet()` PHP. Mencegah dua versi definisi berpisah diam-diam.
- `approved_scope_is_gone`: `method_exists`/`Payment::query()->approved()` melempar `BadMethodCallException` — mengunci jebakan tak kembali diam-diam.

Regresi: suite penuh (514 + baru) hijau. **Bila test lama gagal karena mengunci perilaku lama (refund bikin lunas), itu temuan — laporkan, jangan diam-diam disesuaikan.**

## 6. Risiko

- **Order yang pernah direfund berubah status kelunasan** — itu tujuannya (status lama salah). Order tanpa refund tak bergeser sama sekali.
- **Data historis:** order yang terlanjur berstatus `lunas` di DB tidak ikut berubah oleh perubahan ini (status tersimpan, bukan dihitung ulang), kecuali saat pembayarannya disunting. Perlu tidaknya koreksi massal = **keputusan bisnis**, diangkat ke user, di luar scope.
- **`PAID_NET_SQL` menempel pada nama tabel** `tb_payments`/`tb_orders`/`tb_order_details` — sama seperti SQL yang digantikannya; dikunci `sql_and_php_agree_on_paid_net`.

## 7. Komponen

- **Diubah:** `app/Models/Payment.php` (+`scopeRefund`, −`scopeApproved`); `app/Models/Order.php` (+`paidNet`, +`PAID_NET_SQL`, `isLunas`); `InvoiceController.php` (2); `PaymentBookController.php` (2); `OrderBookController.php` (1); `FullPaymentBookController.php` (1).
- **Baru:** `tests/Feature/PaidNetTest.php`.
- **Tak diubah:** Jurnal Kas, `PaymentCashSyncService`, `RefundController`, `Payment::income()`, UI, skema (tanpa migrasi).
