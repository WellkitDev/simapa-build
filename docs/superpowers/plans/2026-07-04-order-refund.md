# Refund Order (dari Invoice lunas) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`).

**Goal:** Alur refund lengkap dari invoice lunas: form refund → Payment refund (→ pengeluaran Jurnal Kas otomatis) → PDF bukti + email customer + notif in-app → invoice ditandai refund.

**Architecture:** Perluas `InvoiceController::refund` + form baru. Refund membuat `Payment(payment_type='refund',status='paid')` → `PaymentObserver::saved` → `PaymentCashSyncService` → entri pengeluaran (sudah ada). Kirim PDF/email via `SendRefundJob` (tiru `SendInvoiceJob`) + `RefundMail`. Notif via `Notifier`.

**Tech Stack:** Laravel 11, PHP 8.2, dompdf, Queue/Mail, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-04-order-refund-design.md`

---

## Konvensi (semua task)

- **Test:** `php artisan test` (phpunit.xml → `APP_ENV=testing` → DB `avidpedi_simapa_test`). Single: `php artisan test --filter=Nama`. Jangan pakai DB dev untuk test.
- **TDD:** test gagal dulu → konfirmasi gagal → implementasi → konfirmasi lulus.
- **Commit:** author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`. JANGAN "Claude"/Anthropic. `git add` path eksplisit (jangan `git add .`). Commit heredoc via Bash tool.
- **Setelah semua task:** `php artisan migrate` di DB dev (`avidpedi_simapa`) untuk kolom baru. Lihat [[migrate-dev-db-after-new-migration]].

---

## File Structure

- **Buat:** migrasi `2026_07_04_000010_add_refund_fields_to_invoices.php`; `app/Support/RefundPdfData.php`; `app/Mail/RefundMail.php`; `app/Jobs/SendRefundJob.php`; views `resources/views/payments/refunds/refund_pdf.blade.php`, `resources/views/pages/mails/refund_mail.blade.php`, `resources/views/payments/invoices/refund_form.blade.php`; test `RefundInvoiceModelTest`, `RefundNotifierTest`, `RefundDeliveryTest`, `RefundFlowTest`.
- **Ubah:** `app/Models/Invoice.php` (fillable+relasi); `app/Services/Notifier.php` (+refundIssued); `app/Http/Controllers/Pages/InvoiceController.php` (refundForm/refund/refundPdf); `routes/web.php` (+2 rute); `resources/views/payments/invoices/show.blade.php` (tombol→form + link PDF); `tests/Feature/InvoiceLifecycleTest.php` (1 test disesuaikan).

---

## Task 1: Migrasi + model Invoice

**Files:** Create `database/migrations/2026_07_04_000010_add_refund_fields_to_invoices.php`; Modify `app/Models/Invoice.php`; Test `tests/Feature/RefundInvoiceModelTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/RefundInvoiceModelTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function invoice_stores_refund_fields_and_links_payment(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 150000, 'status' => 'paid', 'paid_at' => '2026-06-05']);
        $invoice = Invoice::factory()->create([
            'order_id' => $order->id, 'status' => 'refund',
            'refund_reason' => 'Batal cetak', 'refund_method' => 'transfer', 'refund_account' => 'BCA 123',
            'refund_payment_id' => $payment->id,
        ]);
        $invoice->refresh();

        $this->assertSame('Batal cetak', $invoice->refund_reason);
        $this->assertSame('transfer', $invoice->refund_method);
        $this->assertSame($payment->id, $invoice->refundPayment->id);
        $this->assertEquals(150000, $invoice->refundPayment->amount);
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=RefundInvoiceModelTest`
Expected: FAIL (kolom `refund_reason` tidak ada / relasi `refundPayment` tidak ada).

- [ ] **Step 3: Migrasi** — buat `database/migrations/2026_07_04_000010_add_refund_fields_to_invoices.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->text('refund_reason')->nullable()->after('refunded_at');
            $table->string('refund_method')->nullable()->after('refund_reason');
            $table->string('refund_account')->nullable()->after('refund_method');
            $table->foreignId('refund_payment_id')->nullable()->after('refund_account')
                  ->constrained('tb_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropForeign(['refund_payment_id']);
            $table->dropColumn(['refund_reason', 'refund_method', 'refund_account', 'refund_payment_id']);
        });
    }
};
```

- [ ] **Step 4: Model Invoice** — di `app/Models/Invoice.php`:
- Tambah 4 field ke `$fillable` (setelah `'refunded_by', 'refunded_at',`):
```php
        'refund_reason', 'refund_method', 'refund_account', 'refund_payment_id',
```
- Tambahkan relasi (setelah method `refundedBy()`):
```php
    public function refundPayment()
    {
        return $this->belongsTo(Payment::class, 'refund_payment_id');
    }
```

- [ ] **Step 5: Run — lulus**

Run: `php artisan test --filter=RefundInvoiceModelTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_04_000010_add_refund_fields_to_invoices.php app/Models/Invoice.php tests/Feature/RefundInvoiceModelTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(refund): kolom refund di invoices + relasi refundPayment

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 2: Notifier::refundIssued

**Files:** Modify `app/Services/Notifier.php`; Test `tests/Feature/RefundNotifierTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/RefundNotifierTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class RefundNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function refund_issued_notifies_superadmin_but_not_actor(): void
    {
        $recipient = User::factory()->create(); $recipient->assignRole('superadmin');
        $actor = User::factory()->create(); $actor->assignRole('superadmin');
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 200000, 'status' => 'paid', 'paid_at' => '2026-06-05']);

        app(Notifier::class)->refundIssued($payment, $actor);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $actor->id]);
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=RefundNotifierTest`
Expected: FAIL (`Method refundIssued does not exist`).

- [ ] **Step 3: Implementasi** — di `app/Services/Notifier.php`, tambahkan method (mis. setelah `paymentRejected`):
```php
    public function refundIssued(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $recipients = $this->roleUsers(['manager', 'superadmin'], $actor);
        $owner = $payment->order?->user;
        if ($owner && $owner->id !== $actor->id) {
            $recipients = $recipients->push($owner)->unique('id')->values();
        }
        $this->send($recipients, [
            'category' => 'payment',
            'title'    => 'Refund diproses',
            'message'  => 'Rp ' . $this->rp($payment->amount) . ' — ' . ($payment->order?->user?->name ?? '—'),
            'url'      => route('invoice.index'),
            'icon'     => 'corner-up-left',
        ]);
    }
```
(`Payment`, `User`, `Collection` sudah di-import di file ini.)

- [ ] **Step 4: Run — lulus**

Run: `php artisan test --filter=RefundNotifierTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Notifier.php tests/Feature/RefundNotifierTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(refund): Notifier::refundIssued (notif superadmin/manager + pemilik)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: Delivery — RefundPdfData + view PDF + RefundMail + view email + SendRefundJob

**Files:** Create `app/Support/RefundPdfData.php`, `resources/views/payments/refunds/refund_pdf.blade.php`, `app/Mail/RefundMail.php`, `resources/views/pages/mails/refund_mail.blade.php`, `app/Jobs/SendRefundJob.php`; Test `tests/Feature/RefundDeliveryTest.php`.

- [ ] **Step 1: Test gagal**

Buat `tests/Feature/RefundDeliveryTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function refund_pdf_data_and_mail_subject(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 250000, 'status' => 'paid', 'paid_at' => '2026-06-05']);
        $invoice = Invoice::factory()->create([
            'order_id' => $order->id, 'status' => 'refund', 'invoice_no' => 'INV-RF-1',
            'refund_reason' => 'Batal', 'refund_method' => 'transfer', 'refund_payment_id' => $payment->id,
        ]);

        $data = RefundPdfData::for($invoice);
        $this->assertEquals(250000.0, $data['amount']);
        $this->assertSame('Batal', $data['reason']);
        $this->assertSame('transfer', $data['method']);

        $mail = new RefundMail($invoice, $data, 'PDFBYTES');
        $this->assertStringContainsString('Bukti Refund', $mail->envelope()->subject);
        $this->assertStringContainsString('INV-RF-1', $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=RefundDeliveryTest`
Expected: FAIL (`Class "App\Support\RefundPdfData" not found`).

- [ ] **Step 3: RefundPdfData** — buat `app/Support/RefundPdfData.php`:
```php
<?php

namespace App\Support;

use App\Models\Invoice;

class RefundPdfData
{
    /** @return array{invoice:Invoice,order:?\App\Models\Order,detail:?\App\Models\OrderDetail,contact:mixed,payment:?\App\Models\Payment,amount:float,reason:?string,method:?string,account:?string,refunded_at:mixed} */
    public static function for(Invoice $invoice): array
    {
        $invoice->loadMissing('order.details', 'order.contact', 'refundPayment');
        $order   = $invoice->order;
        $detail  = $order?->details;
        $contact = $order?->contact;
        $payment = $invoice->refundPayment;

        return [
            'invoice'     => $invoice,
            'order'       => $order,
            'detail'      => $detail,
            'contact'     => $contact,
            'payment'     => $payment,
            'amount'      => (float) ($payment->amount ?? 0),
            'reason'      => $invoice->refund_reason,
            'method'      => $invoice->refund_method,
            'account'     => $invoice->refund_account,
            'refunded_at' => $invoice->refunded_at,
        ];
    }
}
```

- [ ] **Step 4: View PDF** — buat `resources/views/payments/refunds/refund_pdf.blade.php`:
```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:12px;margin-top:12px}
table{width:100%;border-collapse:collapse}
td{padding:4px 6px;vertical-align:top}.lbl{color:#666;width:160px}.big{font-size:16px;font-weight:bold}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>BUKTI REFUND</h2>
<div class="muted">No. Invoice: {{ $invoice->invoice_no }} · Tanggal: {{ optional($refunded_at)->format('d/m/Y') }}</div>
<div class="box"><table>
    <tr><td class="lbl">Customer</td><td>{{ optional($contact)->cp_name ?? '-' }}</td></tr>
    <tr><td class="lbl">Order</td><td>{{ optional($order)->code_order ?? '-' }}</td></tr>
    <tr><td class="lbl">Judul</td><td>{{ optional($detail)->title ?? '-' }}</td></tr>
    <tr><td class="lbl">Nominal Refund</td><td class="big">{{ $rp($amount) }}</td></tr>
    <tr><td class="lbl">Metode</td><td>{{ $method ?? '-' }}</td></tr>
    <tr><td class="lbl">Tujuan</td><td>{{ $account ?? '-' }}</td></tr>
    <tr><td class="lbl">Alasan</td><td>{{ $reason ?? '-' }}</td></tr>
</table></div>
<p style="margin-top:40px">Hormat kami,<br><br><br>Avidpedia</p>
</body></html>
```

- [ ] **Step 5: RefundMail** — buat `app/Mail/RefundMail.php`:
```php
<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class RefundMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bukti Refund — ' . $this->invoice->invoice_no);
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.refund_mail');
    }

    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }
        return [
            Attachment::fromData(fn () => $this->pdf, 'Refund_' . $this->invoice->invoice_no . '.pdf')->withMime('application/pdf'),
        ];
    }
}
```

- [ ] **Step 6: View email** — buat `resources/views/pages/mails/refund_mail.blade.php`:
```blade
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<p>Yth. {{ optional($data['contact'])->cp_name ?? 'Pelanggan' }},</p>
<p>Kami telah memproses <strong>refund</strong> untuk pesanan Anda.</p>
<ul>
    <li>No. Invoice: {{ $invoice->invoice_no }}</li>
    <li>Order: {{ optional($data['order'])->code_order }}</li>
    <li>Nominal refund: <strong>{{ $rp($data['amount']) }}</strong></li>
    <li>Metode: {{ $data['method'] }}</li>
    @if($data['account'])<li>Tujuan: {{ $data['account'] }}</li>@endif
    <li>Alasan: {{ $data['reason'] }}</li>
</ul>
<p>Bukti refund terlampir (PDF).</p>
<p>Terima kasih.</p>
```

- [ ] **Step 7: SendRefundJob** — buat `app/Jobs/SendRefundJob.php`:
```php
<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use App\Services\GoogleDriveService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $invoiceId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $invoice = Invoice::with('order.contact', 'order.details', 'refundPayment')->find($this->invoiceId);
        if (! $invoice || ! $invoice->refundPayment) {
            return;
        }

        $data   = RefundPdfData::for($invoice);
        $pdf    = Pdf::loadView('payments.refunds.refund_pdf', $data);
        $pdfOut = $pdf->output();

        // Best-effort simpan + upload Drive (tak menggagalkan email bila Drive mati)
        try {
            $tempDir = storage_path('app/temp/refunds');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Refund_' . $invoice->invoice_no . '.pdf';
            file_put_contents($tempPath, $pdfOut);
            $folderId = $drive->getOrCreateFolderByPath('Application/Refunds/' . now()->format('Y'));
            if ($folderId) {
                $drive->uploadFile($tempPath, $folderId, true);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendRefundJob Drive gagal: ' . $e->getMessage());
        }

        $email = optional($invoice->order?->contact)->cp_email;
        if ($email) {
            Mail::to($email)->send(new RefundMail($invoice, $data, $pdfOut));
        }
    }
}
```

- [ ] **Step 8: Run — lulus**

Run: `php artisan test --filter=RefundDeliveryTest`
Expected: PASS (1 test).

- [ ] **Step 9: view:cache bersih** — `php artisan view:cache` (sukses, tanpa error Blade) lalu `php artisan view:clear`.

- [ ] **Step 10: Commit**

```bash
git add app/Support/RefundPdfData.php resources/views/payments/refunds/refund_pdf.blade.php app/Mail/RefundMail.php resources/views/pages/mails/refund_mail.blade.php app/Jobs/SendRefundJob.php tests/Feature/RefundDeliveryTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(refund): RefundPdfData + PDF bukti + RefundMail + SendRefundJob

Tiru pola SendInvoiceJob (PDF dompdf → Drive best-effort → email dgn
lampiran PDF ke customer).

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: Alur refund (form + proses + PDF) + rute + view + test

**Files:** Modify `app/Http/Controllers/Pages/InvoiceController.php`, `routes/web.php`, `resources/views/payments/invoices/show.blade.php`; Create `resources/views/payments/invoices/refund_form.blade.php`, `tests/Feature/RefundFlowTest.php`; Modify `tests/Feature/InvoiceLifecycleTest.php`.

- [ ] **Step 1: Tulis test gagal (RefundFlowTest)**

Buat `tests/Feature/RefundFlowTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Invoice;
use App\Jobs\SendRefundJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class RefundFlowTest extends TestCase
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

    /** @return array{0:Order,1:Invoice} */
    private function lunasOrder(int $amount = 500000): array
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas', 'amount' => $amount, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        $invoice = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'lunas']);
        return [$order, $invoice];
    }

    /** @test */
    public function refund_form_loads_for_superadmin(): void
    {
        [$order, $invoice] = $this->lunasOrder();
        $this->actingAs($this->user('superadmin'))->get(route('invoice.refund.form', $invoice->id))
            ->assertOk()->assertSee('Proses Refund');
    }

    /** @test */
    public function superadmin_processes_refund(): void
    {
        Queue::fake();
        [$order, $invoice] = $this->lunasOrder(500000);

        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 200000, 'reason' => 'Batal cetak', 'method' => 'transfer', 'account' => 'BCA 1', 'tanggal' => '2026-06-05',
        ])->assertRedirect();

        $refund = Payment::where('order_id', $order->id)->where('payment_type', 'refund')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(200000, $refund->amount);
        $this->assertSame('paid', $refund->status);

        $invoice->refresh();
        $this->assertSame('refund', $invoice->status);
        $this->assertSame($refund->id, $invoice->refund_payment_id);
        $this->assertSame('Batal cetak', $invoice->refund_reason);
        $this->assertNotNull($invoice->refunded_at);

        // pengeluaran otomatis di Jurnal Kas
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $refund->id, 'jenis' => 'pengeluaran']);

        Queue::assertPushed(SendRefundJob::class);
    }

    /** @test */
    public function refund_amount_cannot_exceed_paid(): void
    {
        [$order, $invoice] = $this->lunasOrder(500000);
        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 999999, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::where('payment_type', 'refund')->count());
    }

    /** @test */
    public function cannot_refund_non_lunas(): void
    {
        $order = Order::factory()->create();
        $invoice = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'draft']);
        $this->actingAs($this->user('superadmin'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors();
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    /** @test */
    public function only_superadmin_processes_refund(): void
    {
        [$order, $invoice] = $this->lunasOrder();
        $this->actingAs($this->user('manager'))->post(route('invoice.refund', $invoice->id), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — gagal**

Run: `php artisan test --filter=RefundFlowTest`
Expected: FAIL (`Route [invoice.refund.form] not defined`).

- [ ] **Step 3: Kontroler** — di `app/Http/Controllers/Pages/InvoiceController.php`:
- Tambah import: `use App\Jobs\SendRefundJob;`, `use App\Support\RefundPdfData;`, `use App\Services\Notifier;`
- **Ganti** seluruh method `refund(Request $request, int $id)` yang lama dengan tiga method berikut:
```php
    public function refundForm(int $id)
    {
        if (! Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $invoice = Invoice::with('order.details', 'order.contact', 'order.payments')->findOrFail($id);
        if ($invoice->status !== 'lunas') {
            return back()->withErrors(['refund' => 'Invoice harus berstatus lunas untuk di-refund.']);
        }

        $paidIn = (int) $invoice->order->payments()->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');

        return view('payments.invoices.refund_form', compact('invoice', 'paidIn'));
    }

    public function refund(Request $request, int $id)
    {
        if (! Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $invoice = Invoice::with('order')->findOrFail($id);
        if ($invoice->status !== 'lunas') {
            return back()->withErrors(['refund' => 'Invoice harus berstatus lunas untuk di-refund.']);
        }

        $paidIn = (int) $invoice->order->payments()->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');

        $data = $request->validate([
            'amount'  => 'required|numeric|min:1|max:' . $paidIn,
            'reason'  => 'required|string',
            'method'  => 'required|in:transfer,tunai,lainnya',
            'account' => 'nullable|string|max:150',
            'tanggal' => 'required|date',
        ]);

        $payment = DB::transaction(function () use ($invoice, $data) {
            $payment = Payment::create([
                'order_id'     => $invoice->order_id,
                'payment_type' => 'refund',
                'amount'       => $data['amount'],
                'status'       => 'paid',
                'paid_at'      => $data['tanggal'],
            ]);

            $invoice->update([
                'status'            => 'refund',
                'refunded_by'       => Auth::id(),
                'refunded_at'       => now(),
                'refund_reason'     => $data['reason'],
                'refund_method'     => $data['method'],
                'refund_account'    => $data['account'] ?? null,
                'refund_payment_id' => $payment->id,
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => 'lunas',
                'to_status'   => 'refund',
                'changed_by'  => Auth::id(),
                'note'        => 'Refund Rp ' . number_format($data['amount'], 0, ',', '.') . ' — ' . $data['reason'],
            ]);

            return $payment;
        });

        SendRefundJob::dispatch($invoice->id);
        app(Notifier::class)->refundIssued($payment, Auth::user());

        return redirect()->route('invoice.show', $invoice->id)->with('success', 'Refund diproses. Bukti refund dikirim ke customer.');
    }

    public function refundPdf(int $id)
    {
        $invoice = Invoice::with('order.details', 'order.contact', 'refundPayment')->findOrFail($id);
        abort_if($invoice->status !== 'refund' || ! $invoice->refundPayment, 404, 'Belum ada refund untuk invoice ini.');

        return Pdf::loadView('payments.refunds.refund_pdf', RefundPdfData::for($invoice))
            ->stream('Refund_' . $invoice->invoice_no . '.pdf');
    }
```

- [ ] **Step 4: Rute** — di `routes/web.php`, dalam grup `invoices` (setelah baris `Route::post('{id}/refund', ...)->name('refund')...`), tambahkan:
```php
        Route::get('{id}/refund',     [InvoiceController::class, 'refundForm'])->name('refund.form')->middleware('role:manager|superadmin');
        Route::get('{id}/refund/pdf', [InvoiceController::class, 'refundPdf'])->name('refund.pdf')->middleware('role:manager|superadmin');
```

- [ ] **Step 5: View form** — buat `resources/views/payments/invoices/refund_form.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Refund Invoice - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Proses Refund — Invoice {{ $invoice->invoice_no }}</h5>
    <div class="mb-3 text-muted small">
        Order: {{ optional($invoice->order)->code_order }} ·
        Customer: {{ optional(optional($invoice->order)->contact)->cp_name ?? '-' }} ·
        <strong>Total sudah dibayar: {{ $rp($paidIn) }}</strong>
    </div>
    <form method="POST" action="{{ route('invoice.refund', $invoice->id) }}" onsubmit="return confirm('Proses refund ini? Dana akan tercatat sebagai pengeluaran.')">
        @csrf
        <div class="mb-3">
            <label class="form-label">Nominal Refund (Rp)</label>
            <input type="number" name="amount" value="{{ old('amount', $paidIn) }}" min="1" max="{{ $paidIn }}" class="form-control @error('amount') is-invalid @enderror" required>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="text-muted">Maksimal {{ $rp($paidIn) }} (total yang sudah dibayar).</small>
        </div>
        <div class="mb-3">
            <label class="form-label">Metode</label>
            <select name="method" class="form-select" required>
                <option value="transfer">Transfer Bank</option>
                <option value="tunai">Tunai</option>
                <option value="lainnya">Lainnya</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Rekening / Tujuan (opsional)</label>
            <input type="text" name="account" value="{{ old('account') }}" class="form-control" placeholder="mis. BCA 1234567890 a.n. ...">
        </div>
        <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" name="tanggal" value="{{ old('tanggal', now()->format('Y-m-d')) }}" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Alasan Refund</label>
            <textarea name="reason" rows="3" class="form-control @error('reason') is-invalid @enderror" required>{{ old('reason') }}</textarea>
            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-warning">Proses Refund</button>
        <a href="{{ route('invoice.show', $invoice->id) }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
```

- [ ] **Step 6: Update `show.blade.php`** — ganti blok tombol Refund lama:
```blade
                        @if($invoice->status === 'lunas')
                        <form method="POST" action="{{ route('invoice.refund', $invoice->id) }}"
                              onsubmit="return confirm('Proses refund invoice ini?')">
                            @csrf
                            <input type="hidden" name="note" value="Refund diproses oleh superadmin.">
                            <button type="submit" class="btn btn-sm btn-outline-warning">Refund</button>
                        </form>
                        @endif
```
menjadi:
```blade
                        @if($invoice->status === 'lunas')
                        <a href="{{ route('invoice.refund.form', $invoice->id) }}" class="btn btn-sm btn-outline-warning">Refund</a>
                        @endif
```
Dan pada blok alert refund yang ada, ganti:
```blade
                @if($invoice->status === 'refund')
                    <div class="alert alert-info mt-3 py-2">
                        Refund diproses oleh <strong>{{ $invoice->refundedBy->name ?? '-' }}</strong>
                        pada {{ $invoice->refunded_at?->format('d/m/Y H:i') }}
                    </div>
                @endif
```
menjadi:
```blade
                @if($invoice->status === 'refund')
                    <div class="alert alert-info mt-3 py-2">
                        Refund diproses oleh <strong>{{ $invoice->refundedBy->name ?? '-' }}</strong>
                        pada {{ $invoice->refunded_at?->format('d/m/Y H:i') }}
                        @if($invoice->refund_payment_id)
                            · Rp {{ number_format((float) optional($invoice->refundPayment)->amount, 0, ',', '.') }}
                            · <a href="{{ route('invoice.refund.pdf', $invoice->id) }}" target="_blank">Bukti Refund (PDF)</a>
                        @endif
                    </div>
                @endif
```

- [ ] **Step 7: Sesuaikan `InvoiceLifecycleTest`** — di `tests/Feature/InvoiceLifecycleTest.php`, ganti seluruh method `superadmin_can_refund_only_lunas_invoice` dengan:
```php
    /** @test */
    public function superadmin_can_refund_only_lunas_invoice(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \App\Models\Payment::create(['order_id' => $this->order->id, 'payment_type' => 'lunas', 'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $lunas = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'lunas']);
        $draft = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'draft']);

        $this->actingAs($this->superadmin);

        $this->post(route('invoice.refund', $lunas->id), [
            'amount' => 200000, 'reason' => 'Dana dikembalikan', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertRedirect();
        $this->assertDatabaseHas('tb_invoices', ['id' => $lunas->id, 'status' => 'refund']);

        $this->post(route('invoice.refund', $draft->id), [
            'amount' => 100000, 'reason' => 'Coba refund draft', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors();
    }
```

- [ ] **Step 8: Run — lulus**

Run: `php artisan test --filter=RefundFlowTest`
Run: `php artisan test --filter=InvoiceLifecycleTest`
Expected: PASS semua.

- [ ] **Step 9: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Pages/InvoiceController.php routes/web.php resources/views/payments/invoices/refund_form.blade.php resources/views/payments/invoices/show.blade.php tests/Feature/RefundFlowTest.php tests/Feature/InvoiceLifecycleTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(refund): alur refund invoice (form + proses + PDF cetak ulang)

Form refund → buat Payment refund (→ pengeluaran Jurnal Kas) → invoice
refund + metadata → dispatch SendRefundJob + notif. Tombol show → form;
link Bukti Refund PDF saat refund. superadmin only.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 5: Regresi penuh + migrasi dev

- [ ] **Step 1: Seluruh suite** — `php artisan test` → PASS semua. Bila gagal, perbaiki sebelum lanjut.
- [ ] **Step 2: view:cache final** — `php artisan view:cache` (tanpa error) lalu `php artisan view:clear`.
- [ ] **Step 3: Migrasi dev** — `php artisan migrate` di DB dev (`avidpedi_simapa`) untuk kolom refund baru.
- [ ] **Step 4: (opsional) Verifikasi manual** — invoice lunas → tombol Refund → form → proses → cek: entri pengeluaran muncul di Jurnal Kas, invoice jadi refund, link Bukti Refund PDF tampil.

---

## Self-Review (penulis plan)

**1. Spec coverage:** §2 migrasi+model → Task 1. §6 Notifier → Task 2. §4/§5 RefundPdfData/Job/Mail/view → Task 3. §3/§7 kontroler+rute+view form+show → Task 4. §8 test (RefundFlow + update InvoiceLifecycle) → Task 4; RefundInvoiceModel/RefundNotifier/RefundDelivery → Task 1/2/3. §9 komponen semua tercakup.

**2. Placeholder scan:** Tak ada TBD; semua step berisi kode utuh + perintah + expected. Satu verifikasi manual ditandai opsional.

**3. Type consistency:** `refund_payment_id`/`refundPayment()` konsisten (Task 1) dipakai di RefundPdfData (Task 3), refundPdf & show (Task 4). `RefundPdfData::for()` mengembalikan kunci `amount/reason/method/account/contact/order/detail/refunded_at` — dipakai konsisten di view PDF, email, dan Mail (Task 3). `SendRefundJob::dispatch($invoice->id)` (invoiceId) selaras dgn konstruktor & handle (Task 3/4). Rute `invoice.refund.form`/`invoice.refund.pdf` + existing `invoice.refund` konsisten di kontroler/view/test. Validasi `amount ≤ $paidIn` (non-refund) selaras dgn test cap. `Notifier::refundIssued(Payment,User)` selaras (Task 2/4).
