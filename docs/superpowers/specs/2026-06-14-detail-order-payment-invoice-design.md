# Spec: Detail Order — Download Invoice, Edit Pembayaran, Tabel Invoice, Email saat Approve

**Tanggal:** 2026-06-14
**Status:** Draft — menunggu review
**Area:** Halaman Detail Order (`orders/book/show.blade.php`), Pembayaran, Invoice, Email Invoice

---

## Ringkasan

Empat perbaikan pada halaman **Detail Order** dan alur invoice/email:

1. **Download PDF per-invoice** — unduh PDF untuk invoice spesifik (by id), bukan hanya invoice pertama order. Tampilan PDF **tidak berubah**.
2. **Edit Pembayaran** — manager/superadmin bisa mengedit pembayaran (jumlah/jenis/tanggal/bukti) lewat modal, **hanya selama approval `pending`**.
3. **Tabel Daftar Invoice** — mengganti blok "Invoice Terakhir" dengan tabel berisi semua invoice order.
4. **Email invoice saat approve** — email invoice tidak lagi dikirim saat pembayaran disimpan (pending), melainkan **otomatis saat di-approve**, dan **data pembayaran di PDF hanya yang sudah approved**.

---

## Keputusan Desain (hasil brainstorming)

| Topik | Keputusan |
|-------|-----------|
| Sumber data tabel pembayaran di PDF | Hanya payment dengan `approval.status == 'approved'` (tabel **dan** total) |
| Tampilan/template PDF | **Tidak diubah** (pakai ulang `payments.invoices.book_invoice_pdf`) |
| Edit pembayaran — field | amount, payment_type (dp/lunas/pelunasan), paid_at, proof (opsional re-upload) |
| Edit pembayaran — kapan | Hanya selama `approval.status == 'pending'` |
| Edit pembayaran — siapa | manager + superadmin |
| Edit pembayaran — UI | Modal di halaman Detail Order |
| Tabel invoice | No \| No.Invoice \| Tanggal Terbit \| Jatuh Tempo \| Status \| Aksi (Download); ganti blok lama; terbaru di atas |
| Download invoice | Route baru by-id; tombol lama `payment.printInvoice` dipensiunkan |
| Kapan email dikirim | Saat **approve** (bukan saat store/pending) |
| Mengingat pilihan checkbox | Kolom baru `email_requested` (boolean) di `tb_invoices` |
| Penerima email | **Tetap** `order->contact->cp_email` (tidak diubah) |

---

## 1. Skema Database

### Modifikasi `tb_invoices` (non-destruktif)

Migrasi baru menambah satu kolom:

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| `email_requested` | boolean, default `false`, `after('status')` | Menandai apakah invoice ini diminta dikirim via email saat di-approve (dari checkbox di form pembayaran) |

Tambahkan `email_requested` ke `$fillable` pada `App\Models\Invoice`.

Tidak ada perubahan skema lain.

---

## 2. Filter "approved-only" untuk PDF (berlaku di semua titik generate PDF)

"Sudah di-approve" = payment yang punya relasi `approval` dengan `status == 'approved'`.

Saat memuat order untuk PDF, batasi relasi `payments`:

```php
'order.payments' => fn ($q) =>
    $q->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
      ->orderBy('paid_at', 'asc'),
```

Lalu hitung total dari subset itu:

```php
$alreadyPaid      = $order->payments->sum('amount'); // sudah approved-only
$remainingBalance = $totalCost - $alreadyPaid;
```

View `book_invoice_pdf.blade.php` **tidak diubah** (tetap me-loop `$order->payments` yang kini sudah approved-only; kolom Status tetap "Terbayar").

---

## 3. Download PDF per-invoice

### Route (grup `invoices`, name `invoice.`)

```php
Route::get('{id}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
```

### `InvoiceController@pdf(int $id)`

```php
public function pdf(int $id)
{
    $invoice = Invoice::with([
        'order.details.authors',
        'order.details.scopes',
        'order.contact',
        'order.payments' => fn ($q) =>
            $q->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
              ->orderBy('paid_at', 'asc'),
    ])
    ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
        $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())))
    ->findOrFail($id);

    $order  = $invoice->order;
    $detail = $order->details;
    abort_if(!$detail, 404, 'Detail order tidak ditemukan.');

    $totalCost        = $detail->cost_amount ?? 0;
    $alreadyPaid      = $order->payments->sum('amount');
    $remainingBalance = $totalCost - $alreadyPaid;

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('payments.invoices.book_invoice_pdf', compact(
        'invoice', 'order', 'detail', 'totalCost', 'alreadyPaid', 'remainingBalance'
    ));

    return $pdf->stream('Invoice_' . $invoice->invoice_no . '.pdf');
}
```

### Pensiunkan route lama

- Hapus route `payments/print/{code_order}` (`payment.printInvoice`) dan method `printInvoice` di `PaymentBookController` (satu-satunya pemakai adalah tombol yang dihapus di Bagian 5). `book_invoice_pdf` tetap dipakai oleh route baru & `SendInvoiceJob`.

---

## 4. Edit Pembayaran (modal)

### Route (grup `payments`, name `payment.`)

```php
Route::put('{id}', [PaymentBookController::class, 'update'])
    ->name('update')
    ->middleware('role:manager|superadmin');
```

### `PaymentBookController@update(Request $request, $id)`

```php
public function update(Request $request, $id)
{
    $payment = Payment::with(['approval', 'order.details'])->findOrFail($id);

    if (optional($payment->approval)->status !== 'pending') {
        return back()->with('error', 'Pembayaran sudah diproses, tidak bisa diedit.');
    }

    $validate = $request->validate([
        'amount'       => 'required|numeric|min:1',
        'payment_type' => 'required|in:dp,lunas,pelunasan',
        'paid_at'      => 'required|date',
        'proof_url'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
    ]);

    $strukUrl = $payment->proof_url;
    if ($request->hasFile('proof_url')) {
        $file = $request->file('proof_url');
        $year = \Carbon\Carbon::parse($validate['paid_at'])->format('Y');
        $folderId = $this->drive->getOrCreateFolderByPath("Application/struk_pembayaran/{$year}");
        if (!$folderId) {
            return back()->with('error', 'Gagal membuat folder Google Drive.');
        }
        $filename = $payment->order->contact->cp_email . "_struk." . $file->getClientOriginalExtension();
        $uploadResult = $this->drive->uploadFile($file, $folderId, true, $filename);
        if (!$uploadResult || !isset($uploadResult['url'])) {
            return back()->with('error', 'Gagal upload bukti. Coba lagi.');
        }
        $strukUrl = $uploadResult['url'];
    }

    DB::transaction(function () use ($payment, $validate, $strukUrl) {
        $payment->update([
            'amount'       => $validate['amount'],
            'payment_type' => $validate['payment_type'],
            'paid_at'      => $validate['paid_at'],
            'proof_url'    => $strukUrl,
        ]);

        // Hitung ulang status order dari pembayaran 'paid'
        $order = $payment->order;
        $cost  = $order->details->cost_amount ?? 0;
        $paid  = $order->payments()->where('status', 'paid')->sum('amount');
        $order->update(['status' => ($cost - $paid) <= 0 ? 'lunas' : 'pending']);
    });

    return back()->with('success', 'Pembayaran berhasil diperbarui.');
}
```

> Catatan: tidak menyinkronkan `issued_at`/`due_at` invoice dari pembayaran — tanggal invoice diedit terpisah lewat `InvoiceController@edit` (di luar lingkup).

---

## 5. Perubahan View `orders/book/show.blade.php`

### 5.1 Riwayat Pembayaran — tambah kolom Aksi + modal

- Tambah `<th>Aksi</th>` di header tabel; ubah `colspan` empty-state dari 6 → 7.
- Per baris pembayaran:
  - **Download Invoice** → `route('invoice.pdf', $payment->invoice->id)` (tampil bila `$payment->invoice` ada), `target="_blank"`.
  - **Edit** (hanya `@hasanyrole('manager|superadmin')` **dan** `optional($payment->approval)->status === 'pending'`) → tombol pembuka modal Bootstrap `#editPayment{{ $payment->id }}`.
  - Modal berisi form `method="POST"` + `@method('PUT')` + `@csrf` + `enctype="multipart/form-data"`, action `route('payment.update', $payment->id)`, field prefilled: `amount`, `payment_type` (select dp/lunas/pelunasan), `paid_at` (date), `proof_url` (file, opsional, dengan keterangan "kosongkan jika tidak diganti").
- Controller `OrderBookController@show`: tambahkan eager-load `payments.invoice` (relasi `payments.approval` sudah ada lewat `payments.approval`? — pastikan `with(['details.authors','details.scopes','payments.approval','payments.invoice','invoices','contact'])`).

### 5.2 Invoice Terakhir → Daftar Invoice (tabel)

Ganti isi card "Invoice Terakhir" dengan tabel:

```
No | No.Invoice | Tanggal Terbit | Jatuh Tempo | Status | Aksi
```

- Loop `$order->invoices->sortByDesc('issued_at')` (atau `sortByDesc('id')`).
- Kolom Tanggal Terbit = `issued_at` (format `d F Y`), Jatuh Tempo = `due_at`.
- Status = badge dari `$invoice->status` (peta warna: draft=secondary, diterbitkan=info, jatuh_tempo=warning, lunas=success, dibatalkan=danger, refund=dark).
- Aksi = tombol **Download** → `route('invoice.pdf', $invoice->id)`.
- Empty state bila `$order->invoices->isEmpty()`.
- Pertahankan indikator ringkas LUNAS / Menunggu Pelunasan (berdasar `$remainingBalance`) di header card.
- **Hapus** tombol lama "Download PDF Invoice" yang memakai `payment.printInvoice`.

---

## 6. Email invoice saat approve

### 6.1 `PaymentBookController@store`

- Hitung `$emailRequested = $request->boolean('send_invoice_email')` sebelum transaksi dan teruskan ke closure.
- Saat `Invoice::create([...])`, tambahkan `'email_requested' => $emailRequested`.
- **Hapus** blok pasca-transaksi:

```php
if ($request->boolean('send_invoice_email')) {
    SendInvoiceJob::dispatch($invoiceId);
}
```

(checkbox di `payments/book/create.blade.php` tetap, namanya tetap `send_invoice_email`).

### 6.2 `PaymentBookController@approve`

- Guard di awal (cegah dobel proses & dobel email):

```php
$payment = Payment::with(['approval', 'order'])->findOrFail($id);
if (optional($payment->approval)->status === 'approved') {
    return back()->with('info', 'Pembayaran sudah disetujui.');
}
```

- Di dalam transaksi (logika existing: set payment paid, approval approved, invoice lunas + log, order lunas bila pelunasan), tangkap id invoice yang perlu dikirim:

```php
$invoice = Invoice::where('payment_id', $payment->id)->first();
if ($invoice) {
    // ... update lunas + InvoiceLog (existing) ...
    if ($invoice->email_requested) {
        $invoiceToEmail = $invoice->id; // variabel di-pass by-reference ke closure
    }
}
```

- **Setelah** transaksi commit:

```php
if (!empty($invoiceToEmail)) {
    SendInvoiceJob::dispatch($invoiceToEmail);
}
```

### 6.3 `App\Jobs\SendInvoiceJob`

- Ubah eager-load `order.payments` menjadi approved-only (Bagian 2):

```php
$invoice = Invoice::with([
    'order.details.authors',
    'order.contact',
    'order.payments' => fn ($q) =>
        $q->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
          ->orderBy('paid_at', 'asc'),
])->find($this->invoiceId);
```

- `alreadyPaid = $invoice->order->payments->sum('amount')` (sudah approved-only).
- Penerima tetap `Mail::to($invoice->order->contact->cp_email)` (tidak diubah).
- Sisanya (generate PDF, simpan temp, upload Drive, kirim mail, cleanup) tetap.

---

## Non-Goal

- Template/tampilan PDF tidak diubah (hanya sumber datanya jadi approved-only).
- Penerima email tetap `cp_email` kontak order.
- Tidak mengubah alur pembuatan payment (`store` selain email), reject, atau approval selain penambahan email.
- Tidak menyinkronkan tanggal invoice dari pembayaran.
- Tidak mengedit pembayaran yang sudah approved/rejected.

---

## Edge Case

- **Payment tanpa invoice** (data lama) → tombol Download Invoice tidak ditampilkan di baris itu.
- **Approve ulang** invoice yang sudah approved → diblok di guard (`info`), email tidak dikirim dua kali.
- **`email_requested = false`** → approve tetap jalan, tanpa kirim email.
- **Edit saat bukan pending** → ditolak dengan pesan error (tombol Edit pun disembunyikan di view).
- **Marketing** mengunduh PDF invoice order orang lain → 404.
- **PDF tanpa pembayaran approved** (mis. semua masih pending) → tabel kosong, total terbayar 0, status MENUNGGU PELUNASAN.

---

## Pengujian

**Feature test (`tests/Feature`):**
- `store` dengan `send_invoice_email=1` → invoice `email_requested=true`, dan `Bus::fake()->assertNotDispatched(SendInvoiceJob::class)`.
- `store` tanpa checkbox → `email_requested=false`.
- `approve` payment dengan invoice `email_requested=true` → `assertDispatched(SendInvoiceJob::class)`, invoice jadi `lunas`.
- `approve` dengan `email_requested=false` → `assertNotDispatched`.
- `approve` ulang (sudah approved) → tidak dispatch lagi.
- `payment.update`: manager edit payment pending (amount/jenis/tanggal) → tersimpan + status order dihitung ulang; payment approved/rejected → ditolak (error); marketing → 403.
- `invoice.pdf`: response `200` + header `Content-Type: application/pdf`; marketing hanya bisa unduh invoice order sendiri (404 untuk milik orang lain).
- Filter approved-only: order dengan 1 payment approved + 1 pending → data PDF (mis. `alreadyPaid` yang dipass ke view) hanya menghitung yang approved. (Uji lewat memanggil `InvoiceController@pdf` dan memeriksa `viewData`, atau unit kecil pada query.)

**Cek manual:**
- Halaman Detail Order: tombol Download Invoice & Edit (modal) muncul sesuai role/status; tabel Daftar Invoice tampil; tombol lama hilang.
- Bandingkan PDF hasil unduhan dengan versi lama — layout identik, hanya tabel pembayaran berisi yang approved.
