# Invoice Payment Correction + Tagihan 4 Service Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Edit Invoice dapat memperbaiki nominal & jenis pembayaran terkait (manager/superadmin, kapan saja, dengan hitung-ulang status order + log); Tagihan create menyediakan 4 tipe layanan (buku/jurnal × mandiri/kolaborasi) yang diteruskan ke order.

**Architecture:** #1 menambah field payment ke `InvoiceController@edit/update` + view, meniru recompute order di `PaymentBookController@update`. #2 mengubah domain `Tagihan.type` ke nilai sama dengan `OrderDetail.type`, dengan kompatibilitas legacy `buku`/`jurnal`.

**Tech Stack:** Laravel 11, Blade + Bootstrap 5, Spatie roles. Tanpa migrasi.

**Spec:** `docs/superpowers/specs/2026-06-26-invoice-correction-tagihan-type-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`); mock `GoogleDriveService`. DB error → MySQL/XAMPP mati: `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Tanpa migrasi.

---

## Task 1: Edit Invoice koreksi pembayaran (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/InvoiceController.php`, `resources/views/payments/invoices/edit.blade.php`
- Test: `tests/Feature/InvoicePaymentCorrectionTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/InvoicePaymentCorrectionTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoicePaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    /** order + detail(cost) + paid payment(amount) + invoice linked to that payment */
    private function graph(User $u, int $cost, int $paid): array
    {
        $order = Order::create(['code_order' => 'ORD-INV-1', 'user_id' => $u->id, 'status' => 'pending', 'ordered_at' => today()->toDateString()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'T', 'slug' => 't-' . $order->id, 'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => $cost]);
        $payment = Payment::create(['order_id' => $order->id, 'amount' => $paid, 'payment_type' => 'dp', 'status' => 'paid', 'paid_at' => now()]);
        $invoice = Invoice::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'invoice_no' => 'INV-1', 'status' => 'diterbitkan', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString()]);
        return [$order, $payment, $invoice];
    }

    /** @test */
    public function manager_corrects_linked_payment_and_recomputes_order(): void
    {
        $manager = $this->user('manager');
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 500000);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'payment_id' => $payment->id, 'amount' => 1000000, 'payment_type' => 'lunas',
        ])->assertRedirect(route('invoice.show', $invoice->id));

        $this->assertSame(1000000, (int) $payment->fresh()->amount);
        $this->assertSame('lunas', $payment->fresh()->payment_type);
        $this->assertSame('lunas', $order->fresh()->status);        // cost 1jt - paid 1jt = 0 -> lunas
        $this->assertSame(1, $invoice->logs()->count());            // koreksi tercatat
    }

    /** @test */
    public function partial_payment_keeps_order_pending(): void
    {
        $manager = $this->user('manager');
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 1000000);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'payment_id' => $payment->id, 'amount' => 400000, 'payment_type' => 'dp',
        ])->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);      // cost 1jt - paid 400rb > 0 -> pending
    }

    /** @test */
    public function non_manager_cannot_update_invoice(): void
    {
        [$order, $payment, $invoice] = $this->graph($this->user('marketing'), 1000000, 500000);
        $this->actingAs($this->user('marketing'))->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-1', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
            'amount' => 999, 'payment_type' => 'dp', 'payment_id' => $payment->id,
        ])->assertForbidden();
    }

    /** @test */
    public function invoice_without_payment_updates_invoice_fields_only(): void
    {
        $manager = $this->user('manager');
        $order = Order::create(['code_order' => 'ORD-INV-2', 'user_id' => $this->user('marketing')->id, 'status' => 'pending', 'ordered_at' => today()->toDateString()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'T', 'slug' => 't2-' . $order->id, 'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000]);
        $invoice = Invoice::create(['order_id' => $order->id, 'payment_id' => null, 'invoice_no' => 'INV-2', 'status' => 'draft', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString()]);

        $this->actingAs($manager)->put(route('invoice.update', $invoice->id), [
            'invoice_no' => 'INV-2B', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $this->assertSame('INV-2B', $invoice->fresh()->invoice_no);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=InvoicePaymentCorrectionTest`
Expected: FAIL — `amount`/`payment_type` not applied; order status not recomputed; no InvoiceLog.

- [ ] **Step 3: Controller edit() + update()**

In `app/Http/Controllers/Pages/InvoiceController.php`, replace `edit()` with:

```php
    public function edit(int $id)
    {
        if (!Auth::user()->hasAnyRole(['manager', 'superadmin'])) {
            abort(403);
        }

        $invoice  = Invoice::with(['order.details', 'payment'])->findOrFail($id);
        $orders   = Order::with('details')->latest()->get();
        $payments = Payment::where('status', 'paid')->get();

        $totalCost        = (int) ($invoice->order->details->cost_amount ?? 0);
        $alreadyPaid      = (int) $invoice->order->payments()->where('status', 'paid')->sum('amount');
        $remainingBalance = max($totalCost - $alreadyPaid, 0);

        return view('payments.invoices.edit', compact('invoice', 'orders', 'payments', 'totalCost', 'alreadyPaid', 'remainingBalance'));
    }
```

Replace `update()` with:

```php
    public function update(Request $request, int $id)
    {
        if (!Auth::user()->hasAnyRole(['manager', 'superadmin'])) {
            abort(403);
        }

        $invoice = Invoice::with('order.details')->findOrFail($id);

        $rules = [
            'invoice_no' => 'required|string|max:100|unique:tb_invoices,invoice_no,' . $id,
            'issued_at'  => 'required|date',
            'due_at'     => 'required|date|after_or_equal:issued_at',
            'note'       => 'nullable|string',
            'payment_id' => 'nullable|exists:tb_payments,id',
        ];
        if ($invoice->payment_id) {
            $rules['amount']       = 'required|numeric|min:1';
            $rules['payment_type'] = 'required|in:dp,lunas,pelunasan';
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'invoice_no' => $data['invoice_no'],
                'issued_at'  => $data['issued_at'],
                'due_at'     => $data['due_at'],
                'note'       => $data['note'] ?? null,
                'payment_id' => $data['payment_id'] ?? $invoice->payment_id,
            ]);

            $payment = $invoice->payment_id ? Payment::find($invoice->payment_id) : null;
            if ($payment && array_key_exists('amount', $data)) {
                $payment->update([
                    'amount'       => $data['amount'],
                    'payment_type' => $data['payment_type'],
                ]);

                $order = $invoice->order;
                $cost  = $order->details->cost_amount ?? 0;
                $paid  = $order->payments()->where('status', 'paid')->sum('amount');
                $order->update(['status' => ($cost - $paid) <= 0 ? 'lunas' : 'pending']);

                InvoiceLog::create([
                    'invoice_id'  => $invoice->id,
                    'from_status' => $invoice->status,
                    'to_status'   => $invoice->status,
                    'changed_by'  => Auth::id(),
                    'note'        => 'Koreksi pembayaran: nominal/jenis diperbarui.',
                ]);
            }
        });

        return redirect()->route('invoice.show', $invoice->id)->with('success', 'Invoice berhasil diperbarui.');
    }
```

(`Invoice`, `InvoiceLog`, `Order`, `Payment`, `Auth`, `DB` are already imported in this controller.)

- [ ] **Step 4: Edit view — Pembayaran Terkait section**

In `resources/views/payments/invoices/edit.blade.php`, INSIDE the existing `<form ...>` (after the Tanggal Terbit/Jatuh Tempo row, before the Catatan field), insert:

```blade
                        @if($invoice->payment)
                            <hr>
                            <h6 class="card-title">Pembayaran Terkait</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Total Biaya</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($totalCost, 0, ',', '.') }}">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Sudah Terbayar</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($alreadyPaid, 0, ',', '.') }}">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Sisa</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($remainingBalance, 0, ',', '.') }}">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Total Pembayaran (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror"
                                        min="1" step="1000" value="{{ old('amount', $invoice->payment->amount) }}" required>
                                    @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status Pembayaran <span class="text-danger">*</span></label>
                                    <select name="payment_type" class="form-select" required>
                                        @foreach(['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan'] as $val => $lbl)
                                            <option value="{{ $val }}" {{ old('payment_type', $invoice->payment->payment_type) === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <small class="text-muted d-block mb-2">Mengubah nominal/jenis akan menghitung ulang status order &amp; tercatat di log invoice.</small>
                        @else
                            <hr>
                            <p class="text-muted">Belum ada pembayaran terkait untuk invoice ini.</p>
                        @endif
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=InvoicePaymentCorrectionTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run full suite + compile**

Run: `php artisan test` (expect 276 + 4 = 280 green). Then `php artisan view:cache` (clean) + `php artisan view:clear`.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/InvoiceController.php resources/views/payments/invoices/edit.blade.php tests/Feature/InvoicePaymentCorrectionTest.php
git commit -m "feat(invoice): edit can correct linked payment amount/type + recompute order

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Tagihan 4 service types + routing + prefill (TDD)

**Files:**
- Modify: `app/Models/Tagihan.php`, `app/Http/Controllers/Pages/TagihanController.php`, `resources/views/payments/tagihan/create.blade.php`, `resources/views/payments/tagihan/index.blade.php`, `resources/views/payments/tagihan/show.blade.php`, `app/Http/Controllers/Pages/OrderBookController.php`, `app/Http/Controllers/Pages/OrderJournalController.php`, `resources/views/orders/book/create.blade.php`, `resources/views/orders/journal/create.blade.php`
- Test: `tests/Feature/TagihanTypeTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/TagihanTypeTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TagihanTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    private function tagihan(User $u, string $type): Tagihan
    {
        return Tagihan::create([
            'tagihan_no' => 'TAG-' . substr(md5(uniqid('', true)), 0, 8),
            'client_name' => 'Klien', 'title' => 'Judul', 'type' => $type,
            'amount' => 1000000, 'created_by' => $u->id, 'status' => 'disetujui',
        ]);
    }

    /** @test */
    public function store_accepts_four_service_types(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u)->post(route('tagihan.store'), [
            'client_name' => 'Klien', 'title' => 'Judul', 'type' => 'bk_kolab', 'amount' => 1000000,
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', ['type' => 'bk_kolab', 'title' => 'Judul']);
    }

    /** @test */
    public function buat_order_routes_book_vs_journal_including_legacy(): void
    {
        $u = $this->user('marketing');
        $this->actingAs($u);

        $bk = $this->tagihan($u, 'bk_mandiri');
        $this->get(route('tagihan.buatOrder', $bk->id))->assertRedirect(route('order.book.create', ['from_tagihan' => $bk->id]));

        $at = $this->tagihan($u, 'at_kolab');
        $this->get(route('tagihan.buatOrder', $at->id))->assertRedirect(route('order.journal.create', ['from_tagihan' => $at->id]));

        $legacyBuku = $this->tagihan($u, 'buku');
        $this->get(route('tagihan.buatOrder', $legacyBuku->id))->assertRedirect(route('order.book.create', ['from_tagihan' => $legacyBuku->id]));

        $legacyJurnal = $this->tagihan($u, 'jurnal');
        $this->get(route('tagihan.buatOrder', $legacyJurnal->id))->assertRedirect(route('order.journal.create', ['from_tagihan' => $legacyJurnal->id]));
    }

    /** @test */
    public function type_label_maps_new_and_legacy(): void
    {
        $this->assertSame('Buku Kolaborasi', (new Tagihan(['type' => 'bk_kolab']))->type_label);
        $this->assertSame('Jurnal Mandiri', (new Tagihan(['type' => 'at_mandiri']))->type_label);
        $this->assertSame('Buku', (new Tagihan(['type' => 'buku']))->type_label);
        $this->assertSame('Jurnal', (new Tagihan(['type' => 'jurnal']))->type_label);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TagihanTypeTest`
Expected: FAIL — `store` rejects `bk_kolab` (validation `in:buku,jurnal`); `type_label` undefined; routing wrong.

- [ ] **Step 3: Tagihan model — type_label accessor**

In `app/Models/Tagihan.php`, add this method (e.g. after `canConvert()`):

```php
    /** Label tampilan tipe layanan (mendukung nilai baru + legacy). */
    public function getTypeLabelAttribute(): string
    {
        return [
            'bk_mandiri' => 'Buku Mandiri',
            'bk_kolab'   => 'Buku Kolaborasi',
            'at_mandiri' => 'Jurnal Mandiri',
            'at_kolab'   => 'Jurnal Kolaborasi',
            'buku'       => 'Buku',
            'jurnal'     => 'Jurnal',
        ][$this->type] ?? ucfirst((string) $this->type);
    }
```

- [ ] **Step 4: TagihanController — validateData + buatOrder routing**

In `app/Http/Controllers/Pages/TagihanController.php`:

Change the `type` rule in `validateData()`:
```php
            'type'               => 'required|in:bk_mandiri,bk_kolab,at_mandiri,at_kolab',
```

Change the routing line in `buatOrder()`:
```php
        $isJurnal = in_array($tagihan->type, ['at_mandiri', 'at_kolab', 'jurnal'], true);
        $route = $isJurnal ? 'order.journal.create' : 'order.book.create';
        return redirect()->route($route, ['from_tagihan' => $tagihan->id]);
```

- [ ] **Step 5: Tagihan create view — 4 type options**

In `resources/views/payments/tagihan/create.blade.php`, replace the Tipe `@foreach` (currently `['buku' => 'Buku', 'jurnal' => 'Jurnal']`) with:

```blade
                                    @foreach(['bk_mandiri' => 'Buku Mandiri', 'bk_kolab' => 'Buku Kolaborasi', 'at_mandiri' => 'Jurnal Mandiri', 'at_kolab' => 'Jurnal Kolaborasi'] as $val => $lbl)
                                        <option value="{{ $val }}" {{ old('type', $tagihan->type ?? 'bk_mandiri') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                    @endforeach
```

- [ ] **Step 6: Tagihan index + show — type label**

In `resources/views/payments/tagihan/index.blade.php` and `resources/views/payments/tagihan/show.blade.php`, wherever the raw `{{ $tagihan->type }}` / `$t->type` is displayed as the type, replace it with `{{ $tagihan->type_label }}` (or `{{ $t->type_label }}` matching the loop variable). Read each file to find the type display cell and swap it.

- [ ] **Step 7: Order create — prefill type from tagihan**

In `app/Http/Controllers/Pages/OrderBookController.php` `create()`, inside the `$prefill = [ ... ]` array built from the tagihan, add:
```php
                    'type' => in_array($t->type, ['bk_mandiri', 'bk_kolab'], true) ? $t->type : null,
```
In `app/Http/Controllers/Pages/OrderJournalController.php` `create()`, inside its `$prefill = [ ... ]` array, add:
```php
                    'type' => in_array($t->type, ['at_mandiri', 'at_kolab'], true) ? $t->type : null,
```

- [ ] **Step 8: Order create views — preselect type from prefill**

In `resources/views/orders/book/create.blade.php` and `resources/views/orders/journal/create.blade.php`, find the `<select name="type" ...>` options and add a `selected` when the option value equals `old('type', $prefill['type'] ?? '')`. Example for an option:
```blade
<option value="bk_mandiri" {{ old('type', $prefill['type'] ?? '') === 'bk_mandiri' ? 'selected' : '' }}>Buku Mandiri</option>
```
Apply to each existing `<option>` in both forms (book form: bk_/at_ options it has; journal form: at_mandiri/at_kolab). Do NOT add/remove options — only add the `selected` expression to existing ones.

- [ ] **Step 9: Run, confirm PASS**

Run: `php artisan test --filter=TagihanTypeTest`
Expected: PASS (3 tests).

- [ ] **Step 10: Full suite + compile**

Run: `php artisan test` (expect 280 + 3 = 283 green). Then `php artisan view:cache` (clean) + `php artisan view:clear`.

- [ ] **Step 11: Commit**

```
git add app/Models/Tagihan.php app/Http/Controllers/Pages/TagihanController.php resources/views/payments/tagihan/create.blade.php resources/views/payments/tagihan/index.blade.php resources/views/payments/tagihan/show.blade.php app/Http/Controllers/Pages/OrderBookController.php app/Http/Controllers/Pages/OrderJournalController.php resources/views/orders/book/create.blade.php resources/views/orders/journal/create.blade.php tests/Feature/TagihanTypeTest.php
git commit -m "feat(tagihan): 4 service types + book/journal routing + order type prefill

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS semua (≈283).

- [ ] **Step 2: Compile semua Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Smoke manual (UX)**

Login manager: buka Edit Invoice yang punya payment → koreksi Total Pembayaran + Status Pembayaran → simpan → status order & log invoice ter-update; invoice tanpa payment → hanya field invoice. Login marketing: Tagihan create → Tipe = 4 layanan; ajukan; (sebagai approver) setujui → "Buat Order" → diarahkan ke form yang benar (buku/jurnal) dengan tipe terpilih.

---

## Catatan & Risiko

- Tanpa migrasi: nilai `Tagihan.type` legacy (`buku`/`jurnal`) tetap didukung di routing + label.
- Koreksi pembayaran via Edit Invoice mengubah pemasukan tercatat (sengaja, manager/superadmin, tercatat InvoiceLog) + hitung-ulang status order.
- "Status Pembayaran" = `payment_type` (DP/Lunas/Pelunasan), konsisten dengan form Payment Create.
- Restyle order/payment sebelumnya tetap utuh; perubahan ini hanya menambah field/opsi, bukan mengubah layout.
