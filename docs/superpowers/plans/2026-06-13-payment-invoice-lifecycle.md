# Payment + Invoice Lifecycle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Perluas invoice menjadi lifecycle penuh (Draft → Diterbitkan → Jatuh Tempo → Lunas → Refund/Dibatalkan) dengan CRUD manual, audit log, dan perbaiki payment flow agar jurnal didukung.

**Architecture:** Extend `tb_invoices` (tambah kolom, nullable `payment_id`) + tabel baru `tb_invoice_logs` untuk audit trail. `InvoiceController` baru mengelola seluruh lifecycle. `PaymentBookController` difix agar support order jurnal. Role-based permission: marketing=buat saja, manager=edit+ubah status, superadmin=semua+cancel+refund.

**Tech Stack:** Laravel 10, Spatie Permission (terpasang), Blade + Bootstrap 5, PHPUnit

---

## File Map

| Aksi | Path |
|------|------|
| Create | `database/migrations/2026_06_13_000003_update_tb_invoices_table.php` |
| Create | `database/migrations/2026_06_13_000004_create_tb_invoice_logs_table.php` |
| Create | `app/Models/InvoiceLog.php` |
| Create | `app/Http/Controllers/Pages/InvoiceController.php` |
| Create | `tests/Feature/InvoiceLifecycleTest.php` |
| Create | `resources/views/payments/invoices/index.blade.php` |
| Create | `resources/views/payments/invoices/create.blade.php` |
| Create | `resources/views/payments/invoices/edit.blade.php` |
| Create | `resources/views/payments/invoices/show.blade.php` |
| Modify | `app/Models/Invoice.php` — fillable, casts, relasi InvoiceLog, isOverdue() |
| Modify | `app/Http/Controllers/Pages/PaymentBookController.php` — support jurnal |
| Modify | `routes/web.php` — tambah invoice routes |
| Modify | `resources/views/layouts/sidebar.blade.php` — tambah menu Invoice |

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/2026_06_13_000003_update_tb_invoices_table.php`
- Create: `database/migrations/2026_06_13_000004_create_tb_invoice_logs_table.php`

- [ ] **Step 1: Buat migration update tb_invoices**

```php
<?php
// database/migrations/2026_06_13_000003_update_tb_invoices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            // Ubah payment_id menjadi nullable
            $table->foreignId('payment_id')->nullable()->change();

            // Kolom baru
            $table->string('type', 20)->default('regular')->after('invoice_no');
            $table->string('status', 20)->default('draft')->change();
            $table->text('note')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('note');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete()->after('cancelled_at');
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');
        });

        // Data migration: set existing records ke type=regular, status=lunas
        DB::table('tb_invoices')->whereNull('type')->update(['type' => 'regular']);
        DB::table('tb_invoices')->where('status', 'pending')->update(['status' => 'diterbitkan']);
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropColumn(['type', 'note', 'cancelled_by', 'cancelled_at', 'refunded_by', 'refunded_at']);
        });
    }
};
```

- [ ] **Step 2: Buat migration create tb_invoice_logs**

```php
<?php
// database/migrations/2026_06_13_000004_create_tb_invoice_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_invoice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('tb_invoices')->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_invoice_logs');
    }
};
```

- [ ] **Step 3: Jalankan migrasi**

```bash
php artisan migrate
```

Expected: `2026_06_13_000003... DONE  2026_06_13_000004... DONE`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_13_000003_update_tb_invoices_table.php
git add database/migrations/2026_06_13_000004_create_tb_invoice_logs_table.php
git commit -m "feat: update tb_invoices with lifecycle columns and create tb_invoice_logs"
```

---

## Task 2: Update Invoice Model + Buat InvoiceLog Model

**Files:**
- Modify: `app/Models/Invoice.php`
- Create: `app/Models/InvoiceLog.php`

- [ ] **Step 1: Ganti seluruh isi Invoice.php**

```php
<?php
// app/Models/Invoice.php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'tb_invoices';

    protected $fillable = [
        'order_id', 'payment_id', 'invoice_no', 'type', 'status',
        'issued_at', 'due_at', 'note',
        'pdf_url', 'pdf_drive_id',
        'cancelled_by', 'cancelled_at',
        'refunded_by', 'refunded_at',
    ];

    protected $casts = [
        'issued_at'    => 'datetime',
        'due_at'       => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at'  => 'datetime',
    ];

    const STATUSES = [
        'draft', 'diterbitkan', 'jatuh_tempo', 'lunas', 'dibatalkan', 'refund',
    ];

    const TYPES = ['proforma', 'regular'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function logs()
    {
        return $this->hasMany(InvoiceLog::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && !in_array($this->status, ['lunas', 'dibatalkan', 'refund']);
    }
}
```

- [ ] **Step 2: Buat InvoiceLog model**

```php
<?php
// app/Models/InvoiceLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoiceLog extends Model
{
    use HasFactory;

    protected $table = 'tb_invoice_logs';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Invoice.php app/Models/InvoiceLog.php
git commit -m "feat: update Invoice model and add InvoiceLog model"
```

---

## Task 3: Fix PaymentBookController — Support Order Jurnal

**Files:**
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php`

- [ ] **Step 1: Tambah log InvoiceLog saat payment diapprove**

Dalam method `approve()`, di dalam `DB::transaction()`, tambahkan setelah `$payment->approval()->update(...)`:

```php
// Update invoice status → lunas + catat log
$invoice = $payment->order->invoices()->where('payment_id', $payment->id)->first();
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
}
```

- [ ] **Step 2: Tambah InvoiceLog saat invoice dibuat di `store()`**

Dalam `DB::transaction()` di `store()`, setelah baris `$invoice = Invoice::create([...])`, tambahkan:

```php
\App\Models\InvoiceLog::create([
    'invoice_id'  => $invoice->id,
    'from_status' => '',
    'to_status'   => 'diterbitkan',
    'changed_by'  => Auth::id(),
    'note'        => 'Invoice dibuat otomatis dari pembayaran.',
]);
```

- [ ] **Step 3: Update status invoice saat create dari 'pending' ke 'diterbitkan'**

Dalam `store()`, ubah baris `'status' => 'pending'` menjadi `'status' => 'diterbitkan'`.

- [ ] **Step 4: Verifikasi — payment jurnal sudah bisa menggunakan route yang sama**

Buka `routes/web.php` dan pastikan route `payments/{code_order}/create` tidak ada validasi tipe. Route ini sudah generik dan akan bekerja untuk jurnal karena `PaymentBookController@create` hanya cek `$order->status !== 'pending'`, bukan tipe order.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/PaymentBookController.php
git commit -m "fix: update invoice status to diterbitkan on create, add InvoiceLog on approve"
```

---

## Task 4: InvoiceController

**Files:**
- Create: `app/Http/Controllers/Pages/InvoiceController.php`

- [ ] **Step 1: Tulis failing tests**

```php
<?php
// tests/Feature/InvoiceLifecycleTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private User $manager;
    private User $superadmin;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'marketing',  'guard_name' => 'web']);
        Role::create(['name' => 'manager',    'guard_name' => 'web']);
        Role::create(['name' => 'superadmin', 'guard_name' => 'web']);

        $this->marketing  = User::factory()->create(); $this->marketing->assignRole('marketing');
        $this->manager    = User::factory()->create(); $this->manager->assignRole('manager');
        $this->superadmin = User::factory()->create(); $this->superadmin->assignRole('superadmin');

        $this->order = Order::factory()->create(['status' => 'pending']);
    }

    /** @test */
    public function marketing_can_create_proforma_invoice(): void
    {
        $this->actingAs($this->marketing);

        $this->post(route('invoice.store'), [
            'order_id'   => $this->order->id,
            'type'       => 'proforma',
            'invoice_no' => 'PRF-001',
            'issued_at'  => now()->toDateString(),
            'due_at'     => now()->addDays(14)->toDateString(),
            'note'       => 'Tagihan awal',
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_invoices', [
            'type'   => 'proforma',
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function marketing_cannot_edit_invoice(): void
    {
        $invoice = Invoice::factory()->create(['order_id' => $this->order->id, 'type' => 'proforma', 'status' => 'draft']);

        $this->actingAs($this->marketing);

        $this->get(route('invoice.edit', $invoice->id))->assertStatus(403);
    }

    /** @test */
    public function manager_can_update_status(): void
    {
        $invoice = Invoice::factory()->create(['order_id' => $this->order->id, 'type' => 'proforma', 'status' => 'draft']);

        $this->actingAs($this->manager);

        $this->post(route('invoice.updateStatus', $invoice->id), [
            'status' => 'diterbitkan',
            'note'   => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'diterbitkan']);
        $this->assertDatabaseHas('tb_invoice_logs', ['invoice_id' => $invoice->id, 'to_status' => 'diterbitkan']);
    }

    /** @test */
    public function manager_cannot_cancel_invoice(): void
    {
        $invoice = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'diterbitkan']);

        $this->actingAs($this->manager);

        $this->post(route('invoice.cancel', $invoice->id), ['note' => 'Coba cancel'])
            ->assertStatus(403);
    }

    /** @test */
    public function superadmin_can_cancel_invoice_with_note(): void
    {
        $invoice = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'diterbitkan']);

        $this->actingAs($this->superadmin);

        $this->post(route('invoice.cancel', $invoice->id), ['note' => 'Dibatalkan karena permintaan klien'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'dibatalkan']);
    }

    /** @test */
    public function superadmin_can_refund_only_lunas_invoice(): void
    {
        $lunas   = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'lunas']);
        $draft   = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'draft']);

        $this->actingAs($this->superadmin);

        $this->post(route('invoice.refund', $lunas->id), ['note' => 'Dana dikembalikan'])
            ->assertRedirect();
        $this->assertDatabaseHas('tb_invoices', ['id' => $lunas->id, 'status' => 'refund']);

        $this->post(route('invoice.refund', $draft->id), ['note' => 'Coba refund draft'])
            ->assertSessionHasErrors();
    }
}
```

- [ ] **Step 2: Jalankan tests, pastikan FAIL**

```bash
php artisan test --filter=InvoiceLifecycleTest
```

Expected: semua FAIL — controller belum ada.

- [ ] **Step 3: Buat InvoiceController**

```php
<?php
// app/Http/Controllers/Pages/InvoiceController.php

namespace App\Http\Controllers\Pages;

use App\Models\Invoice;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = Invoice::with(['order.details', 'payment'])
            ->when(Auth::user()->hasRole('marketing'), fn($q) =>
                $q->whereHas('order', fn($o) => $o->where('user_id', Auth::id()))
            )
            ->latest()
            ->get();

        return view('payments.invoices.index', compact('invoices'));
    }

    public function create()
    {
        $orders   = Order::with('details')->latest()->get();
        $payments = Payment::with('order')->where('status', 'paid')->get();
        return view('payments.invoices.create', compact('orders', 'payments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id'   => 'required|exists:tb_orders,id',
            'type'       => 'required|in:proforma,regular',
            'invoice_no' => 'required|string|max:100|unique:tb_invoices,invoice_no',
            'issued_at'  => 'required|date',
            'due_at'     => 'required|date|after_or_equal:issued_at',
            'note'       => 'nullable|string',
            'payment_id' => 'nullable|exists:tb_payments,id',
        ]);

        $invoice = Invoice::create([
            ...$data,
            'status' => 'draft',
        ]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => '',
            'to_status'   => 'draft',
            'changed_by'  => Auth::id(),
            'note'        => 'Invoice dibuat.',
        ]);

        return redirect()->route('invoice.show', $invoice->id)
            ->with('success', 'Invoice berhasil dibuat.');
    }

    public function show(int $id)
    {
        $invoice = Invoice::with([
            'order.details', 'payment', 'logs.changedBy', 'cancelledBy', 'refundedBy',
        ])->findOrFail($id);

        return view('payments.invoices.show', compact('invoice'));
    }

    public function edit(int $id)
    {
        if (Auth::user()->hasRole('marketing')) {
            abort(403);
        }

        $invoice  = Invoice::with('order')->findOrFail($id);
        $orders   = Order::with('details')->latest()->get();
        $payments = Payment::where('status', 'paid')->get();
        return view('payments.invoices.edit', compact('invoice', 'orders', 'payments'));
    }

    public function update(Request $request, int $id)
    {
        if (Auth::user()->hasRole('marketing')) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'invoice_no' => 'required|string|max:100|unique:tb_invoices,invoice_no,' . $id,
            'issued_at'  => 'required|date',
            'due_at'     => 'required|date|after_or_equal:issued_at',
            'note'       => 'nullable|string',
            'payment_id' => 'nullable|exists:tb_payments,id',
        ]);

        $invoice->update($data);

        return redirect()->route('invoice.show', $invoice->id)
            ->with('success', 'Invoice berhasil diperbarui.');
    }

    public function updateStatus(Request $request, int $id)
    {
        if (Auth::user()->hasRole('marketing')) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:draft,diterbitkan,jatuh_tempo,lunas,dibatalkan,refund',
            'note'   => 'nullable|string',
        ]);

        $fromStatus = $invoice->status;
        $invoice->update(['status' => $data['status']]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => $fromStatus,
            'to_status'   => $data['status'],
            'changed_by'  => Auth::id(),
            'note'        => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Status invoice diperbarui.');
    }

    public function cancel(Request $request, int $id)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $request->validate(['note' => 'required|string']);

        $invoice    = Invoice::findOrFail($id);
        $fromStatus = $invoice->status;

        $invoice->update([
            'status'       => 'dibatalkan',
            'cancelled_by' => Auth::id(),
            'cancelled_at' => now(),
        ]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => $fromStatus,
            'to_status'   => 'dibatalkan',
            'changed_by'  => Auth::id(),
            'note'        => $request->note,
        ]);

        return back()->with('warning', 'Invoice dibatalkan.');
    }

    public function refund(Request $request, int $id)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $request->validate(['note' => 'required|string']);

        $invoice = Invoice::findOrFail($id);

        if ($invoice->status !== 'lunas') {
            return back()->withErrors(['note' => 'Invoice harus berstatus lunas untuk di-refund.']);
        }

        $invoice->update([
            'status'      => 'refund',
            'refunded_by' => Auth::id(),
            'refunded_at' => now(),
        ]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => 'lunas',
            'to_status'   => 'refund',
            'changed_by'  => Auth::id(),
            'note'        => $request->note,
        ]);

        return back()->with('success', 'Invoice berhasil di-refund.');
    }

    public function logs(int $id)
    {
        $invoice = Invoice::with('logs.changedBy')->findOrFail($id);
        return response()->json($invoice->logs);
    }
}
```

- [ ] **Step 4: Jalankan tests, pastikan PASS**

```bash
php artisan test --filter=InvoiceLifecycleTest
```

Expected: semua PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/InvoiceController.php tests/Feature/InvoiceLifecycleTest.php
git commit -m "feat: add InvoiceController with full lifecycle management"
```

---

## Task 5: Routes + Sidebar

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1: Tambah import di routes/web.php**

Setelah baris `use App\Http\Controllers\Pages\OrderJournalController;`, tambahkan:

```php
use App\Http\Controllers\Pages\InvoiceController;
```

- [ ] **Step 2: Tambah routes invoice di dalam block Route::middleware('auth')**

Tambahkan setelah block `Route::prefix('payments')`:

```php
Route::prefix('invoices')->name('invoice.')->group(function () {
    Route::get('',                  [InvoiceController::class, 'index'])->name('index');
    Route::get('create',            [InvoiceController::class, 'create'])->name('create');
    Route::post('',                 [InvoiceController::class, 'store'])->name('store');
    Route::get('{id}',              [InvoiceController::class, 'show'])->name('show');
    Route::get('{id}/edit',         [InvoiceController::class, 'edit'])->name('edit');
    Route::put('{id}',              [InvoiceController::class, 'update'])->name('update');
    Route::post('{id}/status',      [InvoiceController::class, 'updateStatus'])->name('updateStatus');
    Route::post('{id}/cancel',      [InvoiceController::class, 'cancel'])->name('cancel');
    Route::post('{id}/refund',      [InvoiceController::class, 'refund'])->name('refund');
    Route::get('{id}/logs',         [InvoiceController::class, 'logs'])->name('logs');
});
```

- [ ] **Step 3: Tambah menu Invoice di sidebar**

Buka `resources/views/layouts/sidebar.blade.php`. Di bagian section **PAYMENT**, tambahkan menu berikut setelah item "Approved":

```blade
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('invoice.*') ? 'active' : '' }}"
       href="{{ route('invoice.index') }}">
        <i data-feather="file-text"></i>
        <span class="menu-title">Invoice</span>
    </a>
</li>
```

- [ ] **Step 4: Commit**

```bash
git add routes/web.php resources/views/layouts/sidebar.blade.php
git commit -m "feat: add invoice routes and sidebar menu"
```

---

## Task 6: Views Invoice

**Files:**
- Create: `resources/views/payments/invoices/index.blade.php`
- Create: `resources/views/payments/invoices/create.blade.php`
- Create: `resources/views/payments/invoices/edit.blade.php`
- Create: `resources/views/payments/invoices/show.blade.php`

- [ ] **Step 1: Buat index view**

```blade
{{-- resources/views/payments/invoices/index.blade.php --}}
@extends('layouts.master')
@section('title', 'Daftar Invoice - SiMAPA')

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Daftar Invoice</h6>
                    <a href="{{ route('invoice.create') }}" class="btn btn-sm btn-primary">+ Buat Invoice</a>
                </div>

                {{-- Filter --}}
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua Status</option>
                            @foreach(['draft','diterbitkan','jatuh_tempo','lunas','dibatalkan','refund'] as $s)
                                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                                    {{ Str::title(str_replace('_',' ',$s)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">Semua Tipe</option>
                            <option value="proforma" {{ request('type')==='proforma' ? 'selected':'' }}>Proforma</option>
                            <option value="regular"  {{ request('type')==='regular'  ? 'selected':'' }}>Regular</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>No Invoice</th><th>Order</th><th>Tipe</th>
                                <th>Status</th><th>Nominal</th><th>Due Date</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invoices as $inv)
                            @php
                                $statusColors = [
                                    'draft'=>'secondary','diterbitkan'=>'primary',
                                    'jatuh_tempo'=>'warning','lunas'=>'success',
                                    'dibatalkan'=>'danger','refund'=>'purple',
                                ];
                                $color = $statusColors[$inv->status] ?? 'secondary';
                            @endphp
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td>
                                    <small class="text-muted">{{ $inv->order->code_order }}</small><br>
                                    {{ Str::limit($inv->order->details->title ?? '-', 30) }}
                                </td>
                                <td><span class="badge bg-{{ $inv->type === 'proforma' ? 'secondary' : 'info' }}">{{ ucfirst($inv->type) }}</span></td>
                                <td><span class="badge bg-{{ $color }}">{{ Str::title(str_replace('_',' ',$inv->status)) }}</span></td>
                                <td>
                                    @if($inv->payment)
                                        Rp {{ number_format($inv->payment->amount, 0, ',', '.') }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td><small>{{ $inv->due_at ? $inv->due_at->format('d/m/Y') : '-' }}</small></td>
                                <td>
                                    <a href="{{ route('invoice.show', $inv->id) }}" class="btn btn-xs btn-primary">Detail</a>
                                    @hasanyrole('manager|superadmin')
                                        <a href="{{ route('invoice.edit', $inv->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                    @endhasanyrole
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted">Belum ada invoice.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 2: Buat create view**

```blade
{{-- resources/views/payments/invoices/create.blade.php --}}
@extends('layouts.master')
@section('title', 'Buat Invoice - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Buat Invoice Baru</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('invoice.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Order <span class="text-danger">*</span></label>
                        <select name="order_id" class="form-select @error('order_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Order --</option>
                            @foreach($orders as $order)
                                <option value="{{ $order->id }}" {{ old('order_id') == $order->id ? 'selected' : '' }}>
                                    {{ $order->code_order }} — {{ $order->details->title ?? '-' }}
                                </option>
                            @endforeach
                        </select>
                        @error('order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tipe Invoice <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" value="proforma"
                                    id="typeProforma" {{ old('type','proforma') === 'proforma' ? 'checked' : '' }}>
                                <label class="form-check-label" for="typeProforma">Proforma (tagihan awal)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" value="regular"
                                    id="typeRegular" {{ old('type') === 'regular' ? 'checked' : '' }}>
                                <label class="form-check-label" for="typeRegular">Regular</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="paymentGroup">
                        <label class="form-label">Payment Terkait <small class="text-muted">(opsional)</small></label>
                        <select name="payment_id" class="form-select">
                            <option value="">-- Tanpa Payment --</option>
                            @foreach($payments as $pay)
                                <option value="{{ $pay->id }}" {{ old('payment_id') == $pay->id ? 'selected' : '' }}>
                                    {{ $pay->order->code_order }} — Rp {{ number_format($pay->amount,0,',','.') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nomor Invoice <span class="text-danger">*</span></label>
                        <input type="text" name="invoice_no" class="form-control @error('invoice_no') is-invalid @enderror"
                            value="{{ old('invoice_no') }}" placeholder="PRF-2026-001" required>
                        @error('invoice_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control @error('issued_at') is-invalid @enderror"
                                value="{{ old('issued_at', now()->toDateString()) }}" required>
                            @error('issued_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                            <input type="date" name="due_at" class="form-control @error('due_at') is-invalid @enderror"
                                value="{{ old('due_at', now()->addDays(14)->toDateString()) }}" required>
                            @error('due_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan Invoice</button>
                        <a href="{{ route('invoice.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 3: Buat edit view**

```blade
{{-- resources/views/payments/invoices/edit.blade.php --}}
@extends('layouts.master')
@section('title', 'Edit Invoice - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Edit Invoice — {{ $invoice->invoice_no }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('invoice.update', $invoice->id) }}">
                    @csrf @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Nomor Invoice <span class="text-danger">*</span></label>
                        <input type="text" name="invoice_no" class="form-control @error('invoice_no') is-invalid @enderror"
                            value="{{ old('invoice_no', $invoice->invoice_no) }}" required>
                        @error('invoice_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Terkait <small class="text-muted">(opsional)</small></label>
                        <select name="payment_id" class="form-select">
                            <option value="">-- Tanpa Payment --</option>
                            @foreach($payments as $pay)
                                <option value="{{ $pay->id }}"
                                    {{ old('payment_id', $invoice->payment_id) == $pay->id ? 'selected' : '' }}>
                                    {{ $pay->order->code_order }} — Rp {{ number_format($pay->amount,0,',','.') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control"
                                value="{{ old('issued_at', $invoice->issued_at?->toDateString()) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                            <input type="date" name="due_at" class="form-control"
                                value="{{ old('due_at', $invoice->due_at?->toDateString()) }}" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note', $invoice->note) }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        <a href="{{ route('invoice.show', $invoice->id) }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Buat show view**

```blade
{{-- resources/views/payments/invoices/show.blade.php --}}
@extends('layouts.master')
@section('title', 'Detail Invoice - SiMAPA')

@section('content')
<div class="row">
    <div class="col-md-8">

        {{-- Info Invoice --}}
        <div class="card mb-4">
            <div class="card-body">
                @php
                    $statusColors = [
                        'draft'=>'secondary','diterbitkan'=>'primary',
                        'jatuh_tempo'=>'warning','lunas'=>'success',
                        'dibatalkan'=>'danger','refund'=>'info',
                    ];
                    $color = $statusColors[$invoice->status] ?? 'secondary';
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0">{{ $invoice->invoice_no }}</h4>
                    <span class="badge bg-{{ $color }} fs-6">
                        {{ Str::title(str_replace('_',' ',$invoice->status)) }}
                    </span>
                </div>
                <div class="row">
                    <div class="col-sm-4"><p class="text-muted mb-1">Tipe</p><p>{{ ucfirst($invoice->type) }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Order</p><p>{{ $invoice->order->code_order }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Judul</p><p>{{ $invoice->order->details->title ?? '-' }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Terbit</p><p>{{ $invoice->issued_at?->format('d/m/Y') }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Jatuh Tempo</p>
                        <p class="{{ $invoice->isOverdue() ? 'text-danger fw-bold' : '' }}">
                            {{ $invoice->due_at?->format('d/m/Y') }}
                            @if($invoice->isOverdue()) <span class="badge bg-danger">Jatuh Tempo</span> @endif
                        </p>
                    </div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Catatan</p><p>{{ $invoice->note ?? '-' }}</p></div>
                </div>

                {{-- Aksi --}}
                <div class="d-flex gap-2 mt-3 flex-wrap">
                    @hasanyrole('manager|superadmin')
                        <a href="{{ route('invoice.edit', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <form method="POST" action="{{ route('invoice.updateStatus', $invoice->id) }}" class="d-flex gap-2">
                            @csrf
                            <select name="status" class="form-select form-select-sm" style="width:auto">
                                @foreach(\App\Models\Invoice::STATUSES as $s)
                                    <option value="{{ $s }}" {{ $invoice->status === $s ? 'selected' : '' }}>
                                        {{ Str::title(str_replace('_',' ',$s)) }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Update Status</button>
                        </form>
                    @endhasanyrole

                    @role('superadmin')
                        @if(!in_array($invoice->status, ['dibatalkan','refund']))
                        <form method="POST" action="{{ route('invoice.cancel', $invoice->id) }}"
                              onsubmit="return confirm('Batalkan invoice ini?')">
                            @csrf
                            <input type="hidden" name="note" value="Dibatalkan oleh superadmin.">
                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                        </form>
                        @endif
                        @if($invoice->status === 'lunas')
                        <form method="POST" action="{{ route('invoice.refund', $invoice->id) }}"
                              onsubmit="return confirm('Proses refund invoice ini?')">
                            @csrf
                            <input type="hidden" name="note" value="Refund diproses oleh superadmin.">
                            <button type="submit" class="btn btn-sm btn-outline-warning">Refund</button>
                        </form>
                        @endif
                    @endrole
                </div>
            </div>
        </div>

        {{-- Log History --}}
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Riwayat Status Invoice</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Dari</th><th>Ke</th><th>Oleh</th><th>Tanggal</th><th>Catatan</th></tr></thead>
                        <tbody>
                            @forelse($invoice->logs->sortByDesc('created_at') as $log)
                            <tr>
                                <td><span class="badge bg-secondary">{{ Str::title(str_replace('_',' ',$log->from_status ?: '-')) }}</span></td>
                                <td><span class="badge bg-primary">{{ Str::title(str_replace('_',' ',$log->to_status)) }}</span></td>
                                <td>{{ $log->changedBy->name ?? '-' }}</td>
                                <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                                <td>{{ $log->note ?? '-' }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted">Belum ada riwayat.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/payments/invoices/
git commit -m "feat: add invoice views (index, create, edit, show with log history)"
```

---

## Task 7: Verifikasi End-to-End

- [ ] **Step 1: Jalankan semua tests**

```bash
php artisan test --filter=InvoiceLifecycleTest
```

Expected: semua PASS

- [ ] **Step 2: Buka browser, login sebagai marketing**

Buka `/invoices/create`. Buat invoice proforma. Pastikan status awal = Draft, log tercatat.

- [ ] **Step 3: Login sebagai manager**

Buka detail invoice proforma. Update status ke "Diterbitkan". Coba klik Cancel → harus 403.

- [ ] **Step 4: Login sebagai superadmin**

Cancel sebuah invoice → berhasil. Buka invoice yang statusnya Lunas → tombol Refund muncul → proses refund → status berubah ke Refund, log tercatat.

- [ ] **Step 5: Test journal payment**

Buat order jurnal baru. Setelah order tersimpan, pastikan bisa akses `/payments/{code_order}/create`. Submit payment jurnal. Approve payment → invoice statusnya otomatis jadi Lunas.

- [ ] **Step 6: Final commit**

```bash
git add .
git commit -m "feat: complete payment + invoice lifecycle feature"
```
