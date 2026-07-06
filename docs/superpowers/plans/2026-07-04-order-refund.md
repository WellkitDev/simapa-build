# Refund Order — REVISI: order-based (menggantikan invoice-based)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps pakai checkbox.
> **KONTEKS:** Branch `order-refund` sudah memuat refund **invoice-based** (v1, commits 7d2f68d…6fb53b5). Rencana ini **merombaknya jadi order-based**. Refund kini: dari **daftar order**, superadmin, order punya pembayaran paid (dp/pelunasan/lunas) & belum pernah refund; metadata di **Payment refund**; **invoice tak disentuh**; PDF memuat **riwayat pembayaran**.

**Goal:** Refund berbasis order dari daftar order → Payment refund (→ pengeluaran Jurnal Kas) → PDF bukti (dgn riwayat pembayaran) + email + notif. Tanpa menyentuh invoice.

**Tech Stack:** Laravel 11, dompdf, Queue/Mail, PHPUnit.

---

## Konvensi (semua task)

- **Test:** `php artisan test` (phpunit.xml → `APP_ENV=testing` → DB test). Single: `--filter=Nama`. Jangan pakai DB dev untuk test.
- **TDD:** test gagal dulu → konfirmasi → implementasi → lulus.
- **Commit:** author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>`. `git add` eksplisit. Commit heredoc via Bash.
- **Migrate dev** hanya di Task R4.

---

## Task R1: Pivot skema ke Payment + revert Invoice

**Files:** Create `database/migrations/2026_07_04_000011_move_refund_fields_to_payments.php`, `tests/Feature/RefundPaymentModelTest.php`; Modify `app/Models/Payment.php`, `app/Models/Invoice.php`; Delete `tests/Feature/RefundInvoiceModelTest.php`.

- [ ] **Step 1: Test gagal** — buat `tests/Feature/RefundPaymentModelTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundPaymentModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function payment_stores_refund_metadata_and_refunded_by(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create();
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 150000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Batal cetak', 'refund_method' => 'transfer', 'refund_account' => 'BCA 123', 'refunded_by' => $user->id,
        ])->refresh();

        $this->assertSame('Batal cetak', $payment->refund_reason);
        $this->assertSame('transfer', $payment->refund_method);
        $this->assertSame($user->id, $payment->refundedBy->id);
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=RefundPaymentModelTest` → FAIL (kolom `refund_reason` di payments belum ada).

- [ ] **Step 3: Migrasi** — buat `database/migrations/2026_07_04_000011_move_refund_fields_to_payments.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_payments', function (Blueprint $table) {
            $table->text('refund_reason')->nullable()->after('status');
            $table->string('refund_method')->nullable()->after('refund_reason');
            $table->string('refund_account')->nullable()->after('refund_method');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refund_account');
        });

        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropForeign(['refund_payment_id']);
            $table->dropColumn(['refund_reason', 'refund_method', 'refund_account', 'refund_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_payments', function (Blueprint $table) {
            $table->dropColumn(['refund_reason', 'refund_method', 'refund_account', 'refunded_by']);
        });

        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->text('refund_reason')->nullable()->after('refunded_at');
            $table->string('refund_method')->nullable()->after('refund_reason');
            $table->string('refund_account')->nullable()->after('refund_method');
            $table->foreignId('refund_payment_id')->nullable()->after('refund_account')->constrained('tb_payments')->nullOnDelete();
        });
    }
};
```

- [ ] **Step 4: Payment model** — di `app/Models/Payment.php`:
- Tambah 4 field ke `$fillable`:
```php
    protected $fillable = [
        'order_id', 'payment_type',
        'amount', 'paid_at',
        'proof_url', 'status',
        'refund_reason', 'refund_method', 'refund_account', 'refunded_by',
    ];
```
- Tambahkan relasi (setelah `approval()`):
```php
    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
```
(Pastikan `use App\Models\User;` tak perlu — same namespace `App\Models`, jadi `User::class` cukup.)

- [ ] **Step 5: Revert Invoice model** — di `app/Models/Invoice.php`:
- Hapus 4 field refund dari `$fillable` (`'refund_reason', 'refund_method', 'refund_account', 'refund_payment_id',`) → kembalikan ke daftar sebelum Task 1.
- Hapus method `refundPayment()`.

- [ ] **Step 6: Hapus test lama** — hapus file `tests/Feature/RefundInvoiceModelTest.php`.

- [ ] **Step 7: Run — lulus** — `php artisan test --filter=RefundPaymentModelTest` → PASS.

- [ ] **Step 8: Commit**
```bash
git add database/migrations/2026_07_04_000011_move_refund_fields_to_payments.php app/Models/Payment.php app/Models/Invoice.php tests/Feature/RefundPaymentModelTest.php tests/Feature/RefundInvoiceModelTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
refactor(refund): pindah metadata refund ke tb_payments (drop dari invoices)

Refund jadi order/payment-based; kolom refund_reason/method/account/
refunded_by di tb_payments; kolom refund di tb_invoices di-drop. Invoice
tak lagi menyimpan status refund.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task R2: Delivery berbasis Payment + riwayat di PDF

**Files:** Modify `app/Support/RefundPdfData.php`, `resources/views/payments/refunds/refund_pdf.blade.php`, `app/Mail/RefundMail.php`, `resources/views/pages/mails/refund_mail.blade.php`, `app/Jobs/SendRefundJob.php`, `tests/Feature/RefundDeliveryTest.php`.

- [ ] **Step 1: Ganti test (jadikan gagal)** — REPLACE seluruh isi `tests/Feature/RefundDeliveryTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RefundDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function refund_pdf_data_includes_history_and_mail_subject(): void
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 200000, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 300000, 'status' => 'paid', 'paid_at' => '2026-06-03']);
        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 250000, 'status' => 'paid', 'paid_at' => '2026-06-05',
            'refund_reason' => 'Batal', 'refund_method' => 'transfer',
        ]);

        $data = RefundPdfData::for($refund);
        $this->assertEquals(250000.0, $data['refundAmount']);
        $this->assertEquals(500000.0, $data['paidIn']);   // 200rb + 300rb (non-refund)
        $this->assertCount(3, $data['payments']);          // dp + pelunasan + refund
        $this->assertSame('Batal', $data['reason']);

        $mail = new RefundMail($refund, $data, 'PDFBYTES');
        $this->assertStringContainsString('Bukti Refund', $mail->envelope()->subject);
        $this->assertStringContainsString($order->code_order, $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=RefundDeliveryTest` → FAIL (RefundPdfData masih invoice-based / signature beda).

- [ ] **Step 3: RefundPdfData** — REPLACE seluruh isi `app/Support/RefundPdfData.php`:
```php
<?php

namespace App\Support;

use App\Models\Payment;

class RefundPdfData
{
    /** Data bukti refund dari Payment refund (order-based) + riwayat pembayaran. */
    public static function for(Payment $refund): array
    {
        $refund->loadMissing('order.details', 'order.contact', 'order.payments');
        $order    = $refund->order;
        $detail   = $order?->details;
        $contact  = $order?->contact;
        $payments = $order ? $order->payments->sortBy('paid_at')->values() : collect();
        $paidIn   = (float) ($order ? $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount') : 0);

        return [
            'refund'       => $refund,
            'order'        => $order,
            'detail'       => $detail,
            'contact'      => $contact,
            'payments'     => $payments,
            'paidIn'       => $paidIn,
            'refundAmount' => (float) $refund->amount,
            'reason'       => $refund->refund_reason,
            'method'       => $refund->refund_method,
            'account'      => $refund->refund_account,
            'refunded_at'  => $refund->paid_at,
        ];
    }
}
```

- [ ] **Step 4: PDF view** — REPLACE seluruh isi `resources/views/payments/refunds/refund_pdf.blade.php`:
```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}.lbl{color:#666;width:150px;border:0}.big{font-size:15px;font-weight:bold}
.plain td{border:0;padding:3px 6px}
</style></head><body>
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $ptype = ['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan', 'refund' => 'Refund'];
@endphp
<h2>BUKTI REFUND</h2>
<div class="muted">Order: {{ optional($order)->code_order ?? '-' }} · Tanggal Refund: {{ optional($refunded_at)->format('d/m/Y') }}</div>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Customer</td><td>{{ optional($contact)->cp_name ?? '-' }}</td></tr>
    <tr><td class="lbl">Judul</td><td>{{ optional($detail)->title ?? '-' }}</td></tr>
</table>

<div style="margin-top:12px;font-weight:bold">Riwayat Pembayaran</div>
<table>
    <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-end">Nominal</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($payments as $p)
        <tr>
            <td>{{ optional($p->paid_at)->format('d/m/Y') }}</td>
            <td>{{ $ptype[$p->payment_type] ?? $p->payment_type }}</td>
            <td class="text-end">{{ $p->payment_type === 'refund' ? '-' . $rp($p->amount) : $rp($p->amount) }}</td>
            <td>{{ $p->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Total Dibayar</td><td>{{ $rp($paidIn) }}</td></tr>
    <tr><td class="lbl">Nominal Refund</td><td class="big">{{ $rp($refundAmount) }}</td></tr>
    <tr><td class="lbl">Sisa Setelah Refund</td><td>{{ $rp($paidIn - $refundAmount) }}</td></tr>
</table>

<div class="box">
    <table class="plain">
        <tr><td class="lbl">Metode</td><td>{{ $method ?? '-' }}</td></tr>
        <tr><td class="lbl">Rekening/Tujuan</td><td>{{ $account ?? '-' }}</td></tr>
        <tr><td class="lbl">Alasan</td><td>{{ $reason ?? '-' }}</td></tr>
    </table>
</div>
<p style="margin-top:40px">Hormat kami,<br><br><br>Avidpedia</p>
</body></html>
```

- [ ] **Step 5: RefundMail** — REPLACE seluruh isi `app/Mail/RefundMail.php`:
```php
<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class RefundMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $refund, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bukti Refund — Order ' . (optional($this->refund->order)->code_order ?? ''));
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
            Attachment::fromData(fn () => $this->pdf, 'Refund_' . (optional($this->refund->order)->code_order ?? 'order') . '.pdf')->withMime('application/pdf'),
        ];
    }
}
```

- [ ] **Step 6: Email view** — REPLACE seluruh isi `resources/views/pages/mails/refund_mail.blade.php`:
```blade
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<p>Yth. {{ optional($data['contact'])->cp_name ?? 'Pelanggan' }},</p>
<p>Kami telah memproses <strong>refund</strong> untuk pesanan Anda.</p>
<ul>
    <li>Order: {{ optional($data['order'])->code_order }}</li>
    <li>Total dibayar: {{ $rp($data['paidIn']) }}</li>
    <li>Nominal refund: <strong>{{ $rp($data['refundAmount']) }}</strong></li>
    <li>Metode: {{ $data['method'] }}</li>
    @if($data['account'])<li>Tujuan: {{ $data['account'] }}</li>@endif
    <li>Alasan: {{ $data['reason'] }}</li>
</ul>
<p>Rincian & riwayat pembayaran ada di bukti refund terlampir (PDF).</p>
<p>Terima kasih.</p>
```

- [ ] **Step 7: SendRefundJob** — REPLACE seluruh isi `app/Jobs/SendRefundJob.php`:
```php
<?php

namespace App\Jobs;

use App\Models\Payment;
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

    public function __construct(protected int $paymentId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $refund = Payment::with('order.contact', 'order.details', 'order.payments')->find($this->paymentId);
        if (! $refund || $refund->payment_type !== 'refund') {
            return;
        }

        $data   = RefundPdfData::for($refund);
        $pdf    = Pdf::loadView('payments.refunds.refund_pdf', $data);
        $pdfOut = $pdf->output();
        $code   = optional($refund->order)->code_order ?? 'order';

        try {
            $tempDir = storage_path('app/temp/refunds');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Refund_' . $code . '.pdf';
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

        $email = optional($refund->order?->contact)->cp_email;
        if ($email) {
            Mail::to($email)->send(new RefundMail($refund, $data, $pdfOut));
        }
    }
}
```

- [ ] **Step 8: Run — lulus** — `php artisan test --filter=RefundDeliveryTest` → PASS.

- [ ] **Step 9: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 10: Commit**
```bash
git add app/Support/RefundPdfData.php resources/views/payments/refunds/refund_pdf.blade.php app/Mail/RefundMail.php resources/views/pages/mails/refund_mail.blade.php app/Jobs/SendRefundJob.php tests/Feature/RefundDeliveryTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
refactor(refund): PDF/email/job berbasis Payment + riwayat pembayaran

RefundPdfData::for(Payment) + tabel riwayat pembayaran & ringkasan di
PDF; RefundMail/SendRefundJob pakai payment id.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task R3: RefundController order-based + rute + tombol daftar order + hapus refund invoice

**Files:** Create `app/Http/Controllers/Pages/RefundController.php`, `resources/views/payments/refunds/refund_form.blade.php`; Modify `routes/web.php`, `resources/views/orders/book/index.blade.php`, `app/Http/Controllers/Pages/InvoiceController.php`, `resources/views/payments/invoices/show.blade.php`, `tests/Feature/InvoiceLifecycleTest.php`, `tests/Feature/MarketingAccessTest.php`; Create `tests/Feature/RefundFlowTest.php` (replace); Delete `resources/views/payments/invoices/refund_form.blade.php`.

- [ ] **Step 1: Ganti RefundFlowTest (order-based)** — REPLACE seluruh isi `tests/Feature/RefundFlowTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
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

    private function paidOrder(int $amount = 500000): Order
    {
        $order = Order::factory()->create();
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'status' => 'paid', 'paid_at' => '2026-06-01']);
        return $order;
    }

    /** @test */
    public function refund_form_loads_for_superadmin(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->user('superadmin'))->get(route('order.refund.form', $order->code_order))
            ->assertOk()->assertSee('Proses Refund');
    }

    /** @test */
    public function superadmin_processes_refund(): void
    {
        Queue::fake();
        $order = $this->paidOrder(500000);
        $sa = $this->user('superadmin');

        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), [
            'amount' => 200000, 'reason' => 'Batal cetak', 'method' => 'transfer', 'account' => 'BCA 1', 'tanggal' => '2026-06-05',
        ])->assertRedirect();

        $refund = Payment::where('order_id', $order->id)->where('payment_type', 'refund')->first();
        $this->assertNotNull($refund);
        $this->assertEquals(200000, $refund->amount);
        $this->assertSame('paid', $refund->status);
        $this->assertSame('Batal cetak', $refund->refund_reason);
        $this->assertSame($sa->id, $refund->refunded_by);

        // pengeluaran otomatis
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $refund->id, 'jenis' => 'pengeluaran']);
        Queue::assertPushed(SendRefundJob::class);
    }

    /** @test */
    public function refund_amount_cannot_exceed_paid(): void
    {
        $order = $this->paidOrder(500000);
        $this->actingAs($this->user('superadmin'))->post(route('order.refund.store', $order->code_order), [
            'amount' => 999999, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::where('payment_type', 'refund')->count());
    }

    /** @test */
    public function cannot_refund_without_paid_payment(): void
    {
        $order = Order::factory()->create(); // tanpa pembayaran paid
        $this->actingAs($this->user('superadmin'))->get(route('order.refund.form', $order->code_order))
            ->assertSessionHasErrors('refund');
    }

    /** @test */
    public function cannot_refund_twice(): void
    {
        Queue::fake();
        $order = $this->paidOrder(500000);
        $sa = $this->user('superadmin');
        $payload = ['amount' => 100000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05'];

        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), $payload)->assertRedirect();
        $this->actingAs($sa)->post(route('order.refund.store', $order->code_order), $payload)->assertSessionHasErrors('refund');
        $this->assertSame(1, Payment::where('order_id', $order->id)->where('payment_type', 'refund')->count());
    }

    /** @test */
    public function only_superadmin_can_refund(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->user('manager'))->get(route('order.refund.form', $order->code_order))->assertForbidden();
        $this->actingAs($this->user('manager'))->post(route('order.refund.store', $order->code_order), [
            'amount' => 1000, 'reason' => 'x', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run — gagal** — `php artisan test --filter=RefundFlowTest` → FAIL (`Route [order.refund.form] not defined`).

- [ ] **Step 3: RefundController** — buat `app/Http/Controllers/Pages/RefundController.php`:
```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Jobs\SendRefundJob;
use App\Services\Notifier;
use App\Support\RefundPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    private function findOrder(string $code): Order
    {
        return Order::with('details', 'contact', 'payments')->where('code_order', $code)->firstOrFail();
    }

    private function paidIn(Order $order): int
    {
        return (int) $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');
    }

    private function alreadyRefunded(Order $order): bool
    {
        return $order->payments->where('payment_type', 'refund')->isNotEmpty();
    }

    public function form(string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order = $this->findOrder($code);

        if ($this->alreadyRefunded($order)) {
            return back()->withErrors(['refund' => 'Order ini sudah pernah di-refund.']);
        }
        $paidIn = $this->paidIn($order);
        if ($paidIn <= 0) {
            return back()->withErrors(['refund' => 'Order belum memiliki pembayaran untuk di-refund.']);
        }

        return view('payments.refunds.refund_form', compact('order', 'paidIn'));
    }

    public function store(Request $request, string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order = $this->findOrder($code);

        if ($this->alreadyRefunded($order)) {
            return back()->withErrors(['refund' => 'Order ini sudah pernah di-refund.']);
        }
        $paidIn = $this->paidIn($order);

        $data = $request->validate([
            'amount'  => 'required|numeric|min:1|max:' . $paidIn,
            'reason'  => 'required|string',
            'method'  => 'required|in:transfer,tunai,lainnya',
            'account' => 'nullable|string|max:150',
            'tanggal' => 'required|date',
        ]);

        $payment = Payment::create([
            'order_id'       => $order->id,
            'payment_type'   => 'refund',
            'amount'         => $data['amount'],
            'status'         => 'paid',
            'paid_at'        => $data['tanggal'],
            'refund_reason'  => $data['reason'],
            'refund_method'  => $data['method'],
            'refund_account' => $data['account'] ?? null,
            'refunded_by'    => Auth::id(),
        ]);
        // observer (saved) → PaymentCashSyncService → entri pengeluaran

        SendRefundJob::dispatch($payment->id);
        app(Notifier::class)->refundIssued($payment, Auth::user());

        return redirect()->route('order.book.index')->with('success', 'Refund diproses. Bukti refund dikirim ke customer.');
    }

    public function pdf(string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order  = $this->findOrder($code);
        $refund = $order->payments()->where('payment_type', 'refund')->latest('paid_at')->first();
        abort_if(! $refund, 404, 'Belum ada refund untuk order ini.');

        return Pdf::loadView('payments.refunds.refund_pdf', RefundPdfData::for($refund))
            ->stream('Refund_' . $order->code_order . '.pdf');
    }
}
```

- [ ] **Step 4: Rute** — di `routes/web.php`, dalam grup `Route::prefix('order')->name('order.')->group(...)` (tempat `book.create` dll), tambahkan:
```php
        Route::get('refund/{code_order}', [\App\Http\Controllers\Pages\RefundController::class, 'form'])->name('refund.form')->middleware('role:superadmin');
        Route::post('refund/{code_order}', [\App\Http\Controllers\Pages\RefundController::class, 'store'])->name('refund.store')->middleware('role:superadmin');
        Route::get('refund/{code_order}/pdf', [\App\Http\Controllers\Pages\RefundController::class, 'pdf'])->name('refund.pdf')->middleware('role:superadmin');
```

- [ ] **Step 5: Form view** — buat `resources/views/payments/refunds/refund_form.blade.php`:
```blade
@extends('layouts.master')
@section('title', 'Refund Order - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Proses Refund — Order {{ $order->code_order }}</h5>
    <div class="mb-3 text-muted small">
        Customer: {{ optional($order->contact)->cp_name ?? '-' }} ·
        Judul: {{ optional($order->details)->title ?? '-' }} ·
        <strong>Total sudah dibayar: {{ $rp($paidIn) }}</strong>
    </div>
    <form method="POST" action="{{ route('order.refund.store', $order->code_order) }}" onsubmit="return confirm('Proses refund ini? Dana akan tercatat sebagai pengeluaran.')">
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
        <a href="{{ route('order.book.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
```

- [ ] **Step 6: Tombol di daftar order** — di `resources/views/orders/book/index.blade.php`, di dalam blok KONDISI 3 (`@else` — order sudah ada pembayaran approved). Ganti:
```blade
                                                    @else
                                                    <a href="{{ route('order.book.edit', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-primary">
                                                        <i class="" data-feather="edit"></i>
                                                    </a>
                                                    @endif
                                                @endif
```
menjadi:
```blade
                                                    @else
                                                    <a href="{{ route('order.book.edit', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-primary">
                                                        <i class="" data-feather="edit"></i>
                                                    </a>
                                                    @endif
                                                    @role('superadmin')
                                                        @php
                                                            $paidIn = $order->payments->where('status','paid')->where('payment_type','!=','refund')->sum('amount');
                                                            $refunded = $order->payments->where('payment_type','refund')->isNotEmpty();
                                                        @endphp
                                                        @if($refunded)
                                                            <a href="{{ route('order.refund.pdf', $order->code_order) }}" target="_blank" class="btn btn-icon btn-outline-secondary" title="Bukti Refund"><i class="" data-feather="file-text"></i></a>
                                                        @elseif($paidIn > 0)
                                                            <a href="{{ route('order.refund.form', $order->code_order) }}" class="btn btn-icon btn-outline-warning" title="Refund"><i class="" data-feather="corner-up-left"></i></a>
                                                        @endif
                                                    @endrole
                                                @endif
```

- [ ] **Step 7: Hapus refund invoice** —
(a) `app/Http/Controllers/Pages/InvoiceController.php`: HAPUS ketiga method `refundForm`, `refund`, `refundPdf` (yang ditambahkan v1). Hapus import yang jadi tak terpakai: `use App\Jobs\SendRefundJob;`, `use App\Support\RefundPdfData;`, `use App\Services\Notifier;`. (Biarkan import `Pdf`, `Payment`, dll yang masih dipakai method lain.)
(b) `routes/web.php`: HAPUS 3 baris rute invoice refund:
```php
        Route::post('{id}/refund', [InvoiceController::class, 'refund'])->name('refund')->middleware('role:manager|superadmin');
        Route::get('{id}/refund',     [InvoiceController::class, 'refundForm'])->name('refund.form')->middleware('role:manager|superadmin');
        Route::get('{id}/refund/pdf', [InvoiceController::class, 'refundPdf'])->name('refund.pdf')->middleware('role:manager|superadmin');
```
(c) `resources/views/payments/invoices/show.blade.php`: HAPUS blok tombol Refund (`@if($invoice->status === 'lunas') <a ... invoice.refund.form ...> @endif`). Pada blok alert refund, HAPUS baris link PDF (`@if($invoice->refund_payment_id) ... invoice.refund.pdf ... @endif`) sehingga alert kembali hanya menampilkan "Refund diproses oleh … pada …".
(d) Hapus file `resources/views/payments/invoices/refund_form.blade.php`.

- [ ] **Step 8: Perbaiki test yang mengacu refund invoice** —
(a) `tests/Feature/MarketingAccessTest.php` (baris ~48): ganti `$this->post(route('invoice.refund', 1))->assertForbidden();` → `$this->get(route('order.refund.form', 'X'))->assertForbidden();` (marketing tak boleh refund). *(Order tak perlu ada; role middleware `superadmin` menolak marketing lebih dulu → 403.)*
(b) `tests/Feature/InvoiceLifecycleTest.php`: HAPUS seluruh method `superadmin_can_refund_only_lunas_invoice` (refund tak lagi lewat invoice).

- [ ] **Step 9: Run — lulus** —
```
php artisan test --filter=RefundFlowTest
php artisan test --filter=InvoiceLifecycleTest
php artisan test --filter=MarketingAccessTest
```
Semua PASS. Lalu grep sanity: pastikan tak ada lagi referensi `invoice.refund` di luar docs (`resources/`, `routes/`, `app/`, `tests/`).

- [ ] **Step 10: view:cache bersih** — `php artisan view:cache` (sukses) lalu `php artisan view:clear`.

- [ ] **Step 11: Commit**
```bash
git add app/Http/Controllers/Pages/RefundController.php resources/views/payments/refunds/refund_form.blade.php routes/web.php resources/views/orders/book/index.blade.php app/Http/Controllers/Pages/InvoiceController.php resources/views/payments/invoices/show.blade.php tests/Feature/RefundFlowTest.php tests/Feature/MarketingAccessTest.php tests/Feature/InvoiceLifecycleTest.php resources/views/payments/invoices/refund_form.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(refund): refund order-based dari daftar order (superadmin)

Tombol Refund di daftar order (bila ada pembayaran paid & belum refund);
RefundController form/proses/PDF by code_order → Payment refund →
pengeluaran kas + PDF/email/notif. Hapus alur refund berbasis invoice.

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task R4: Regresi penuh + migrasi dev

- [ ] **Step 1: Seluruh suite** — `php artisan test` → PASS semua. Bila gagal, perbaiki dulu.
- [ ] **Step 2: view:cache final** — `php artisan view:cache` (tanpa error) lalu `php artisan view:clear`.
- [ ] **Step 3: Migrasi dev** — `php artisan migrate` di DB dev (`avidpedi_simapa`): terapkan `2026_07_04_000011` (payments +kolom refund, invoices −kolom refund).
- [ ] **Step 4: (opsional) Verifikasi manual** — daftar order (superadmin): order ber-pembayaran → tombol Refund → form → proses → cek entri pengeluaran di Jurnal Kas + tombol berubah jadi "Bukti Refund" (PDF berisi riwayat pembayaran).

---

## Self-Review

**Spec coverage (order-based):** metadata di payments + drop invoice → R1. PDF+riwayat+email+job payment-based → R2. Controller order-based + rute + tombol daftar order + hapus refund invoice + test → R3. Regresi+migrate → R4. Notifier::refundIssued (dari v1) tetap dipakai apa adanya (menerima Payment).

**Placeholder scan:** tak ada TBD; semua step berisi kode utuh + perintah + expected.

**Type consistency:** `RefundPdfData::for(Payment)` → keys `refund/order/detail/contact/payments/paidIn/refundAmount/reason/method/account/refunded_at` dipakai konsisten di PDF view, email view, RefundMail test (R2). `SendRefundJob::dispatch($payment->id)` (paymentId) selaras konstruktor/handle (R2/R3). Rute `order.refund.form/store/pdf` konsisten controller/rute/view/test/tombol (R3). Payment `refund_reason/method/account/refunded_by` + `refundedBy()` selaras (R1) dipakai di controller (R3) & test. Semua referensi `invoice.refund*` dihapus (R3 Step 7-8).
```
