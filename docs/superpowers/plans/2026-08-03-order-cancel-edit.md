# Koreksi Order (Edit & Batal) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memberi jalan koreksi pada order yang salah input — tombol Edit tersedia sejak order dibuat, dan order bisa **dibatalkan** (soft delete berjenjang + status `dibatalkan`) selama belum ada pembayaran yang **disetujui**, dengan jalur **Pulihkan** untuk manager/superadmin.

**Architecture:** Pembatalan adalah operasi lintas-tabel (order → detail → title progress → payment → invoice → tagihan) yang dipusatkan di satu service `OrderCancellationService` dalam satu `DB::transaction`. Penghapusan bersifat *soft* di tiga tabel (`tb_orders`, `tb_order_details`, `tb_title_progress`) sehingga *global scope* Eloquent otomatis membersihkan papan manuskrip, distribusi, dan dashboard produksi tanpa satu pun call site disentuh. Gerbang Batal dibaca dari `tb_payment_approvals.status === 'approved'`, **bukan** `tb_payments.status === 'paid'` (lihat spec §0.1).

**Tech Stack:** Laravel 10, Eloquent SoftDeletes, Spatie Permission (via `config/permissions.php` + middleware `access`), Bootstrap 5 + DataTables, PHPUnit.

**Spec:** [`docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md`](../specs/2026-08-03-order-cancel-title-management-design.md) — Bagian 1 (§1) + urutan implementasi §8 langkah 1–3.

---

## Penyimpangan yang disengaja dari spec (baca sebelum mulai)

Dua hal ditemukan saat verifikasi kode dan **sengaja berbeda** dari teks spec. Jangan "dibetulkan" balik ke spec.

1. **Status invoice yang dibatalkan = `'dibatalkan'`, bukan `'batal'`.**
   Spec §1.3 langkah 3 menulis `'batal'`. Tapi `Invoice::STATUSES` ([`app/Models/Invoice.php:31`](../../../app/Models/Invoice.php#L31)) hanya mengenal `draft|diterbitkan|jatuh_tempo|lunas|dibatalkan|refund`, `Invoice::isOverdue()` menyaring `['lunas','dibatalkan','refund']`, dan `InvoiceController::cancel()` sudah memakai `'dibatalkan'`. Memakai `'batal'` akan membuat invoice order yang dibatalkan tetap dihitung jatuh tempo dan badge-nya kosong. Untuk **Payment**, nilai `'batal'` dipakai persis seperti spec — kolomnya string bebas dan tidak ada kosakata lain yang berlaku.

2. **Status order setelah dipulihkan diturunkan dari payment, tidak dipaksa `'pending'`.**
   Spec §1.3 menulis "status kembali `pending`". Tapi [`PaymentBookController::store()`](../../../app/Http/Controllers/Pages/PaymentBookController.php#L170) menyetel `tb_orders.status = 'lunas'` begitu payment bertipe `lunas`/`pelunasan` disubmit — sebelum approval. Order seperti itu bisa dibatalkan (approval masih `pending`), dan memaksa `'pending'` saat dipulihkan akan **menghilangkan** status lunasnya. `restore()` karena itu memakai `statusAfterRestore()`: `'lunas'` bila ada payment `lunas`/`pelunasan` ber-status `paid` setelah dipulihkan, selain itu `'pending'`.

Catatan rute yang wajib diketahui: form Edit **buku** mengirim `$order->id` sebagai parameter `{code_order}` ([`orders/edit.blade.php:19`](../../../resources/views/orders/edit.blade.php#L19)), dan `OrderBookController::update()` memang memakai `Order::findOrFail($code_order)`. Form Edit **jurnal** mengirim `code_order` sungguhan. Penjagaan baru di `update()` harus memakai resolusi yang sama persis dengan yang sudah ada di masing-masing controller — jangan diseragamkan di rencana ini.

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_03_000001_add_cancel_fields_to_tb_orders.php` | Kolom `cancel_reason`, `cancelled_by`, `cancelled_at` |
| `database/migrations/2026_08_03_000002_add_soft_deletes_to_order_details_and_progress.php` | `deleted_at` di `tb_order_details` & `tb_title_progress` |
| `app/Models/Order.php` | Trait `SoftDeletes` + empat metode gerbang |
| `app/Models/OrderDetail.php`, `app/Models/TitleProgress.php` | Trait `SoftDeletes` |
| `app/Services/OrderCancellationService.php` | **Baru.** Seluruh logika `cancel()` / `restore()` |
| `app/Exceptions/OrderCancellationException.php` | **Baru.** Penjagaan pembatalan; punya `render()` sendiri mengikuti pola `CashEntryGuardException`, sehingga controller tidak perlu try/catch |
| `app/Services/Notifier.php` | Dua metode baru: `orderCancelled()`, `orderRestored()` |
| `app/Http/Controllers/Pages/OrderBookController.php` | `destroy()`, `restore()`, `index(?trashed=1)`, `show()` withTrashed, penjagaan `edit`/`update` |
| `app/Http/Controllers/Pages/OrderJournalController.php` | Penjagaan `edit`/`update`; stub `destroy()` diarahkan ke daftar order (route batal/pulihkan generik, dilayani `OrderBookController`) |
| `routes/web.php` | `order.cancel` (DELETE), `order.restore` (POST) |
| `config/permissions.php` + `database/seeders/AccessMatrixSeeder.php` | Permission `order.cancel`, `order.restore` |
| `resources/views/orders/book/index.blade.php` | Kolom Aksi ditulis ulang, badge Dibatalkan, modal Batal, toggle trashed |
| `resources/views/orders/book/show.blade.php` | Panel "Order ini dibatalkan" |
| `tests/Feature/OrderCancelTest.php`, `OrderRestoreTest.php`, `OrderEditGateTest.php` | **Baru.** |

---

## Task 1: Fondasi skema & model

**Files:**
- Create: `database/migrations/2026_08_03_000001_add_cancel_fields_to_tb_orders.php`
- Create: `database/migrations/2026_08_03_000002_add_soft_deletes_to_order_details_and_progress.php`
- Modify: `app/Models/Order.php`
- Modify: `app/Models/OrderDetail.php`
- Modify: `app/Models/TitleProgress.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/OrderCancelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function kolom_pembatalan_dan_soft_delete_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_orders', ['cancel_reason', 'cancelled_by', 'cancelled_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumn('tb_order_details', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('tb_title_progress', 'deleted_at'));
    }

    /** @test */
    public function tiga_model_memakai_soft_deletes(): void
    {
        $owner  = $this->user('marketing');
        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id]);
        $prog   = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        $order->delete();
        $detail->delete();
        $prog->delete();

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detail->id]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $prog->id]);
        $this->assertNull(Order::find($order->id));
        $this->assertNull(OrderDetail::find($detail->id));
        $this->assertNull(TitleProgress::find($prog->id));
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Failed asserting that false is true` (kolom `cancel_reason` belum ada).

- [ ] **Step 3: Buat migrasi kolom pembatalan**

Buat `database/migrations/2026_08_03_000001_add_cancel_fields_to_tb_orders.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('note');
            $table->foreignId('cancelled_by')->nullable()->after('cancel_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancel_reason', 'cancelled_at']);
        });
    }
};
```

> `tb_orders.deleted_at` **sudah ada** sejak [migrasi awal](../../../database/migrations/2025_12_19_040542_create_tb_orders_table.php) — jangan ditambahkan lagi.

- [ ] **Step 4: Buat migrasi soft delete berjenjang**

Buat `database/migrations/2026_08_03_000002_add_soft_deletes_to_order_details_and_progress.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
```

- [ ] **Step 5: Pasang trait di `Order`**

Di [`app/Models/Order.php`](../../../app/Models/Order.php), ganti blok baris 11–24 (ada `use HasFactory;` terduplikasi di baris 13 & 15 — sekalian dirapikan):

```php
class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_orders';

    protected $fillable = [
        'code_order', 'user_id', 'status',
        'note', 'ordered_at', 'completed_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected $dates = ['ordered_at', 'completed_at'];

    protected $casts = ['cancelled_at' => 'datetime'];
```

> Import `SoftDeletes` sudah ada di baris 8 — tidak perlu ditambah.

- [ ] **Step 6: Pasang trait di `OrderDetail`**

Di [`app/Models/OrderDetail.php`](../../../app/Models/OrderDetail.php), ubah baris 5–11 menjadi:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderDetail extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_order_details';
```

- [ ] **Step 7: Pasang trait di `TitleProgress`**

Di [`app/Models/TitleProgress.php`](../../../app/Models/TitleProgress.php), ubah baris 6–13 menjadi:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class TitleProgress extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_title_progress';
```

- [ ] **Step 8: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (2 tests).

- [ ] **Step 9: Audit pemakaian `->details` terhadap kemungkinan null**

Spec §1.7 mewajibkan audit ini: `$order->details` adalah relasi `hasOne`, dan detail order yang dibatalkan ikut soft-deleted, jadi relasi itu kini bisa bernilai **null**.

Run: `grep -rn -- "->details" app/ resources/views/`

Hasil audit yang sudah dilakukan saat perancangan — **konfirmasi ulang, jangan diambil mentah:**

| Lokasi | Keadaan | Tindakan |
|---|---|---|
| `IncomeController` (3×), `FinancialReportService` (2×), `ProfitAnalysisService`, `PaymentCashSyncService`, `RefundPdfData`, `Order::isLunas()` | sudah `optional(...)` | aman, jangan disentuh |
| `InvoiceController` (2×), `PaymentBookController:256`, `SendInvoiceJob:89`, view `payments/invoices/*`, `income/*` | sudah `?? 0` / `?? '-'` | aman, jangan disentuh |
| `SendInvoiceJob:55`, `InvoicePdfData:28` | melempar `$detail` ke view, view-nya ber-guard | aman |
| `OrderBookController::show()`, `edit()`, `update()`; `OrderJournalController::edit()`, `update()` | **tidak** ber-guard | ditangani Task 8 & 9 (`withTrashed()` + gerbang `isEditable()`) |
| `resources/views/orders/book/index.blade.php` (4×) | **tidak** ber-guard | ditangani Task 8 Step 6 (eager load `withTrashed()`) + Task 10 Step 5 |
| `resources/views/orders/edit.blade.php`, `orders/journal/edit.blade.php` | **tidak** ber-guard | aman: order yang dibatalkan ditolak 403 sebelum view dirender (Task 8) |
| `resources/views/payments/dp/index.blade.php:39` | `$detail = $order->details;` lalu dipakai | **periksa manual** — bila memakai `$detail->` tanpa guard, tambahkan `optional()` |

Expected: tidak ada pemakaian `->details` tanpa guard yang bisa dicapai oleh order yang dibatalkan. Catat temuan baru (bila ada) dan perbaiki di langkah ini juga.

- [ ] **Step 10: Jalankan SELURUH suite — regresi wajib hijau**

Run: `php artisan test`
Expected: seluruh test lulus. Soft delete tidak boleh menggeser satu angka pun di `PaidNetTest`.

Bila ada yang merah di titik ini, **berhenti dan perbaiki dulu** — kesalahan fondasi akan menular ke semua task berikutnya.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_03_000001_add_cancel_fields_to_tb_orders.php \
        database/migrations/2026_08_03_000002_add_soft_deletes_to_order_details_and_progress.php \
        app/Models/Order.php app/Models/OrderDetail.php app/Models/TitleProgress.php \
        tests/Feature/OrderCancelTest.php
git commit -m "feat(order): fondasi pembatalan — kolom cancel + soft delete berjenjang"
```

---

## Task 2: Metode gerbang di model `Order`

**Files:**
- Modify: `app/Models/Order.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan di `tests/Feature/OrderCancelTest.php` — `use` baru di atas file:

```php
use App\Models\Payment;
use App\Models\PaymentApproval;
```

dan metode-metode ini di dalam kelas:

```php
    /** Order + detail + progress lengkap, milik $owner. */
    private function makeOrder(User $owner, string $code = 'ORD-202608-0001'): Order
    {
        $order = Order::create([
            'code_order' => $code,
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        $detail = OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        return $order->fresh();
    }

    /** Payment `paid` + approval berstatus $approvalStatus (seperti alur submit sungguhan). */
    private function addPayment(Order $order, string $approvalStatus = 'pending', int $amount = 500000): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => 'dp',
            'amount'       => $amount,
            'status'       => 'paid',
            'paid_at'      => '2026-08-02',
        ]);

        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approvalStatus]);

        return $payment;
    }

    /** @test */
    public function gerbang_batal_membaca_approval_bukan_status_payment(): void
    {
        $owner = $this->user('marketing');

        $polos = $this->makeOrder($owner, 'ORD-202608-0001');
        $this->assertTrue($polos->isCancellable());
        $this->assertTrue($polos->isEditable());
        $this->assertFalse($polos->hasApprovedPayment());

        // Bukti bayar sudah diunggah (payment 'paid') tapi approval MASIH pending →
        // tetap boleh dibatalkan. Inilah kasus yang paling butuh dibatalkan.
        $menunggu = $this->makeOrder($owner, 'ORD-202608-0002');
        $this->addPayment($menunggu, 'pending');
        $this->assertFalse($menunggu->fresh()->hasApprovedPayment());
        $this->assertTrue($menunggu->fresh()->isCancellable());

        // Approval sudah 'approved' → Batal tertutup, Edit TETAP terbuka.
        $disetujui = $this->makeOrder($owner, 'ORD-202608-0003');
        $this->addPayment($disetujui, 'approved');
        $this->assertTrue($disetujui->fresh()->hasApprovedPayment());
        $this->assertFalse($disetujui->fresh()->isCancellable());
        $this->assertTrue($disetujui->fresh()->isEditable());
    }

    /** @test */
    public function order_dibatalkan_tidak_editable_dan_tidak_cancellable(): void
    {
        $order = $this->makeOrder($this->user('marketing'));
        $order->update(['status' => 'dibatalkan']);
        $order->delete();

        $trashed = Order::withTrashed()->find($order->id);
        $this->assertTrue($trashed->isCancelled());
        $this->assertFalse($trashed->isEditable());
        $this->assertFalse($trashed->isCancellable());
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Call to undefined method App\Models\Order::isCancellable()`.

- [ ] **Step 3: Tambah metode gerbang**

Di [`app/Models/Order.php`](../../../app/Models/Order.php), sisipkan sebelum `paidNet()`:

```php
    /**
     * Ada pembayaran yang benar-benar sudah disetujui approver.
     *
     * SENGAJA membaca tb_payment_approvals.status, BUKAN tb_payments.status:
     * PaymentBookController::store() menulis status 'paid' bersamaan dengan approval
     * 'pending', jadi 'paid' tidak berarti disetujui (spec §0.1).
     */
    public function hasApprovedPayment(): bool
    {
        // Pakai koleksi yang sudah di-eager load bila ada: daftar order memanggil
        // isCancellable() sekali per baris, dan payments()->exists() akan menembak
        // SQL baru tiap panggilan meski relasinya sudah dimuat.
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(
                fn ($payment) => optional($payment->approval)->status === 'approved'
            );
        }

        return $this->payments()
            ->whereHas('approval', fn ($q) => $q->where('status', 'approved'))
            ->exists();
    }

    /** Order dibatalkan: status 'dibatalkan' atau sudah soft-deleted. */
    public function isCancelled(): bool
    {
        return $this->status === 'dibatalkan' || $this->trashed();
    }

    /**
     * Boleh diedit selama belum dibatalkan — termasuk setelah pembayaran disetujui.
     * Gerbang ini dipakai untuk MEMBUKA Edit lebih awal, bukan menutupnya belakangan.
     */
    public function isEditable(): bool
    {
        return ! $this->isCancelled();
    }

    /**
     * Boleh dibatalkan: belum dibatalkan, belum ada payment yang disetujui,
     * DAN belum pernah di-refund.
     *
     * Refund sengaja ikut menutup gerbang: RefundController::paidIn() tidak memeriksa
     * approval, dan payment refund dibuat tanpa PaymentApproval — jadi order yang sudah
     * di-refund tetap lolos hasApprovedPayment(). Membatalkannya akan menghapus entri kas
     * pemasukan DAN pengeluaran refund-nya sekaligus, padahal uangnya benar-benar sudah
     * masuk lalu keluar lewat transfer bank.
     */
    public function isCancellable(): bool
    {
        return ! $this->isCancelled()
            && ! $this->hasApprovedPayment()
            && ! $this->hasRefund();
    }

    /** Sudah pernah di-refund (uang keluar tercatat). */
    public function hasRefund(): bool
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(fn ($payment) => $payment->payment_type === 'refund');
        }

        return $this->payments()->where('payment_type', 'refund')->exists();
    }
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Order.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): metode gerbang isEditable/isCancellable berbasis approval"
```

---

## Task 3: `OrderCancellationService::cancel()` — inti soft delete berjenjang

**Files:**
- Create: `app/Services/OrderCancellationService.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan `use` di `tests/Feature/OrderCancelTest.php`:

```php
use App\Services\OrderCancellationService;
```

dan test ini di dalam kelas:

```php
    /** @test */
    public function batal_menghapus_order_detail_dan_progress_secara_berjenjang(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $detailId = $order->details->id;
        $progId   = TitleProgress::where('order_detail_id', $detailId)->value('id');

        app(OrderCancellationService::class)->cancel($order, 'Salah input harga', $owner);

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detailId]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $progId]);

        $trashed = Order::withTrashed()->find($order->id);
        $this->assertSame('dibatalkan', $trashed->status);
        $this->assertSame('Salah input harga', $trashed->cancel_reason);
        $this->assertSame($owner->id, $trashed->cancelled_by);
        $this->assertNotNull($trashed->cancelled_at);
    }

    /** @test */
    public function alasan_boleh_kosong(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->assertNull(Order::withTrashed()->find($order->id)->cancel_reason);
        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
    }

    /** @test */
    public function batal_ditolak_bila_payment_sudah_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $this->addPayment($order, 'approved');

        $this->expectException(\App\Exceptions\OrderCancellationException::class);
        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);
    }

    /** @test */
    public function order_dibatalkan_hilang_dari_papan_manuskrip(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->assertSame(1, TitleProgress::count());

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->assertSame(0, TitleProgress::count());
        $this->assertSame(0, OrderDetail::count());
        $this->assertSame(1, TitleProgress::withTrashed()->count());
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Target class [App\Services\OrderCancellationService] does not exist.`

- [ ] **Step 3: Buat service dengan `cancel()` versi inti**

Buat `app/Services/OrderCancellationService.php`:

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pembatalan & pemulihan order.
 *
 * Semua penghapusan bersifat SOFT: nomor ORD-xxxx tidak pernah dipakai ulang, dan
 * soft delete berjenjang (order → detail → progress) membuat order yang dibatalkan
 * hilang sendirinya dari papan manuskrip, distribusi, dan dashboard produksi lewat
 * global scope Eloquent — tanpa satu pun call site OrderDetail::/TitleProgress::
 * yang tersebar di 12+ tempat perlu disentuh (spec §0.3).
 */
class OrderCancellationService
{
    public function cancel(Order $order, ?string $reason, User $actor): void
    {
        if (! $order->isCancellable()) {
            throw OrderCancellationException::notCancellable();
        }

        DB::transaction(function () use ($order, $reason, $actor) {
            $detailIds = $order->details()->pluck('id');

            TitleProgress::whereIn('order_detail_id', $detailIds)->delete();
            $order->details()->delete();

            $order->update([
                'status'       => 'dibatalkan',
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);
            $order->delete();
        });
    }
}
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderCancellationService.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): OrderCancellationService::cancel soft delete berjenjang"
```

---

## Task 4: `cancel()` — payment, approval, invoice, entri kas

**Files:**
- Modify: `app/Services/OrderCancellationService.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan `use` di `tests/Feature/OrderCancelTest.php`:

```php
use App\Models\CashEntry;
use App\Models\Invoice;
use App\Models\InvoiceLog;
```

dan test ini:

```php
    /** Invoice + log seperti yang dibuat PaymentBookController::store(). */
    private function addInvoice(Order $order, Payment $payment): Invoice
    {
        $invoice = Invoice::create([
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
            'invoice_no' => 'INV-' . $order->id . '-' . $payment->id,
            'issued_at'  => '2026-08-02',
            'due_at'     => '2026-08-09',
            'status'     => 'diterbitkan',
        ]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => '',
            'to_status'   => 'diterbitkan',
            'changed_by'  => $order->user_id,
            'note'        => 'Invoice dibuat otomatis dari pembayaran.',
        ]);

        return $invoice;
    }

    /** @test */
    public function batal_membatalkan_payment_approval_invoice_dan_menghapus_entri_kas(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending');
        $invoice = $this->addInvoice($order, $payment);

        // PaymentObserver sudah membuat entri kas saat payment ber-status 'paid'.
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);

        app(OrderCancellationService::class)->cancel($order->fresh(), 'Dobel input', $owner);

        $this->assertSame('batal', $payment->fresh()->status);
        $this->assertSame('rejected', $payment->fresh()->approval->status);
        $this->assertSame('dibatalkan', $invoice->fresh()->status);
        $this->assertSame($owner->id, $invoice->fresh()->cancelled_by);
        $this->assertNotNull($invoice->fresh()->cancelled_at);

        $this->assertDatabaseMissing('tb_cash_entries', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('tb_invoice_logs', [
            'invoice_id'  => $invoice->id,
            'from_status' => 'diterbitkan',
            'to_status'   => 'dibatalkan',
        ]);
    }

    /** @test */
    public function payment_yang_dibatalkan_keluar_dari_perhitungan_uang_masuk(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending', 750000);

        $this->assertSame(750000, $order->fresh()->paidNet());

        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);

        $this->assertSame(0, Order::withTrashed()->find($order->id)->paidNet());
        $this->assertSame(0, (int) Payment::income()->sum('amount'));
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Failed asserting that 'paid' is identical to 'batal'`.

- [ ] **Step 3: Lengkapi `cancel()`**

Di `app/Services/OrderCancellationService.php`, tambahkan `use` berikut di bagian atas:

```php
use App\Models\InvoiceLog;
```

lalu ganti isi closure `DB::transaction(...)` di `cancel()` menjadi:

```php
        DB::transaction(function () use ($order, $reason, $actor) {
            $this->cancelPayments($order, $actor);
            $this->cancelInvoices($order, $actor);

            $detailIds = $order->details()->pluck('id');

            TitleProgress::whereIn('order_detail_id', $detailIds)->delete();
            $order->details()->delete();

            $order->update([
                'status'        => 'dibatalkan',
                'cancel_reason' => $reason,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
            ]);
            $order->delete();
        });
```

dan tambahkan dua metode privat di akhir kelas:

```php
    /**
     * Payment yang belum disetujui → 'batal', approval-nya → 'rejected'.
     * PaymentObserver::saved() otomatis menghapus CashEntry-nya, karena
     * PaymentCashSyncService::sync() membuang entri untuk payment ber-status != 'paid'.
     */
    private function cancelPayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->with('approval')->get() as $payment) {
            $payment->update(['status' => 'batal']);

            if ($payment->approval) {
                $payment->approval->update([
                    'status'      => 'rejected',
                    'note'        => 'Order dibatalkan',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
            }
        }
    }

    /**
     * Invoice order → 'dibatalkan' (kosakata Invoice::STATUSES; 'batal' di spec §1.3
     * bukan status yang dikenal model, lihat catatan penyimpangan di rencana ini).
     */
    private function cancelInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->get() as $invoice) {
            $from = $invoice->status;

            $invoice->update([
                'status'       => 'dibatalkan',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $from,
                'to_status'   => 'dibatalkan',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan.',
            ]);
        }
    }
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderCancellationService.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): pembatalan ikut membatalkan payment, invoice, dan entri kas"
```

---

## Task 5: `cancel()` — tagihan tertaut dikembalikan

**Files:**
- Modify: `app/Services/OrderCancellationService.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan `use` di `tests/Feature/OrderCancelTest.php`:

```php
use App\Models\Tagihan;
```

dan test ini:

```php
    /** @test */
    public function tagihan_tertaut_kembali_ke_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $tagihan = Tagihan::create([
            'tagihan_no'   => 'TGH-0001',
            'created_by'   => $owner->id,
            'client_name'  => 'Klien A',
            'client_email' => 'klien@example.com',
            'title'        => 'Judul Uji',
            'type'         => 'bk_mandiri',
            'amount'       => 1000000,
            'status'       => 'jadi_order',
            'order_id'     => $order->id,
            'order_code'   => $order->code_order,
        ]);

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $tagihan->refresh();
        $this->assertSame('disetujui', $tagihan->status);
        $this->assertNull($tagihan->order_id);
        $this->assertNull($tagihan->order_code);
        $this->assertDatabaseHas('tb_tagihan_logs', [
            'tagihan_id'  => $tagihan->id,
            'from_status' => 'jadi_order',
            'to_status'   => 'disetujui',
        ]);
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Failed asserting that 'jadi_order' is identical to 'disetujui'`.

- [ ] **Step 3: Kembalikan tagihan di dalam transaksi**

Di `app/Services/OrderCancellationService.php`, tambahkan `use`:

```php
use App\Models\Tagihan;
use App\Models\TagihanLog;
```

Panggil di dalam closure `cancel()`, tepat setelah `$order->delete();`:

```php
            $this->releaseTagihan($order, $actor);
```

dan tambahkan metode privat:

```php
    /**
     * Tagihan yang sudah "jadi_order" dikembalikan ke 'disetujui'. Tanpa ini, tagihan
     * yang sudah disetujui ikut mati bersama order dan tidak bisa dipakai membuat
     * order pengganti.
     */
    private function releaseTagihan(Order $order, User $actor): void
    {
        $tagihans = Tagihan::where('order_id', $order->id)->where('status', 'jadi_order')->get();

        foreach ($tagihans as $tagihan) {
            $tagihan->update([
                'status'     => 'disetujui',
                'order_id'   => null,
                'order_code' => null,
            ]);

            TagihanLog::create([
                'tagihan_id'  => $tagihan->id,
                'from_status' => 'jadi_order',
                'to_status'   => 'disetujui',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan; tagihan bisa dipakai lagi.',
            ]);
        }
    }
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderCancellationService.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): tagihan tertaut kembali ke disetujui saat order dibatalkan"
```

---

## Task 6: `cancel()` — penjagaan kunci periode kas + notifikasi

**Files:**
- Modify: `app/Services/OrderCancellationService.php`
- Modify: `app/Services/Notifier.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan `use` di `tests/Feature/OrderCancelTest.php`:

```php
use App\Models\CashPeriodLock;
```

dan test ini:

```php
    /** @test */
    public function batal_ditolak_bila_periode_kas_terkunci(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending');

        // Entri kas payment jatuh di Agustus 2026 (paid_at = 2026-08-02).
        CashPeriodLock::create([
            'year'      => 2026,
            'month'     => 8,
            'locked_by' => $this->user('superadmin')->id,
            'locked_at' => now(),
        ]);

        try {
            app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);
            $this->fail('Pembatalan seharusnya ditolak karena periode kas terkunci.');
        } catch (\App\Exceptions\OrderCancellationException $e) {
            $this->assertStringContainsString('08/2026', $e->getMessage());
        }

        // Tidak ada satu pun efek samping yang lolos.
        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Pembatalan seharusnya ditolak karena periode kas terkunci.`

- [ ] **Step 3: Tambah penjagaan periode kas**

Di `app/Services/OrderCancellationService.php`, tambahkan `use`:

```php
use App\Models\CashEntry;
use Carbon\Carbon;
```

Tambahkan juga factory baru di `app/Exceptions/OrderCancellationException.php` (kelas ini dibuat di Task 3, mengikuti pola `CashEntryGuardException` — punya `render()` sendiri sehingga controller tak perlu try/catch):

```php
    public static function periodLocked(string $period): self
    {
        return new self(
            'Periode kas ' . $period . ' sudah dikunci. '
            . 'Minta superadmin membuka periode atau gunakan alur Refund.'
        );
    }
```

Panggil tepat setelah penjagaan `isCancellable()` di `cancel()`, **sebelum** `DB::transaction`:

```php
        $this->assertCashPeriodsUnlocked($order->payments()->pluck('id')->all());
```

dan tambahkan metode privat:

```php
    /**
     * Menghapus/membuat ulang CashEntry di periode yang sudah ditutup melanggar
     * CashPeriodLock. Aturan mainnya dibaca dari CashPeriodService yang sudah ada,
     * bukan diduplikasi. Berlaku dua arah: cancel menghapus entri, restore membuatnya lagi.
     *
     * @param  array<int>  $paymentIds
     */
    private function assertCashPeriodsUnlocked(array $paymentIds): void
    {
        if (empty($paymentIds)) {
            return;
        }

        $periods = CashEntry::whereIn('payment_id', $paymentIds)->pluck('tanggal');
        $service = app(CashPeriodService::class);

        foreach ($periods as $tanggal) {
            if (! $tanggal) {
                continue;
            }

            $d = Carbon::parse($tanggal);
            if ($service->isLocked((int) $d->format('Y'), (int) $d->format('n'))) {
                throw OrderCancellationException::periodLocked($d->format('m/Y'));
            }
        }
    }
```

> `CashPeriodService` berada di namespace yang sama (`App\Services`) — tidak perlu `use`.

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Tambah notifikasi ke `Notifier`**

Di [`app/Services/Notifier.php`](../../../app/Services/Notifier.php), tambahkan `use App\Models\Order;` di bagian import, dan dua metode ini setelah `refundIssued()`:

```php
    public function orderCancelled(Order $order, User $actor): void
    {
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'order',
            'title'    => 'Order dibatalkan',
            'message'  => $order->code_order . ' dibatalkan oleh ' . $actor->name,
            'url'      => route('order.book.index', ['trashed' => 1]),
            'icon'     => 'x-octagon',
        ]);
    }

    public function orderRestored(Order $order, User $actor): void
    {
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'order',
            'title'    => 'Order dipulihkan',
            'message'  => $order->code_order . ' dipulihkan oleh ' . $actor->name,
            'url'      => route('order.book.index'),
            'icon'     => 'rotate-ccw',
        ]);
    }
```

- [ ] **Step 6: Panggil notifikasi di luar transaksi**

Di `app/Services/OrderCancellationService.php`, tambahkan `use Illuminate\Support\Facades\Log;` lalu tambahkan di akhir `cancel()`, **setelah** blok `DB::transaction(...)`:

```php
        // Non-fatal: pembatalan sudah ter-commit. Kegagalan notifikasi tidak boleh
        // menjatuhkan alur (pola yang sama dengan paymentSubmitted).
        try {
            app(Notifier::class)->orderCancelled($order, $actor);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi pembatalan order gagal: ' . $e->getMessage());
        }
```

- [ ] **Step 7: Jalankan test — harus tetap LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (12 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Services/OrderCancellationService.php app/Services/Notifier.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): tolak pembatalan di periode kas terkunci + notifikasi manager"
```

---

## Task 7: `OrderCancellationService::restore()`

**Files:**
- Modify: `app/Services/OrderCancellationService.php`
- Test: `tests/Feature/OrderRestoreTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/OrderRestoreTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CashPeriodLock;
use App\Models\Invoice;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function makeOrder(User $owner, string $code = 'ORD-202608-0001'): Order
    {
        $order = Order::create([
            'code_order' => $code,
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        $detail = OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        return $order->fresh();
    }

    private function addPayment(Order $order, string $type = 'dp', int $amount = 500000): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => $type,
            'amount'       => $amount,
            'status'       => 'paid',
            'paid_at'      => '2026-08-02',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'pending']);

        Invoice::create([
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
            'invoice_no' => 'INV-' . $order->id . '-' . $payment->id,
            'issued_at'  => '2026-08-02',
            'due_at'     => '2026-08-09',
            'status'     => 'diterbitkan',
        ]);

        return $payment;
    }

    /** @test */
    public function pulihkan_mengembalikan_order_detail_progress_payment_dan_invoice(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order);
        $detailId = $order->details->id;
        $progId   = TitleProgress::where('order_detail_id', $detailId)->value('id');

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), 'salah', $owner);

        $service->restore(Order::withTrashed()->find($order->id), $manager);

        $restored = Order::find($order->id);
        $this->assertNotNull($restored);
        $this->assertSame('pending', $restored->status);
        $this->assertNull($restored->cancel_reason);
        $this->assertNull($restored->cancelled_by);
        $this->assertNull($restored->cancelled_at);

        $this->assertNotNull(OrderDetail::find($detailId));
        $this->assertNotNull(TitleProgress::find($progId));

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->approval->status);
        $this->assertSame('diterbitkan', Invoice::where('payment_id', $payment->id)->value('status'));
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);
    }

    /** @test */
    public function pulihkan_mengembalikan_status_lunas_bukan_pending(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $this->addPayment($order, 'lunas', 1000000);
        $order->update(['status' => 'lunas']); // seperti PaymentBookController::store()

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), null, $owner);
        $service->restore(Order::withTrashed()->find($order->id), $manager);

        $this->assertSame('lunas', Order::find($order->id)->status);
    }

    /** @test */
    public function pulihkan_ditolak_bila_order_tidak_dibatalkan(): void
    {
        $order = $this->makeOrder($this->user('marketing'));

        $this->expectException(\App\Exceptions\OrderCancellationException::class);
        app(OrderCancellationService::class)->restore($order, $this->user('manager'));
    }

    /** @test */
    public function pulihkan_ditolak_bila_periode_kas_terkunci(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order);

        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);

        // Periode dikunci SETELAH pembatalan: memulihkan akan membuat ulang entri kas di sana.
        CashPeriodLock::create([
            'year' => 2026, 'month' => 8,
            'locked_by' => $this->user('superadmin')->id, 'locked_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\OrderCancellationException::class);
        app(OrderCancellationService::class)->restore(Order::withTrashed()->find($order->id), $manager);
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderRestoreTest`
Expected: FAIL — `Call to undefined method App\Services\OrderCancellationService::restore()`.

- [ ] **Step 3: Implementasi `restore()`**

Di `app/Services/OrderCancellationService.php`, tambahkan metode publik setelah `cancel()`:

```php
    /**
     * Membalik cancel(): payment 'batal' → 'paid' (approval kembali 'pending'),
     * invoice 'dibatalkan' → 'diterbitkan', progress/detail/order di-restore.
     *
     * Tagihan SENGAJA tidak ditarik kembali: bila sudah dipakai order lain, menariknya
     * justru merusak data. Cukup terlihat di TagihanLog milik pembatalan.
     */
    public function restore(Order $order, User $actor): void
    {
        if (! $order->isCancelled()) {
            // Factory baru di OrderCancellationException (dibuat Task 3):
            //   public static function notCancelled(): self
            //   { return new self('Order ini tidak dalam keadaan dibatalkan.'); }
            throw OrderCancellationException::notCancelled();
        }

        // Memulihkan payment membuat ulang CashEntry-nya — penjagaan periode berlaku dua arah.
        $this->assertCashPeriodsUnlocked($order->payments()->pluck('id')->all());

        DB::transaction(function () use ($order, $actor) {
            $order->restore();

            $order->details()->onlyTrashed()->restore();
            $detailIds = $order->details()->pluck('id');
            TitleProgress::onlyTrashed()->whereIn('order_detail_id', $detailIds)->restore();

            $this->restorePayments($order, $actor);
            $this->restoreInvoices($order, $actor);

            $order->update([
                'status'        => $this->statusAfterRestore($order),
                'cancel_reason' => null,
                'cancelled_by'  => null,
                'cancelled_at'  => null,
            ]);
        });

        try {
            app(Notifier::class)->orderRestored($order, $actor);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi pemulihan order gagal: ' . $e->getMessage());
        }
    }
```

dan tiga metode privat pendukung di akhir kelas:

```php
    private function restorePayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->where('status', 'batal')->with('approval')->get() as $payment) {
            $payment->update(['status' => 'paid']); // observer membuat ulang CashEntry

            if ($payment->approval) {
                $payment->approval->update([
                    'status'      => 'pending',
                    'note'        => 'Order dipulihkan',
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }
        }
    }

    private function restoreInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->where('status', 'dibatalkan')->get() as $invoice) {
            $invoice->update([
                'status'       => 'diterbitkan',
                'cancelled_by' => null,
                'cancelled_at' => null,
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => 'dibatalkan',
                'to_status'   => 'diterbitkan',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dipulihkan.',
            ]);
        }
    }

    /**
     * Status order setelah dipulihkan diturunkan dari payment, TIDAK dipaksa 'pending':
     * PaymentBookController::store() menyetel 'lunas' begitu payment lunas/pelunasan
     * disubmit (sebelum approval), jadi memaksa 'pending' akan menghilangkan status itu.
     * Dipanggil SETELAH restorePayments() agar membaca status 'paid' yang sudah pulih.
     */
    private function statusAfterRestore(Order $order): string
    {
        $lunas = $order->payments()
            ->whereIn('payment_type', ['lunas', 'pelunasan'])
            ->where('status', 'paid')
            ->exists();

        return $lunas ? 'lunas' : 'pending';
    }
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderRestoreTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Jalankan kedua test order**

Run: `php artisan test --filter="OrderCancelTest|OrderRestoreTest"`
Expected: PASS (16 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/OrderCancellationService.php tests/Feature/OrderRestoreTest.php
git commit -m "feat(order): pemulihan order yang dibatalkan"
```

---

## Task 8: Route, permission, dan controller

**Files:**
- Modify: `routes/web.php`
- Modify: `config/permissions.php`
- Modify: `database/seeders/AccessMatrixSeeder.php`
- Modify: `app/Http/Controllers/Pages/OrderBookController.php`
- Modify: `app/Http/Controllers/Pages/OrderJournalController.php`
- Test: `tests/Feature/OrderCancelTest.php`, `tests/Feature/OrderEditGateTest.php`

- [ ] **Step 1: Tulis test HTTP yang gagal (pembatalan lewat route)**

Tambahkan di `tests/Feature/OrderCancelTest.php`:

```php
    /** @test */
    public function marketing_pemilik_bisa_membatalkan_lewat_route(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($owner)
            ->delete(route('order.cancel', $order->code_order), ['cancel_reason' => 'Salah harga'])
            ->assertRedirect(route('order.book.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSame('Salah harga', Order::withTrashed()->find($order->id)->cancel_reason);
    }

    /** @test */
    public function marketing_tidak_bisa_membatalkan_order_marketing_lain(): void
    {
        $owner = $this->user('marketing');
        $lain  = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($lain)
            ->delete(route('order.cancel', $order->code_order))
            ->assertForbidden();

        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    /** @test */
    public function production_tidak_punya_akses_pembatalan(): void
    {
        $order = $this->makeOrder($this->user('marketing'));

        $this->actingAs($this->user('production'))
            ->delete(route('order.cancel', $order->code_order))
            ->assertForbidden();
    }

    /** @test */
    public function route_batal_menolak_order_dengan_payment_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $this->addPayment($order, 'approved');

        $this->actingAs($owner)
            ->delete(route('order.cancel', $order->code_order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    /** @test */
    public function order_dibatalkan_tidak_muncul_di_daftar_default_tapi_muncul_di_daftar_trashed(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->actingAs($owner)->get(route('order.book.index'))
            ->assertOk()->assertDontSee($order->code_order);

        $this->actingAs($owner)->get(route('order.book.index', ['trashed' => 1]))
            ->assertOk()->assertSee($order->code_order);
    }

    /** @test */
    public function hanya_manager_atau_superadmin_yang_bisa_memulihkan(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->actingAs($owner)
            ->post(route('order.restore', $order->code_order))
            ->assertForbidden();

        $this->actingAs($this->user('manager'))
            ->post(route('order.restore', $order->code_order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(Order::find($order->id));
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=OrderCancelTest`
Expected: FAIL — `Route [order.cancel] not defined.`

- [ ] **Step 3: Daftarkan route**

Di [`routes/web.php`](../../../routes/web.php), di dalam grup `Route::prefix('order')->name('order.')`, tambahkan **setelah** baris `refund.pdf` (paling akhir dalam grup itu):

```php
        // Ditaruh PALING BAWAH agar segmen {code_order} tidak menelan path statis
        // buku/* dan jurnal/*. Batasan pola menjaga URL aneh tidak sampai ke controller.
        Route::delete('{code_order}', [OrderBookController::class, 'destroy'])
            ->name('cancel')->where('code_order', 'ORD-[A-Za-z0-9\-]+');
        Route::post('{code_order}/restore', [OrderBookController::class, 'restore'])
            ->name('restore')->where('code_order', 'ORD-[A-Za-z0-9\-]+');
```

- [ ] **Step 4: Petakan permission**

Di [`config/permissions.php`](../../../config/permissions.php), pada modul `order` (baris ~24), tambahkan dua action setelah `'refund'`:

```php
                'cancel'  => ['order.cancel'],
                'restore' => ['order.restore'],
```

Di [`database/seeders/AccessMatrixSeeder.php`](../../../database/seeders/AccessMatrixSeeder.php), pada array `$grants['marketing']`, ubah baris permission order menjadi:

```php
            'order.view', 'order.create', 'order.edit', 'order.cancel',
```

> `order.restore` **tidak** ditambahkan ke role manapun: manager & superadmin sudah mendapatkannya lewat hibah `'*'` / `Gate::before`.

- [ ] **Step 5: Isi `destroy()` dan `restore()` di `OrderBookController`**

Di [`app/Http/Controllers/Pages/OrderBookController.php`](../../../app/Http/Controllers/Pages/OrderBookController.php), tambahkan `use App\Services\OrderCancellationService;` di bagian import, lalu **ganti** stub `destroy()` (baris ~490) dengan:

```php
    /**
     * Batalkan order (soft delete berjenjang). Dipakai order buku MAUPUN jurnal —
     * route-nya generik di grup order.*.
     */
    public function destroy(Request $request, string $code_order)
    {
        $order = Order::where('code_order', $code_order)->firstOrFail();

        // Marketing hanya boleh menyentuh order miliknya sendiri — sejalan dengan
        // filter kepemilikan yang sudah dipakai index().
        abort_if(Auth::user()->hasRole('marketing') && $order->user_id !== Auth::id(), 403);

        $data = $request->validate(['cancel_reason' => 'nullable|string|max:1000']);

        // Tanpa try/catch: OrderCancellationException punya render() sendiri
        // (pola CashEntryGuardException) yang mengubah dirinya jadi back()->with('error').
        app(OrderCancellationService::class)->cancel($order, $data['cancel_reason'] ?? null, Auth::user());

        return redirect()->route('order.book.index')
            ->with('success', 'Order ' . $order->code_order . ' dibatalkan.');
    }

    /** Pulihkan order yang dibatalkan (manager/superadmin — dijaga permission order.restore). */
    public function restore(string $code_order)
    {
        $order = Order::withTrashed()->where('code_order', $code_order)->firstOrFail();

        app(OrderCancellationService::class)->restore($order, Auth::user());

        return redirect()->route('order.book.index')
            ->with('success', 'Order ' . $order->code_order . ' dipulihkan.');
    }
```

- [ ] **Step 6: Daftar order menerima `?trashed=1`**

Di file yang sama, ganti `index()` (baris 38–54) dengan:

```php
    public function index(Request $request)
    {
        $trashed = $request->boolean('trashed');

        // details di-eager load withTrashed() karena detail order yang dibatalkan
        // ikut soft-deleted — tanpa ini kolom Judul/Penulis null dan view pecah (spec §1.7).
        $orders = Order::with([
                'payments.approval',
                'details' => fn ($q) => $q->withTrashed(),
                'details.authors',
            ])
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when(Auth::user()->hasRole('marketing'), fn ($q) => $q->where('user_id', Auth::id()))
            ->latest()
            ->get();

        return view('orders.book.index', compact('orders', 'trashed'));
    }
```

- [ ] **Step 7: Arahkan stub `OrderJournalController::destroy()`**

Di [`app/Http/Controllers/Pages/OrderJournalController.php`](../../../app/Http/Controllers/Pages/OrderJournalController.php), ganti stub `destroy()` (baris ~346) dengan:

```php
    /**
     * Pembatalan order jurnal memakai route generik order.cancel (OrderBookController)
     * — sama seperti show() yang mengarah ke halaman detail generik. Method ini tidak
     * diroute; ada agar pemanggil lama tidak diam-diam menjadi no-op.
     */
    public function destroy(string $code_order)
    {
        return redirect()->route('order.book.index');
    }
```

- [ ] **Step 8: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (18 tests).

Bila `order_dibatalkan_tidak_muncul_di_daftar_default...` gagal karena view belum menampilkan apa pun untuk daftar trashed, biarkan — Task 10 memperbaiki view. Bila gagal karena error 500, perbaiki sekarang.

- [ ] **Step 9: Test gerbang Edit**

Buat `tests/Feature/OrderEditGateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderEditGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function makeOrder(User $owner): Order
    {
        $order = Order::create([
            'code_order' => 'ORD-202608-0001',
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        OrderContact::create([
            'order_id' => $order->id, 'cp_phone' => '0811', 'cp_email' => 'cp@example.com',
        ]);

        return $order->fresh();
    }

    /** @test */
    public function edit_terbuka_sejak_order_dibuat(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))
            ->assertOk()->assertSee('Judul Uji');
    }

    /** @test */
    public function edit_tetap_terbuka_setelah_payment_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $payment = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000,
            'status' => 'paid', 'paid_at' => '2026-08-02',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'approved']);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))->assertOk();
    }

    /** @test */
    public function edit_ditolak_untuk_order_yang_dibatalkan(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))
            ->assertForbidden();

        $this->actingAs($owner)->put(route('order.book.update', $order->id), [
            'type' => 'bk_mandiri', 'title_id' => 'Judul Lain', 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'issued_at' => '2026-08-01', 'cost_amount' => 1000000,
            'contact_phone' => '0811', 'contact_email' => 'cp@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ])->assertForbidden();
    }
}
```

- [ ] **Step 10: Jalankan — harus GAGAL di test ketiga**

Run: `php artisan test --filter=OrderEditGateTest`
Expected: FAIL — `edit_ditolak_untuk_order_yang_dibatalkan` mendapat 404 (order sudah tersaring soft delete), bukan 403.

- [ ] **Step 11: Pasang penjagaan di `edit()`/`update()` kedua controller**

Di `OrderBookController::edit()`, ganti baris pengambilan order menjadi:

```php
        $order = Order::withTrashed()->with(['details' => fn ($q) => $q->withTrashed(), 'details.authors', 'contact', 'invoices'])
            ->where('code_order', $code_order)->firstOrFail();
        abort_unless($order->isEditable(), 403);
```

Di `OrderBookController::update()`, tepat setelah blok `$request->validate([...])` dan **sebelum** `try {`, tambahkan:

```php
        // Resolusi order di update() memakai findOrFail (form Edit buku mengirim
        // $order->id sebagai parameter {code_order}) — penjagaan harus memakai
        // resolusi yang sama persis agar tidak meleset ke order lain.
        abort_unless(Order::withTrashed()->findOrFail($code_order)->isEditable(), 403);
```

Di `OrderJournalController::edit()`, ganti baris pengambilan order menjadi:

```php
        $order = Order::withTrashed()->with(['details' => fn ($q) => $q->withTrashed(), 'details.authors', 'details.scopes', 'contact'])
            ->where('code_order', $code_order)->firstOrFail();
        abort_unless($order->isEditable(), 403);
```

Di `OrderJournalController::update()`, tepat setelah `$request->validate([...])` dan sebelum `try {`:

```php
        abort_unless(
            Order::withTrashed()->where('code_order', $code_order)->firstOrFail()->isEditable(),
            403
        );
```

- [ ] **Step 12: Jalankan test — harus LULUS**

Run: `php artisan test --filter="OrderEditGateTest|OrderJournalEditTest"`
Expected: PASS.

- [ ] **Step 13: Jalankan test hak akses & pemetaan permission**

Run: `php artisan test --filter="PermissionMapCompletenessTest|AccessParityTest|PermissionPageTest|PermissionButtonVisibilityTest"`
Expected: PASS. `PermissionMapCompletenessTest` akan merah bila route baru lupa dipetakan di `config/permissions.php`.

- [ ] **Step 14: Commit**

```bash
git add routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php \
        app/Http/Controllers/Pages/OrderBookController.php \
        app/Http/Controllers/Pages/OrderJournalController.php \
        tests/Feature/OrderCancelTest.php tests/Feature/OrderEditGateTest.php
git commit -m "feat(order): route + permission batal/pulihkan, gerbang edit di controller"
```

---

## Task 9: Halaman detail order yang dibatalkan

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php`
- Modify: `resources/views/orders/book/show.blade.php`
- Test: `tests/Feature/OrderCancelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan di `tests/Feature/OrderCancelTest.php`:

```php
    /** @test */
    public function halaman_detail_order_dibatalkan_tampil_dengan_panel_pembatalan(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, 'Klien mundur', $owner);

        $this->actingAs($owner)->get(route('order.book.show', $order->code_order))
            ->assertOk()
            ->assertSee('Order ini dibatalkan')
            ->assertSee('Klien mundur')
            ->assertSee('Judul Uji');
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=halaman_detail_order_dibatalkan`
Expected: FAIL — 404 (order tersaring global scope).

- [ ] **Step 3: `show()` membaca order + detail yang trashed**

Di `OrderBookController::show()`, ganti blok query (baris ~321–331) menjadi:

```php
        // withTrashed() di dua tingkat: order yang dibatalkan harus tetap bisa dilihat
        // read-only, dan detail-nya ikut soft-deleted (spec §1.7).
        $order = Order::withTrashed()->with([
            'details' => fn ($q) => $q->withTrashed(),
            'details.authors',
            'details.scopes',
            'payments.approval',
            'payments.invoice',
            'invoices',
            'contact',
        ])->where('code_order', $code_order)->firstOrFail();

        $firstDetail = $order->details;
```

- [ ] **Step 4: Panel pembatalan di view**

Di [`resources/views/orders/book/show.blade.php`](../../../resources/views/orders/book/show.blade.php), sisipkan tepat setelah blok header (`</div>` penutup baris 19) dan **sebelum** `<div class="row">` pertama:

```blade
    @if ($order->isCancelled())
        <div class="alert alert-secondary border" role="alert">
            <h6 class="alert-heading mb-1">Order ini dibatalkan</h6>
            <div class="small">
                Dibatalkan oleh <strong>{{ $order->cancelledBy?->name ?? '—' }}</strong>
                pada {{ optional($order->cancelled_at)->format('d/m/Y H:i') ?? '—' }}.
            </div>
            @if ($order->cancel_reason)
                <div class="small mt-1">Alasan: <em>{{ $order->cancel_reason }}</em></div>
            @endif
            <div class="small text-muted mt-1">Halaman ini hanya-baca. Pulihkan order lewat daftar order (manager/superadmin).</div>
        </div>
    @endif
```

- [ ] **Step 5: Tambah relasi `cancelledBy` di `Order`**

Di [`app/Models/Order.php`](../../../app/Models/Order.php), tambahkan setelah `user()`:

```php
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
```

- [ ] **Step 6: Matikan tombol yang mengubah data pada order yang dibatalkan**

Halaman ini hanya punya **dua** kontrol yang mengubah data, keduanya berpasangan `@can('payment.edit')` + `@if ($appStatus === 'pending')`: tombol "Edit" pembayaran (baris ~194–199) dan modal Edit Pembayaran (baris ~203–205). Sisanya link baca (Lihat Bukti, Download Invoice, Download) yang memang tetap boleh dibuka.

Ganti **kedua** baris berikut:

```blade
                                                @if ($appStatus === 'pending')
```
```blade
                                        @if ($appStatus === 'pending')
```

menjadi (pertahankan indentasi masing-masing):

```blade
                                                @if ($appStatus === 'pending' && ! $order->isCancelled())
```
```blade
                                        @if ($appStatus === 'pending' && ! $order->isCancelled())
```

> Penjagaan ini defensif: `cancel()` menyetel approval jadi `rejected`, jadi `$appStatus === 'pending'` sudah false dengan sendirinya. Ditulis eksplisit supaya tidak bergantung pada efek samping itu.

Verifikasi tidak ada kontrol lain yang terlewat:

Run: `grep -n "method=\"POST\"\|data-bs-target" resources/views/orders/book/show.blade.php`
Expected: hanya form Edit Pembayaran dan tombol pemicunya — keduanya kini ber-guard.

- [ ] **Step 7: Jalankan test — harus LULUS**

Run: `php artisan test --filter=OrderCancelTest`
Expected: PASS (19 tests).

- [ ] **Step 8: Jalankan test yang menyentuh halaman detail**

Run: `php artisan test --filter="DetailOrderPaymentInvoiceTest|OrderJournalEditTest|PaymentApprovalQueueTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php app/Models/Order.php \
        resources/views/orders/book/show.blade.php tests/Feature/OrderCancelTest.php
git commit -m "feat(order): halaman detail order dibatalkan (read-only + panel alasan)"
```

---

## Task 10: Kolom Aksi, badge, modal Batal, dan toggle di daftar order

**Files:**
- Modify: `resources/views/orders/book/index.blade.php`

- [ ] **Step 1: Ganti kolom Status Order**

Di [`resources/views/orders/book/index.blade.php`](../../../resources/views/orders/book/index.blade.php), ganti blok `<td>` status (baris 66–72) dengan:

```blade
                                            <td>
                                                @if ($order->isCancelled())
                                                    <span class="badge bg-secondary">Dibatalkan</span>
                                                @elseif ($order->status == 'pending')
                                                    <span class="badge bg-warning text-dark">Menunggu</span>
                                                @else
                                                    <span class="badge bg-success">Diproses</span>
                                                @endif
                                            </td>
```

- [ ] **Step 2: Tulis ulang kolom Aksi**

Ganti seluruh blok `<td>` Aksi (baris 73–127) dengan:

```blade
                                            <td>
                                                @php
                                                    $isJournal = in_array($order->details?->type, ['at_mandiri', 'at_kolab'], true);
                                                    $editUrl   = $isJournal
                                                        ? route('order.journal.edit', $order->code_order)
                                                        : route('order.book.edit', $order->code_order);
                                                    $hasPayment = $order->payments->isNotEmpty();
                                                    $approved   = $order->payments->contains(
                                                        fn ($p) => optional($p->approval)->status === 'approved'
                                                    );
                                                @endphp

                                                @if ($order->isCancelled())
                                                    {{-- Dibatalkan: hanya-baca + Pulihkan (manager/superadmin) --}}
                                                    <a href="{{ route('order.book.show', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-secondary" title="Lihat">
                                                        <i data-feather="eye"></i>
                                                    </a>
                                                    @can('order.restore')
                                                        <form action="{{ route('order.restore', $order->code_order) }}"
                                                            method="POST" class="d-inline m-0">
                                                            @csrf
                                                            <button class="btn btn-icon btn-outline-success" title="Pulihkan">
                                                                <i data-feather="rotate-ccw"></i>
                                                            </button>
                                                        </form>
                                                    @endcan
                                                @else
                                                    @if (!$hasPayment)
                                                        <a href="{{ route('payment.create', $order->code_order) }}"
                                                            class="btn btn-icon btn-primary" title="Pembayaran">
                                                            <i data-feather="credit-card"></i>
                                                        </a>
                                                    @else
                                                        <a href="{{ route('order.book.show', $order->code_order) }}"
                                                            class="btn btn-icon btn-primary" title="Lihat">
                                                            <i data-feather="eye"></i>
                                                        </a>
                                                    @endif

                                                    @can('order.edit')
                                                        <a href="{{ $editUrl }}" class="btn btn-icon btn-outline-primary" title="Edit">
                                                            <i data-feather="edit"></i>
                                                        </a>
                                                    @endcan

                                                    @if ($order->isCancellable())
                                                        @can('order.cancel')
                                                            <button type="button" class="btn btn-icon btn-outline-danger"
                                                                data-bs-toggle="modal" data-bs-target="#cancelOrder{{ $order->id }}"
                                                                title="Batalkan order">
                                                                <i data-feather="x-octagon"></i>
                                                            </button>
                                                        @endcan
                                                    @endif

                                                    @if ($approved)
                                                        @can('order.refund')
                                                            @php
                                                                $paidIn   = $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');
                                                                $refunded = $order->payments->where('payment_type', 'refund')->isNotEmpty();
                                                            @endphp
                                                            @if ($refunded)
                                                                <a href="{{ route('order.refund.pdf', $order->code_order) }}" target="_blank"
                                                                    class="btn btn-icon btn-outline-secondary" title="Bukti Refund">
                                                                    <i data-feather="file-text"></i>
                                                                </a>
                                                            @elseif ($paidIn > 0)
                                                                <a href="{{ route('order.refund.form', $order->code_order) }}"
                                                                    class="btn btn-icon btn-outline-warning" title="Refund">
                                                                    <i data-feather="corner-up-left"></i>
                                                                </a>
                                                            @endif
                                                        @endcan
                                                    @endif
                                                @endif
                                            </td>
```

- [ ] **Step 3: Tambah toggle "Tampilkan order dibatalkan"**

Ganti blok header kartu (baris 17–19) dengan:

```blade
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">
                            {{ $trashed ? 'Order Dibatalkan' : 'Manajemen Order' }}
                        </h6>
                        @if ($trashed)
                            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary">
                                ← Kembali ke order aktif
                            </a>
                        @else
                            <a href="{{ route('order.book.index', ['trashed' => 1]) }}"
                                class="btn btn-sm btn-outline-secondary">
                                Tampilkan order dibatalkan
                            </a>
                        @endif
                    </div>
```

- [ ] **Step 4: Tambah modal konfirmasi Batal (di luar tabel)**

Sisipkan tepat setelah `</div>` penutup `.table-responsive` (baris ~132), masih di dalam `.card-body`:

```blade
                    {{-- Modal ditaruh DI LUAR <table>: DataTables + responsive memindahkan
                         DOM baris, dan modal yang bersarang di <td> bisa ikut tersembunyi. --}}
                    @foreach ($orders as $order)
                        @if ($order->isCancellable())
                            <div class="modal fade" id="cancelOrder{{ $order->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <form class="modal-content" method="POST"
                                        action="{{ route('order.cancel', $order->code_order) }}">
                                        @csrf
                                        @method('DELETE')
                                        <div class="modal-header">
                                            <h5 class="modal-title">Batalkan Order</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="mb-2">Order berikut akan dibatalkan:</p>
                                            <ul class="mb-3">
                                                <li>Kode: <strong>{{ $order->code_order }}</strong></li>
                                                <li>Judul: {{ $order->details?->title ?? '—' }}</li>
                                                <li>Total biaya: Rp {{ number_format((int) ($order->details?->cost_amount ?? 0), 0, ',', '.') }}</li>
                                            </ul>
                                            <div class="mb-2">
                                                <label class="form-label">Alasan pembatalan <span class="text-muted">(opsional)</span></label>
                                                <textarea name="cancel_reason" class="form-control" rows="3"
                                                    placeholder="Mis. salah input harga, klien membatalkan"></textarea>
                                            </div>
                                            <p class="small text-muted mb-0">
                                                Order tidak dihapus permanen — nomor order tetap tercatat dan bisa dipulihkan
                                                oleh manager/superadmin.
                                            </p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Kembali</button>
                                            <button type="submit" class="btn btn-sm btn-danger">Ya, batalkan order</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    @endforeach
```

- [ ] **Step 5: Amankan kolom Judul/Penulis/Jenis dari detail null**

Ganti baris 39–65 (kolom Judul, Penulis, Jenis) dengan:

```blade
                                            <td>{{ $order->details ? Str::title(Str::limit($order->details->title, 30)) : '-' }}</td>
                                            <td class="dt-judul">
                                                @foreach ($order->details?->authors ?? [] as $author)
                                                    <span class="badge border text-dark fw-normal bg-light me-1 mb-1">
                                                        <i class="fa fa-user size-10"></i> {{ $author->name }}
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td>
                                                @switch($order->details?->type)
                                                    @case('bk_mandiri')
                                                    @case('bk_kolab')
                                                        Buku
                                                    @break

                                                    @case('at_mandiri')
                                                    @case('at_kolab')
                                                        Artikel
                                                    @break

                                                    @default
                                                        —
                                                @endswitch
                                            </td>
```

- [ ] **Step 6: Jalankan test terkait**

Run: `php artisan test --filter="OrderCancelTest|OrderRestoreTest|OrderEditGateTest|PermissionButtonVisibilityTest"`
Expected: PASS (semua).

- [ ] **Step 7: Buka aplikasinya**

Run: `php artisan migrate` (DB dev `avidpedi_simapa`), lalu buka `/management/order` sebagai marketing.
Expected:
- Order baru menampilkan tombol Payment · Edit · Batal;
- Klik Batal membuka modal dengan kode order, judul, dan total biaya;
- Setelah dibatalkan, order hilang dari daftar dan muncul di "Tampilkan order dibatalkan" dengan badge abu-abu;
- Sebagai manager, tombol Pulihkan tampil di daftar itu.

- [ ] **Step 8: Commit**

```bash
git add resources/views/orders/book/index.blade.php
git commit -m "feat(order): kolom aksi baru, badge dibatalkan, modal batal, toggle order dibatalkan"
```

---

## Task 11: Verifikasi akhir

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: seluruh test lulus (baseline 2026-07-24: 744 lulus, 1 skip — ditambah ~26 test baru dari rencana ini). **Setiap** kegagalan wajib ditelusuri, terutama:
- `PaidNetTest` — soft delete tidak boleh menggeser satu angka pun pada order aktif;
- `RouteSmokeTest` / `DeepRouteSmokeTest` — route baru tidak boleh 500;
- `ManuscriptTrackerTest`, `ArticleDistributionTest`, `BookDistributionTest`, `ProductionWorkspaceTest` — global scope baru tidak boleh menghilangkan data order aktif.

- [ ] **Step 2: Migrasi DB dev**

Run: `php artisan migrate`
Expected: `2026_08_03_000001` dan `2026_08_03_000002` tereksekusi. Tanpa ini, aplikasi dev 500 pada kolom yang belum ada.

- [ ] **Step 3: Seed ulang matriks akses di DB dev**

Run: `php artisan db:seed --class=AccessMatrixSeeder`
Expected: permission `order.cancel` & `order.restore` tercipta dan tersinkron ke role.

- [ ] **Step 4: Cek halaman Hak Akses**

Buka `/hak-akses` sebagai superadmin.
Expected: modul Order kini menampilkan action **cancel** dan **restore**.

- [ ] **Step 5: Commit dokumentasi status spec**

Di [`docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md`](../specs/2026-08-03-order-cancel-title-management-design.md), ubah baris `**Status:** Draft — menunggu review` menjadi:

```markdown
**Status:** Bagian 1 (Edit & Batal Order) terimplementasi 2026-08-03 — lihat `docs/superpowers/plans/2026-08-03-order-cancel-edit.md`. Bagian 2a/2b/2c menyusul di `docs/superpowers/plans/2026-08-03-title-management.md`.
```

```bash
git add docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md
git commit -m "docs: tandai Bagian 1 spec koreksi order sebagai terimplementasi"
```

---

## Utang teknis yang ditemukan saat implementasi (SENGAJA tidak dikerjakan di sini)

**`CashLog` tidak pernah ditulis saat entri kas dihapus lewat sinkron payment.**
[`PaymentCashSyncService::sync()`](../../../app/Services/PaymentCashSyncService.php#L17) memakai `CashEntry::where('payment_id', ...)->delete()` — bulk delete lewat query builder, yang **tidak** membangkitkan model event, sehingga [`CashEntryObserver::deleted()`](../../../app/Observers/CashEntryObserver.php#L36) tak pernah jalan dan tak ada baris `CashLog`. Padahal header `CashAuditLogTest` menyatakan observer itu ada justru supaya "SEMUA jalur tertangkap".

Ini **pra-ada**, bukan regresi dari fitur pembatalan: `PaymentBookController::reject()` sudah lama menempuh jalur yang sama. Tapi `OrderCancellationService::cancelPayments()` kini menjadi pemicu dengan volume tertinggi, tepat di skenario "membalik uang" yang paling butuh jejak audit.

Tidak diperbaiki di rencana ini karena menyentuh kode akuntansi bersama di luar lingkup pembatalan order. Layak jadi spec/perbaikan tersendiri.

---

## Catatan risiko

**Risiko regresi terbesar** ada di Task 1 (global scope soft delete baru di `OrderDetail` & `TitleProgress`). Keduanya di-query **langsung** — tanpa join ke `tb_orders` — di sedikitnya 12 tempat: `ManuscriptTrackerController`, `TitleController`, `ManuscriptStageStatsService`, `TitleProgressService`, `TitleBackfillService`, model `Author`/`Scope`/`Title`/`TitleProgress`, `PerformanceService`, `ProductionDashboardService`, dan dua controller order. Itu justru **tujuannya** (order dibatalkan hilang sendirinya), tapi berarti satu bug di trait akan terasa di seluruh aplikasi. Karena itu Step 9 di Task 1 menjalankan seluruh suite sebelum melangkah.

**Surface yang menampilkan order dibatalkan wajib `withTrashed()`** pada relasi `details` — sudah ditangani di `index()` (Task 8) dan `show()` (Task 9). Surface lain (Invoice, Income, Payment, laporan keuangan) **sengaja tidak** disentuh: semuanya sudah memakai `optional(...)` atau `?? '-'`, dan order yang dibatalkan memang seharusnya tidak muncul di sana.
