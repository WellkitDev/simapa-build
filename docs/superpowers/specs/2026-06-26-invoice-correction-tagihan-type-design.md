# Spec — Edit Invoice Koreksi Pembayaran + Tagihan Tipe 4 Layanan

- **Tanggal:** 2026-06-26
- **Branch:** `ui-consistency` (revisi sebelum merge)
- **Scope:** (1) Halaman **Edit Invoice** dapat mengoreksi **nominal & status pembayaran** (Payment terkait), bukan hanya tanggal. (2) Field **Tipe** di **Tagihan create** menyediakan 4 layanan (buku/jurnal × mandiri/kolaborasi) + meneruskannya ke order.
- **Di luar scope (sengaja):** migrasi data tagihan lama (tetap kompatibel), perubahan alur approval pembayaran, perubahan status invoice (lunas/dibatalkan/refund tetap di halaman show).

---

## 1. Latar Belakang

`Invoice` ber-relasi ke `Payment` via `payment_id`. `Payment` menyimpan `amount` + `payment_type` (`dp`/`lunas`/`pelunasan`) + `status` (`paid`/`pending`/`rejected`). Edit Invoice saat ini hanya mengubah `invoice_no`, `issued_at`, `due_at`, `note`, `payment_id` — sehingga **salah input nominal/jenis pembayaran tak bisa diperbaiki** dari sana. `PaymentBookController@update` bisa koreksi `amount`, tapi hanya saat approval `pending`. Keputusan: izinkan koreksi **kapan saja** (manager/superadmin) lewat Edit Invoice, dengan hitung-ulang status order.

`Tagihan.type` saat ini `buku`/`jurnal` (validasi `in:buku,jurnal`), dipakai untuk routing "jadi order" (`type === 'jurnal' ? journal : book`). Superadmin tak tahu layanan spesifik (mandiri/kolaborasi). Tambahkan 4 nilai layanan yang **sama dengan `OrderDetail.type`** (`bk_mandiri`/`bk_kolab`/`at_mandiri`/`at_kolab`).

## 2. Tujuan & Kriteria Sukses

1. Manager/superadmin dapat memperbaiki **nominal (`amount`)** dan **jenis (`payment_type`)** pembayaran terkait dari Edit Invoice; status order dihitung ulang; perubahan tercatat (InvoiceLog).
2. Invoice tanpa payment terkait tetap bisa diedit field invoice-nya (bagian pembayaran tampil "belum ada").
3. Tagihan create menyediakan 4 tipe layanan; routing "jadi order" benar (buku→book, jurnal→journal) dan **kompatibel** dengan nilai legacy `buku`/`jurnal`.
4. Tipe tagihan ter-prefill ke form order saat "jadi order".
5. Perilaku tertutup test; suite tetap hijau (276 + test baru).

---

## 3. Desain — Revisi #1 (Edit Invoice koreksi pembayaran)

### 3.1 View `payments/invoices/edit.blade.php` (gaya NobleUI, sudah)

Tambah kartu/seksi **"Pembayaran Terkait"** sebelum/sesudah seksi invoice:
- Bila `$invoice->payment` ada:
  - **Total Pembayaran (Rp)** — `<input type="number" name="amount" value="{{ old('amount', $invoice->payment->amount) }}" required min="1">`.
  - **Status Pembayaran** — `<select name="payment_type">` opsi `dp` (DP), `lunas` (Lunas), `pelunasan` (Pelunasan), preselect `$invoice->payment->payment_type`.
  - **Konteks read-only** (disabled): Total Biaya order (`$totalCost`), Sudah terbayar (`$alreadyPaid`), Sisa (`$remainingBalance`) — dihitung di controller dari order.
- Bila tidak ada payment terkait → tampil keterangan "Belum ada pembayaran terkait untuk invoice ini." (tanpa field amount/payment_type).
- Field invoice tetap: `invoice_no`, `issued_at`, `due_at`, `note`. (Dropdown `payment_id` boleh tetap untuk menautkan, namun koreksi nominal/status adalah inti.)

### 3.2 Controller `InvoiceController`

- **`edit($id)`** (sudah gate manager|superadmin): muat `Invoice::with('order.details','payment')`, hitung konteks dari order: `$totalCost = $invoice->order->details->cost_amount ?? 0`; `$alreadyPaid = $invoice->order->payments()->where('status','paid')->sum('amount')`; `$remainingBalance = max($totalCost - $alreadyPaid, 0)`. Kirim ke view bersama `$orders`/`$payments` yang sudah ada.
- **`update(Request,$id)`** (gate manager|superadmin):
  - Validasi field invoice (seperti sekarang) + bila `$invoice->payment_id` ada: `'amount' => 'required|numeric|min:1'`, `'payment_type' => 'required|in:dp,lunas,pelunasan'`.
  - Dalam `DB::transaction`: update invoice (`invoice_no`,`issued_at`,`due_at`,`note`,`payment_id`); bila ada payment terkait → `$payment->update(['amount'=>..., 'payment_type'=>...])`; **hitung ulang status order**: `$paid = $order->payments()->where('status','paid')->sum('amount'); $order->update(['status' => ($cost - $paid) <= 0 ? 'lunas' : 'pending']);` (meniru `PaymentBookController@update`); buat `InvoiceLog` (`from_status`=`to_status`=status invoice sekarang, note "Koreksi pembayaran: nominal/jenis diperbarui"). 
  - Redirect `invoice.show` dengan sukses.
- **Tanpa** gerbang approval (koreksi kapan saja). Bila invoice tak punya payment, lewati blok payment (hanya update invoice).

> Catatan: mengoreksi pembayaran yang sudah disetujui mengubah pemasukan tercatat — sengaja diizinkan untuk manager/superadmin, dengan jejak InvoiceLog.

## 4. Desain — Revisi #2 (Tagihan Tipe 4 layanan)

### 4.1 Nilai & label
Tipe layanan (sama dengan `OrderDetail.type`):
| value | label |
|---|---|
| `bk_mandiri` | Buku Mandiri |
| `bk_kolab` | Buku Kolaborasi |
| `at_mandiri` | Jurnal Mandiri |
| `at_kolab` | Jurnal Kolaborasi |

Helper label tunggal (mis. di view via `@php $typeLabels = [...]; @endphp` atau accessor `Tagihan::typeLabel()`) yang juga memetakan **legacy** `buku`→"Buku", `jurnal`→"Jurnal".

### 4.2 Controller `TagihanController`
- `validateData`: `'type' => 'required|in:bk_mandiri,bk_kolab,at_mandiri,at_kolab'`.
- Routing "jadi order" (yang sekarang `type === 'jurnal' ? 'order.journal.create' : 'order.book.create'`): jadi `$isJurnal = in_array($tagihan->type, ['at_mandiri','at_kolab','jurnal'], true); $route = $isJurnal ? 'order.journal.create' : 'order.book.create';` (legacy `jurnal` tetap → journal; `buku` & semua `bk_*` → book).
- Saat redirect ke order create dari tagihan, sertakan tipe agar bisa di-prefill (lewat query/`from_tagihan` yang sudah ada — order create membaca tagihan via `from_tagihan`, jadi prefill `type` diambil dari `$t->type` di controller order).

### 4.3 View `payments/tagihan/create.blade.php`
Dropdown Tipe: 4 `<option>` dengan value+label di §4.1 (default tetap valid; bila edit tagihan legacy, preselect nilai lama bila ada). Hapus/ganti sumber `$types` lama agar berisi 4 layanan.

### 4.4 Prefill type ke order
- `OrderBookController@create` & `OrderJournalController@create`: saat `from_tagihan` valid, tambahkan `'type' => $t->type` ke array `$prefill` (hanya bila `$t->type` ∈ nilai order yang valid untuk form tsb; jurnal create hanya `at_*`, book create hanya `bk_*`; bila legacy/tidak cocok, biarkan kosong).
- `orders/book/create.blade.php` & `orders/journal/create.blade.php`: select `type` preselect `old('type', $prefill['type'] ?? '')`.

### 4.5 Display label
`payments/tagihan/index.blade.php` & `show.blade.php`: tampilkan label tipe via map §4.1 (+ legacy), bukan raw value.

## 5. Komponen yang Disentuh

- **Diubah:** `app/Http/Controllers/Pages/InvoiceController.php` (edit/update), `resources/views/payments/invoices/edit.blade.php`; `app/Http/Controllers/Pages/TagihanController.php` (validateData + routing + prefill source), `resources/views/payments/tagihan/{create,index,show}.blade.php`; `app/Http/Controllers/Pages/OrderBookController.php` + `OrderJournalController.php` (`create` prefill `type`), `resources/views/orders/{book,journal}/create.blade.php` (preselect type).

## 6. Rencana Test

- **Feature `InvoicePaymentCorrectionTest` (baru)**: manager `PUT invoice.update` dengan `amount`+`payment_type` baru → payment terkait ter-update (assert) + status order dihitung ulang (mis. amount < cost → order `pending`; amount ≥ cost → `lunas`); InvoiceLog bertambah; non-manager → 403; invoice tanpa payment → update field invoice sukses tanpa error.
- **Feature `TagihanTypeTest` (baru)**: tagihan create dengan `type=bk_kolab` tersimpan; "jadi order" untuk `at_*` redirect ke `order.journal.create`, untuk `bk_*` ke `order.book.create`, legacy `jurnal`→journal & `buku`→book; (opsional) order create dari tagihan `bk_kolab` menampilkan type terpilih.
- **Regression:** seluruh suite tetap hijau (`DetailOrderPaymentInvoiceTest`, `TagihanLifecycleTest`, dll.). `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Tanpa migrasi** (kompatibel mundur dengan nilai tagihan lama).

## 7. Asumsi & Risiko

- "Status Pembayaran" = `payment_type` (DP/Lunas/Pelunasan), konsisten dengan form Payment Create.
- Koreksi pembayaran yang sudah disetujui mengubah pemasukan tercatat — sengaja (manager/superadmin), tercatat di InvoiceLog; hitung-ulang status order menjaga konsistensi.
- Tagihan `type` baru memakai nilai sama dengan `OrderDetail.type` → prefill order langsung cocok; legacy `buku`/`jurnal` tetap didukung di routing & label tanpa migrasi.
- Tak menyentuh template PDF maupun alur approval pembayaran.
