# Detail Order — Payment/Invoice Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the Detail Order page, add per-invoice PDF download, an edit-payment modal, an all-invoices table, and make invoice email approval-gated with the PDF payment table/totals filtered to approved payments only.

**Architecture:** A small `InvoicePdfData` helper centralizes the "approved-only" PDF data (used by both the new download route and `SendInvoiceJob`). A new `email_requested` flag on `tb_invoices` remembers the "send email" checkbox between payment creation and approval; `store` no longer emails immediately, `approve` dispatches `SendInvoiceJob` when the flag is set. Edit-payment is a `PUT` endpoint surfaced via a modal. No PDF template change.

**Tech Stack:** Laravel 10, Blade + Bootstrap 5 modal, barryvdh/laravel-dompdf, Spatie Permission, PHPUnit feature tests with `RefreshDatabase` + `Queue::fake()`.

**Spec:** `docs/superpowers/specs/2026-06-14-detail-order-payment-invoice-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_06_14_000001_add_email_requested_to_tb_invoices.php` | Create | Add `email_requested` boolean |
| `app/Models/Invoice.php` | Modify | Add `email_requested` to `$fillable` |
| `app/Support/InvoicePdfData.php` | Create | Build approved-only PDF data array from an Invoice |
| `app/Http/Controllers/Pages/InvoiceController.php` | Modify | Add `pdf($id)` |
| `app/Jobs/SendInvoiceJob.php` | Modify | Use `InvoicePdfData` (approved-only) |
| `app/Http/Controllers/Pages/PaymentBookController.php` | Modify | `store` (flag, no immediate email), `approve` (guard + dispatch), `update` (edit payment); remove `printInvoice` |
| `routes/web.php` | Modify | Add `invoice.pdf` + `payment.update`; remove `payment.printInvoice` |
| `app/Http/Controllers/Pages/OrderBookController.php` | Modify | `show()` eager-load `payments.invoice` |
| `resources/views/orders/book/show.blade.php` | Modify | Payment Aksi column + edit modal; Daftar Invoice table; remove old button |
| `tests/Feature/DetailOrderPaymentInvoiceTest.php` | Create | All feature tests + shared helpers |

**Conventions (already in this codebase):**
- Tests: `Tests\TestCase` + `RefreshDatabase`; create Spatie roles in `setUp`; `PaymentBookController`/`OrderBookController` constructors inject `GoogleDriveService` → `$this->mock(GoogleDriveService::class)` in `setUp`.
- No `Payment`/`PaymentApproval` factory → build via `::create()`. `Invoice::factory()`, `Order::factory()`, `OrderDetail::factory()` exist. `Order::details()` is **hasOne**.
- `SendInvoiceJob implements ShouldQueue`; phpunit `QUEUE_CONNECTION=sync` → tests MUST `Queue::fake()` before acting, or the job runs for real.
- Run tests with `php artisan test --filter=...` (uses the separate `avidpedi_simapa_test` DB via `.env.testing`). Never `git add .`; commit explicit paths.

---

## Task 1: Add `email_requested` flag to invoices

**Files:**
- Create: `database/migrations/2026_06_14_000001_add_email_requested_to_tb_invoices.php`
- Modify: `app/Models/Invoice.php`
- Create: `tests/Feature/DetailOrderPaymentInvoiceTest.php`

- [ ] **Step 1: Create the feature test file with setUp, helpers, and the first test**

Create `tests/Feature/DetailOrderPaymentInvoiceTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\Invoice;
use App\Jobs\SendInvoiceJob;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

class DetailOrderPaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private User $manager;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Controllers' constructors inject GoogleDriveService — avoid real API.
        $this->mock(GoogleDriveService::class);

        Role::create(['name' => 'marketing',  'guard_name' => 'web']);
        Role::create(['name' => 'manager',    'guard_name' => 'web']);
        Role::create(['name' => 'superadmin', 'guard_name' => 'web']);

        $this->marketing  = User::factory()->create(); $this->marketing->assignRole('marketing');
        $this->manager    = User::factory()->create(); $this->manager->assignRole('manager');
        $this->superadmin = User::factory()->create(); $this->superadmin->assignRole('superadmin');
    }

    /** Order (pending) + one detail + a contact. */
    private function makeOrder(?User $owner = null): Order
    {
        $owner = $owner ?? $this->marketing;
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);
        OrderDetail::factory()->create(['order_id' => $order->id, 'cost_amount' => 1000000]);
        OrderContact::create(['order_id' => $order->id, 'cp_email' => 'cust@example.com', 'cp_phone' => '0812']);
        return $order->fresh(['details', 'contact']);
    }

    /** Payment + its approval row. $approval in: pending|approved|rejected. */
    private function makePayment(Order $order, string $type, int $amount, string $approval): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => $type,
            'amount'       => $amount,
            'proof_url'    => null,
            'paid_at'      => now(),
            'status'       => $approval === 'rejected' ? 'rejected' : 'paid',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approval]);
        return $payment;
    }

    private function makeInvoice(Order $order, ?Payment $payment, bool $emailRequested = false, string $status = 'diterbitkan'): Invoice
    {
        return Invoice::factory()->create([
            'order_id'        => $order->id,
            'payment_id'      => $payment?->id,
            'status'          => $status,
            'email_requested' => $emailRequested,
        ]);
    }

    /** @test */
    public function invoice_persists_email_requested_flag(): void
    {
        $order   = $this->makeOrder();
        $invoice = $this->makeInvoice($order, null, true);

        $this->assertDatabaseHas('tb_invoices', [
            'id'              => $invoice->id,
            'email_requested' => true,
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: FAIL — unknown column `email_requested` (and/or not mass-assignable).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_14_000001_add_email_requested_to_tb_invoices.php`:

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
            $table->boolean('email_requested')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropColumn('email_requested');
        });
    }
};
```

- [ ] **Step 4: Add `email_requested` to the Invoice fillable**

In `app/Models/Invoice.php`, change the `$fillable` array to include `email_requested` (add it right after `'status'`):

```php
    protected $fillable = [
        'order_id', 'payment_id', 'invoice_no', 'type', 'status', 'email_requested',
        'issued_at', 'due_at', 'note',
        'pdf_url', 'pdf_drive_id',
        'cancelled_by', 'cancelled_at',
        'refunded_by', 'refunded_at',
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_14_000001_add_email_requested_to_tb_invoices.php app/Models/Invoice.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: add email_requested flag to invoices"
```

---

## Task 2: InvoicePdfData helper (approved-only)

**Files:**
- Create: `app/Support/InvoicePdfData.php`
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add a method)

- [ ] **Step 1: Add the failing test**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function invoice_pdf_data_counts_only_approved_payments(): void
    {
        $order = $this->makeOrder();
        $this->makePayment($order, 'dp', 400000, 'approved');
        $this->makePayment($order, 'pelunasan', 600000, 'pending'); // must be excluded
        $this->makePayment($order, 'dp', 100000, 'rejected');       // must be excluded
        $invoice = $this->makeInvoice($order, null, false);

        $data = \App\Support\InvoicePdfData::for($invoice);

        $this->assertSame(400000, (int) $data['alreadyPaid']);
        $this->assertSame(600000, (int) $data['remainingBalance']); // 1_000_000 - 400_000
        $this->assertCount(1, $data['order']->payments);            // only the approved one
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=invoice_pdf_data_counts_only_approved_payments`
Expected: FAIL — `Class "App\Support\InvoicePdfData" not found`.

- [ ] **Step 3: Create the helper**

Create `app/Support/InvoicePdfData.php`:

```php
<?php

namespace App\Support;

use App\Models\Invoice;

class InvoicePdfData
{
    /**
     * Build the data array for the invoice PDF view, counting ONLY payments
     * whose approval status is 'approved'. Used by the download route and the
     * SendInvoiceJob so both stay consistent.
     *
     * @return array{invoice: Invoice, order: \App\Models\Order, detail: \App\Models\OrderDetail, totalCost: float|int, alreadyPaid: float|int, remainingBalance: float|int}
     */
    public static function for(Invoice $invoice): array
    {
        $invoice->load([
            'order.details.authors',
            'order.details.scopes',
            'order.contact',
            'order.payments' => fn ($q) =>
                $q->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
                  ->orderBy('paid_at', 'asc'),
        ]);

        $order  = $invoice->order;
        $detail = $order->details;

        $totalCost        = $detail->cost_amount ?? 0;
        $alreadyPaid      = $order->payments->sum('amount');
        $remainingBalance = $totalCost - $alreadyPaid;

        return compact('invoice', 'order', 'detail', 'totalCost', 'alreadyPaid', 'remainingBalance');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=invoice_pdf_data_counts_only_approved_payments`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/InvoicePdfData.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: add InvoicePdfData helper (approved-only payment data)"
```

---

## Task 3: Per-invoice PDF route + wire job to helper + retire printInvoice

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Pages/InvoiceController.php`
- Modify: `app/Jobs/SendInvoiceJob.php`
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php` (remove `printInvoice`)
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add methods)

- [ ] **Step 1: Add the failing tests**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function invoice_pdf_route_streams_a_pdf(): void
    {
        $order   = $this->makeOrder();
        $this->makePayment($order, 'lunas', 1000000, 'approved');
        $invoice = $this->makeInvoice($order, null, false);

        $resp = $this->actingAs($this->manager)->get(route('invoice.pdf', $invoice->id));

        $resp->assertOk();
        $this->assertSame('application/pdf', $resp->headers->get('content-type'));
    }

    /** @test */
    public function marketing_cannot_download_other_users_invoice_pdf(): void
    {
        $owner = User::factory()->create(); $owner->assignRole('marketing');
        $order = $this->makeOrder($owner); // belongs to someone else
        $invoice = $this->makeInvoice($order, null, false);

        $this->actingAs($this->marketing)
            ->get(route('invoice.pdf', $invoice->id))
            ->assertStatus(404);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: FAIL — `Route [invoice.pdf] not defined`.

- [ ] **Step 3a: Add the route and remove the old one**

In `routes/web.php`, inside the `Route::prefix('invoices')->name('invoice.')` group, add:

```php
        Route::get('{id}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
```

In the `Route::prefix('payments')->name('payment.')` group, DELETE this line:

```php
        Route::get('print/{code_order}', [PaymentBookController::class, 'printInvoice'])->name('printInvoice');
```

- [ ] **Step 3b: Add `pdf()` to InvoiceController**

In `app/Http/Controllers/Pages/InvoiceController.php`, add `use App\Support\InvoicePdfData;` and `use Barryvdh\DomPDF\Facade\Pdf;` to the imports, then add this method:

```php
    public function pdf(int $id)
    {
        $invoice = Invoice::query()
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())))
            ->findOrFail($id);

        $data = InvoicePdfData::for($invoice);
        abort_if(!$data['detail'], 404, 'Detail order tidak ditemukan.');

        return Pdf::loadView('payments.invoices.book_invoice_pdf', $data)
            ->stream('Invoice_' . $invoice->invoice_no . '.pdf');
    }
```

- [ ] **Step 3c: Wire SendInvoiceJob to the helper**

In `app/Jobs/SendInvoiceJob.php`, replace the body of `handle()` from the `$invoice = ...` load down to (and including) the `$remainingBalance = ...` line with:

```php
        $invoice = Invoice::find($this->invoiceId);
        if (!$invoice) return;

        $data             = \App\Support\InvoicePdfData::for($invoice);
        $invoice          = $data['invoice'];
        $totalCost        = $data['totalCost'];
        $alreadyPaid      = $data['alreadyPaid'];
        $remainingBalance = $data['remainingBalance'];
```

(The rest of `handle()` — `Pdf::loadView('payments.invoices.book_invoice_pdf', [...])`, temp file, Drive upload, `Mail::to($invoice->order->contact->cp_email)`, cleanup — stays unchanged.)

- [ ] **Step 3d: Remove the dead printInvoice method**

In `app/Http/Controllers/Pages/PaymentBookController.php`, delete the entire `public function printInvoice(string $code_order) { ... }` method (it was only reachable via the route removed in Step 3a). Leave all other methods intact.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: PASS. (If DomPDF errors on a missing image asset, ensure `public/assets/images/{logo-sm,bg-pdf,ttd}.png` exist — they ship with the app.)

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/Pages/InvoiceController.php app/Jobs/SendInvoiceJob.php app/Http/Controllers/Pages/PaymentBookController.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: per-invoice PDF download route; approved-only PDF data via helper; retire printInvoice"
```

---

## Task 4: store() sets flag, stops emailing on creation

**Files:**
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php`
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add a method)

- [ ] **Step 1: Add the failing test**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function store_saves_email_flag_and_does_not_email_while_pending(): void
    {
        Queue::fake();

        // GoogleDriveService must return a folder id + upload url for store() to succeed.
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-123');
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'file-1', 'url' => 'https://drive/struk']);
        });

        $order = $this->makeOrder();

        $resp = $this->actingAs($this->marketing)->post(route('payment.store', $order->code_order), [
            'issued_at'          => now()->toDateString(),
            'dued_at'            => now()->addDays(14)->toDateString(),
            'status'             => 'dp',
            'pay_amount'         => 500000,
            'proof_url'          => UploadedFile::fake()->image('struk.jpg'),
            'send_invoice_email' => '1',
        ]);

        $resp->assertRedirect();
        $this->assertDatabaseHas('tb_invoices', [
            'order_id'        => $order->id,
            'email_requested' => true,
        ]);
        Queue::assertNotPushed(SendInvoiceJob::class);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=store_saves_email_flag_and_does_not_email_while_pending`
Expected: FAIL — `email_requested` is not set (column stays default false) and `SendInvoiceJob` is pushed.

- [ ] **Step 3: Update store()**

In `app/Http/Controllers/Pages/PaymentBookController.php` `store()`:

(a) Just before the `try {` line, capture the checkbox:

```php
        $emailRequested = $request->boolean('send_invoice_email');
```

(b) Change the transaction closure signature to receive it and set it on the invoice. Replace `function () use ($validate, $order, $strukUrl) {` with:

```php
            $invoiceId = DB::transaction(function () use ($validate, $order, $strukUrl, $emailRequested) {
```

and in the `Invoice::create([...])` call add the flag:

```php
                $invoice = Invoice::create([
                    'order_id'        => $order->id,
                    'payment_id'      => $payment->id,
                    'invoice_no'      => $invNo,
                    'issued_at'       => $validate['issued_at'],
                    'due_at'          => $validate['dued_at'],
                    'status'          => 'diterbitkan',
                    'email_requested' => $emailRequested,
                ]);
```

(c) DELETE the post-transaction dispatch block:

```php
            // Jika send_invoice_email
            if ($request->boolean('send_invoice_email')) {
                // Dispatch ke queue
                SendInvoiceJob::dispatch($invoiceId);
            }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=store_saves_email_flag_and_does_not_email_while_pending`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/PaymentBookController.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: payment store records email_requested and no longer emails while pending"
```

---

## Task 5: approve() guards re-approval and emails when requested

**Files:**
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php`
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add methods)

- [ ] **Step 1: Add the failing tests**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function approve_dispatches_email_when_requested(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'pending');
        $invoice = $this->makeInvoice($order, $payment, true, 'diterbitkan');

        $this->actingAs($this->manager)
            ->post(route('payment.approve', $payment->id))
            ->assertRedirect();

        Queue::assertPushed(SendInvoiceJob::class);
        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'lunas']);
    }

    /** @test */
    public function approve_does_not_email_when_not_requested(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'pending');
        $this->makeInvoice($order, $payment, false, 'diterbitkan');

        $this->actingAs($this->manager)->post(route('payment.approve', $payment->id))->assertRedirect();

        Queue::assertNotPushed(SendInvoiceJob::class);
    }

    /** @test */
    public function approve_is_blocked_when_already_approved(): void
    {
        Queue::fake();
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'lunas', 1000000, 'approved');
        $this->makeInvoice($order, $payment, true, 'lunas');

        $this->actingAs($this->manager)->post(route('payment.approve', $payment->id))->assertRedirect();

        Queue::assertNotPushed(SendInvoiceJob::class);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=approve_`
Expected: FAIL — current `approve()` never dispatches `SendInvoiceJob` and does not guard re-approval.

- [ ] **Step 3: Update approve()**

In `app/Http/Controllers/Pages/PaymentBookController.php`, replace the entire `approve($id)` method with:

```php
    public function approve($id)
    {
        $payment = Payment::with('approval')->findOrFail($id);

        if (optional($payment->approval)->status === 'approved') {
            return back()->with('info', 'Pembayaran sudah disetujui.');
        }

        try {
            $invoiceToEmail = null;

            DB::transaction(function () use ($payment, &$invoiceToEmail) {
                $payment->update(['status' => 'paid']);

                $payment->approval()->update([
                    'status'      => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                $invoice = Invoice::where('payment_id', $payment->id)->first();
                if ($invoice) {
                    $fromStatus = $invoice->status;
                    $invoice->update(['status' => 'lunas']);
                    \App\Models\InvoiceLog::create([
                        'invoice_id'  => $invoice->id,
                        'from_status' => $fromStatus,
                        'to_status'   => 'lunas',
                        'changed_by'  => auth()->id(),
                        'note'        => 'Disetujui otomatis saat payment approve.',
                    ]);

                    if ($invoice->email_requested) {
                        $invoiceToEmail = $invoice->id;
                    }
                }

                if ($payment->payment_type === 'lunas' || $payment->payment_type === 'pelunasan') {
                    $payment->order->update(['status' => 'lunas']);
                }
            });

            if (!empty($invoiceToEmail)) {
                SendInvoiceJob::dispatch($invoiceToEmail);
            }

            return redirect()->route('payment.index')->with('success', 'Pembayaran berhasil disetujui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal memproses approval: ' . $e->getMessage());
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: PASS (all tests so far).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/PaymentBookController.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: approve dispatches invoice email when requested; guards re-approval"
```

---

## Task 6: Edit Pembayaran endpoint

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php`
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add methods)

- [ ] **Step 1: Add the failing tests**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function manager_can_edit_pending_payment_and_order_status_recomputes(): void
    {
        $order   = $this->makeOrder(); // cost 1_000_000
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');

        $this->actingAs($this->manager)->put(route('payment.update', $payment->id), [
            'amount'       => 1000000,
            'payment_type' => 'lunas',
            'paid_at'      => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_payments', ['id' => $payment->id, 'amount' => 1000000, 'payment_type' => 'lunas']);
        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'status' => 'lunas']); // remaining <= 0
    }

    /** @test */
    public function approved_payment_cannot_be_edited(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'approved');

        $this->actingAs($this->manager)->put(route('payment.update', $payment->id), [
            'amount'       => 999,
            'payment_type' => 'dp',
            'paid_at'      => now()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_payments', ['id' => $payment->id, 'amount' => 300000]); // unchanged
    }

    /** @test */
    public function marketing_cannot_edit_payment(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');

        $this->actingAs($this->marketing)->put(route('payment.update', $payment->id), [
            'amount'       => 1,
            'payment_type' => 'dp',
            'paid_at'      => now()->toDateString(),
        ])->assertStatus(403);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=_payment`
Expected: FAIL — `Route [payment.update] not defined`.

- [ ] **Step 3a: Add the route**

In `routes/web.php`, inside the `Route::prefix('payments')->name('payment.')` group, add:

```php
        Route::put('{id}', [PaymentBookController::class, 'update'])
            ->name('update')
            ->middleware('role:manager|superadmin');
```

- [ ] **Step 3b: Implement update()**

In `app/Http/Controllers/Pages/PaymentBookController.php`, replace the empty `update(Request $request, string $id)` stub with:

```php
    public function update(Request $request, string $id)
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
            $year = Carbon::parse($validate['paid_at'])->format('Y');
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

            $order = $payment->order;
            $cost  = $order->details->cost_amount ?? 0;
            $paid  = $order->payments()->where('status', 'paid')->sum('amount');
            $order->update(['status' => ($cost - $paid) <= 0 ? 'lunas' : 'pending']);
        });

        return back()->with('success', 'Pembayaran berhasil diperbarui.');
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/Pages/PaymentBookController.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: edit-payment endpoint (manager/superadmin, pending only) with order-status recompute"
```

---

## Task 7: Detail Order view — actions, edit modal, invoice table

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php` (`show()` eager-load)
- Modify: `resources/views/orders/book/show.blade.php`
- Test: `tests/Feature/DetailOrderPaymentInvoiceTest.php` (add a method)

- [ ] **Step 1: Add the failing test**

Add to `DetailOrderPaymentInvoiceTest`:

```php
    /** @test */
    public function detail_order_page_shows_invoice_table_and_payment_actions(): void
    {
        $order   = $this->makeOrder();
        $payment = $this->makePayment($order, 'dp', 300000, 'pending');
        $invoice = $this->makeInvoice($order, $payment, false, 'diterbitkan');

        $resp = $this->actingAs($this->manager)->get(route('order.book.show', $order->code_order));

        $resp->assertOk();
        $resp->assertSee('Daftar Invoice');
        $resp->assertSee($invoice->invoice_no);
        $resp->assertSee('Download Invoice');
        $resp->assertSee('Edit Pembayaran'); // edit modal title shown for pending payment (manager)
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=detail_order_page_shows_invoice_table_and_payment_actions`
Expected: FAIL — current view has no "Daftar Invoice" table, no "Download Invoice"/"Edit Pembayaran".

- [ ] **Step 3a: Eager-load `payments.invoice` in show()**

In `app/Http/Controllers/Pages/OrderBookController.php` `show()`, change the eager-load to include `payments.invoice`:

```php
        $order = Order::with([
        'details.authors',
        'details.scopes',
        'payments.approval',
        'payments.invoice',
        'invoices',
        'contact'
        ])->where('code_order', $code_order)->firstOrFail();
```

- [ ] **Step 3b: Replace the Riwayat Pembayaran table block**

In `resources/views/orders/book/show.blade.php`, replace the whole `<table class="table table-hover mt-3"> ... </table>` (the Riwayat Pembayaran table) with the version below — it adds an **Aksi** column (Download Invoice + Edit) and a per-row edit modal:

```blade
                    <table class="table table-hover mt-3">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Bukti</th>
                                <th>Status Approval</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->payments as $index => $payment)
                                @php $appStatus = $payment->approval->status ?? 'pending'; @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $payment->created_at->format('d M Y H:i') }}</td>
                                    <td><span class="badge bg-secondary text-uppercase">{{ $payment->payment_type }}</span></td>
                                    <td>Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($payment->proof_url)
                                            <a href="{{ $payment->proof_url }}" target="_blank" class="btn btn-sm btn-outline-primary">Lihat Bukti</a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $appStatus == 'approved' ? 'bg-success' : ($appStatus == 'rejected' ? 'bg-danger' : 'bg-warning') }}">
                                            {{ ucfirst($appStatus) }}
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($payment->invoice)
                                            <a href="{{ route('invoice.pdf', $payment->invoice->id) }}" target="_blank"
                                               class="btn btn-sm btn-outline-success">Download Invoice</a>
                                        @endif
                                        @hasanyrole('manager|superadmin')
                                            @if ($appStatus === 'pending')
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                        data-bs-toggle="modal" data-bs-target="#editPayment{{ $payment->id }}">Edit</button>
                                            @endif
                                        @endhasanyrole
                                    </td>
                                </tr>

                                @hasanyrole('manager|superadmin')
                                    @if ($appStatus === 'pending')
                                        <div class="modal fade" id="editPayment{{ $payment->id }}" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form class="modal-content" method="POST"
                                                      action="{{ route('payment.update', $payment->id) }}" enctype="multipart/form-data">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Edit Pembayaran</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Jumlah (Rp)</label>
                                                            <input type="number" name="amount" class="form-control" min="1"
                                                                   value="{{ old('amount', $payment->amount) }}" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Jenis</label>
                                                            <select name="payment_type" class="form-select" required>
                                                                @foreach (['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan'] as $val => $lbl)
                                                                    <option value="{{ $val }}" {{ $payment->payment_type === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Tanggal</label>
                                                            <input type="date" name="paid_at" class="form-control"
                                                                   value="{{ old('paid_at', \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d')) }}" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Bukti (opsional, kosongkan jika tidak diganti)</label>
                                                            <input type="file" name="proof_url" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-primary">Simpan</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                @endhasanyrole
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Belum ada riwayat pembayaran.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
```

- [ ] **Step 3c: Replace the "Invoice Terakhir" card with a Daftar Invoice table**

In `resources/views/orders/book/show.blade.php`, replace the entire last card — `<div class="card shadow-sm"> ... </div>` (the "Invoice Terakhir" block, including its header with the old `payment.printInvoice` button and its `card-body`) — with:

```blade
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Daftar Invoice</h5>
                <span class="badge {{ $remainingBalance <= 0 ? 'bg-success' : 'bg-warning text-dark' }}">
                    {{ $remainingBalance <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
                </span>
            </div>
            <div class="card-body">
                @php
                    $invStatusColors = [
                        'draft' => 'secondary', 'diterbitkan' => 'info', 'jatuh_tempo' => 'warning',
                        'lunas' => 'success', 'dibatalkan' => 'danger', 'refund' => 'dark',
                    ];
                @endphp
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>No. Invoice</th>
                                <th>Tanggal Terbit</th>
                                <th>Jatuh Tempo</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($order->invoices->sortByDesc('id') as $i => $invoice)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $invoice->invoice_no }}</td>
                                    <td>{{ \Carbon\Carbon::parse($invoice->issued_at)->format('d F Y') }}</td>
                                    <td>{{ \Carbon\Carbon::parse($invoice->due_at)->format('d F Y') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $invStatusColors[$invoice->status] ?? 'secondary' }}">
                                            {{ Str::title(str_replace('_', ' ', $invoice->status)) }}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('invoice.pdf', $invoice->id) }}" target="_blank"
                                           class="btn btn-sm btn-outline-success">Download</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Invoice belum diterbitkan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=DetailOrderPaymentInvoiceTest`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Run the full suite (no regressions)**

Run: `php artisan test`
Expected: all green (existing `TitleProgressTest`, `InvoiceLifecycleTest`, `ArchiveGroupedTitlesTest`, etc., plus the new file).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php resources/views/orders/book/show.blade.php tests/Feature/DetailOrderPaymentInvoiceTest.php
git commit -m "feat: Detail Order invoice table + payment download/edit actions"
```

---

## Manual Verification (after all tasks)

- [ ] Create a payment with the "Kirim invoice otomatis…" checkbox ticked → invoice created, **no** email yet (queue empty); approve it → `SendInvoiceJob` runs and emails the invoice.
- [ ] Open a downloaded invoice PDF → layout identical to before; payment-history table lists **only approved** payments; totals match.
- [ ] On Detail Order: each payment row shows **Download Invoice**; pending payments show **Edit** (manager/superadmin) opening a prefilled modal; saving updates the row and the order status.
- [ ] Daftar Invoice table lists all invoices newest-first with working **Download** buttons; old single "Download PDF Invoice" button is gone.

---

## Self-Review Notes (author)

- **Spec coverage:** §1 schema → Task 1; §2 approved-only → Task 2 (helper) consumed in Tasks 3; §3 PDF route + retire printInvoice → Task 3; §4 edit payment → Task 6; §5 view (actions/modal/table) → Task 7; §6 email-on-approve → Tasks 4 (store) + 5 (approve) + 3 (job). Edge cases (no-invoice row, re-approve guard, email_requested=false, non-pending edit, marketing 404, no approved payments) covered by tests in Tasks 3–7.
- **Type/name consistency:** `InvoicePdfData::for()` returns `invoice/order/detail/totalCost/alreadyPaid/remainingBalance` — same keys the `book_invoice_pdf` view already expects and used identically in Task 3 (route + job). Route names `invoice.pdf`, `payment.update` used consistently in controllers, views, and tests. `email_requested` defined in Task 1 and read in Tasks 4/5.
- **No placeholders:** every step has full code/commands.
