# Spec — Akuntansi Fase B: Auto-flow Payment → Jurnal Kas

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-payment-sync`
- **Scope (Fase B):** Saat Payment berstatus `paid`, otomatis buat entri kas (`source=payment`, idempotent per payment) tanpa ketik ulang. DP/Pelunasan → pemasukan; Refund → pengeluaran. Sinkron saat payment diubah; hapus entri saat payment tak `paid`/dihapus. Entri auto **read-only** di Jurnal Kas.
- **Di luar scope:** backfill payment lama (maju-saja agar tak dobel dgn Saldo Awal); rekap/dashboard (Fase C); pengeluaran non-payment tetap manual.

> Lanjutan Akuntansi Fase A. Payment jadi `paid` di `PaymentBookController::store` (dibuat langsung paid) & `approve` (pending→paid), diubah di `update`, ditolak jadi `rejected`. Sumber data `data-excel/` tetap **gitignored**.

---

## 1. Tujuan & Kriteria Sukses

1. Payment `paid` (dp/pelunasan) → satu entri kas **pemasukan** (kategori sesuai tipe order, produk artikel/buku, amount, ref invoice/order, tanggal=paid_at); refund `paid` → entri **pengeluaran** (kategori "Refund").
2. Idempotent: satu entri per payment (`payment_id` unik) — approve/update payment memperbarui entri yang sama, tak menduplikasi.
3. Payment berubah ke non-`paid` (rejected/pending) atau dihapus → entri kas terkait hilang.
4. Entri `source=payment` tak dapat diedit/hapus manual di Jurnal Kas (badge "auto").
5. Observer null-safe (payment tanpa order/details tak melempar; jalan di dalam transaksi payment). Suite tetap hijau.

## 2. Skema — migrasi `2026_07_04_000005_add_payment_sync_to_cash.php`

- `tb_cash_entries`: tambah `payment_id` unsignedBigInt nullable **unique** FK → `tb_payments` `cascadeOnDelete` (hapus payment → entri ikut terhapus; NULL untuk entri manual).
- `tb_cash_categories`: tambah `map_key` string nullable.
- **Backfill map_key** (by name, dari seed Fase A): `Order Artikel Kolaborasi`→`at_kolab`, `Order Artikel Mandiri`→`at_mandiri`, `Order Buku Kolaborasi`→`bk_kolab`, `Order Buku Mandiri`→`bk_mandiri`.
- **Seed** kategori pengeluaran **"Refund"** (`jenis=pengeluaran`, `map_key=refund`, position 7, active).

`CashEntry` `$fillable` +`payment_id`. `CashCategory` `$fillable` +`map_key`.

## 3. Logika — `PaymentCashSyncService`

`sync(Payment $payment): void`:
```
if ($payment->status !== 'paid') { CashEntry::where('payment_id',$payment->id)->delete(); return; }
$order  = $payment->order;                 // belongsTo (null-safe)
$detail = optional($order)->details;       // hasOne OrderDetail
$type   = optional($detail)->type;         // bk_mandiri|bk_kolab|at_mandiri|at_kolab
$refund = $payment->payment_type === 'refund';
$jenis  = $refund ? 'pengeluaran' : 'pemasukan';
$produk = $type ? (str_starts_with($type,'bk_') ? 'buku' : (str_starts_with($type,'at_') ? 'artikel' : null)) : null;
$mapKey = $refund ? 'refund' : $type;
$catId  = $mapKey ? optional(CashCategory::where('map_key',$mapKey)->first())->id : null;
$ref    = optional($payment->invoice)->invoice_no ?: optional($order)->code_order;
$tgl    = $payment->paid_at ?: now();
$ket    = trim(ucfirst((string)$payment->payment_type) . ' — ' . ($ref ?: '-') . ' — ' . (optional($detail)->title ?? optional($order)->code_order ?? ''));
CashEntry::updateOrCreate(['payment_id'=>$payment->id], [
    'tanggal'=>$tgl, 'jenis'=>$jenis, 'amount'=>$payment->amount, 'cash_category_id'=>$catId,
    'produk'=>$produk, 'ref'=>$ref, 'keterangan'=>$ket, 'source'=>'payment',
    'kode'=>app(CashJournalService::class)->deriveKode(\Carbon\Carbon::parse($tgl)),
]);
```
> Defensif: semua akses relasi `optional()`; tak melempar → aman di dalam transaksi `store`/`approve`.

## 4. Trigger — `PaymentObserver` (didaftarkan di `AppServiceProvider::boot`)

```php
class PaymentObserver {
    public function saved(Payment $p): void   { app(PaymentCashSyncService::class)->sync($p); }
    public function deleted(Payment $p): void  { CashEntry::where('payment_id', $p->id)->delete(); }
}
// AppServiceProvider::boot(): Payment::observe(PaymentObserver::class);
```
`saved` menangkap create (store paid), update (approve pending→paid, ubah nominal), dan perubahan status (→rejected/pending → sync menghapus). `deleted` + FK cascade jaga penghapusan.

## 5. View — Jurnal Kas (`accounting/journal.blade.php`)

Entri `source=payment`: badge kecil **"⚙ auto"** di kolom Keterangan/Aksi; **sembunyikan tombol Hapus** (dan tak ada aksi edit). Entri manual tetap punya Hapus. (Ubah entri auto hanya lewat modul Payment.)

## 6. Rencana Test

- **Feature/Unit `PaymentCashSyncTest`** (Drive di-mock; kategori dari seed + backfill migrasi):
  - `paid_payment_creates_income_entry`: buat Order(+detail `at_kolab`)+Invoice + Payment(dp, paid, amount) → entri kas: jenis pemasukan, kategori map_key `at_kolab`, produk artikel, amount sama, source payment, payment_id terisi, ref=invoice_no.
  - `refund_creates_expense_entry`: payment_type refund, paid → entri pengeluaran, kategori map_key `refund`.
  - `approve_then_update_syncs_one_entry`: payment pending → tak ada entri; update status paid → 1 entri; ubah amount → entri ikut (tetap 1).
  - `not_paid_or_deleted_removes_entry`: payment paid (entri ada) → set rejected → entri hilang; buat lagi paid lalu delete payment → entri hilang.
  - `null_safe_without_order_details`: payment paid tanpa OrderDetail → entri tetap dibuat (kategori/produk null), tak error.
  - `auto_entry_is_readonly_in_journal`: `GET accounting.journal` (accounting) menampilkan entri auto **tanpa** tombol hapus (assertDontSee route destroy utk entri itu / assertSee badge "auto").
- **Regresi**: `PaymentBookController`/payment tests, TitleArchive tests (yang buat Payment) tetap hijau; observer tak memutus transaksi. `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (kolom payment_id + map_key + seed Refund). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `2026_07_04_000005`; `app/Services/PaymentCashSyncService.php`; `app/Observers/PaymentObserver.php`; test `PaymentCashSyncTest`.
- **Diubah:** `app/Models/CashEntry.php` (+payment_id fillable + relasi `payment()`); `app/Models/CashCategory.php` (+map_key fillable); `app/Providers/AppServiceProvider.php` (register observer); `resources/views/accounting/journal.blade.php` (lock entri auto).
- **Tak diubah:** `PaymentBookController` (observer menangkap tanpa mengubah alur); `CashJournalService` (dipakai untuk deriveKode).

## 8. Asumsi & Risiko

- Maju-saja: hanya payment yang di-`save` setelah fitur aktif tersinkron; payment lama tak dibackfill (hindari dobel dgn Saldo Awal). Backfill manual (artisan) bisa ditambah kelak.
- Idempotensi via `payment_id` unik + `updateOrCreate`. FK `cascadeOnDelete` + observer `deleted` menjaga penghapusan.
- Observer null-safe & idempotent → aman dipanggil berkali-kali dalam transaksi payment; tak memengaruhi tes payment existing (mereka tak memeriksa entri kas; kategori tersedia dari migrasi seed).
- Refund = uang keluar → pengeluaran kategori "Refund". DP & Pelunasan sama-sama pemasukan (uang diterima).
- Entri auto read-only → konsistensi dgn Payment; koreksi lewat Payment.
