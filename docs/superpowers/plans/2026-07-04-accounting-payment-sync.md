# Akuntansi Fase B: Auto-flow Payment → Jurnal Kas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Payment `paid` otomatis jadi entri Jurnal Kas (idempotent per payment, DP/Pelunasan→pemasukan, Refund→pengeluaran) via observer; entri auto read-only.

**Architecture:** `PaymentObserver` (saved/deleted) → `PaymentCashSyncService::sync` upsert/delete `CashEntry` keyed by `payment_id`. Kategori dipetakan dari tipe order via `map_key`. Observer null-safe (aman di transaksi payment). View Jurnal Kas mengunci entri `source=payment`.

**Tech Stack:** Laravel 11 model observer, Eloquent, Blade. Test: PHPUnit `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_04_000005_add_payment_sync_to_cash.php` (**create**)
- `app/Models/CashEntry.php` (**modify**) — +`payment_id` fillable + `payment()`
- `app/Models/CashCategory.php` (**modify**) — +`map_key` fillable
- `app/Services/PaymentCashSyncService.php` (**create**)
- `app/Observers/PaymentObserver.php` (**create**)
- `app/Providers/AppServiceProvider.php` (**modify**) — register observer
- `resources/views/accounting/journal.blade.php` (**modify**) — lock entri auto
- `tests/Feature/PaymentCashSyncTest.php` (**create**)

---

## Konteks untuk implementer

- `Payment` (`tb_payments`): `order_id`, `payment_type` (dp/pelunasan/refund), `amount`, `paid_at`, `status` (paid/pending/rejected). Relasi `order()` belongsTo, `invoice()` hasOne Invoice (invoice_no). Payment jadi `paid` di `PaymentBookController::store` (create langsung paid, dalam `DB::transaction`) & `approve` (update pending→paid).
- `Order` → `details()` hasOne `OrderDetail` (`type` bk_mandiri/bk_kolab/at_mandiri/at_kolab, `title`), `code_order`, `user` (marketing).
- `CashJournalService::deriveKode(Carbon)` sudah ada. `CashCategory` map_key baru dipakai untuk mapping.
- Migrasi terakhir: `2026_07_04_000004`. Baru: `000005`.
- Observer WAJIB null-safe & tak melempar (jalan di dalam transaksi store payment; throw → rollback payment).
- Fixture Payment: butuh Order(+OrderDetail). Invoice opsional; karena observer fire saat Payment disimpan (sebelum invoice dibuat di store), `ref` jatuh ke `code_order` bila belum ada invoice → test assert `code_order`.

---

### Task 1: Migrasi (payment_id + map_key + backfill + Refund) + model

**Files:** migrasi `2026_07_04_000005`; `CashEntry.php`; `CashCategory.php`

- [ ] **Step 1: Migrasi `2026_07_04_000005_add_payment_sync_to_cash.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->unique()->after('created_by')->constrained('tb_payments')->cascadeOnDelete();
        });
        Schema::table('tb_cash_categories', function (Blueprint $table) {
            $table->string('map_key')->nullable()->after('jenis');
        });

        $map = [
            'Order Artikel Kolaborasi' => 'at_kolab',
            'Order Artikel Mandiri'    => 'at_mandiri',
            'Order Buku Kolaborasi'    => 'bk_kolab',
            'Order Buku Mandiri'       => 'bk_mandiri',
        ];
        foreach ($map as $name => $key) {
            DB::table('tb_cash_categories')->where('name', $name)->update(['map_key' => $key]);
        }

        DB::table('tb_cash_categories')->insert([
            'jenis' => 'pengeluaran', 'name' => 'Refund', 'map_key' => 'refund',
            'active' => true, 'position' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
        Schema::table('tb_cash_categories', function (Blueprint $table) {
            $table->dropColumn('map_key');
        });
        DB::table('tb_cash_categories')->where('map_key', 'refund')->where('name', 'Refund')->delete();
    }
};
```

- [ ] **Step 2: `app/Models/CashEntry.php` — +`payment_id` + relasi** — ubah `$fillable` menambah `'payment_id'` dan tambah method:

Ganti baris fillable:
```php
    protected $fillable = ['tanggal', 'kode', 'keterangan', 'jenis', 'amount', 'cash_category_id', 'produk', 'ref', 'catatan', 'source', 'created_by', 'payment_id'];
```
Tambah relasi (setelah `creator()`):
```php
    public function payment() { return $this->belongsTo(\App\Models\Payment::class); }
```

- [ ] **Step 3: `app/Models/CashCategory.php` — +`map_key`** — ubah `$fillable`:
```php
    protected $fillable = ['name', 'jenis', 'active', 'position', 'map_key'];
```

- [ ] **Step 4: Migrasi DB test + commit**

Run: `php artisan migrate --env=testing`
Expected: `2026_07_04_000005_add_payment_sync_to_cash ... DONE`.

```bash
git add database/migrations/2026_07_04_000005_add_payment_sync_to_cash.php app/Models/CashEntry.php app/Models/CashCategory.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): payment_id di cash_entries + map_key kategori (backfill + Refund)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: PaymentCashSyncService + Observer + register + feature test

**Files:** `PaymentCashSyncService.php`; `PaymentObserver.php`; `AppServiceProvider.php`; `tests/Feature/PaymentCashSyncTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/PaymentCashSyncTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\CashEntry;
use App\Models\CashCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentCashSyncTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $type = 'at_kolab'): Order
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji', 'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => 500000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        return $order;
    }

    private function pay(Order $order, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-05',
        ], $attrs));
    }

    /** @test */
    public function paid_payment_creates_income_entry(): void
    {
        $order = $this->order('at_kolab');
        $payment = $this->pay($order);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertNotNull($e);
        $this->assertSame('pemasukan', $e->jenis);
        $this->assertSame('artikel', $e->produk);
        $this->assertSame('500000.00', $e->amount);
        $this->assertSame('payment', $e->source);
        $this->assertSame($order->code_order, $e->ref);
        $this->assertSame('B626', $e->kode);
        $this->assertSame(CashCategory::where('map_key', 'at_kolab')->first()->id, $e->cash_category_id);
    }

    /** @test */
    public function refund_creates_expense_entry(): void
    {
        $order = $this->order('bk_mandiri');
        $payment = $this->pay($order, ['payment_type' => 'refund']);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertSame('pengeluaran', $e->jenis);
        $this->assertSame(CashCategory::where('map_key', 'refund')->first()->id, $e->cash_category_id);
    }

    /** @test */
    public function approve_then_update_keeps_single_entry(): void
    {
        $order = $this->order();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 300000, 'status' => 'pending', 'paid_at' => '2026-06-05']);
        $this->assertSame(0, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['status' => 'paid']);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['amount' => 700000]);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());
        $this->assertSame('700000.00', CashEntry::where('payment_id', $payment->id)->first()->amount);
    }

    /** @test */
    public function rejected_or_deleted_removes_entry(): void
    {
        $order = $this->order();
        $payment = $this->pay($order);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['status' => 'rejected']);
        $this->assertSame(0, CashEntry::where('payment_id', $payment->id)->count());

        $order2 = $this->order();
        $payment2 = $this->pay($order2);
        $this->assertSame(1, CashEntry::where('payment_id', $payment2->id)->count());
        $payment2->delete();
        $this->assertSame(0, CashEntry::where('payment_id', $payment2->id)->count());
    }

    /** @test */
    public function sync_is_null_safe_without_order_details(): void
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-ND-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => '2026-06-05']);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertNotNull($e);
        $this->assertNull($e->cash_category_id);
        $this->assertNull($e->produk);
        $this->assertSame('pemasukan', $e->jenis);
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (observer belum ada → tak ada entri).
Run: `php artisan test --env=testing tests/Feature/PaymentCashSyncTest.php`
Expected: FAIL (CashEntry null).

- [ ] **Step 3: `app/Services/PaymentCashSyncService.php`**

```php
<?php

namespace App\Services;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Payment;
use Carbon\Carbon;

class PaymentCashSyncService
{
    /** Sinkron satu entri kas dari Payment (idempotent per payment_id). */
    public function sync(Payment $payment): void
    {
        if ($payment->status !== 'paid') {
            CashEntry::where('payment_id', $payment->id)->delete();
            return;
        }

        $order  = $payment->order;
        $detail = optional($order)->details;
        $type   = optional($detail)->type;
        $refund = $payment->payment_type === 'refund';

        $produk = null;
        if ($type) {
            $produk = str_starts_with($type, 'bk_') ? 'buku' : (str_starts_with($type, 'at_') ? 'artikel' : null);
        }
        $mapKey = $refund ? 'refund' : $type;
        $catId  = $mapKey ? optional(CashCategory::where('map_key', $mapKey)->first())->id : null;
        $ref    = optional($payment->invoice)->invoice_no ?: optional($order)->code_order;
        $tgl    = $payment->paid_at ?: now();
        $ket    = trim(ucfirst((string) $payment->payment_type) . ' — ' . ($ref ?: '-') . ' — ' . (optional($detail)->title ?? optional($order)->code_order ?? ''));

        CashEntry::updateOrCreate(
            ['payment_id' => $payment->id],
            [
                'tanggal'          => $tgl,
                'jenis'            => $refund ? 'pengeluaran' : 'pemasukan',
                'amount'           => $payment->amount,
                'cash_category_id' => $catId,
                'produk'           => $produk,
                'ref'              => $ref,
                'keterangan'       => $ket,
                'source'           => 'payment',
                'kode'             => app(CashJournalService::class)->deriveKode(Carbon::parse($tgl)),
            ]
        );
    }
}
```

- [ ] **Step 4: `app/Observers/PaymentObserver.php`**

```php
<?php

namespace App\Observers;

use App\Models\CashEntry;
use App\Models\Payment;
use App\Services\PaymentCashSyncService;

class PaymentObserver
{
    public function saved(Payment $payment): void
    {
        app(PaymentCashSyncService::class)->sync($payment);
    }

    public function deleted(Payment $payment): void
    {
        CashEntry::where('payment_id', $payment->id)->delete();
    }
}
```

- [ ] **Step 5: Register observer di `app/Providers/AppServiceProvider.php`** — dalam `boot()`, tambahkan (dan `use` bila perlu, atau FQN):

```php
        \App\Models\Payment::observe(\App\Observers\PaymentObserver::class);
```

- [ ] **Step 6: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Feature/PaymentCashSyncTest.php`
Expected: 5 passed.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PaymentCashSyncService.php app/Observers/PaymentObserver.php app/Providers/AppServiceProvider.php tests/Feature/PaymentCashSyncTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): PaymentObserver + PaymentCashSyncService (auto entri kas dari payment)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: View lock entri auto + test

**Files:** `resources/views/accounting/journal.blade.php`; `tests/Feature/PaymentCashSyncTest.php`

- [ ] **Step 1: Tambah test read-only (gagal dulu)** — di `PaymentCashSyncTest`, tambah setup role + test:

Tambah `use` di atas kelas: `use Spatie\Permission\Models\Role;`. Tambah `setUp` bila belum ada roles — tambahkan method:

```php
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }
```

Dan test:

```php
    /** @test */
    public function auto_entry_is_readonly_in_journal(): void
    {
        $order = $this->order();
        $payment = $this->pay($order);
        $entry = CashEntry::where('payment_id', $payment->id)->first();

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        $res = $this->actingAs($sa)->get(route('accounting.journal', ['year' => 2026, 'month' => 6]))->assertOk();
        $res->assertSee('auto');                                            // badge entri otomatis
        $res->assertDontSee(route('accounting.entry.destroy', $entry->id)); // tanpa tombol hapus
    }
```

- [ ] **Step 2: Jalankan — GAGAL** (view belum kunci entri auto; badge/hidden belum ada).
Run: `php artisan test --env=testing tests/Feature/PaymentCashSyncTest.php::auto_entry_is_readonly_in_journal`
Expected: FAIL.

- [ ] **Step 3: Kunci entri auto di `resources/views/accounting/journal.blade.php`** — pada kolom Aksi tabel, ganti cell aksi (`<td>` dengan form destroy) agar entri `source=payment` tampil badge "auto" tanpa tombol hapus. Cari baris:

```blade
                        <td><form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form></td>
```

Ganti menjadi:

```blade
                        <td>
                            @if($e->source === 'payment')
                                <span class="badge bg-light text-muted border" title="Otomatis dari pembayaran">⚙ auto</span>
                            @else
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            @endif
                        </td>
```

- [ ] **Step 4: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/PaymentCashSyncTest.php`
Expected: 6 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 5: Commit**

```bash
git add resources/views/accounting/journal.blade.php tests/Feature/PaymentCashSyncTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(accounting): kunci entri auto (source=payment) di Jurnal Kas — badge auto, tanpa hapus

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_04_000005_add_payment_sync_to_cash ... DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 436 + 6 baru = 442 passed). Perhatikan test yang membuat `Payment` (mis. `TitleArchiveTest`, `TitleArchiveEligibleTest`) tetap hijau — observer null-safe & tak memutus transaksi. Bila salah satu gagal karena observer melempar, itu penemuan sah: pastikan `sync()` tak mengakses properti pada null (semua via `optional()`).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §2 skema (payment_id + map_key + backfill + Refund) → Task 1. ✓
- §3 sync service → Task 2 Step 3 + tests. ✓
- §4 observer + register → Task 2 Step 4-5 + tests (create/approve/update/reject/delete). ✓
- §1(1-3) jenis/idempotent/hapus → Task 2 tests. §1(5) null-safe → `sync_is_null_safe…`. ✓
- §5 view lock → Task 3 + `auto_entry_is_readonly_in_journal`. ✓
- §6 test → Task 2/3. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step.

**3. Type/nama konsistensi:** kolom `payment_id`/`map_key`; `CashCategory::where('map_key',…)`; `CashEntry` fillable +payment_id + relasi `payment()`. `PaymentCashSyncService::sync` dipakai observer. `PaymentObserver` saved/deleted register di AppServiceProvider. `source='payment'` dipakai sync + view. `deriveKode` reuse CashJournalService. Test literal `kode='B626'` konsisten dgn `paid_at='2026-06-05'` (Juni 2026).

Migrasi baru → **wajib `php artisan migrate` dev** (Task 4). Test via `.env.testing`.
