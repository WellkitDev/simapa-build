# Spec — Refund Order (dari Invoice lunas)

> **REVISI 2026-07-04 (final = order-based).** Setelah v1 (invoice-based) selesai dibangun, disepakati ulang: refund **berbasis order** (bukan invoice), dari **daftar order** (superadmin), untuk order yang punya pembayaran **paid** (dp/pelunasan/lunas) & belum pernah refund. Metadata refund pindah ke **`tb_payments`** (`refund_reason/method/account/refunded_by`); kolom refund di `tb_invoices` **di-drop**; invoice **tak disentuh** saat refund. Refund PDF menampilkan **riwayat pembayaran order** + ringkasan. Detail rombakan di `docs/superpowers/plans/2026-07-04-order-refund.md` (Task R1–R4). Bagian di bawah = desain v1 (invoice-based), sebagian di-superseded.



- **Tanggal:** 2026-07-04
- **Branch:** `order-refund`
- **Scope:** Alur refund lengkap dari **invoice lunas**: halaman form refund (nominal sebagian + alasan + metode) → buat **Payment refund** (→ pengeluaran Jurnal Kas otomatis) → **PDF bukti refund** → **email** ke customer + **notif in-app** → invoice ditandai `refund`. superadmin.
- **Di luar scope:** refund parsial berkali-kali (v1: satu refund per invoice), refund tanpa invoice, refund kartu/gateway otomatis.

> Melengkapi `InvoiceController::refund` yang kini **hanya** mengubah status invoice. Menyambungkan ke infra yang sudah ada: `PaymentCashSyncService` (refund→pengeluaran), pola `SendInvoiceJob`/`InvoiceMail`, `Notifier`, `InvoicePdfData`.

---

## 1. Tujuan & Kriteria Sukses

1. superadmin membuka **halaman form refund** untuk invoice **lunas**: nominal (default = total dibayar, boleh dikurangi), alasan, metode, rekening tujuan, tanggal.
2. Submit → dibuat **Payment** `payment_type='refund'`, `status='paid'` → observer membuat entri **pengeluaran** di Jurnal Kas (kategori refund). Invoice → `refund` + `refunded_by/at` + metadata refund.
3. **PDF bukti refund** dibuat & **email** dikirim ke customer (`order->contact->cp_email`); **notif in-app** ke superadmin/manager + pemilik order.
4. Guard: superadmin saja; invoice harus lunas; nominal `>0` & `≤` total dibayar (non-refund); cegah refund ganda. Suite tetap hijau.

## 2. Data Model — migrasi `2026_07_04_000010_add_refund_fields_to_invoices`

`tb_invoices` (alter):
```php
$table->text('refund_reason')->nullable()->after('refunded_at');
$table->string('refund_method')->nullable()->after('refund_reason');   // transfer|tunai|lainnya
$table->string('refund_account')->nullable()->after('refund_method');  // rekening/tujuan
$table->foreignId('refund_payment_id')->nullable()->after('refund_account')
      ->constrained('tb_payments')->nullOnDelete();
```
> `down()`: drop FK+kolom. Refund **nominal** disimpan di Payment refund (bukan kolom invoice); ditautkan via `refund_payment_id`.

**`Invoice`** (`app/Models/Invoice.php`): fillable +`refund_reason`,`refund_method`,`refund_account`,`refund_payment_id`; tambah relasi:
```php
public function refundPayment() { return $this->belongsTo(Payment::class, 'refund_payment_id'); }
```

## 3. Kontroler & Rute — `InvoiceController`

**`refundForm(int $id)` (baru, GET):**
- superadmin only (`abort(403)` bila bukan).
- `$invoice = Invoice::with('order.details','order.contact','order.payments')->findOrFail($id)`.
- Bila `status !== 'lunas'` → `back()->withErrors(['refund'=>'Invoice harus lunas untuk di-refund.'])`.
- `$paidIn = (int) $invoice->order->payments()->where('status','paid')->where('payment_type','!=','refund')->sum('amount')`.
- `return view('payments.invoices.refund_form', compact('invoice','paidIn'))`.

**`refund(Request, $id)` (perluas):**
- superadmin only. `$invoice = Invoice::with('order')->findOrFail($id)`. Bila `status!=='lunas'` → back withErrors.
- `$paidIn` seperti di atas.
- Validasi: `amount required numeric min:1 max:$paidIn`, `reason required string`, `method required in:transfer,tunai,lainnya`, `account nullable string max:150`, `tanggal required date`.
- `DB::transaction`:
  - `$payment = Payment::create(['order_id'=>$invoice->order_id,'payment_type'=>'refund','amount'=>$data['amount'],'status'=>'paid','paid_at'=>$data['tanggal']])` → **observer** `saved` → `PaymentCashSyncService` → entri **pengeluaran** (map_key `refund`).
  - `$invoice->update(['status'=>'refund','refunded_by'=>Auth::id(),'refunded_at'=>now(),'refund_reason'=>$data['reason'],'refund_method'=>$data['method'],'refund_account'=>$data['account']??null,'refund_payment_id'=>$payment->id])`.
  - `InvoiceLog::create([... 'from_status'=>'lunas','to_status'=>'refund','changed_by'=>Auth::id(),'note'=>'Refund Rp '.number_format($data['amount'],0,',','.').' — '.$data['reason']])`.
  - return `$payment`.
- `SendRefundJob::dispatch($invoice->id)` (job baca refundPayment dari invoice); `app(Notifier::class)->refundIssued($payment, Auth::user())`.
- `redirect()->route('invoice.show', $invoice->id)->with('success','Refund diproses. Bukti refund dikirim ke customer.')`.

**`refundPdf(int $id)` (baru, GET — cetak ulang staf):**
- `$invoice = Invoice::with('order.details','order.contact','refundPayment')->findOrFail($id)`; `abort_if($invoice->status!=='refund' || !$invoice->refundPayment, 404)`.
- `return Pdf::loadView('payments.refunds.refund_pdf', RefundPdfData::for($invoice))->stream('Refund_'.$invoice->invoice_no.'.pdf')`.

**Rute** (grup `invoices` name `invoice.`; form & pdf `middleware('role:manager|superadmin')`, kontroler tegakkan superadmin):
```php
Route::get('{id}/refund',     [InvoiceController::class, 'refundForm'])->name('refund.form')->middleware('role:manager|superadmin');
Route::get('{id}/refund/pdf', [InvoiceController::class, 'refundPdf'])->name('refund.pdf')->middleware('role:manager|superadmin');
// existing: POST {id}/refund name invoice.refund (tetap)
```

## 4. Support — `app/Support/RefundPdfData.php`
`static for(Invoice $invoice): array` — kembalikan `invoice, order, detail, contact, payment (refundPayment), amount, reason, method, account, refunded_at`. (Mirip `InvoicePdfData`.)

## 5. Email & Job

**`app/Jobs/SendRefundJob.php`** (tiru `SendInvoiceJob`): `handle(GoogleDriveService $drive)`:
- `$invoice = Invoice::with('order.contact','order.details','refundPayment')->find($this->invoiceId)`; guard null.
- `$data = RefundPdfData::for($invoice)`; `$pdf = Pdf::loadView('payments.refunds.refund_pdf', $data)`.
- Simpan temp `storage_path('app/temp/refunds')`; upload Drive `Application/Refunds/{year}` (best-effort).
- `Mail::to($invoice->order->contact->cp_email)->send(new RefundMail($invoice, $data, $pdf->output()))`.
- Cleanup temp.
- Job menerima `refund_payment_id`? Tidak — terima **`invoiceId`** (konsisten `SendInvoiceJob`), ambil refundPayment dari invoice.

**`app/Mail/RefundMail.php`** (tiru `InvoiceMail`): `__construct(Invoice $invoice, array $data, ?string $pdf = null)`; `envelope` subject `"Bukti Refund — {$invoice->invoice_no}"`; `content` view `pages.mails.refund_mail`; `attachments` → bila `$pdf`: `Attachment::fromData(fn()=>$this->pdf, 'Refund_'.$invoice->invoice_no.'.pdf')->withMime('application/pdf')`.

**View email** `resources/views/pages/mails/refund_mail.blade.php` — ringkas: sapaan, nominal refund, alasan, metode, ref invoice/order, catatan lampiran PDF.

## 6. Notifier — `refundIssued`
```php
public function refundIssued(Payment $payment, User $actor): void
{
    $payment->loadMissing('order.user');
    $recipients = $this->roleUsers(['manager', 'superadmin'], $actor);
    $owner = $payment->order?->user;
    if ($owner && $owner->id !== $actor->id) { $recipients = $recipients->push($owner)->unique('id')->values(); }
    $this->send($recipients, [
        'category' => 'payment',
        'title'    => 'Refund diproses',
        'message'  => 'Rp ' . $this->rp($payment->amount) . ' — ' . ($payment->order?->user?->name ?? '—'),
        'url'      => route('invoice.index'),
        'icon'     => 'corner-up-left',
    ]);
}
```

## 7. View — form & PDF

**`resources/views/payments/invoices/refund_form.blade.php`** (extends master): ringkasan invoice/order/customer + form POST `route('invoice.refund',$invoice->id)`: Nominal (`type=number`, default `{{ $paidIn }}`, `max={{ $paidIn }}`) · Alasan (textarea, wajib) · Metode (select transfer/tunai/lainnya) · Rekening/tujuan (opsional) · Tanggal (default hari ini). Tombol "Proses Refund" (konfirmasi). Tampilkan info "Total sudah dibayar: Rp {{ paidIn }}".

**`resources/views/payments/refunds/refund_pdf.blade.php`** (standalone HTML dompdf): kop "BUKTI REFUND", No (invoice_no), Tanggal refund, Customer (contact), Order (code_order), Judul (detail->title), **Nominal Refund**, Metode, Rekening tujuan, Alasan, ttd.

**Invoice show** `resources/views/payments/invoices/show.blade.php`: kontrol Refund yang ada (POST note) → ganti jadi **link** ke `route('invoice.refund.form',$invoice->id)` (tampil saat `status==='lunas'`); saat `status==='refund'` tampilkan info refund + link `route('invoice.refund.pdf',$invoice->id)`.

## 8. Rencana Test

**Perbarui `InvoiceLifecycleTest::superadmin_can_refund_only_lunas_invoice`:** kini kirim `amount/reason/method/tanggal` (bukan `note`). Sediakan order + payment paid pada order lunas. Assert invoice status `refund` (+ draft ditolak). *(Sesuaikan pembuatan data agar order punya pembayaran paid.)*

**Baru `RefundFlowTest`** (Queue::fake):
- `refund_form_loads`: superadmin, invoice lunas → GET `invoice.refund.form` 200 + `assertSee('Proses Refund')`.
- `superadmin_processes_refund`: order + payment paid total 500.000 + invoice lunas. POST `invoice.refund` (amount 200000, reason, method transfer, tanggal) → redirect; **Payment refund** ada (type refund, amount 200000, status paid); invoice `status=refund`+`refund_payment_id`+`refund_reason`; **CashEntry pengeluaran** dgn `payment_id`=refund payment; `Queue::assertPushed(SendRefundJob::class)`; notifikasi DB untuk superadmin bertambah.
- `refund_amount_cannot_exceed_paid`: amount 999999 (> paid) → `assertSessionHasErrors('amount')`; tak ada Payment refund.
- `cannot_refund_non_lunas`: invoice draft → ditolak (withErrors), tak ada refund.
- `only_superadmin_processes_refund`: manager POST refund → 403.

**Regresi:** `MarketingAccessTest` (marketing→403) tetap; `PaymentCashSyncTest` tetap. Suite hijau; `view:cache` bersih. `php artisan migrate` (dev) untuk kolom baru.

## 9. Komponen

- **Baru:** migrasi `2026_07_04_000010`; `RefundPdfData`; `SendRefundJob`; `RefundMail` + view `pages/mails/refund_mail.blade.php`; views `payments/invoices/refund_form.blade.php`, `payments/refunds/refund_pdf.blade.php`; `InvoiceController::refundForm/refundPdf`; `Notifier::refundIssued`; test `RefundFlowTest`.
- **Diubah:** `Invoice` (fillable+relasi); `InvoiceController::refund` (perluas); `routes/web.php` (+2 rute); `payments/invoices/show.blade.php` (tombol→form); `InvoiceLifecycleTest` (1 test disesuaikan).
- **Tak diubah (dipakai apa adanya):** `PaymentObserver`/`PaymentCashSyncService` (refund→pengeluaran otomatis), `InvoicePdfData`, pola `SendInvoiceJob`/`InvoiceMail`.

## 10. Asumsi & Risiko

- **Nominal refund** di Payment refund; validasi `≤ total dibayar non-refund` menjaga tak over-refund. Satu refund/invoice (status `lunas`→`refund` sekali jalan).
- **Cash otomatis**: `PaymentObserver::saved` (create+update) → refund Payment `paid` langsung jadi pengeluaran. Aman & sudah teruji (`PaymentCashSyncTest`).
- **Email/Drive** di dalam job antri (Queue) → di test pakai `Queue::fake` (tak butuh Drive/mail nyata). Bila Drive mati, email tetap terkirim dgn **lampiran PDF** (`Attachment::fromData`).
- superadmin-only ditegakkan di kontroler; rute juga dibatasi `manager|superadmin`.
- Nominal input `type=number` (mask ribuan opsional, tak di-scope).
