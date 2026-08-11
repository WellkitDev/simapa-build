# Invoice Layanan / Jasa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modul mandiri untuk menagih jasa non-publikasi (instalasi OJS, perbaikan, upgrade, desain, hosting, maintenance, Turnitin/plagiasi) — invoice custom dengan rincian item, pembayaran bertahap, pelacakan status pengerjaan, PDF, dan email — tanpa satu pun titik singgung dengan modul keuangan/order/payment.

**Architecture:** Satu entitas "invoice hidup" (`tb_service_invoices`) = satu pekerjaan sekaligus satu dokumen tagihan; DP → pelunasan ditangani lewat tabel pembayaran anak, bukan invoice baru. Data klien & item disimpan sebagai snapshot supaya dokumen terbit tidak berubah isi. Total didenormalisasi lewat `recalcTotals()`. Dua sumbu status: pengerjaan (manual) dan bayar (turunan dari SUM).

**Tech Stack:** Laravel 10, MySQL, Blade + Bootstrap 5 (template `template-web/`), DataTables (`datatables.net-bs4`), barryvdh/laravel-dompdf, spatie/laravel-permission, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-11-invoice-layanan-jasa-design.md`

---

## Aturan yang Berlaku di Seluruh Plan

Baca sekali sebelum Task 1. Melanggar salah satu ini membuat suite merah dengan gejala yang menyesatkan.

1. **Tes memakai DB tes, bukan DB dev.** `.env.testing` menunjuk `avidpedia_simapa_test`. Jalankan tes dengan `php artisan test`. Jangan pernah menjalankan tes terhadap `avidpedia_simapa`.
2. **Setelah menambah migrasi, jalankan `php artisan migrate` di DB dev juga** (Task 15). Suite hijau tidak menjamin aplikasi hidup jalan — tabelnya belum ada di dev.
3. **Role `accounting` sudah dibuat oleh migrasi.** Di `setUp()` tes, pakai `Role::firstOrCreate`, bukan `Role::create`, atau gunakan daftar tanpa `accounting` seperti tes slip gaji.
4. **`EnforcePermission` fail-closed.** Route bernama yang belum dipetakan di `config/permissions.php` ditolak untuk semua orang kecuali superadmin, dan `PermissionMapCompletenessTest` merah. **Rute dan permission-nya wajib lahir dalam commit yang sama.**
5. **Jangan pakai `protected $dates`** — mati sejak Laravel 10. Selalu `protected $casts`.
6. **Cast `decimal:2` mengembalikan string.** Di assertion pakai `assertEquals` (longgar), bukan `assertSame`.
7. **Jangan menyentuh** `tb_orders`, `tb_payments`, `tb_invoices`, `tb_cash_*`, atau modul keuangan mana pun. Task 14 punya tes yang mengunci ini.
8. **Commit tiap akhir task**, dengan `git add` jalur berkas eksplisit — jangan `git add -A`.
9. **JANGAN menaruh `route()` ke rute yang belum ada di dalam `@can`/`@canany`.** `Gate::before` di `AuthServiceProvider` meloloskan superadmin untuk ability **apa pun**, termasuk permission yang belum terdaftar — jadi penjaga itu tidak menahan apa-apa baginya, `route()` di dalamnya tetap dievaluasi, dan halamannya 500. Terbukti di Task 7: `@can('service_invoice.view')` aman bagi manager (Spatie mengembalikan false) tapi meledak bagi superadmin. Tunggu sampai rutenya lahir di task-nya sendiri.
10. **Tes akses selalu sertakan superadmin, bukan hanya manager.** Karena Gate::before, keduanya menempuh jalur kode yang berbeda; tes manager saja tidak akan pernah melihat jebakan di aturan 9.
11. **Layar apa pun yang punya form WAJIB punya blok `@if ($errors->any())` sendiri.** `layouts/master.blade.php:123-125` hanya merender `session('success')`, `session('error')`, dan `session('info')` — **tidak** `$errors`, dan **tidak** `session('warning')`. Tanpa blok itu setiap simpan yang ditolak validasi memantul tanpa satu pun tanda.
12. **Jangan pernah mengirim nilai berkolom `decimal:2` ke input teks yang nilainya nanti dibersihkan pemisah ribuan.** Cast-nya memancarkan `"350000.00"`, pembersihnya membuang titik desimal, dan angkanya jadi 100×. Pakai `(int)` seperti `accounting/journal.blade.php:255` dan `salary/slips/form.blade.php:7`.

---

## Struktur Berkas

**Migrasi** (`database/migrations/`) — 6 berkas, urut supaya FK-nya valid:
```
2026_08_11_000001_create_tb_service_clients_table.php
2026_08_11_000002_create_tb_service_catalogs_table.php
2026_08_11_000003_create_tb_service_invoices_table.php
2026_08_11_000004_create_tb_service_invoice_items_table.php
2026_08_11_000005_create_tb_service_invoice_payments_table.php
2026_08_11_000006_create_tb_service_invoice_logs_table.php
```

**Model** (`app/Models/`) — satu berkas per tabel, tipis; logika hitung ada di `ServiceInvoice`:
```
ServiceClient.php          master klien; hasMany invoices
ServiceCatalog.php         katalog + konstanta CATEGORIES
ServiceInvoice.php         inti: relasi, konstanta, recalcTotals(), isEditable()
ServiceInvoiceItem.php     baris item (snapshot)
ServiceInvoicePayment.php  baris pembayaran + konstanta TYPES/METHODS
ServiceInvoiceLog.php      jejak; tanpa perilaku
```

**Support** (`app/Support/`) — memisahkan hal yang kalau ditaruh di controller bikin controller-nya membengkak:
```
ServiceInvoiceNumber.php   penomoran anti-balapan + pembungkus retry
ServiceInvoiceForm.php     aturan validasi, normalisasi angka, sync item, resolve klien
ServiceInvoicePdfData.php  perakit data PDF — dipakai bersama route unduh & job email
```

**Service** (`app/Services/`) — pola "ubah keadaan + tulis baris log", mengikuti konvensi `CashPeriodService`/`TitleProgressService`:
```
ServiceInvoiceWorkflow.php  changeStatus() (Task 5) + cancel() (Task 10)
```

**Controller** (`app/Http/Controllers/Pages/`):
```
ServiceInvoiceController.php         index, create, store, show, edit, update, destroy,
                                     status, cancel, pdf, send
ServiceInvoicePaymentController.php  store, destroy
ServiceCatalogController.php         index, store, update, destroy
ServiceClientController.php          index, show, store, update, destroy
```

**View** (`resources/views/services/`):
```
invoices/index.blade.php    daftar + filter (DataTables)
invoices/form.blade.php     dipakai create & edit
invoices/show.blade.php     detail + pembayaran + status + log
invoices/invoice_pdf.blade.php
catalogs/index.blade.php
clients/index.blade.php
clients/show.blade.php
```
Plus `resources/views/pages/mails/service_invoice_mail.blade.php`.

**Diubah:** `routes/web.php`, `config/permissions.php`, `database/seeders/AccessMatrixSeeder.php`, `resources/views/layouts/sidebar.blade.php`.

---

### Task 1: Tabel & model klien + katalog

**Files:**
- Create: `database/migrations/2026_08_11_000001_create_tb_service_clients_table.php`
- Create: `database/migrations/2026_08_11_000002_create_tb_service_catalogs_table.php`
- Create: `app/Models/ServiceClient.php`
- Create: `app/Models/ServiceCatalog.php`
- Create: `database/factories/ServiceClientFactory.php`
- Create: `database/factories/ServiceCatalogFactory.php`
- Test: `tests/Feature/ServiceCatalogModelTest.php`

- [ ] **Step 1: Tulis migrasi klien**

`database/migrations/2026_08_11_000001_create_tb_service_clients_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_clients');
    }
};
```

- [ ] **Step 2: Tulis migrasi katalog**

`database/migrations/2026_08_11_000002_create_tb_service_catalogs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('price_max', 15, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_catalogs');
    }
};
```

- [ ] **Step 3: Tulis tes model katalog yang gagal**

`tests/Feature/ServiceCatalogModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function catalog_exposes_category_labels(): void
    {
        $this->assertArrayHasKey('instalasi', ServiceCatalog::CATEGORIES);
        $this->assertArrayHasKey('similarity', ServiceCatalog::CATEGORIES);
        $this->assertSame('Layanan Perbaikan', ServiceCatalog::CATEGORIES['perbaikan']);
    }

    /** @test */
    public function price_label_shows_range_when_price_max_present(): void
    {
        $fixed = ServiceCatalog::factory()->create(['price' => 750000, 'price_max' => null]);
        $range = ServiceCatalog::factory()->create(['price' => 500000, 'price_max' => 1000000]);

        $this->assertSame('Rp 750.000', $fixed->priceLabel());
        $this->assertSame('Rp 500.000 – Rp 1.000.000', $range->priceLabel());
    }

    /** @test */
    public function client_is_soft_deleted(): void
    {
        $client = ServiceClient::factory()->create();
        $client->delete();

        $this->assertSoftDeleted('tb_service_clients', ['id' => $client->id]);
        $this->assertCount(1, ServiceClient::withTrashed()->get());
    }
}
```

- [ ] **Step 4: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceCatalogModelTest`
Expected: FAIL — `Class "App\Models\ServiceCatalog" not found`

- [ ] **Step 5: Tulis model `ServiceClient`**

`app/Models/ServiceClient.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceClient extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_clients';

    protected $fillable = [
        'name', 'institution', 'email', 'phone', 'address', 'note',
        'created_by', 'updated_by',
    ];

    public function invoices()
    {
        // Pemecah seri wajib: issued_at bertipe date (tanpa jam), jadi dua invoice
        // di hari yang sama akan bertukar urutan antar-request tanpa `id`.
        return $this->hasMany(ServiceInvoice::class)
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
    }

    /** "Nama — Instansi" untuk dropdown; instansi kosong tidak menyisakan tanda pisah. */
    public function displayName(): string
    {
        return $this->institution ? $this->name . ' — ' . $this->institution : $this->name;
    }
}
```

- [ ] **Step 6: Tulis model `ServiceCatalog`**

`app/Models/ServiceCatalog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceCatalog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_catalogs';

    protected $fillable = [
        'category', 'name', 'price', 'price_max', 'unit',
        'description', 'is_active', 'position',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'price_max' => 'decimal:2',
        'is_active' => 'boolean',
        'position'  => 'integer',
    ];

    const CATEGORIES = [
        'instalasi'   => 'Layanan Instalasi & Setup',
        'perbaikan'   => 'Layanan Perbaikan',
        'upgrade'     => 'Upgrade & Migrasi',
        'desain'      => 'Desain OJS',
        'hosting'     => 'Hosting OJS (per Tahun)',
        'maintenance' => 'Maintenance',
        'similarity'  => 'Turnitin & Penurunan Plagiasi',
        'bundle'      => 'Paket Bundle',
        'lainnya'     => 'Lainnya',
    ];

    const UNITS = ['paket' => 'Paket', 'bulan' => 'Bulan', 'tahun' => 'Tahun', 'jurnal' => 'Jurnal'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Statis, karena kedua pemanggilnya (daftar katalog & optgroup form invoice)
     * memegang KUNCI kategori hasil groupBy, bukan instance model. Fallback `?? $key`
     * tinggal di satu tempat.
     */
    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key] ?? (string) $key;
    }

    /** "Rp 500.000 – Rp 1.000.000" bila berkisar, "Rp 750.000" bila tetap. */
    public function priceLabel(): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return $this->price_max !== null
            ? $rp($this->price) . ' – ' . $rp($this->price_max)
            : $rp($this->price);
    }
}
```

- [ ] **Step 7: Tulis kedua factory**

`database/factories/ServiceClientFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ServiceClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceClientFactory extends Factory
{
    protected $model = ServiceClient::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->name(),
            'institution' => 'Universitas ' . $this->faker->city(),
            'email'       => $this->faker->unique()->safeEmail(),
            'phone'       => '0812' . $this->faker->numerify('########'),
            'address'     => $this->faker->address(),
        ];
    }
}
```

`database/factories/ServiceCatalogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ServiceCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceCatalogFactory extends Factory
{
    protected $model = ServiceCatalog::class;

    public function definition(): array
    {
        return [
            'category'  => 'instalasi',
            'name'      => 'Instalasi OJS ' . $this->faker->word(),
            'price'     => 750000,
            'price_max' => null,
            'unit'      => 'paket',
            'is_active' => true,
            'position'  => 0,
        ];
    }
}
```

- [ ] **Step 8: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceCatalogModelTest`
Expected: PASS (3 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_11_000001_create_tb_service_clients_table.php \
        database/migrations/2026_08_11_000002_create_tb_service_catalogs_table.php \
        app/Models/ServiceClient.php app/Models/ServiceCatalog.php \
        database/factories/ServiceClientFactory.php database/factories/ServiceCatalogFactory.php \
        tests/Feature/ServiceCatalogModelTest.php
git commit -m "layanan: tabel & model klien jasa + katalog layanan"
```

---

### Task 2: Tabel & model invoice, item, pembayaran, log

**Files:**
- Create: `database/migrations/2026_08_11_000003_create_tb_service_invoices_table.php`
- Create: `database/migrations/2026_08_11_000004_create_tb_service_invoice_items_table.php`
- Create: `database/migrations/2026_08_11_000005_create_tb_service_invoice_payments_table.php`
- Create: `database/migrations/2026_08_11_000006_create_tb_service_invoice_logs_table.php`
- Create: `app/Models/ServiceInvoice.php`, `app/Models/ServiceInvoiceItem.php`, `app/Models/ServiceInvoicePayment.php`, `app/Models/ServiceInvoiceLog.php`
- Create: `database/factories/ServiceInvoiceFactory.php`
- Test: `tests/Feature/ServiceInvoiceModelTest.php`

- [ ] **Step 1: Tulis migrasi invoice**

`database/migrations/2026_08_11_000003_create_tb_service_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->foreignId('service_client_id')->nullable()
                  ->constrained('tb_service_clients')->nullOnDelete();

            // SNAPSHOT klien — sumber kebenaran untuk cetakan.
            $table->string('client_name');
            $table->string('client_institution')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone', 40)->nullable();
            $table->text('client_address')->nullable();

            $table->date('issued_at');
            $table->date('due_at')->nullable();

            $table->string('work_status', 20)->default('belum');
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('remaining', 15, 2)->default(0);
            $table->string('payment_status', 20)->default('belum');

            $table->text('note')->nullable();
            $table->text('internal_note')->nullable();

            $table->string('pdf_drive_url')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);

            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_status', 'payment_status']);
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoices');
    }
};
```

- [ ] **Step 2: Tulis migrasi item**

`database/migrations/2026_08_11_000004_create_tb_service_invoice_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->nullable()
                  ->constrained('tb_service_catalogs')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('qty', 8, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoice_items');
    }
};
```

- [ ] **Step 3: Tulis migrasi pembayaran**

`database/migrations/2026_08_11_000005_create_tb_service_invoice_payments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
            $table->date('paid_at');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('method', 20)->default('transfer');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoice_payments');
    }
};
```

- [ ] **Step 4: Tulis migrasi log**

`database/migrations/2026_08_11_000006_create_tb_service_invoice_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoice_logs');
    }
};
```

- [ ] **Step 5: Tulis tes relasi yang gagal**

`tests/Feature/ServiceInvoiceModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function invoice_relates_to_client_items_payments_and_logs(): void
    {
        $client  = ServiceClient::factory()->create();
        $invoice = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);

        $invoice->items()->create([
            'name' => 'Instalasi OJS Basic', 'qty' => 1,
            'unit_price' => 500000, 'subtotal' => 500000, 'position' => 0,
        ]);
        $invoice->payments()->create([
            'paid_at' => now()->toDateString(), 'type' => 'dp',
            'amount' => 200000, 'method' => 'transfer',
        ]);
        $invoice->logs()->create(['event' => 'created']);

        $invoice->refresh();
        $this->assertSame($client->id, $invoice->client->id);
        $this->assertCount(1, $invoice->items);
        $this->assertCount(1, $invoice->payments);
        $this->assertCount(1, $invoice->logs);
        $this->assertCount(1, $client->invoices);
    }

    /** @test */
    public function deleting_invoice_cascades_items_and_payments(): void
    {
        $invoice = ServiceInvoice::factory()->create();
        $invoice->items()->create(['name' => 'X', 'qty' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);
        $invoice->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 500]);

        $invoice->forceDelete();

        $this->assertDatabaseCount('tb_service_invoice_items', 0);
        $this->assertDatabaseCount('tb_service_invoice_payments', 0);
    }

    /** @test */
    public function status_constants_are_defined(): void
    {
        $this->assertSame('Belum Dikerjakan', ServiceInvoice::WORK_STATUS['belum']);
        $this->assertSame('Dibatalkan', ServiceInvoice::WORK_STATUS['batal']);
        $this->assertSame('Lunas', ServiceInvoice::PAYMENT_STATUS['lunas']);
    }

    /** @test */
    public function derived_money_columns_are_not_mass_assignable(): void
    {
        $inv = ServiceInvoice::factory()->create();

        $inv->update([
            'subtotal'   => 999999,
            'total'      => 999999,
            'paid_total' => 999999,
            'remaining'  => 999999,
        ]);

        $inv->refresh();
        $this->assertEquals(0, $inv->subtotal);
        $this->assertEquals(0, $inv->total);
        $this->assertEquals(0, $inv->paid_total);
        $this->assertEquals(0, $inv->remaining);
    }

    /** @test */
    public function overdue_starts_the_day_after_due_date_and_only_when_money_is_owed(): void
    {
        // remaining tidak fillable (lihat $fillable), jadi diisi lewat forceFill —
        // yang sekaligus jalur yang dipakai recalcTotals() di Task 3.
        $owed = ServiceInvoice::factory()->create(['due_at' => today()->toDateString()]);
        $owed->forceFill(['remaining' => 500000])->save();

        $this->assertFalse($owed->isOverdue(), 'Hari jatuh tempo masih hak klien, belum telat.');

        $owed->update(['due_at' => today()->subDay()->toDateString()]);
        $this->assertTrue($owed->fresh()->isOverdue());

        $settled = ServiceInvoice::factory()->create(['due_at' => today()->subDays(30)->toDateString()]);
        $this->assertFalse($settled->isOverdue(), 'Tanpa sisa tagihan tidak ada yang telat.');

        $cancelled = ServiceInvoice::factory()->create([
            'due_at'      => today()->subDays(30)->toDateString(),
            'work_status' => 'batal',
        ]);
        $cancelled->forceFill(['remaining' => 500000])->save();
        $this->assertFalse($cancelled->isOverdue(), 'Invoice batal tidak pernah telat.');
    }
}
```

- [ ] **Step 6: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceModelTest`
Expected: FAIL — `Class "App\Models\ServiceInvoice" not found`

- [ ] **Step 7: Tulis model `ServiceInvoice`**

`app/Models/ServiceInvoice.php` — `recalcTotals()` dan `applyWorkStatus()` sengaja belum ada; itu Task 3 & 5.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_invoices';

    protected $fillable = [
        'invoice_no', 'service_client_id',
        'client_name', 'client_institution', 'client_email', 'client_phone', 'client_address',
        'issued_at', 'due_at',
        'work_status', 'work_started_at', 'work_finished_at',
        // `subtotal`, `total`, `paid_total`, `remaining` SENGAJA tidak fillable:
        // satu-satunya penulisnya adalah recalcTotals(), lewat forceFill() yang
        // memang melewati $fillable. Membiarkannya terbuka berarti sebuah form
        // (mis. layar koreksi) bisa mengirim paid_total dan membuatnya menyimpang
        // dari SUM(payments.amount) sampai recalcTotals() berikutnya.
        // `discount` ikut karena memang masukan pengguna; `payment_status` ikut
        // karena diisi saat invoice dibuat.
        'discount', 'payment_status',
        'note', 'internal_note',
        'pdf_drive_url', 'sent_at', 'sent_count',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
        'created_by', 'updated_by',
    ];

    // CATATAN: JANGAN pakai `protected $dates` — mati sejak Laravel 10, diam-diam
    // membuat kolom tanggal tetap berupa string dan ->format() meledak.
    protected $casts = [
        'issued_at'        => 'date',
        'due_at'           => 'date',
        'work_started_at'  => 'datetime',
        'work_finished_at' => 'datetime',
        'sent_at'          => 'datetime',
        'cancelled_at'     => 'datetime',
        'subtotal'         => 'decimal:2',
        'discount'         => 'decimal:2',
        'total'            => 'decimal:2',
        'paid_total'       => 'decimal:2',
        'remaining'        => 'decimal:2',
        'sent_count'       => 'integer',
    ];

    const WORK_STATUS = [
        'belum'   => 'Belum Dikerjakan',
        'proses'  => 'Proses',
        'selesai' => 'Selesai',
        'batal'   => 'Dibatalkan',
    ];

    const PAYMENT_STATUS = [
        'belum' => 'Belum Dibayar',
        'dp'    => 'DP',
        'lunas' => 'Lunas',
    ];

    public function client()
    {
        return $this->belongsTo(ServiceClient::class, 'service_client_id');
    }

    public function items()
    {
        return $this->hasMany(ServiceInvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(ServiceInvoicePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(ServiceInvoiceLog::class)->latest('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function workStatusLabel(): string
    {
        return self::WORK_STATUS[$this->work_status] ?? $this->work_status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUS[$this->payment_status] ?? $this->payment_status;
    }

    public function isCancelled(): bool
    {
        return $this->work_status === 'batal';
    }

    public function isOverpaid(): bool
    {
        return (float) $this->remaining < 0;
    }

    public function overpaidAmount(): float
    {
        return $this->isOverpaid() ? abs((float) $this->remaining) : 0.0;
    }

    /**
     * Dua hal yang mudah salah di sini:
     *  - `lt(today())`, BUKAN `isPast()`. `due_at` di-cast `date` sehingga jatuh di
     *    tengah malam, dan `isPast()` menandai invoice telat sejak pukul 00:00 pada
     *    hari jatuh temponya sendiri — padahal hari itu masih hak klien.
     *  - ambang utangnya `remaining`, BUKAN `payment_status`. Invoice bertotal nol
     *    (mis. pekerjaan garansi) tak pernah bisa mencapai 'lunas', jadi memakai
     *    payment_status akan menandainya telat selamanya atas utang nol.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->lt(today())
            && (float) $this->remaining > 0
            && ! $this->isCancelled();
    }
}
```

- [ ] **Step 8: Tulis tiga model anak**

`app/Models/ServiceInvoiceItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceItem extends Model
{
    protected $table = 'tb_service_invoice_items';

    protected $fillable = [
        'service_invoice_id', 'service_catalog_id',
        'name', 'description', 'qty', 'unit_price', 'subtotal', 'position',
    ];

    protected $casts = [
        'qty'        => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal'   => 'decimal:2',
        'position'   => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }
}
```

`app/Models/ServiceInvoicePayment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoicePayment extends Model
{
    use SoftDeletes;

    protected $table = 'tb_service_invoice_payments';

    protected $fillable = [
        'service_invoice_id', 'paid_at', 'type', 'amount',
        'method', 'reference', 'note', 'created_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'decimal:2',
    ];

    const TYPES   = ['dp' => 'DP', 'cicilan' => 'Cicilan', 'pelunasan' => 'Pelunasan'];
    const METHODS = ['transfer' => 'Transfer', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya'];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
```

`app/Models/ServiceInvoiceLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceLog extends Model
{
    protected $table = 'tb_service_invoice_logs';

    protected $fillable = [
        'service_invoice_id', 'event', 'from_status', 'to_status', 'note', 'changed_by',
    ];

    const EVENTS = [
        'created'         => 'Invoice dibuat',
        'updated'         => 'Invoice diubah',
        'status_changed'  => 'Status pengerjaan diubah',
        'payment_added'   => 'Pembayaran dicatat',
        'payment_deleted' => 'Pembayaran dihapus',
        'emailed'         => 'Invoice dikirim via email',
        'email_failed'    => 'Pengiriman email gagal',
        'cancelled'       => 'Invoice dibatalkan',
    ];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }
}
```

- [ ] **Step 9: Tulis factory invoice**

`database/factories/ServiceInvoiceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceInvoiceFactory extends Factory
{
    protected $model = ServiceInvoice::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            // Namespace nomor KHUSUS TES. Sengaja beda dari `INV-JS-<Ym>-` yang
            // dialokasikan ServiceInvoiceNumber::next(): tanpa pemisahan ini, tes
            // yang membuat invoice lewat factory DAN lewat store() akan tabrakan di
            // unique index begitu kedua pencacahnya bertemu di angka yang sama.
            // Tes yang memang menguji format nomor menimpanya sendiri (Task 4).
            'invoice_no'         => 'INV-JS-TEST-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'service_client_id'  => null,
            'client_name'        => $this->faker->name(),
            'client_institution' => 'Universitas ' . $this->faker->city(),
            'client_email'       => $this->faker->unique()->safeEmail(),
            'client_phone'       => '0812' . $this->faker->numerify('########'),
            'issued_at'          => now()->toDateString(),
            'due_at'             => now()->addDays(14)->toDateString(),
            'work_status'        => 'belum',
            'payment_status'     => 'belum',
            'discount'           => 0,
        ];
    }
}
```

- [ ] **Step 10: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceModelTest`
Expected: PASS (5 tests)

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_11_00000[3-6]_*.php \
        app/Models/ServiceInvoice.php app/Models/ServiceInvoiceItem.php \
        app/Models/ServiceInvoicePayment.php app/Models/ServiceInvoiceLog.php \
        database/factories/ServiceInvoiceFactory.php \
        tests/Feature/ServiceInvoiceModelTest.php
git commit -m "layanan: tabel & model invoice, item, pembayaran, log"
```

---

### Task 3: `recalcTotals()` — perhitungan total & status bayar

Menutup T-CALC-1..4 dari spec §10.

**Files:**
- Modify: `app/Models/ServiceInvoice.php` (tambah satu metode)
- Test: `tests/Feature/ServiceInvoiceCalcTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceCalcTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInvoiceCalcTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithItems(float $discount = 0): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create(['discount' => $discount]);
        $inv->items()->create(['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => 750000, 'subtotal' => 750000, 'position' => 0]);
        $inv->items()->create(['name' => 'Maintenance',   'qty' => 3, 'unit_price' => 300000, 'subtotal' => 900000, 'position' => 1]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function subtotal_and_total_follow_items_and_discount(): void
    {
        $inv = $this->invoiceWithItems(discount: 150000);

        $this->assertEquals(1650000, $inv->subtotal);
        $this->assertEquals(1500000, $inv->total);
        $this->assertEquals(0,       $inv->paid_total);
        $this->assertEquals(1500000, $inv->remaining);
        $this->assertSame('belum',   $inv->payment_status);
    }

    /** @test */
    public function payment_status_walks_belum_to_dp_to_lunas(): void
    {
        $inv = $this->invoiceWithItems();
        $this->assertSame('belum', $inv->payment_status);

        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 650000]);
        $inv->recalcTotals();
        $inv->refresh();
        $this->assertSame('dp', $inv->payment_status);
        $this->assertEquals(1000000, $inv->remaining);

        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1000000]);
        $inv->recalcTotals();
        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(0, $inv->remaining);
    }

    /** @test */
    public function deleting_a_payment_rolls_the_totals_back(): void
    {
        $inv = $this->invoiceWithItems();
        $pay = $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 650000]);
        $inv->recalcTotals();

        $pay->delete();
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(0,       $inv->paid_total);
        $this->assertEquals(1650000, $inv->remaining);
        $this->assertSame('belum',   $inv->payment_status);
    }

    /** @test */
    public function overpayment_is_kept_visible_not_discarded(): void
    {
        $inv = $this->invoiceWithItems();
        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1700000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(-50000, $inv->remaining);
        $this->assertTrue($inv->isOverpaid());
        $this->assertEquals(50000, $inv->overpaidAmount());
    }

    /** @test */
    public function exactly_paid_invoice_with_odd_cents_is_lunas_not_dp(): void
    {
        // Jebakan float sungguhan: (float) total menghasilkan ...9900000002
        // sementara (float) paid menghasilkan ...9899999999, sehingga perbandingan
        // mentah `paid >= total` gagal dan invoice yang sudah lunas tersangkut di
        // 'dp' SELAMANYA — sementara `remaining` tersimpan 0.00 karena kolomnya
        // decimal(15,2). Barisnya jadi menyangkal dirinya sendiri.
        $inv = ServiceInvoice::factory()->create(['discount' => 636766.00]);
        $inv->items()->create([
            'name' => 'Hosting prorata', 'qty' => 1,
            'unit_price' => 2548189.99, 'subtotal' => 2548189.99,
        ]);
        $inv->payments()->create([
            'paid_at' => now()->toDateString(), 'type' => 'pelunasan', 'amount' => 1911423.99,
        ]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(1911423.99, $inv->total);
        $this->assertEquals(1911423.99, $inv->paid_total);
        $this->assertEquals(0, $inv->remaining);
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertFalse($inv->isOverpaid());
    }

    /** @test */
    public function zero_total_invoice_stays_belum_until_money_arrives(): void
    {
        $inv = ServiceInvoice::factory()->create([
            'discount' => 999999999,
            'due_at'   => today()->subDays(10)->toDateString(),
        ]);
        $inv->items()->create(['name' => 'X', 'qty' => 1, 'unit_price' => 100000, 'subtotal' => 100000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertEquals(0, $inv->total);
        $this->assertEquals(0, $inv->remaining);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertFalse($inv->isOverdue(), 'Utang nol tak pernah telat, walau jatuh temponya lewat.');

        // Cabang sebaliknya: total nol tapi ada uang masuk = lebih bayar.
        $inv->payments()->create(['paid_at' => now()->toDateString(), 'type' => 'dp', 'amount' => 50000]);
        $inv->recalcTotals();
        $inv->refresh();

        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(-50000, $inv->remaining);
        $this->assertTrue($inv->isOverpaid());
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceCalcTest`
Expected: FAIL — `Call to undefined method App\Models\ServiceInvoice::recalcTotals()`

- [ ] **Step 3: Tambahkan `recalcTotals()` ke `ServiceInvoice`**

Sisipkan tepat sebelum `isOverdue()` di `app/Models/ServiceInvoice.php`:

```php
    /**
     * Tulis ulang subtotal/total/paid_total/remaining/payment_status dari baris anaknya.
     * WAJIB dipanggil setiap kali item, diskon, atau pembayaran berubah — kolom-kolom
     * ini sengaja didenormalisasi supaya daftar bisa mengurutkan & memfilter di SQL.
     *
     * payment_status TIDAK PERNAH diketik manusia; selalu hasil hitungan di sini.
     *
     * Semua nilai dibulatkan ke 2 desimal SEBELUM dibandingkan. Tanpa itu, invoice
     * yang dibayar tepat lunas bisa tersangkut di 'dp' selamanya: float tak bisa
     * mewakili sebagian pecahan rupiah, sehingga `paid >= total` bernilai false
     * padahal selisihnya 6e-8 — dan selisih itu tersimpan sebagai 0.00 di kolom
     * decimal(15,2), membuat barisnya menyangkal dirinya sendiri (sisa nol, status DP).
     *
     * DUA HAL YANG PERLU DIINGAT PEMANGGIL:
     *  - `save()` menulis SEMUA atribut yang kotor, bukan hanya lima kolom di bawah.
     *    Jangan panggil metode ini sambil menggantung perubahan lain di memori
     *    kecuali memang ingin ikut tersimpan.
     *  - SUM di sini adalah consistent read TANPA kunci baris, jadi membungkusnya
     *    dengan `DB::transaction` saja TIDAK menyerialkan dua pencatatan pembayaran
     *    yang bersamaan — yang terakhir bisa menimpa dengan angka basi. Hitungannya
     *    derivatif (bukan inkremental), jadi panggilan berikutnya memulihkannya.
     *    Diterima sebagai risiko: alat internal dengan satu-dua operator.
     */
    public function recalcTotals(): void
    {
        $subtotal  = round((float) $this->items()->sum('subtotal'), 2);
        $total     = round(max($subtotal - (float) $this->discount, 0), 2);
        $paid      = round((float) $this->payments()->sum('amount'), 2);
        $remaining = round($total - $paid, 2);

        $status = 'belum';
        if ($paid > 0) {
            $status = $remaining <= 0 ? 'lunas' : 'dp';
        }

        $this->forceFill([
            'subtotal'       => $subtotal,
            'total'          => $total,
            'paid_total'     => $paid,
            'remaining'      => $remaining,   // negatif = lebih bayar, sengaja dipertahankan
            'payment_status' => $status,
        ])->save();
    }

```

- [ ] **Step 4: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceCalcTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/ServiceInvoice.php tests/Feature/ServiceInvoiceCalcTest.php
git commit -m "layanan: recalcTotals — total, sisa, dan status bayar turunan"
```

---

### Task 4: Penomoran invoice anti-balapan

Menutup T-NO-1..2.

**Files:**
- Create: `app/Support/ServiceInvoiceNumber.php`
- Test: `tests/Feature/ServiceInvoiceNumberTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceNumberTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Support\ServiceInvoiceNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceInvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function numbers_run_in_sequence_within_a_month(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');

        $first = ServiceInvoiceNumber::next($aug);
        $this->assertSame('INV-JS-202608-0001', $first);

        ServiceInvoice::factory()->create(['invoice_no' => $first, 'issued_at' => $aug->toDateString()]);
        $this->assertSame('INV-JS-202608-0002', ServiceInvoiceNumber::next($aug));
    }

    /** @test */
    public function sequence_restarts_each_month(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        $sep = \Carbon\Carbon::parse('2026-09-01');

        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0007', 'issued_at' => $aug->toDateString()]);

        $this->assertSame('INV-JS-202608-0008', ServiceInvoiceNumber::next($aug));
        $this->assertSame('INV-JS-202609-0001', ServiceInvoiceNumber::next($sep));
    }

    /** @test */
    public function deleted_invoice_numbers_are_never_reused(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');

        $inv = ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001', 'issued_at' => $aug->toDateString()]);
        $inv->delete();   // soft delete

        $this->assertSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
        $this->assertSame('INV-JS-202608-0002', ServiceInvoiceNumber::next($aug));
    }

    /** @test */
    public function malformed_last_number_fails_loudly_instead_of_colliding(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        ServiceInvoice::factory()->create([
            'invoice_no' => 'INV-JS-202608-REV', 'issued_at' => $aug->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak berformat angka/');

        ServiceInvoiceNumber::next($aug);
    }

    /** @test */
    public function running_out_of_numbers_in_a_month_fails_legibly(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        ServiceInvoice::factory()->create([
            'invoice_no' => 'INV-JS-202608-9999', 'issued_at' => $aug->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/habis/');

        ServiceInvoiceNumber::next($aug);
    }

    /** @test */
    public function retrying_recovers_from_a_real_duplicate_number(): void
    {
        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);

        $attempts = 0;

        $result = ServiceInvoiceNumber::retrying(function () use (&$attempts) {
            $attempts++;
            // Percobaan pertama sengaja memakai nomor yang sudah terpakai, jadi
            // duplikatnya datang dari unique index sungguhan — bukan pengecualian
            // yang dirakit tangan.
            $no = $attempts === 1 ? 'INV-JS-202608-0001' : 'INV-JS-202608-0002';

            return ServiceInvoice::factory()->create(['invoice_no' => $no]);
        });

        $this->assertSame(2, $attempts, 'Duplikat harus diulang sekali lalu berhasil.');
        $this->assertSame('INV-JS-202608-0002', $result->invoice_no);
    }

    /** @test */
    public function retrying_gives_up_after_the_configured_attempts(): void
    {
        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);

        $attempts = 0;

        try {
            ServiceInvoiceNumber::retrying(function () use (&$attempts) {
                $attempts++;

                return ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);
            });
            $this->fail('Tabrakan yang tak kunjung reda harus akhirnya dilempar.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->errorInfo[0]);
        }

        $this->assertSame(3, $attempts);
    }

    /** @test */
    public function retrying_does_not_repeat_unrelated_database_errors(): void
    {
        $attempts = 0;

        try {
            ServiceInvoiceNumber::retrying(function () use (&$attempts) {
                $attempts++;

                DB::table('tabel_yang_tidak_ada')->insert(['x' => 1]);
            });
            $this->fail('Galat non-balapan harus dilempar apa adanya.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tabel_yang_tidak_ada', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'Galat non-balapan tidak boleh diulang.');
    }

    /** @test */
    public function deadlock_is_treated_as_a_race_and_retried(): void
    {
        // Deadlock TIDAK bisa dipicu dari satu koneksi tes, jadi pengecualiannya
        // dirakit dengan bentuk errorInfo yang sama persis seperti yang dilempar
        // MySQL. Ini satu-satunya tes di berkas ini yang memakai galat rakitan —
        // dan memang harus, karena jalur inilah yang membuat invoice PERTAMA tiap
        // bulan tidak berakhir 500.
        $attempts = 0;

        $result = ServiceInvoiceNumber::retrying(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                $pdoException = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new QueryException(
                    'mysql',
                    'insert into `tb_service_invoices` (`invoice_no`) values (?)',
                    ['INV-JS-202608-0001'],
                    $pdoException
                );
            }

            return 'berhasil';
        });

        $this->assertSame(2, $attempts, 'Deadlock adalah balapan murni dan harus diulang.');
        $this->assertSame('berhasil', $result);
    }
}
```

> **Sebelum menulis tes deadlock:** periksa tanda tangan konstruktor `QueryException` di `vendor/laravel/framework/src/Illuminate/Database/QueryException.php`. Laravel 10 memakai `($connectionName, $sql, array $bindings, Throwable $previous)`; Laravel 9 tanpa `$connectionName`. Sesuaikan dan laporkan yang Anda temukan.

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceNumberTest`
Expected: FAIL — `Class "App\Support\ServiceInvoiceNumber" not found`

- [ ] **Step 3: Tulis `ServiceInvoiceNumber`**

`app/Support/ServiceInvoiceNumber.php`:

```php
<?php

namespace App\Support;

use App\Models\ServiceInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

class ServiceInvoiceNumber
{
    /**
     * Nomor invoice berikutnya untuk bulan penerbitan: INV-JS-YYYYMM-NNNN.
     *
     * WAJIB dipanggil DI DALAM transaksi yang sama dengan insert-nya — lockForUpdate
     * baru berarti di sana. Tiga lapis pengaman: kunci baris, withTrashed (nomor yang
     * dihapus tak pernah didaur ulang), dan unique index + retry() sebagai jaring akhir.
     *
     * MAX() string aman di sini karena sufiksnya zero-padded dengan panjang tetap dan
     * prefiksnya sama persis; urutan leksikografis = urutan numerik. Asumsi itu putus
     * kalau satu bulan melewati 9999 invoice — tidak terjangkau pada volume jasa.
     */
    public static function next(CarbonInterface $issuedAt): string
    {
        $prefix = 'INV-JS-' . $issuedAt->format('Ym') . '-';

        $last = ServiceInvoice::withTrashed()
            ->where('invoice_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('invoice_no');

        $suffix = $last !== null ? substr($last, strlen($prefix)) : null;

        // Gagal keras, jangan menebak. Sufiks non-angka (data hasil sunting tangan
        // atau importer) membuat (int) menghasilkan 0, `next()` mengembalikan 0001
        // yang sudah terpakai, dan bulan itu macet permanen dengan galat SQL
        // yang tak menjelaskan apa pun.
        if ($suffix !== null && ! ctype_digit($suffix)) {
            throw new \RuntimeException(
                "Nomor invoice layanan terakhir tidak berformat angka: {$last}. "
                . 'Perbaiki datanya sebelum menerbitkan invoice baru.'
            );
        }

        $seq = $suffix !== null ? ((int) $suffix) + 1 : 1;

        // Di 10000 sufiksnya jadi 5 digit dan urutan leksikografis MAX() putus —
        // '1' < '9' membuat baris 5 digit terabaikan dan nomor yang sama diterbitkan
        // berulang. Tak terjangkau pada volume jasa, tapi harus berbunyi jelas.
        if ($seq > 9999) {
            throw new \RuntimeException(
                'Kuota nomor invoice layanan bulan ' . $issuedAt->format('F Y') . ' habis (9999). '
                . 'Lebarkan format penomoran sebelum menerbitkan invoice baru.'
            );
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Jalankan $fn, ulangi hanya bila gagal karena balapan alokasi nomor. Galat lain
     * dilempar apa adanya — mengulang galat sembarangan menyembunyikan bug.
     *
     * Jeda acak kecil antar-percobaan supaya dua pemanggil yang bertabrakan tidak
     * langsung bertabrakan lagi di percobaan berikutnya.
     */
    public static function retrying(callable $fn, int $tries = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if (! self::isRaceCollision($e) || $attempt >= $tries) {
                    throw $e;
                }

                usleep(random_int(10_000, 50_000));
            }
        }
    }

    /**
     * Dicocokkan lewat SQLSTATE + kode driver di `errorInfo`, BUKAN teks pesan:
     * Laravel menempelkan seluruh SQL ke pesan, sehingga mencari 'invoice_no' di
     * sana ikut cocok dengan duplikat kolom lain hanya karena nama kolomnya muncul
     * di daftar INSERT. Presedennya sudah ada di `EnforceIdempotency`.
     *
     * Deadlock & lock-wait ikut dianggap balapan karena justru DIPICU oleh
     * lockForUpdate() di next(): pada bulan yang masih kosong, `LIKE 'prefix%'
     * FOR UPDATE` hanya mengambil gap lock yang kompatibel-bersama, jadi dua
     * transaksi sama-sama menghitung 0001 lalu saling mengunci saat INSERT.
     * Tanpa memasukkan 40001/1213 ke sini, invoice PERTAMA tiap bulan bisa
     * berakhir 500 — tepat di kasus yang retry ini dibuat untuk menanganinya.
     */
    private static function isRaceCollision(QueryException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $detail     = (string) ($e->errorInfo[2] ?? '');

        if ($sqlState === '40001' || in_array($driverCode, [1213, 1205], true)) {
            return true;
        }

        // errorInfo[2] hanya memuat nama indeks yang bentrok, tanpa SQL-nya —
        // jadi ini benar-benar menyaring unique index invoice_no.
        return $sqlState === '23000'
            && $driverCode === 1062
            && str_contains($detail, 'invoice_no');
    }
}
```

- [ ] **Step 4: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceNumberTest`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Support/ServiceInvoiceNumber.php tests/Feature/ServiceInvoiceNumberTest.php
git commit -m "layanan: penomoran invoice anti-balapan + pembungkus retry"
```

---

### Task 5: Mesin status pengerjaan

Menutup T-WS-1..3. (T-WS-4 — batal hanya superadmin — butuh route, jadi ada di Task 11.)

**Files:**
- Create: `app/Services/ServiceInvoiceWorkflow.php`
- Test: `tests/Feature/ServiceInvoiceWorkStatusTest.php`

> **Kenapa Service, bukan metode di model.** Rancangan awal menaruh ini sebagai `ServiceInvoice::applyWorkStatus()`. Diubah setelah review Task 4, atas tiga alasan: (1) codebase ini punya konvensi hidup bahwa "ubah keadaan + tulis baris log" tinggal di Service — `CashPeriodService::lock()/unlock()` dan `TitleProgressService::log()` persis pola itu; (2) `ServiceInvoice` sudah 179 baris, dan metode ini akan melewatkannya ~200; (3) yang menentukan — Task 10 punya `cancel()` dengan bentuk yang sama persis (ubah status, tulis log, bungkus transaksi), jadi tanpa Service pola itu ditulis dua kali di dua tempat berbeda. Task 10 menambahkan `cancel()` ke kelas yang sama. Model tetap sekadar rekaman.

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceWorkStatusTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\ServiceInvoiceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceInvoiceWorkStatusTest extends TestCase
{
    use RefreshDatabase;

    private function workflow(): ServiceInvoiceWorkflow
    {
        return app(ServiceInvoiceWorkflow::class);
    }

    /** @test */
    public function moving_to_proses_stamps_started_at_once_only(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // Waktu WAJIB dikendalikan di sini. Kolom `work_started_at` bertipe timestamp
        // tanpa pecahan detik, dan ketiga panggilan di bawah selesai dalam milidetik —
        // tanpa travelTo(), tes ini tetap lulus walau stempelnya ditimpa setiap kali,
        // karena kedua nilai kebetulan jatuh di detik yang sama.
        $this->travelTo(Carbon::parse('2026-08-11 09:00:00'));
        $this->workflow()->changeStatus($inv, 'proses', 'mulai instalasi', $user->id);
        $inv->refresh();
        $this->assertSame('2026-08-11 09:00:00', $inv->work_started_at->toDateTimeString());

        // Bolak-balik: kembali ke Proses TIDAK boleh menimpa stempel awal.
        $this->travelTo(Carbon::parse('2026-08-12 14:30:00'));
        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);

        $this->travelTo(Carbon::parse('2026-08-13 08:15:00'));
        $this->workflow()->changeStatus($inv, 'proses', 'revisi klien', $user->id);
        $inv->refresh();

        $this->assertSame(
            '2026-08-11 09:00:00',
            $inv->work_started_at->toDateTimeString(),
            'Stempel mulai hanya dipasang sekali, tidak ditimpa saat kembali ke Proses.'
        );
    }

    /** @test */
    public function leaving_selesai_in_any_direction_clears_the_finish_date(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);
        $this->workflow()->changeStatus($inv, 'belum', 'salah tandai', $user->id);
        $inv->refresh();

        $this->assertSame('belum', $inv->work_status);
        $this->assertNull($inv->work_finished_at);
    }

    /** @test */
    public function a_stale_instance_cannot_strand_a_finish_date_on_an_unfinished_row(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // Dua operator memuat invoice yang sama sebelum salah satunya menyimpan.
        // Instance kedua memegang $from yang basi ('belum'), jadi logika yang
        // berkunci pada asal akan melewatkan pembersihan tanggal selesai.
        $a = ServiceInvoice::find($inv->id);
        $b = ServiceInvoice::find($inv->id);

        $this->workflow()->changeStatus($a, 'selesai', null, $user->id);
        $this->workflow()->changeStatus($b, 'proses', null, $user->id);

        $fresh = ServiceInvoice::find($inv->id);
        $this->assertSame('proses', $fresh->work_status);
        $this->assertNull($fresh->work_finished_at, 'Baris Proses tak boleh menyimpan tanggal selesai.');
    }

    /** @test */
    public function unknown_and_terminal_statuses_are_refused(): void
    {
        $user = User::factory()->create();

        foreach (['batal', 'Selesai', 'done', ''] as $bogus) {
            $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

            try {
                $this->workflow()->changeStatus($inv, $bogus, null, $user->id);
                $this->fail("Status '{$bogus}' seharusnya ditolak.");
            } catch (ValidationException $e) {
                // sesuai harapan
            }

            $inv->refresh();
            $this->assertSame('proses', $inv->work_status, "Status '{$bogus}' tak boleh tersimpan.");
            $this->assertCount(0, $inv->logs);
        }
    }

    /** @test */
    public function finishing_stamps_finished_at_and_leaving_clears_it(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);
        $inv->refresh();
        $this->assertNotNull($inv->work_finished_at);

        // Tanggal selesai tidak boleh berbohong setelah pekerjaan dibuka lagi.
        $this->workflow()->changeStatus($inv, 'proses', 'klien minta revisi tema', $user->id);
        $inv->refresh();
        $this->assertNull($inv->work_finished_at);
    }

    /** @test */
    public function every_transition_writes_one_log_row(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->workflow()->changeStatus($inv, 'proses', 'mulai', $user->id);
        $this->workflow()->changeStatus($inv, 'selesai', 'beres', $user->id);
        $inv->refresh();

        $this->assertCount(2, $inv->logs);

        $latest = $inv->logs->first();
        $this->assertSame('status_changed', $latest->event);
        $this->assertSame('proses',  $latest->from_status);
        $this->assertSame('selesai', $latest->to_status);
        $this->assertSame('beres',   $latest->note);
        $this->assertSame($user->id, $latest->changed_by);
    }

    /** @test */
    public function transition_to_the_same_status_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $moved = $this->workflow()->changeStatus($inv, 'proses', 'tidak berubah', $user->id);
        $inv->refresh();

        $this->assertFalse($moved);
        $this->assertCount(0, $inv->logs);
    }

    /** @test */
    public function a_failed_log_write_rolls_the_status_back(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // `changed_by` punya foreign key ke users, jadi id yang tidak ada membuat
        // INSERT log gagal SETELAH baris invoice diperbarui. Keduanya harus jatuh
        // bersama — tak boleh ada perpindahan status yang tidak punya jejak.
        try {
            $this->workflow()->changeStatus($inv, 'proses', null, 999999);
            $this->fail('INSERT log dengan changed_by tak dikenal seharusnya gagal.');
        } catch (\Illuminate\Database\QueryException $e) {
            // yang diuji adalah keadaan sesudahnya, bukan pesannya
        }

        // Dibaca ulang dari basis data: instance di memori sudah terlanjur dimutasi
        // oleh update() walau transaksinya dibatalkan.
        $this->assertSame('belum', ServiceInvoice::find($inv->id)->work_status);
        $this->assertSame(0, $inv->logs()->count());
    }
}
```

> Tes terakhir memaksa kegagalan di tengah transaksi untuk membuktikan `DB::transaction` di dalam `changeStatus()` benar-benar mengikat perpindahan status dengan penulisan lognya.

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceWorkStatusTest`
Expected: FAIL — `Class "App\Services\ServiceInvoiceWorkflow" not found`

- [ ] **Step 3: Tulis `ServiceInvoiceWorkflow`**

`app/Services/ServiceInvoiceWorkflow.php`:

```php
<?php

namespace App\Services;

use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perpindahan status pengerjaan invoice layanan, beserta jejaknya.
 *
 * Ditaruh di Service mengikuti konvensi yang sudah hidup di codebase ini untuk
 * pola "ubah keadaan + tulis baris log": CashPeriodService::lock()/unlock() dan
 * TitleProgressService::log(). Model tetap sekadar rekaman.
 *
 * Gerbang "siapa boleh" TIDAK ada di sini — itu urusan permission di rute.
 */
class ServiceInvoiceWorkflow
{
    /** Status yang boleh dimasuki lewat changeStatus(). 'batal' sengaja di luar. */
    public const CHANGEABLE = ['belum', 'proses', 'selesai'];

    /**
     * Pindahkan status pengerjaan dan catat jejaknya. Transisi bebas antara
     * belum/proses/selesai — pekerjaan jasa rutin kembali ke Proses karena revisi
     * klien, dan memaksa satu arah cuma membuat operator berbohong.
     *
     * Pembatalan TIDAK lewat sini: 'batal' keadaan terminal yang butuh alasan.
     * Lihat cancel() (ditambahkan di Task 10).
     *
     * DUA HAL YANG PERLU DIINGAT PEMANGGIL:
     *  - `refresh()` di bawah MEMBUANG perubahan yang masih menggantung di memori
     *    pada instance yang dioper. Ini kebalikan dari `ServiceInvoice::recalcTotals()`,
     *    yang justru ikut menyimpan atribut kotor lain. Jangan mengoper invoice yang
     *    baru diubah tapi belum disimpan ke sini.
     *  - `refresh()` berada DI LUAR transaksi dan tanpa kunci baris, jadi ia menutup
     *    kasus instance basi (dua operator memuat halaman lalu menyimpan bergantian),
     *    bukan tulisan yang benar-benar serempak. Sisa celahnya diterima sadar —
     *    alat internal, satu-dua operator, sama seperti catatan di recalcTotals().
     *    Penutupnya kelak `lockForUpdate()` pada baris invoice di dalam transaksi.
     *
     * @return bool true bila status benar-benar berpindah; false bila sama.
     */
    public function changeStatus(ServiceInvoice $invoice, string $to, ?string $note, ?int $userId): bool
    {
        // Divalidasi DI SINI, bukan hanya di aturan `in:` milik controller. Kolomnya
        // varchar biasa, jadi basis data bukan jaring pengaman: 'Selesai' berkapital
        // akan tersimpan apa adanya dan lolos dari setiap filter, sedangkan 'batal'
        // lewat jalur ini menghasilkan invoice batal tanpa alasan/pelaku yang tak
        // bisa digerakkan lagi dari mana pun. Kedua service sejenis di codebase ini
        // (TitleProgressService, ChapterManuscriptService) juga memvalidasi di dalam.
        if (! in_array($to, self::CHANGEABLE, true)) {
            throw ValidationException::withMessages([
                'work_status' => "Status pengerjaan '{$to}' tidak dikenal. "
                    . 'Pembatalan punya jalurnya sendiri lewat cancel().',
            ]);
        }

        // Baca ulang dari basis data SEBELUM memutuskan apa pun. Instance yang dipegang
        // pemanggil bisa basi (dua operator memuat baris yang sama sebelum salah satu
        // menyimpan). Tanpa ini dua hal salah sekaligus: (1) $from di bawah bisa keliru,
        // dan (2) Eloquent's update() cuma mengirim kolom yang "dirty" RELATIF KE
        // SNAPSHOT ASLI MODEL INI, bukan relatif ke isi tabel saat ini — jadi kalau nilai
        // baru yang kita tulis (mis. work_finished_at = null) KEBETULAN sama dengan nilai
        // basi yang pertama kali dimuat model ini, kolom itu diam-diam tidak pernah masuk
        // ke SQL UPDATE sama sekali, dan tanggal selesai tulisan penulis lain bertahan.
        $invoice->refresh();

        $from = $invoice->work_status;
        if ($from === $to) {
            return false;
        }

        $attrs = ['work_status' => $to];

        if ($to === 'proses' && $invoice->work_started_at === null) {
            $attrs['work_started_at'] = now();
        }

        // Berkunci pada TUJUAN, bukan asal. `elseif ($from === 'selesai')` tampak
        // setara, tapi $from bisa basi: kalau dua orang memuat invoice yang sama
        // lalu yang satu menandai Selesai dan yang lain memindahkannya ke Proses,
        // $from si kedua masih 'belum' sehingga tanggal selesai milik yang pertama
        // ikut tertinggal di baris berstatus Proses. Tak ada yang memperbaikinya.
        if ($to === 'selesai') {
            $attrs['work_finished_at'] = now();
        } else {
            $attrs['work_finished_at'] = null;
        }

        // Perpindahan status dan jejaknya harus jatuh bersama: status yang berpindah
        // tanpa baris log adalah riwayat yang berbohong.
        DB::transaction(function () use ($invoice, $attrs, $from, $to, $note, $userId) {
            $invoice->update($attrs);

            $invoice->logs()->create([
                'event'       => 'status_changed',
                'from_status' => $from,
                'to_status'   => $to,
                'note'        => $note,
                'changed_by'  => $userId,
            ]);
        });

        return true;
    }
}
```

- [ ] **Step 4: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceWorkStatusTest`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/ServiceInvoiceWorkflow.php tests/Feature/ServiceInvoiceWorkStatusTest.php
git commit -m "layanan: ServiceInvoiceWorkflow — perpindahan status + jejaknya"
```

---

### Task 6: Katalog layanan — CRUD, rute, permission, seeder, menu

Rute pertama modul ini. **Permission wajib ikut di commit yang sama**, atau `PermissionMapCompletenessTest` merah dan semua non-superadmin kena 403.

**Files:**
- Create: `app/Http/Controllers/Pages/ServiceCatalogController.php`
- Create: `database/seeders/ServiceCatalogSeeder.php`
- Create: `resources/views/services/catalogs/index.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/ServiceCatalogCrudTest.php`

> `AccessMatrixSeeder.php` **tidak** disentuh di task ini: `manager` sudah memegang hibah `'*'`, dan role lain memakai daftar eksplisit sehingga tak kebagian apa pun. Seeder itu baru diubah di Task 9 & 10, saat `service_invoice.delete` dan `.cancel` perlu masuk `$superadminOnly`.

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceCatalogCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceCatalogCrudTest extends TestCase
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

    /** @test */
    public function manager_can_list_create_update_and_delete(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('service.catalog.index'))->assertOk();

        $this->actingAs($manager)->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Perbaikan SMTP',
            'price' => '350.000', 'unit' => 'paket',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog = ServiceCatalog::firstWhere('name', 'Perbaikan SMTP');
        $this->assertNotNull($catalog);
        $this->assertEquals(350000, $catalog->price);   // pemisah ribuan dibuang

        $this->actingAs($manager)->put(route('service.catalog.update', $catalog->id), [
            'category' => 'perbaikan', 'name' => 'Perbaikan SMTP',
            'price' => '400000', 'price_max' => '600000', 'is_active' => '0',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog->refresh();
        $this->assertEquals(600000, $catalog->price_max);
        $this->assertFalse($catalog->is_active);

        $this->actingAs($manager)->delete(route('service.catalog.destroy', $catalog->id))->assertRedirect();
        $this->assertSoftDeleted('tb_service_catalogs', ['id' => $catalog->id]);
    }

    /** @test */
    public function price_max_must_not_be_below_price(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Salah Kisaran',
            'price' => '1000000', 'price_max' => '500000',
        ])->assertSessionHasErrors('price_max');

        $this->assertDatabaseMissing('tb_service_catalogs', ['name' => 'Salah Kisaran']);
    }

    /** @test */
    public function other_roles_are_locked_out(): void
    {
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.catalog.index'))
                ->assertForbidden();
        }
    }

    /** @test */
    public function seeder_fills_the_published_price_list(): void
    {
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        // Jumlah dipatok eksplisit. Tanpa ini, seeder yang kehilangan 27 dari 30
        // barisnya tetap lolos selama tiga baris yang kebetulan disebut di bawah
        // masih ada — dan seluruh gunanya task ini adalah menyalin daftar harga
        // klien dengan setia.
        $this->assertSame(30, ServiceCatalog::count());

        $perCategory = ServiceCatalog::get()->groupBy('category')->map->count()->all();
        $this->assertSame([
            'instalasi' => 5, 'perbaikan' => 7, 'upgrade' => 4, 'desain' => 4,
            'hosting' => 4, 'maintenance' => 3, 'bundle' => 3,
        ], $perCategory);

        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Instalasi OJS Basic', 'price' => 500000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Fix Error Sedang', 'price' => 500000.00, 'price_max' => 1000000.00]);
        $this->assertDatabaseHas('tb_service_catalogs', ['name' => 'Paket Enterprise', 'category' => 'bundle']);

        // Kategori Turnitin/plagiasi sengaja kosong — tarifnya belum ditetapkan.
        $this->assertDatabaseMissing('tb_service_catalogs', ['category' => 'similarity']);

        // Idempoten: dijalankan dua kali tidak menggandakan baris.
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);
        $this->assertSame(30, ServiceCatalog::count());
    }

    /** @test */
    public function reseeding_does_not_resurrect_a_row_the_operator_deleted(): void
    {
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        ServiceCatalog::firstWhere('name', 'Setup Multi Jurnal')->delete();
        $this->assertSame(29, ServiceCatalog::count());

        // Lookup-nya memakai withTrashed, jadi baris yang sudah dihapus tetap
        // dikenali dan tidak dibuat ulang sebagai duplikat.
        $this->seed(\Database\Seeders\ServiceCatalogSeeder::class);

        $this->assertSame(29, ServiceCatalog::count());
        $this->assertSame(30, ServiceCatalog::withTrashed()->count());
    }

    /** @test */
    public function reopening_and_saving_a_row_unchanged_keeps_its_price(): void
    {
        $manager = $this->user('manager');
        $catalog = ServiceCatalog::factory()->create([
            'name' => 'Perbaikan SMTP', 'price' => 350000, 'price_max' => 1000000,
        ]);

        // Ambil payload PERSIS seperti yang ditanam view ke tombol Edit, bukan
        // yang diketik tangan — jebakannya justru ada di payload itu.
        $html = $this->actingAs($manager)->get(route('service.catalog.index'))->getContent();
        $this->assertSame(1, preg_match('/data-catalog="([^"]*)"/', $html, $m));
        $payload = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        // Kalau ini "350000.00" dan bukan 350000, pembersih pemisah ribuan di
        // controller akan membuang titiknya dan harganya jadi 35.000.000 hanya
        // karena barisnya dibuka lalu disimpan tanpa diubah sama sekali.
        $this->assertSame(350000, $payload['price']);
        $this->assertSame(1000000, $payload['price_max']);

        $this->actingAs($manager)->put(route('service.catalog.update', $catalog->id), [
            'category'  => $payload['category'],
            'name'      => $payload['name'],
            'price'     => (string) $payload['price'],
            'price_max' => (string) $payload['price_max'],
            'is_active' => '1',
        ])->assertRedirect(route('service.catalog.index'));

        $catalog->refresh();
        $this->assertEquals(350000, $catalog->price);
        $this->assertEquals(1000000, $catalog->price_max);
    }

    /** @test */
    public function a_rejected_save_is_shown_to_the_operator(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)
            ->from(route('service.catalog.index'))
            ->post(route('service.catalog.store'), [
                'category' => 'perbaikan', 'name' => 'Salah Kisaran',
                'price' => '1000000', 'price_max' => '500000',
            ])
            ->assertRedirect(route('service.catalog.index'));

        // Sesi punya galatnya belum cukup: layouts/master tidak merender $errors,
        // jadi tanpa blok galat di view-nya operator tak melihat apa pun dan
        // mengira aplikasinya macet.
        $this->actingAs($manager)
            ->get(route('service.catalog.index'))
            ->assertOk()
            ->assertSee('Data belum tersimpan.');
    }

    /** @test */
    public function an_empty_is_active_value_does_not_explode(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.catalog.store'), [
            'category' => 'perbaikan', 'name' => 'Checkbox Kosong',
            'price' => '350000', 'is_active' => '',
        ])->assertRedirect(route('service.catalog.index'));

        $this->assertFalse(ServiceCatalog::firstWhere('name', 'Checkbox Kosong')->is_active);
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceCatalogCrudTest`
Expected: FAIL — `Route [service.catalog.index] not defined.`

- [ ] **Step 3: Tulis controller**

`app/Http/Controllers/Pages/ServiceCatalogController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceCatalog;
use Illuminate\Http\Request;

class ServiceCatalogController extends Controller
{
    public function index()
    {
        $catalogs = ServiceCatalog::orderBy('category')->orderBy('position')->orderBy('name')->get();

        return view('services.catalogs.index', [
            'catalogs'   => $catalogs->groupBy('category'),
            'categories' => ServiceCatalog::CATEGORIES,
            'units'      => ServiceCatalog::UNITS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        ServiceCatalog::create($data);

        return redirect()->route('service.catalog.index')->with('success', 'Layanan ditambahkan ke katalog.');
    }

    public function update(Request $request, int $id)
    {
        $catalog = ServiceCatalog::findOrFail($id);
        $catalog->update($this->validated($request));

        return redirect()->route('service.catalog.index')->with('success', 'Layanan diperbarui.');
    }

    public function destroy(int $id)
    {
        ServiceCatalog::findOrFail($id)->delete();

        // 'info', bukan 'warning': layouts/master hanya merender success/error/info,
        // jadi pesan ber-key 'warning' tak pernah sampai ke layar sama sekali.
        return redirect()->route('service.catalog.index')
            ->with('info', 'Layanan dihapus dari katalog. Invoice lama tidak berubah.');
    }

    /**
     * Buang pemisah ribuan sebelum validasi, lalu validasi.
     *
     * Harga di modul ini RUPIAH BULAT. Pembersih di bawah membuang titik, koma,
     * dan spasi tanpa bisa membedakan pemisah ribuan dari titik desimal — jadi
     * "1.500,50" akan jadi 150050. Itu diterima sadar: tarif jasa tak pernah bersen.
     * Konsekuensinya form HARUS menerima angka bulat saja; lihat catatan di view
     * soal `(int)` pada data-catalog, yang mencegah "350000.00" kembali ke sini.
     */
    private function validated(Request $request): array
    {
        foreach (['price', 'price_max'] as $field) {
            if ($request->filled($field)) {
                // Pertahankan tanda minus agar nominal negatif tetap DITOLAK min:0.
                $request->merge([$field => preg_replace('/[.,\s]/', '', (string) $request->input($field))]);
            }
        }

        // `is_active` SENGAJA di luar daftar aturan. Kalau ia divalidasi sebagai
        // `nullable|boolean`, nilai kosong lolos sebagai null lalu menabrak kolom
        // NOT NULL dan berakhir 500. Di luar daftar, union `+` di bawah selalu
        // yang mengisinya, dan checkbox yang tak dicentang jadi false.
        return $request->validate([
            'category'    => 'required|in:' . implode(',', array_keys(ServiceCatalog::CATEGORIES)),
            'name'        => 'required|string|max:190',
            'price'       => 'required|numeric|min:0|max:9999999999999.99',
            'price_max'   => 'nullable|numeric|min:0|max:9999999999999.99|gte:price',
            'unit'        => 'nullable|in:' . implode(',', array_keys(ServiceCatalog::UNITS)),
            'description' => 'nullable|string',
            'position'    => 'nullable|integer|min:0',
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
```

> Catatan: `+ ['is_active' => ...]` di akhir tidak menimpa kunci yang sudah ada — operator `+` pada array mempertahankan yang kiri. Karena `is_active` bersifat `nullable` dan checkbox yang tidak dicentang tidak terkirim, kuncinya absen dari hasil validasi, sehingga nilai boolean-nya yang dipakai.

- [ ] **Step 4: Tulis seeder katalog**

`database/seeders/ServiceCatalogSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\ServiceCatalog;
use Illuminate\Database\Seeder;

/**
 * Daftar harga jasa OJS yang berlaku. firstOrCreate berdasarkan category+name,
 * jadi aman dijalankan ulang dan TIDAK menimpa harga yang sudah disunting operator.
 *
 * `withTrashed()` pada pencariannya penting: tanpa itu, baris seed yang sengaja
 * DIHAPUS operator tak terlihat oleh lookup, seeder membuatnya lagi, dan
 * penghapusannya batal diam-diam sambil menumpuk tombstone tiap kali dijalankan.
 *
 * BATAS YANG HARUS DIKETAHUI: kuncinya adalah NAMA. Baris seed yang DIGANTI
 * NAMANYA oleh operator tak akan dikenali lagi, jadi menjalankan ulang seeder
 * melahirkan kembali baris lama di samping hasil suntingan itu. Kalau kelak
 * penggantian nama jadi hal biasa, kuncinya harus pindah ke kolom `code` yang
 * stabil — bukan menambal seeder-nya.
 *
 * Kategori 'similarity' (Turnitin & penurunan plagiasi) sengaja kosong: tarifnya
 * belum ditetapkan, diisi lewat CRUD katalog tanpa perlu deploy.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // [nama, harga, harga_maks, satuan, deskripsi]
        $rows = [
            'instalasi' => [
                ['Instalasi OJS Basic',           500000,  null,     'paket', null],
                ['Instalasi + Konfigurasi OJS',   750000,  null,     'paket', null],
                ['Instalasi + Desain Tampilan',   1250000, null,     'paket', null],
                ['Setup Lengkap Jurnal',          2500000, null,     'paket', null],
                ['Setup Multi Jurnal',            3500000, 5000000,  'paket', null],
            ],
            'perbaikan' => [
                ['Fix Error Ringan',              250000,  500000,   'paket', null],
                ['Fix Error Sedang',              500000,  1000000,  'paket', null],
                ['Fix Error Berat',               1000000, 2500000,  'paket', null],
                ['Perbaikan SMTP',                350000,  null,     'paket', null],
                ['Perbaikan DOI Crossref',        500000,  null,     'paket', null],
                ['Perbaikan PKP PN',              500000,  null,     'paket', null],
                ['Pembersihan Malware',           750000,  2000000,  'paket', null],
            ],
            'upgrade' => [
                ['Upgrade Minor',                 750000,  null,     'paket', null],
                ['Upgrade Mayor',                 1500000, 3000000,  'paket', 'Mis. 3.2 → 3.3, 3.3 → 3.4'],
                ['Migrasi Hosting',               1000000, 2500000,  'paket', null],
                ['Migrasi VPS',                   1500000, 3500000,  'paket', null],
            ],
            'desain' => [
                ['Redesign Homepage',             750000,  null,     'paket', null],
                ['Custom Homepage Premium',       1500000, null,     'paket', null],
                ['Desain Logo Jurnal',            250000,  null,     'paket', null],
                ['Custom Theme OJS',              2500000, 5000000,  'paket', null],
            ],
            'hosting' => [
                ['Starter (5GB)',                 750000,  null,     'tahun', null],
                ['Standard (10GB)',               1250000, null,     'tahun', null],
                ['Professional (25GB)',           2500000, null,     'tahun', null],
                ['VPS Managed',                   4500000, 12000000, 'tahun', null],
            ],
            'maintenance' => [
                ['Maintenance Bulanan',           300000,  null,     'bulan', null],
                ['Maintenance Semester',          1500000, null,     'paket', null],
                ['Maintenance Tahunan',           2500000, null,     'tahun', null],
            ],
            'bundle' => [
                ['Paket Starter',      1750000, null, 'tahun',
                 'Domain .com/.or.id · Hosting 5 GB · Instalasi OJS · SSL · Setup SMTP · Konsultasi ISSN'],
                ['Paket Professional', 3500000, null, 'tahun',
                 'Domain · Hosting 10 GB · Instalasi OJS · Desain Homepage · Setup DOI · SMTP · Support 1 Tahun'],
                ['Paket Enterprise',   6500000, null, 'tahun',
                 'Domain · Hosting 25 GB · Hingga 5 Jurnal · Desain Premium · DOI & PKP PN · Maintenance 1 Tahun · Prioritas Support'],
            ],
        ];

        foreach ($rows as $category => $items) {
            foreach ($items as $position => [$name, $price, $priceMax, $unit, $description]) {
                ServiceCatalog::withTrashed()->firstOrCreate(
                    ['category' => $category, 'name' => $name],
                    [
                        'price'       => $price,
                        'price_max'   => $priceMax,
                        'unit'        => $unit,
                        'description' => $description,
                        'is_active'   => true,
                        'position'    => $position,
                    ]
                );
            }
        }
    }
}
```

- [ ] **Step 5: Daftarkan rute**

Tambahkan di `routes/web.php`, di dalam grup `auth` (setelah blok `salary/slip`):

```php
    /* ===================== LAYANAN / JASA (modul standalone) ===================== */
    Route::prefix('layanan')->name('service.')->group(function () {
        // Katalog
        Route::get   ('katalog',      [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'index'])  ->name('catalog.index');
        Route::post  ('katalog',      [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'store'])  ->name('catalog.store');
        Route::put   ('katalog/{id}', [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'update']) ->name('catalog.update')->whereNumber('id');
        Route::delete('katalog/{id}', [\App\Http\Controllers\Pages\ServiceCatalogController::class, 'destroy'])->name('catalog.destroy')->whereNumber('id');
    });
```

- [ ] **Step 6: Petakan permission**

Tambahkan di `config/permissions.php`, di dalam `'modules'`, tepat sebelum entri `'permission' =>`:

```php
        'service_catalog' => [
            'label'   => 'Katalog Layanan',
            'actions' => [
                'view'   => ['service.catalog.index'],
                'manage' => ['service.catalog.store', 'service.catalog.update', 'service.catalog.destroy'],
            ],
        ],
```

- [ ] **Step 7: Tambahkan entri menu**

Di `resources/views/layouts/sidebar.blade.php`, sisipkan grup baru tepat setelah blok `@endcanany` milik grup **Pembayaran**:

```blade
            {{-- ===================== LAYANAN / JASA ===================== --}}
            {{-- Modul standalone: TIDAK bermuara ke kas, karena itu tidak digabung
                 ke grup Pembayaran yang semua itemnya masuk keuangan. --}}
            @canany(['service_invoice.view', 'service_catalog.view', 'service_client.view'])
                <li class="nav-item nav-category">Layanan</li>
                @can('service_catalog.view')
                    <li class="nav-item {{ nav_active('service.catalog.*') }}">
                        <a href="{{ route('service.catalog.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="list"></i>
                            <span class="link-title">Katalog Layanan</span>
                        </a>
                    </li>
                @endcan
            @endcanany
```

> Item Invoice Layanan & Klien Jasa ditambahkan ke grup ini di Task 8 dan Task 7. `@canany` sudah menyebut ketiganya sekarang supaya grupnya tidak perlu disentuh dua kali.

- [ ] **Step 8: Tulis view katalog**

`resources/views/services/catalogs/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Katalog Layanan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Katalog Layanan</h6>
                    @can('service_catalog.manage')
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#catalogModal"
                                onclick="resetCatalogForm()">+ Tambah Layanan</button>
                    @endcan
                </div>

                <p class="text-muted small">
                    Harga di sini hanya acuan awal — saat membuat invoice, nominalnya tetap bisa ditimpa
                    sesuai kompleksitas pekerjaan. Mengubah harga di katalog <strong>tidak</strong>
                    mengubah invoice yang sudah terbit. Isi angka bulat tanpa sen.
                </p>

                {{-- WAJIB: layouts/master hanya merender session success/error/info, BUKAN $errors.
                     Tanpa blok ini setiap simpan yang ditolak validasi hanya memantul kembali ke
                     daftar tanpa satu pun tanda — operator mengira aplikasinya yang macet lalu
                     mengulang masukan yang sama. --}}
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <strong>Data belum tersimpan.</strong>
                        <ul class="mb-0 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Kategori</th><th>Layanan</th><th>Harga</th>
                                <th>Satuan</th><th>Aktif</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($catalogs as $category => $rows)
                                @foreach($rows as $c)
                                <tr>
                                    <td><span class="badge bg-secondary">{{ \App\Models\ServiceCatalog::categoryLabel($category) }}</span></td>
                                    <td>
                                        {{ $c->name }}
                                        @if($c->description)
                                            <br><small class="text-muted">{{ $c->description }}</small>
                                        @endif
                                    </td>
                                    {{-- data-order: tanpa ini DataTables mengurutkan teks "Rp 1.250.000"
                                         secara leksikografis, yang menaruhnya sebelum "Rp 250.000". --}}
                                    <td data-order="{{ (int) $c->price }}">{{ $c->priceLabel() }}</td>
                                    <td>{{ $units[$c->unit] ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $c->is_active ? 'success' : 'secondary' }}">
                                            {{ $c->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('service_catalog.manage')
                                        {{-- JANGAN pakai $c->toJson(): cast decimal:2 memancarkan
                                             "350000.00", dan begitu string itu masuk ke input teks,
                                             pembersih pemisah ribuan di controller membuang titik
                                             desimalnya — harganya jadi 35.000.000 hanya karena
                                             dibuka lalu disimpan. Konvensi yang sama sudah dipakai
                                             accounting/journal.blade.php dan salary/slips/form.blade.php. --}}
                                        <button class="btn btn-xs btn-outline-secondary"
                                                data-bs-toggle="modal" data-bs-target="#catalogModal"
                                                data-catalog="{{ json_encode([
                                                    'id'          => $c->id,
                                                    'category'    => $c->category,
                                                    'name'        => $c->name,
                                                    'price'       => (int) $c->price,
                                                    'price_max'   => $c->price_max !== null ? (int) $c->price_max : null,
                                                    'unit'        => $c->unit,
                                                    'description' => $c->description,
                                                    'is_active'   => $c->is_active,
                                                    'position'    => (int) $c->position,
                                                ]) }}"
                                                onclick="fillCatalogForm(this)">Edit</button>
                                        {{-- data-confirm, bukan onsubmit="return confirm(...)": ada
                                             listener SweetAlert terdelegasi di layouts/master yang
                                             dipakai seluruh aksi destruktif lain di aplikasi ini. --}}
                                        <form action="{{ route('service.catalog.destroy', $c->id) }}" method="POST" class="d-inline"
                                              data-confirm="Hapus layanan ini dari katalog? Invoice lama tidak berubah.">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('service_catalog.manage')
<div class="modal fade" id="catalogModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="catalogForm" action="{{ route('service.catalog.store') }}">
            @csrf
            <input type="hidden" name="_method" id="catalogMethod" value="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="catalogModalTitle">Tambah Layanan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Kategori</label>
                        <select name="category" id="catalogCategory" class="form-select" required>
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Nama Layanan</label>
                        <input type="text" name="name" id="catalogName" class="form-control" required maxlength="190">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Harga</label>
                            <input type="text" name="price" id="catalogPrice" class="form-control" required>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Harga Maks <small class="text-muted">(bila berkisar)</small></label>
                            <input type="text" name="price_max" id="catalogPriceMax" class="form-control">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Satuan</label>
                        <select name="unit" id="catalogUnit" class="form-select">
                            <option value="">—</option>
                            @foreach($units as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Keterangan</label>
                        <textarea name="description" id="catalogDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Urutan Tampil</label>
                        <input type="number" name="position" id="catalogPosition" class="form-control" min="0" value="0">
                        <small class="text-muted">Menentukan urutan di dalam kategorinya. Kecil tampil lebih dulu.</small>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="catalogActive" class="form-check-input" value="1" checked>
                        <label class="form-check-label" for="catalogActive">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    // Rute update dibangun dari template bernama, bukan URL yang diketik tangan:
    // kalau prefiks `layanan` kelak berpindah, tombol Edit ikut pindah sendiri
    // alih-alih diam-diam menembak 404.
    const CATALOG_UPDATE_URL = "{{ route('service.catalog.update', ['id' => '__ID__']) }}";

    $(function () {
        $(".datatable").DataTable({
            pageLength: 50,
            responsive: true,
            // order: [] mempertahankan urutan dari server (kategori lalu position).
            // Tanpa ini DataTables mengurutkan ulang berdasar label kategori secara
            // alfabetis dan membuang pengurutan yang sudah disusun controller.
            order: [],
            columnDefs: [{ orderable: false, targets: 5 }],
            language: { emptyTable: "Katalog masih kosong." }
        });
    });

    function resetCatalogForm() {
        document.getElementById('catalogModalTitle').textContent = 'Tambah Layanan';
        document.getElementById('catalogForm').action = "{{ route('service.catalog.store') }}";
        document.getElementById('catalogMethod').value = 'POST';
        // Kategori & satuan WAJIB ikut direset: kalau tidak, membuka Edit pada baris
        // hosting lalu menekan "+ Tambah Layanan" menyisakan Kategori=Hosting dan
        // Satuan=Tahun, dan layanan baru masuk ke kategori yang salah.
        document.getElementById('catalogCategory').selectedIndex = 0;
        document.getElementById('catalogUnit').value = '';
        document.getElementById('catalogName').value = '';
        document.getElementById('catalogPrice').value = '';
        document.getElementById('catalogPriceMax').value = '';
        document.getElementById('catalogDescription').value = '';
        document.getElementById('catalogPosition').value = 0;
        document.getElementById('catalogActive').checked = true;
    }

    function fillCatalogForm(button) {
        const c = JSON.parse(button.dataset.catalog);
        document.getElementById('catalogModalTitle').textContent = 'Edit Layanan';
        document.getElementById('catalogForm').action = CATALOG_UPDATE_URL.replace('__ID__', c.id);
        document.getElementById('catalogMethod').value = 'PUT';
        document.getElementById('catalogCategory').value = c.category;
        document.getElementById('catalogName').value = c.name;
        document.getElementById('catalogPrice').value = c.price;
        document.getElementById('catalogPriceMax').value = c.price_max ?? '';
        document.getElementById('catalogUnit').value = c.unit ?? '';
        document.getElementById('catalogDescription').value = c.description ?? '';
        document.getElementById('catalogPosition').value = c.position ?? 0;
        document.getElementById('catalogActive').checked = !!c.is_active;
    }
</script>
@endpush
```

- [ ] **Step 9: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceCatalogCrudTest`
Expected: PASS (8 tests)

- [ ] **Step 10: Pastikan peta permission tetap lengkap**

Run: `php artisan test --filter=PermissionMapCompletenessTest`
Expected: PASS — rute `service.catalog.*` sudah terpeta.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Pages/ServiceCatalogController.php \
        database/seeders/ServiceCatalogSeeder.php \
        resources/views/services/catalogs/index.blade.php \
        routes/web.php config/permissions.php resources/views/layouts/sidebar.blade.php \
        tests/Feature/ServiceCatalogCrudTest.php
git commit -m "layanan: CRUD katalog layanan + seeder daftar harga + menu"
```

---

### Task 7: Klien jasa — CRUD + halaman detail

**Files:**
- Create: `app/Http/Controllers/Pages/ServiceClientController.php`
- Create: `resources/views/services/clients/index.blade.php`
- Create: `resources/views/services/clients/show.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/ServiceClientCrudTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceClientCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceClientCrudTest extends TestCase
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

    /** @test */
    public function manager_can_create_update_and_delete_a_client(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('service.client.index'))->assertOk();

        $this->actingAs($manager)->post(route('service.client.store'), [
            'name' => 'Dr. Sartika', 'institution' => 'Universitas Batanghari',
            'email' => 'jurnal@unbari.ac.id', 'phone' => '081234567890',
        ])->assertRedirect(route('service.client.index'));

        $client = ServiceClient::firstWhere('email', 'jurnal@unbari.ac.id');
        $this->assertNotNull($client);
        $this->assertSame($manager->id, $client->created_by);

        $this->actingAs($manager)->put(route('service.client.update', $client->id), [
            'name' => 'Dr. Sartika', 'institution' => 'UNBARI',
            'email' => 'jurnal@unbari.ac.id',
        ])->assertRedirect(route('service.client.index'));

        $client->refresh();
        $this->assertSame('UNBARI', $client->institution);
        $this->assertSame($manager->id, $client->updated_by);

        $this->actingAs($manager)->delete(route('service.client.destroy', $client->id))->assertRedirect();
        $this->assertSoftDeleted('tb_service_clients', ['id' => $client->id]);
    }

    /** @test */
    public function client_detail_lists_that_clients_invoices(): void
    {
        $client = ServiceClient::factory()->create();
        ServiceInvoice::factory()->count(2)->create(['service_client_id' => $client->id]);
        ServiceInvoice::factory()->create();   // klien lain

        $this->actingAs($this->user('manager'))
            ->get(route('service.client.show', $client->id))
            ->assertOk()
            ->assertViewHas('client', fn ($c) => $c->invoices->count() === 2);
    }

    /** @test */
    public function deleting_a_client_leaves_its_invoices_intact(): void
    {
        $client  = ServiceClient::factory()->create();
        $invoice = ServiceInvoice::factory()->create([
            'service_client_id' => $client->id,
            'client_name'       => 'Nama Tersalin',
        ]);

        $this->actingAs($this->user('manager'))->delete(route('service.client.destroy', $client->id));

        $invoice->refresh();
        $this->assertNull($invoice->service_client_id);       // FK dilepas
        $this->assertSame('Nama Tersalin', $invoice->client_name);   // snapshot utuh
    }

    /** @test */
    public function superadmin_can_open_a_client_detail_page(): void
    {
        $client = ServiceClient::factory()->create();
        ServiceInvoice::factory()->create(['service_client_id' => $client->id]);

        // BUKAN pengulangan tes manager. Gate::before (AuthServiceProvider) meloloskan
        // superadmin untuk ability APA PUN, termasuk permission yang belum terdaftar —
        // jadi blok @can yang benar-benar aman bagi manager tetap DIEVALUASI bagi
        // superadmin, dan setiap route() yang belum ada di dalamnya meledak jadi 500.
        // Tes ini yang menahan pola itu supaya tidak masuk lagi.
        $this->actingAs($this->user('superadmin'))
            ->get(route('service.client.show', $client->id))
            ->assertOk();
    }

    /** @test */
    public function other_roles_are_locked_out(): void
    {
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.client.index'))
                ->assertForbidden();
        }
    }

    /** @test */
    public function a_rejected_save_is_shown_to_the_operator(): void
    {
        $manager = $this->user('manager');

        $this->actingAs($manager)
            ->from(route('service.client.index'))
            ->post(route('service.client.store'), ['name' => 'Tanpa Email Valid', 'email' => 'bukan-email'])
            ->assertRedirect(route('service.client.index'));

        // Galat yang cuma sampai ke sesi tidak berguna: layouts/master tidak
        // merender $errors, jadi view-nya harus menampilkannya sendiri.
        $this->actingAs($manager)
            ->get(route('service.client.index'))
            ->assertOk()
            ->assertSee('Data belum tersimpan.');
    }
}
```

> `deleting_a_client_leaves_its_invoices_intact` mengandalkan `nullOnDelete` di FK. Soft delete Eloquent **tidak** memicu FK database, jadi controller harus melepas tautannya sendiri — lihat Step 3.

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceClientCrudTest`
Expected: FAIL — `Route [service.client.index] not defined.`

- [ ] **Step 3: Tulis controller**

`app/Http/Controllers/Pages/ServiceClientController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceClientController extends Controller
{
    public function index()
    {
        $clients = ServiceClient::withCount('invoices')->orderBy('name')->get();

        return view('services.clients.index', compact('clients'));
    }

    public function show(int $id)
    {
        $client = ServiceClient::with('invoices')->findOrFail($id);

        return view('services.clients.show', compact('client'));
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        ServiceClient::create($data);

        return redirect()->route('service.client.index')->with('success', 'Klien jasa ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $client = ServiceClient::findOrFail($id);

        $data = $this->rules($request);
        $data['updated_by'] = Auth::id();
        $client->update($data);

        return redirect()->route('service.client.index')
            ->with('success', 'Klien jasa diperbarui. Invoice yang sudah terbit tidak berubah.');
    }

    public function destroy(int $id)
    {
        $client = ServiceClient::findOrFail($id);

        DB::transaction(function () use ($client) {
            // Soft delete Eloquent tidak memicu FK nullOnDelete di database, jadi
            // tautannya dilepas manual. Snapshot di invoice sengaja TIDAK disentuh —
            // dokumen yang sudah terbit harus tetap mencetak isi yang sama.
            ServiceInvoice::where('service_client_id', $client->id)
                ->update(['service_client_id' => null]);

            $client->delete();
        });

        // 'info', bukan 'warning': layouts/master hanya merender success/error/info.
        return redirect()->route('service.client.index')
            ->with('info', 'Klien dihapus. Invoice lamanya tetap utuh.');
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:190',
            'institution' => 'nullable|string|max:190',
            'email'       => 'nullable|email|max:190',
            'phone'       => 'nullable|string|max:40',
            'address'     => 'nullable|string',
            'note'        => 'nullable|string',
        ]);
    }
}
```

- [ ] **Step 4: Daftarkan rute**

Tambahkan di dalam grup `Route::prefix('layanan')->name('service.')` di `routes/web.php`, setelah blok Katalog:

```php
        // Klien jasa
        Route::get   ('klien',      [\App\Http\Controllers\Pages\ServiceClientController::class, 'index'])  ->name('client.index');
        Route::post  ('klien',      [\App\Http\Controllers\Pages\ServiceClientController::class, 'store'])  ->name('client.store');
        Route::get   ('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'show'])   ->name('client.show')->whereNumber('id');
        Route::put   ('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'update']) ->name('client.update')->whereNumber('id');
        Route::delete('klien/{id}', [\App\Http\Controllers\Pages\ServiceClientController::class, 'destroy'])->name('client.destroy')->whereNumber('id');
```

- [ ] **Step 5: Petakan permission**

Tambahkan di `config/permissions.php` tepat setelah blok `'service_catalog'`:

```php
        'service_client' => [
            'label'   => 'Klien Jasa',
            'actions' => [
                'view'   => ['service.client.index', 'service.client.show'],
                'manage' => ['service.client.store', 'service.client.update', 'service.client.destroy'],
            ],
        ],
```

- [ ] **Step 6: Tambahkan item menu**

Di `resources/views/layouts/sidebar.blade.php`, di dalam grup **Layanan**, tepat setelah blok `@can('service_catalog.view')`:

```blade
                @can('service_client.view')
                    <li class="nav-item {{ nav_active('service.client.*') }}">
                        <a href="{{ route('service.client.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="users"></i>
                            <span class="link-title">Klien Jasa</span>
                        </a>
                    </li>
                @endcan
```

- [ ] **Step 7: Tulis view daftar klien**

`resources/views/services/clients/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Klien Jasa - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Klien Jasa</h6>
                    @can('service_client.manage')
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#clientModal"
                                onclick="resetClientForm()">+ Tambah Klien</button>
                    @endcan
                </div>

                {{-- WAJIB: layouts/master hanya merender session success/error/info, BUKAN $errors.
                     Tanpa blok ini, email yang salah format memantul kembali ke daftar tanpa satu
                     pun tanda dan operator mengira aplikasinya macet. --}}
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <strong>Data belum tersimpan.</strong>
                        <ul class="mb-0 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr><th>Nama</th><th>Instansi</th><th>Email</th><th>Telepon</th><th>Invoice</th><th>Aksi</th></tr>
                        </thead>
                        <tbody>
                            @foreach($clients as $c)
                            <tr>
                                <td><a href="{{ route('service.client.show', $c->id) }}">{{ $c->name }}</a></td>
                                <td>{{ $c->institution ?? '-' }}</td>
                                <td>{{ $c->email ?? '-' }}</td>
                                <td>{{ $c->phone ?? '-' }}</td>
                                <td><span class="badge bg-info">{{ $c->invoices_count }}</span></td>
                                <td>
                                    @can('service_client.manage')
                                    {{-- Payload dirakit eksplisit, bukan $c->toJson(): hanya kolom
                                         yang memang dipakai form yang perlu sampai ke browser. --}}
                                    <button class="btn btn-xs btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#clientModal"
                                            data-client="{{ json_encode([
                                                'id'          => $c->id,
                                                'name'        => $c->name,
                                                'institution' => $c->institution,
                                                'email'       => $c->email,
                                                'phone'       => $c->phone,
                                                'address'     => $c->address,
                                                'note'        => $c->note,
                                            ]) }}" onclick="fillClientForm(this)">Edit</button>
                                    {{-- data-confirm: listener SweetAlert terdelegasi di layouts/master,
                                         dipakai seluruh aksi destruktif lain di aplikasi ini. --}}
                                    <form action="{{ route('service.client.destroy', $c->id) }}" method="POST" class="d-inline"
                                          data-confirm="Hapus klien ini? Invoice lamanya tetap utuh.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('service_client.manage')
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="clientForm" action="{{ route('service.client.store') }}">
            @csrf
            <input type="hidden" name="_method" id="clientMethod" value="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="clientModalTitle">Tambah Klien</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Nama</label>
                        <input type="text" name="name" id="clientName" class="form-control" required maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Instansi</label>
                        <input type="text" name="institution" id="clientInstitution" class="form-control" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="clientEmail" class="form-control" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="phone" id="clientPhone" class="form-control" maxlength="40">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Alamat</label>
                        <textarea name="address" id="clientAddress" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" id="clientNote" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    // Rute update dari template bernama, bukan URL yang diketik tangan: kalau
    // prefiks `layanan` kelak berpindah, tombol Edit ikut pindah sendiri.
    const CLIENT_UPDATE_URL = "{{ route('service.client.update', ['id' => '__ID__']) }}";

    $(function () {
        $(".datatable").DataTable({
            pageLength: 25, responsive: true,
            columnDefs: [{ orderable: false, targets: 5 }],
            language: { emptyTable: "Belum ada klien jasa." }
        });
    });

    function resetClientForm() {
        document.getElementById('clientModalTitle').textContent = 'Tambah Klien';
        document.getElementById('clientForm').action = "{{ route('service.client.store') }}";
        document.getElementById('clientMethod').value = 'POST';
        ['clientName','clientInstitution','clientEmail','clientPhone','clientAddress','clientNote']
            .forEach(id => document.getElementById(id).value = '');
    }

    function fillClientForm(button) {
        const c = JSON.parse(button.dataset.client);
        document.getElementById('clientModalTitle').textContent = 'Edit Klien';
        document.getElementById('clientForm').action = CLIENT_UPDATE_URL.replace('__ID__', c.id);
        document.getElementById('clientMethod').value = 'PUT';
        document.getElementById('clientName').value = c.name;
        document.getElementById('clientInstitution').value = c.institution ?? '';
        document.getElementById('clientEmail').value = c.email ?? '';
        document.getElementById('clientPhone').value = c.phone ?? '';
        document.getElementById('clientAddress').value = c.address ?? '';
        document.getElementById('clientNote').value = c.note ?? '';
    }
</script>
@endpush
```

- [ ] **Step 8: Tulis view detail klien**

`resources/views/services/clients/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Klien: ' . $client->name . ' - SiMAPA')

@section('content')
<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">{{ $client->name }}</h6>
                <p class="mb-1"><strong>Instansi:</strong> {{ $client->institution ?? '-' }}</p>
                <p class="mb-1"><strong>Email:</strong> {{ $client->email ?? '-' }}</p>
                <p class="mb-1"><strong>Telepon:</strong> {{ $client->phone ?? '-' }}</p>
                <p class="mb-1"><strong>Alamat:</strong> {{ $client->address ?? '-' }}</p>
                @if($client->note)
                    <p class="mb-0"><strong>Catatan:</strong> {{ $client->note }}</p>
                @endif
                <a href="{{ route('service.client.index') }}" class="btn btn-sm btn-outline-secondary mt-3">← Kembali</a>
            </div>
        </div>
    </div>

    <div class="col-md-8 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Riwayat Pekerjaan</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-centered">
                        <thead>
                            <tr><th>No Invoice</th><th>Terbit</th><th>Total</th><th>Kerja</th><th>Bayar</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse($client->invoices as $inv)
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td><small>{{ $inv->issued_at?->format('d/m/Y') }}</small></td>
                                <td>Rp {{ number_format($inv->total, 0, ',', '.') }}</td>
                                <td><span class="badge bg-secondary">{{ $inv->workStatusLabel() }}</span></td>
                                <td><span class="badge bg-info">{{ $inv->paymentStatusLabel() }}</span></td>
                                {{-- Tombol Detail sengaja BELUM ada di sini; ditambahkan di Task 8
                                     bersama rute service.invoice.show. Membungkusnya dengan
                                     @can('service_invoice.view') TIDAK aman: Gate::before di
                                     AuthServiceProvider meloloskan superadmin untuk ability APA PUN,
                                     termasuk permission yang belum terdaftar — jadi blok itu tetap
                                     dievaluasi dan route() yang belum ada melempar 500. --}}
                                <td></td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted">Belum ada invoice untuk klien ini.</td></tr>
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

> **Koreksi terhadap draf sebelumnya.** Draf awal menaruh tombol Detail di sini, dibungkus `@can('service_invoice.view')`, dengan alasan "permission-nya belum ada jadi blok-nya tak pernah dievaluasi". **Itu salah**, dan sudah terbukti 500 di lingkungan nyata: alasan itu hanya berlaku untuk manager. `Gate::before` meloloskan superadmin untuk ability apa pun, jadi baginya blok itu tetap dijalankan dan `route()` yang belum ada melempar `RouteNotFoundException`. Tombolnya pindah ke Task 8. Lihat aturan global 9 & 10.

- [ ] **Step 9: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceClientCrudTest`
Expected: PASS (6 tests)

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Pages/ServiceClientController.php \
        resources/views/services/clients/index.blade.php \
        resources/views/services/clients/show.blade.php \
        routes/web.php config/permissions.php resources/views/layouts/sidebar.blade.php \
        tests/Feature/ServiceClientCrudTest.php
git commit -m "layanan: CRUD klien jasa + halaman riwayat pekerjaan per klien"
```

---

> **Catatan urutan untuk Task 8–13.** Halaman detail (`show`) tumbuh bertahap: tiap task hanya menambahkan blok yang rutenya sudah terdaftar di task itu. Ini disengaja — menulis seluruh `show.blade.php` di Task 8 akan memanggil `route('service.invoice.status', ...)` dsb. yang belum ada, dan blade-nya meledak saat dirender oleh tes.

### Task 8: Invoice — daftar, form, dan pembuatan

Menutup T-CLIENT-1.

**Files:**
- Create: `app/Support/ServiceInvoiceForm.php`
- Create: `app/Http/Controllers/Pages/ServiceInvoiceController.php`
- Create: `resources/views/services/invoices/index.blade.php`
- Create: `resources/views/services/invoices/form.blade.php`
- Create: `resources/views/services/invoices/show.blade.php`
- Modify: `routes/web.php`, `config/permissions.php`, `resources/views/layouts/sidebar.blade.php`
- Modify: `resources/views/services/clients/show.blade.php` (tombol Detail yang ditunda dari Task 7)
- Test: `tests/Feature/ServiceInvoiceStoreTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceStoreTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceStoreTest extends TestCase
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

    private function payload(array $override = []): array
    {
        return array_merge([
            'client_name'        => 'Dr. Sartika',
            'client_institution' => 'Universitas Batanghari',
            'client_email'       => 'jurnal@unbari.ac.id',
            'client_phone'       => '081234567890',
            'issued_at'          => '2026-08-11',
            'due_at'             => '2026-08-25',
            'discount'           => '0',
            'items'              => [
                ['name' => 'Instalasi + Konfigurasi OJS', 'qty' => 1, 'unit_price' => '750.000'],
                ['name' => 'Maintenance Bulanan',         'qty' => 3, 'unit_price' => '300.000'],
            ],
        ], $override);
    }

    /** @test */
    public function store_creates_invoice_with_number_items_and_totals(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload())
            ->assertRedirect();

        $inv = ServiceInvoice::first();
        $this->assertNotNull($inv);
        $this->assertSame('INV-JS-202608-0001', $inv->invoice_no);
        $this->assertCount(2, $inv->items);
        $this->assertEquals(1650000, $inv->subtotal);   // pemisah ribuan dibuang
        $this->assertEquals(1650000, $inv->total);
        $this->assertEquals(1650000, $inv->remaining);
        $this->assertSame('belum', $inv->work_status);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertSame('created', $inv->logs->first()->event);
    }

    /** @test */
    public function typing_a_new_client_creates_a_master_row_and_snapshots_it(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload());

        $client = ServiceClient::firstWhere('email', 'jurnal@unbari.ac.id');
        $this->assertNotNull($client, 'Klien baru harus tersimpan sebagai master.');

        $inv = ServiceInvoice::first();
        $this->assertSame($client->id, $inv->service_client_id);
        $this->assertSame('Dr. Sartika', $inv->client_name);
        $this->assertSame('Universitas Batanghari', $inv->client_institution);
    }

    /** @test */
    public function picking_an_existing_client_does_not_duplicate_the_master_row(): void
    {
        $client = ServiceClient::factory()->create(['name' => 'Klien Lama']);

        $this->actingAs($this->user('manager'))->post(
            route('service.invoice.store'),
            $this->payload(['service_client_id' => $client->id, 'client_name' => 'Klien Lama'])
        );

        $this->assertSame(1, ServiceClient::count());
        $this->assertSame($client->id, ServiceInvoice::first()->service_client_id);
    }

    /** @test */
    public function catalog_id_is_only_a_trace_the_name_and_price_are_copied(): void
    {
        $catalog = ServiceCatalog::factory()->create(['name' => 'Instalasi OJS Basic', 'price' => 500000]);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload([
            'items' => [[
                'service_catalog_id' => $catalog->id,
                'name'               => 'Instalasi OJS Basic (rumit)',
                'qty'                => 1,
                'unit_price'         => '850000',
            ]],
        ]));

        $item = ServiceInvoice::first()->items->first();
        $this->assertSame($catalog->id, $item->service_catalog_id);
        $this->assertSame('Instalasi OJS Basic (rumit)', $item->name);   // nama ditimpa operator
        $this->assertEquals(850000, $item->unit_price);                  // harga ditimpa operator
    }

    /** @test */
    public function at_least_one_item_is_required(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, ServiceInvoice::count());
    }

    /** @test */
    public function discount_cannot_exceed_subtotal(): void
    {
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.store'), $this->payload(['discount' => '99.000.000']))
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, ServiceInvoice::count());
    }

    /** @test */
    public function fractional_qty_survives_normalisation(): void
    {
        $this->actingAs($this->user('manager'))->post(route('service.invoice.store'), $this->payload([
            'items' => [['name' => 'Maintenance', 'qty' => '1.5', 'unit_price' => '300.000']],
        ]));

        $item = ServiceInvoice::first()->items->first();
        $this->assertEquals(1.5, $item->qty);        // BUKAN 15 — qty tidak boleh ikut dibuang titiknya
        $this->assertEquals(450000, $item->subtotal);
    }

    /** @test */
    public function index_and_create_render_for_manager(): void
    {
        ServiceInvoice::factory()->create();

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.index'))->assertOk();
        $this->actingAs($manager)->get(route('service.invoice.create'))->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceStoreTest`
Expected: FAIL — `Route [service.invoice.store] not defined.`

- [ ] **Step 3: Tulis `ServiceInvoiceForm`**

`app/Support/ServiceInvoiceForm.php`:

```php
<?php

namespace App\Support;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Aturan validasi + perakitan data form invoice layanan. Dipisah dari controller
 * supaya store() dan update() memakai definisi yang sama persis, dan controller-nya
 * tetap bisa dibaca sekali lihat.
 */
class ServiceInvoiceForm
{
    public static function rules(): array
    {
        return [
            'service_client_id'  => 'nullable|exists:tb_service_clients,id',
            'client_name'        => 'required|string|max:190',
            'client_institution' => 'nullable|string|max:190',
            'client_email'       => 'nullable|email|max:190',
            'client_phone'       => 'nullable|string|max:40',
            'client_address'     => 'nullable|string',
            'issued_at'          => 'required|date',
            'due_at'             => 'nullable|date|after_or_equal:issued_at',
            'discount'           => 'nullable|numeric|min:0|max:9999999999999.99',
            'note'               => 'nullable|string',
            'internal_note'      => 'nullable|string',

            'items'                      => 'required|array|min:1',
            'items.*.service_catalog_id' => 'nullable|exists:tb_service_catalogs,id',
            'items.*.name'               => 'required|string|max:190',
            'items.*.description'        => 'nullable|string',
            'items.*.qty'                => 'required|numeric|min:0.01|max:999999',
            'items.*.unit_price'         => 'required|numeric|min:0|max:9999999999999.99',
        ];
    }

    /**
     * Buang pemisah ribuan dari kolom UANG saja, sebelum validasi.
     *
     * `qty` SENGAJA tidak ikut: qty boleh pecahan ("1.5"), dan membuang titiknya
     * akan diam-diam mengubahnya jadi 15.
     *
     * Tanda minus dipertahankan supaya nominal negatif tetap DITOLAK aturan min:0,
     * bukan diam-diam dibalik jadi positif.
     */
    public static function normalize(Request $request): void
    {
        if ($request->filled('discount')) {
            $request->merge(['discount' => self::digits($request->input('discount'))]);
        }

        $items = $request->input('items', []);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $i => $row) {
            if (isset($row['unit_price'])) {
                $items[$i]['unit_price'] = self::digits($row['unit_price']);
            }
        }
        $request->merge(['items' => $items]);
    }

    private static function digits($value): string
    {
        return preg_replace('/[.,\s]/', '', (string) $value);
    }

    /** Diskon tidak boleh melebihi subtotal item yang dikirim. */
    public static function assertDiscount(array $data): void
    {
        $subtotal = 0.0;
        foreach ($data['items'] as $row) {
            $subtotal += round((float) $row['qty'] * (float) $row['unit_price'], 2);
        }

        if ((float) ($data['discount'] ?? 0) > $subtotal) {
            throw ValidationException::withMessages([
                'discount' => 'Diskon tidak boleh melebihi subtotal layanan (Rp '
                    . number_format($subtotal, 0, ',', '.') . ').',
            ]);
        }
    }

    /**
     * Klien dari master bila dipilih; kalau diketik manual, baris master baru dibuat
     * lalu dipakai. Tidak ada invoice tanpa induk klien kecuali klien itu dihapus kelak.
     */
    public static function resolveClient(array $data): ServiceClient
    {
        if (! empty($data['service_client_id'])) {
            return ServiceClient::findOrFail($data['service_client_id']);
        }

        return ServiceClient::create([
            'name'        => $data['client_name'],
            'institution' => $data['client_institution'] ?? null,
            'email'       => $data['client_email'] ?? null,
            'phone'       => $data['client_phone'] ?? null,
            'address'     => $data['client_address'] ?? null,
            'created_by'  => Auth::id(),
            'updated_by'  => Auth::id(),
        ]);
    }

    /** Salinan data klien untuk invoice — SNAPSHOT, bukan referensi. */
    public static function snapshotFrom(ServiceClient $client, array $data): array
    {
        return [
            'service_client_id'  => $client->id,
            'client_name'        => $data['client_name'],
            'client_institution' => $data['client_institution'] ?? null,
            'client_email'       => $data['client_email'] ?? null,
            'client_phone'       => $data['client_phone'] ?? null,
            'client_address'     => $data['client_address'] ?? null,
        ];
    }

    /** Buat ulang seluruh baris item dari input tervalidasi. */
    public static function syncItems(ServiceInvoice $invoice, array $data): void
    {
        $invoice->items()->delete();

        foreach (array_values($data['items']) as $position => $row) {
            $qty   = (float) $row['qty'];
            $price = (float) $row['unit_price'];

            $invoice->items()->create([
                'service_catalog_id' => $row['service_catalog_id'] ?? null,
                'name'               => $row['name'],
                'description'        => $row['description'] ?? null,
                'qty'                => $qty,
                'unit_price'         => $price,
                'subtotal'           => round($qty * $price, 2),
                'position'           => $position,
            ]);
        }
    }
}
```

- [ ] **Step 4: Tulis controller (index, create, store, show)**

`app/Http/Controllers/Pages/ServiceInvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Support\ServiceInvoiceForm;
use App\Support\ServiceInvoiceNumber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = ServiceInvoice::query()
            ->when($request->filled('work_status'),    fn ($q) => $q->where('work_status', $request->input('work_status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
            // `where`, bukan `whereDate`: issued_at SUDAH bertipe DATE, jadi
            // whereDate() cuma membungkusnya dengan date() dan membuat indeksnya
            // tak terpakai tanpa memberi apa pun.
            ->when($request->filled('from'),           fn ($q) => $q->where('issued_at', '>=', $request->input('from')))
            ->when($request->filled('to'),             fn ($q) => $q->where('issued_at', '<=', $request->input('to')))
            ->latest('issued_at')
            ->latest('id')
            ->get();

        return view('services.invoices.index', compact('invoices'));
    }

    public function create()
    {
        return view('services.invoices.form', [
            'invoice'  => null,
            'mode'     => 'create',
            'clients'  => ServiceClient::orderBy('name')->get(),
            'catalogs' => ServiceCatalog::active()->orderBy('category')->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request)
    {
        ServiceInvoiceForm::normalize($request);
        $data = $request->validate(ServiceInvoiceForm::rules());
        ServiceInvoiceForm::assertDiscount($data);

        $invoice = ServiceInvoiceNumber::retrying(fn () => DB::transaction(function () use ($data) {
            $issuedAt = Carbon::parse($data['issued_at']);
            $client   = ServiceInvoiceForm::resolveClient($data);

            $invoice = ServiceInvoice::create(
                ServiceInvoiceForm::snapshotFrom($client, $data) + [
                    'invoice_no'     => ServiceInvoiceNumber::next($issuedAt),
                    'issued_at'      => $issuedAt->toDateString(),
                    'due_at'         => $data['due_at'] ?? null,
                    'discount'       => $data['discount'] ?? 0,
                    'note'           => $data['note'] ?? null,
                    'internal_note'  => $data['internal_note'] ?? null,
                    'work_status'    => 'belum',
                    'payment_status' => 'belum',
                    'created_by'     => Auth::id(),
                    'updated_by'     => Auth::id(),
                ]
            );

            ServiceInvoiceForm::syncItems($invoice, $data);
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'created',
                'to_status'  => 'belum',
                'changed_by' => Auth::id(),
            ]);

            return $invoice;
        }));

        return redirect()->route('service.invoice.show', $invoice->id)
            ->with('success', 'Invoice layanan ' . $invoice->invoice_no . ' dibuat.');
    }

    public function show(int $id)
    {
        $invoice = ServiceInvoice::with(['items', 'payments', 'logs.changedBy', 'client'])->findOrFail($id);

        return view('services.invoices.show', compact('invoice'));
    }
}
```

- [ ] **Step 5: Daftarkan rute**

Tambahkan di dalam grup `Route::prefix('layanan')->name('service.')` di `routes/web.php`, **sebelum** blok Katalog (supaya urutannya terbaca dari yang paling sering dipakai):

```php
        // Invoice layanan
        Route::get ('invoice',        [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'index'])  ->name('invoice.index');
        Route::get ('invoice/create', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'create']) ->name('invoice.create');
        Route::post('invoice',        [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'store'])  ->name('invoice.store');
        Route::get ('invoice/{id}',   [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'show'])   ->name('invoice.show')->whereNumber('id');
```

- [ ] **Step 6: Petakan permission**

Tambahkan di `config/permissions.php` tepat **sebelum** blok `'service_catalog'`:

```php
        'service_invoice' => [
            'label'   => 'Invoice Layanan',
            'actions' => [
                'view'   => ['service.invoice.index', 'service.invoice.show'],
                'create' => ['service.invoice.create', 'service.invoice.store'],
            ],
        ],
```

> Aksi `edit`, `status`, `payment`, `export`, `send`, `cancel`, `delete` ditambahkan ke blok ini di Task 9–13, bersama rutenya masing-masing.

- [ ] **Step 7: Tambahkan item menu**

Di `resources/views/layouts/sidebar.blade.php`, di dalam grup **Layanan**, tepat **sebelum** blok `@can('service_catalog.view')`:

```blade
                @can('service_invoice.view')
                    <li class="nav-item {{ nav_active('service.invoice.*') }}">
                        <a href="{{ route('service.invoice.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="file-text"></i>
                            <span class="link-title">Invoice Layanan</span>
                        </a>
                    </li>
                @endcan
```

- [ ] **Step 7b: Pasang tombol Detail yang ditunda dari Task 7**

Sekarang `service.invoice.show` sudah ada, jadi tautan dari halaman klien aman dipasang. Di `resources/views/services/clients/show.blade.php`, ganti sel kosong beserta komentarnya:

```blade
                                <td></td>
```

dengan:

```blade
                                <td>
                                    @can('service_invoice.view')
                                        <a href="{{ route('service.invoice.show', $inv->id) }}" class="btn btn-xs btn-primary">Detail</a>
                                    @endcan
                                </td>
```

Tes `superadmin_can_open_a_client_detail_page` di `ServiceClientCrudTest` yang menjaga langkah ini: ia harus tetap hijau setelah tautannya dipasang. Kalau merah, rutenya belum benar-benar terdaftar.

- [ ] **Step 8: Tulis view daftar invoice**

`resources/views/services/invoices/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Invoice Layanan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $workColors = ['belum' => 'secondary', 'proses' => 'warning', 'selesai' => 'success', 'batal' => 'danger'];
    $payColors  = ['belum' => 'secondary', 'dp' => 'info', 'lunas' => 'success'];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Invoice Layanan</h6>
                    @can('service_invoice.create')
                        <a href="{{ route('service.invoice.create') }}" class="btn btn-sm btn-primary">+ Buat Invoice</a>
                    @endcan
                </div>

                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-2">
                        <select name="work_status" class="form-select form-select-sm">
                            <option value="">Semua Status Kerja</option>
                            @foreach(\App\Models\ServiceInvoice::WORK_STATUS as $key => $label)
                                <option value="{{ $key }}" {{ request('work_status') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="payment_status" class="form-select form-select-sm">
                            <option value="">Semua Status Bayar</option>
                            @foreach(\App\Models\ServiceInvoice::PAYMENT_STATUS as $key => $label)
                                <option value="{{ $key }}" {{ request('payment_status') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr>
                                <th>No Invoice</th><th>Klien</th><th>Total</th><th>Sisa</th>
                                <th>Kerja</th><th>Bayar</th><th>Jatuh Tempo</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $inv)
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td>
                                    {{ $inv->client_name }}
                                    @if($inv->client_institution)
                                        <br><small class="text-muted">{{ $inv->client_institution }}</small>
                                    @endif
                                </td>
                                <td>Rp {{ number_format($inv->total, 0, ',', '.') }}</td>
                                <td>
                                    @if($inv->isOverpaid())
                                        <span class="text-info">Lebih Rp {{ number_format($inv->overpaidAmount(), 0, ',', '.') }}</span>
                                    @else
                                        Rp {{ number_format($inv->remaining, 0, ',', '.') }}
                                    @endif
                                </td>
                                <td><span class="badge bg-{{ $workColors[$inv->work_status] ?? 'secondary' }}">{{ $inv->workStatusLabel() }}</span></td>
                                <td><span class="badge bg-{{ $payColors[$inv->payment_status] ?? 'secondary' }}">{{ $inv->paymentStatusLabel() }}</span></td>
                                <td>
                                    <small class="{{ $inv->isOverdue() ? 'text-danger fw-bold' : '' }}">
                                        {{ $inv->due_at ? $inv->due_at->format('d/m/Y') : '-' }}
                                    </small>
                                </td>
                                <td><a href="{{ route('service.invoice.show', $inv->id) }}" class="btn btn-xs btn-primary">Detail</a></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function () {
        $(".datatable").DataTable({
            pageLength: 25, responsive: true,
            columnDefs: [{ orderable: false, targets: 7 }],
            language: { emptyTable: "Belum ada invoice layanan." }
        });
    });
</script>
@endpush
```

- [ ] **Step 9: Tulis view form (dipakai create & edit)**

`resources/views/services/invoices/form.blade.php`:

```blade
@extends('layouts.master')
@section('title', ($mode === 'create' ? 'Buat' : 'Edit') . ' Invoice Layanan - SiMAPA')

@section('content')
<form method="POST"
      action="{{ $mode === 'create' ? route('service.invoice.store') : route('service.invoice.update', $invoice->id) }}">
    @csrf
    @if($mode === 'edit') @method('PUT') @endif

    <div class="row">
        <div class="col-md-5 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Data Klien</h6>

                    <div class="mb-2">
                        <label class="form-label">Pilih Klien Terdaftar</label>
                        <select id="clientPicker" class="form-select" onchange="applyClient()">
                            <option value="">— Klien baru (isi manual di bawah) —</option>
                            @foreach($clients as $c)
                                <option value="{{ $c->id }}"
                                    data-client="{{ $c->toJson() }}"
                                    {{ old('service_client_id', $invoice->service_client_id ?? '') == $c->id ? 'selected' : '' }}>
                                    {{ $c->displayName() }}
                                </option>
                            @endforeach
                        </select>
                        <input type="hidden" name="service_client_id" id="serviceClientId"
                               value="{{ old('service_client_id', $invoice->service_client_id ?? '') }}">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" id="clientName" class="form-control @error('client_name') is-invalid @enderror"
                               value="{{ old('client_name', $invoice->client_name ?? '') }}" required maxlength="190">
                        @error('client_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Instansi</label>
                        <input type="text" name="client_institution" id="clientInstitution" class="form-control"
                               value="{{ old('client_institution', $invoice->client_institution ?? '') }}" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email <small class="text-muted">(wajib bila mau dikirim email)</small></label>
                        <input type="email" name="client_email" id="clientEmail" class="form-control"
                               value="{{ old('client_email', $invoice->client_email ?? '') }}" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="client_phone" id="clientPhone" class="form-control"
                               value="{{ old('client_phone', $invoice->client_phone ?? '') }}" maxlength="40">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Alamat</label>
                        <textarea name="client_address" id="clientAddress" class="form-control" rows="2">{{ old('client_address', $invoice->client_address ?? '') }}</textarea>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control @error('issued_at') is-invalid @enderror"
                                   value="{{ old('issued_at', isset($invoice) ? $invoice->issued_at?->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                            @error('issued_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Jatuh Tempo</label>
                            <input type="date" name="due_at" class="form-control @error('due_at') is-invalid @enderror"
                                   value="{{ old('due_at', isset($invoice) ? $invoice->due_at?->format('Y-m-d') : now()->addDays(14)->format('Y-m-d')) }}">
                            @error('due_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Catatan <small class="text-muted">(tercetak di PDF)</small></label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note', $invoice->note ?? '') }}</textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan Internal <small class="text-muted">(tidak tercetak)</small></label>
                        <textarea name="internal_note" class="form-control" rows="2">{{ old('internal_note', $invoice->internal_note ?? '') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-7 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Rincian Layanan</h6>

                    @error('items') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                    <div class="mb-2">
                        <label class="form-label">Ambil dari Katalog</label>
                        <select id="catalogPicker" class="form-select" onchange="addFromCatalog()">
                            <option value="">— Pilih layanan —</option>
                            @foreach($catalogs->groupBy('category') as $category => $rows)
                                <optgroup label="{{ \App\Models\ServiceCatalog::categoryLabel($category) }}">
                                    @foreach($rows as $cat)
                                        <option value="{{ $cat->id }}"
                                                data-name="{{ $cat->name }}"
                                                data-price="{{ (int) $cat->price }}">
                                            {{ $cat->name }} — {{ $cat->priceLabel() }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <small class="text-muted">Harga terisi otomatis dan tetap bisa diubah sesuai kompleksitas.</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:40%">Layanan</th><th style="width:12%">Qty</th>
                                    <th style="width:22%">Harga</th><th style="width:20%">Subtotal</th><th></th>
                                </tr>
                            </thead>
                            <tbody id="itemRows"></tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addRow()">+ Tambah Baris</button>

                    <hr>
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Diskon (Rp)</label>
                            <input type="text" name="discount" id="discount" class="form-control @error('discount') is-invalid @enderror"
                                   value="{{ old('discount', isset($invoice) ? (int) $invoice->discount : 0) }}" oninput="recalc()">
                            @error('discount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 text-end">
                            <div class="text-muted small">Subtotal</div>
                            <div class="h6" id="previewSubtotal">Rp 0</div>
                            <div class="text-muted small">Total</div>
                            <div class="h5 text-primary" id="previewTotal">Rp 0</div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <a href="{{ $mode === 'create' ? route('service.invoice.index') : route('service.invoice.show', $invoice->id) }}"
                           class="btn btn-outline-secondary">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('custom-scripts')
<script>
    // Angka di layar ini hanya PRATINJAU. Nilai yang disimpan selalu dihitung ulang
    // di server dari qty & unit_price mentah (ServiceInvoiceForm::syncItems).
    let rowIndex = 0;

    const existingItems = @json(
        old('items', isset($invoice)
            ? $invoice->items->map(fn ($i) => [
                'service_catalog_id' => $i->service_catalog_id,
                'name'               => $i->name,
                'qty'                => (float) $i->qty,
                'unit_price'         => (int) $i->unit_price,
              ])->values()
            : [])
    );

    function rupiah(n) {
        return 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');
    }

    function digits(v) {
        return parseFloat(String(v).replace(/[.,\s]/g, '')) || 0;
    }

    function addRow(item = {}) {
        const i = rowIndex++;
        const html = `
            <tr id="row-${i}">
                <td>
                    <input type="hidden" name="items[${i}][service_catalog_id]" value="${item.service_catalog_id ?? ''}">
                    <input type="text" name="items[${i}][name]" class="form-control form-control-sm"
                           value="${item.name ?? ''}" required maxlength="190">
                </td>
                <td><input type="number" step="0.01" min="0.01" name="items[${i}][qty]"
                           class="form-control form-control-sm qty" value="${item.qty ?? 1}" required oninput="recalc()"></td>
                <td><input type="text" name="items[${i}][unit_price]"
                           class="form-control form-control-sm price" value="${item.unit_price ?? 0}" required oninput="recalc()"></td>
                <td class="subtotal text-end">Rp 0</td>
                <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="removeRow(${i})">×</button></td>
            </tr>`;
        document.getElementById('itemRows').insertAdjacentHTML('beforeend', html);
        recalc();
    }

    function removeRow(i) {
        document.getElementById('row-' + i)?.remove();
        recalc();
    }

    function addFromCatalog() {
        const picker = document.getElementById('catalogPicker');
        const opt = picker.selectedOptions[0];
        if (!opt || !opt.value) return;

        addRow({ service_catalog_id: opt.value, name: opt.dataset.name, qty: 1, unit_price: opt.dataset.price });
        picker.value = '';
    }

    function applyClient() {
        const opt = document.getElementById('clientPicker').selectedOptions[0];
        document.getElementById('serviceClientId').value = opt.value || '';
        if (!opt.value) return;

        const c = JSON.parse(opt.dataset.client);
        document.getElementById('clientName').value = c.name ?? '';
        document.getElementById('clientInstitution').value = c.institution ?? '';
        document.getElementById('clientEmail').value = c.email ?? '';
        document.getElementById('clientPhone').value = c.phone ?? '';
        document.getElementById('clientAddress').value = c.address ?? '';
    }

    function recalc() {
        let subtotal = 0;
        document.querySelectorAll('#itemRows tr').forEach(tr => {
            const qty   = parseFloat(tr.querySelector('.qty').value) || 0;
            const price = digits(tr.querySelector('.price').value);
            const line  = qty * price;
            subtotal += line;
            tr.querySelector('.subtotal').textContent = rupiah(line);
        });

        const discount = digits(document.getElementById('discount').value);
        document.getElementById('previewSubtotal').textContent = rupiah(subtotal);
        document.getElementById('previewTotal').textContent = rupiah(Math.max(subtotal - discount, 0));
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (existingItems.length) {
            existingItems.forEach(addRow);
        } else {
            addRow();
        }
    });
</script>
@endpush
```

- [ ] **Step 10: Tulis view detail (kerangka; tumbuh di Task 9–13)**

`resources/views/services/invoices/show.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Invoice ' . $invoice->invoice_no . ' - SiMAPA')

@section('content')
@php
    $workColors = ['belum' => 'secondary', 'proses' => 'warning', 'selesai' => 'success', 'batal' => 'danger'];
    $payColors  = ['belum' => 'secondary', 'dp' => 'info', 'lunas' => 'success'];
@endphp
<div class="row">
    <div class="col-md-8 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="mb-1">{{ $invoice->invoice_no }}</h5>
                        <span class="badge bg-{{ $workColors[$invoice->work_status] ?? 'secondary' }}">{{ $invoice->workStatusLabel() }}</span>
                        <span class="badge bg-{{ $payColors[$invoice->payment_status] ?? 'secondary' }}">{{ $invoice->paymentStatusLabel() }}</span>
                    </div>
                    <a href="{{ route('service.invoice.index') }}" class="btn btn-sm btn-outline-secondary">← Daftar</a>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <h6>Kepada</h6>
                        <p class="mb-0">
                            {{ $invoice->client_name }}<br>
                            {{ $invoice->client_institution ?? '-' }}<br>
                            {{ $invoice->client_email ?? '-' }}<br>
                            {{ $invoice->client_phone ?? '-' }}
                        </p>
                    </div>
                    <div class="col-6 text-end">
                        <h6>Tanggal</h6>
                        <p class="mb-0">
                            Terbit: {{ $invoice->issued_at?->format('d M Y') }}<br>
                            Jatuh tempo:
                            <span class="{{ $invoice->isOverdue() ? 'text-danger fw-bold' : '' }}">
                                {{ $invoice->due_at?->format('d M Y') ?? '-' }}
                            </span>
                        </p>
                    </div>
                </div>

                <h6>Rincian Layanan</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>#</th><th>Layanan</th><th class="text-end">Qty</th>
                                <th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $i => $item)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $item->name }}
                                    @if($item->description)<br><small class="text-muted">{{ $item->description }}</small>@endif
                                </td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                                <td class="text-end">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end">Subtotal</td>
                                <td class="text-end">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
                            @if((float) $invoice->discount > 0)
                            <tr><td colspan="4" class="text-end">Diskon</td>
                                <td class="text-end">− Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td></tr>
                            @endif
                            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td>
                                <td class="text-end">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td></tr>
                            <tr><td colspan="4" class="text-end">Terbayar</td>
                                <td class="text-end">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td></tr>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Tagihan' }}</td>
                                <td class="text-end {{ $invoice->isOverpaid() ? 'text-info' : ((float) $invoice->remaining > 0 ? 'text-danger' : 'text-success') }}">
                                    Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if($invoice->note)
                    <div class="alert alert-light border mt-2 mb-0"><strong>Catatan:</strong> {{ $invoice->note }}</div>
                @endif
                @if($invoice->internal_note)
                    <div class="alert alert-warning py-2 mt-2 mb-0">
                        <strong>Catatan internal</strong> (tidak tercetak): {{ $invoice->internal_note }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Riwayat</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($invoice->logs as $log)
                    <li class="mb-2 pb-2 border-bottom">
                        <div><strong>{{ $log->eventLabel() }}</strong></div>
                        @if($log->from_status || $log->to_status)
                            <div><small class="text-muted">{{ $log->from_status ?? '—' }} → {{ $log->to_status }}</small></div>
                        @endif
                        @if($log->note)<div><small>{{ $log->note }}</small></div>@endif
                        <small class="text-muted">
                            {{ $log->created_at->format('d/m/Y H:i') }} · {{ $log->changedBy->name ?? 'Sistem' }}
                        </small>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 11: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceStoreTest`
Expected: PASS (8 tests)

- [ ] **Step 12: Commit**

```bash
git add app/Support/ServiceInvoiceForm.php \
        app/Http/Controllers/Pages/ServiceInvoiceController.php \
        resources/views/services/invoices/index.blade.php \
        resources/views/services/invoices/form.blade.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php resources/views/layouts/sidebar.blade.php \
        tests/Feature/ServiceInvoiceStoreTest.php
git commit -m "layanan: daftar, form, dan pembuatan invoice layanan"
```

---

### Task 9: Ubah invoice + aturan kunci edit + hapus

Menutup T-EDIT-1..2.

**Files:**
- Modify: `app/Models/ServiceInvoice.php` (tambah `isEditable()`)
- Modify: `app/Http/Controllers/Pages/ServiceInvoiceController.php` (tambah `edit`, `update`, `destroy`)
- Modify: `resources/views/services/invoices/show.blade.php` (tombol Edit & Hapus)
- Modify: `resources/views/services/invoices/form.blade.php` (kolom alasan koreksi)
- Modify: `routes/web.php`, `config/permissions.php`, `database/seeders/AccessMatrixSeeder.php`
- Test: `tests/Feature/ServiceInvoiceEditLockTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceEditLockTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceEditLockTest extends TestCase
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

    private function invoice(): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create();
        $inv->items()->create(['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => 750000, 'subtotal' => 750000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'client_name' => 'Klien Baru',
            'issued_at'   => '2026-08-11',
            'items'       => [['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => '900000']],
        ], $override);
    }

    /** @test */
    public function fresh_invoice_is_editable_by_manager(): void
    {
        $inv = $this->invoice();
        $this->assertTrue($inv->isEditable());

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.edit', $inv->id))->assertOk();

        $this->actingAs($manager)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        $this->assertEquals(900000, $inv->total);
        $this->assertSame('updated', $inv->logs->first()->event);
    }

    /** @test */
    public function invoice_with_a_payment_is_locked_for_manager(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();

        $this->assertFalse($inv->fresh()->isEditable());

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.edit', $inv->id))
            ->assertRedirect(route('service.invoice.show', $inv->id));
        $this->actingAs($manager)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertRedirect(route('service.invoice.show', $inv->id));

        $this->assertEquals(750000, $inv->fresh()->total);   // tidak berubah
    }

    /** @test */
    public function sent_invoice_is_locked_even_without_payments(): void
    {
        $inv = $this->invoice();
        $inv->update(['sent_at' => now(), 'sent_count' => 1]);

        $this->assertFalse($inv->fresh()->isEditable());
    }

    /** @test */
    public function superadmin_can_correct_a_locked_invoice_but_must_give_a_reason(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();

        $superadmin = $this->user('superadmin');

        // Tanpa alasan: ditolak.
        $this->actingAs($superadmin)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertSessionHasErrors('correction_reason');
        $this->assertEquals(750000, $inv->fresh()->total);

        // Dengan alasan: lolos, dan alasannya tercatat.
        $this->actingAs($superadmin)->put(
            route('service.invoice.update', $inv->id),
            $this->payload(['correction_reason' => 'salah input harga, disepakati ulang'])
        )->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        $this->assertEquals(900000, $inv->total);
        $this->assertStringContainsString('salah input harga', $inv->logs->first()->note);
    }

    /** @test */
    public function only_superadmin_can_delete_and_deletion_is_soft(): void
    {
        $inv = $this->invoice();

        // EnforcePermission menolak submit non-GET dengan redirect + flash error,
        // BUKAN 403 mentah (EnforcePermission::deny). Jadi yang diperiksa efeknya:
        // invoice-nya harus tetap ada.
        $this->actingAs($this->user('manager'))
            ->delete(route('service.invoice.destroy', $inv->id))
            ->assertRedirect();
        $this->assertNotSoftDeleted('tb_service_invoices', ['id' => $inv->id]);

        $this->actingAs($this->user('superadmin'))
            ->delete(route('service.invoice.destroy', $inv->id))
            ->assertRedirect(route('service.invoice.index'));

        $this->assertSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceEditLockTest`
Expected: FAIL — `Call to undefined method App\Models\ServiceInvoice::isEditable()`

- [ ] **Step 3: Tambahkan `isEditable()` ke `ServiceInvoice`**

Sisipkan tepat setelah `isCancelled()` di `app/Models/ServiceInvoice.php`:

```php
    /**
     * Boleh diedit bebas selama nomornya belum "beredar": belum ada pembayaran,
     * belum pernah dikirim email, dan belum dibatalkan. Setelah itu hanya superadmin,
     * wajib beralasan — mengubah nominal invoice yang sudah dipegang klien secara
     * diam-diam membuat dokumen di tangan mereka berbeda dari yang ada di sistem.
     */
    public function isEditable(): bool
    {
        return $this->sent_at === null
            && ! $this->isCancelled()
            && $this->payments()->doesntExist();
    }

```

- [ ] **Step 4: Tambahkan `edit`, `update`, `destroy` ke controller**

Sisipkan setelah `show()` di `app/Http/Controllers/Pages/ServiceInvoiceController.php`:

```php
    public function edit(int $id)
    {
        $invoice = ServiceInvoice::with('items')->findOrFail($id);

        if (! $invoice->isEditable() && ! Auth::user()->hasRole('superadmin')) {
            return redirect()->route('service.invoice.show', $invoice->id)
                ->with('error', 'Invoice ini sudah dibayar atau sudah dikirim — hanya superadmin yang bisa mengoreksinya.');
        }

        return view('services.invoices.form', [
            'invoice'  => $invoice,
            'mode'     => 'edit',
            'clients'  => ServiceClient::orderBy('name')->get(),
            'catalogs' => ServiceCatalog::active()->orderBy('category')->orderBy('position')->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $locked  = ! $invoice->isEditable();

        if ($locked && ! Auth::user()->hasRole('superadmin')) {
            return redirect()->route('service.invoice.show', $invoice->id)
                ->with('error', 'Invoice ini sudah dibayar atau sudah dikirim — hanya superadmin yang bisa mengoreksinya.');
        }

        ServiceInvoiceForm::normalize($request);

        $rules = ServiceInvoiceForm::rules();
        if ($locked) {
            // Koreksi atas dokumen yang sudah beredar wajib punya jejak alasan.
            $rules['correction_reason'] = 'required|string';
        }
        $data = $request->validate($rules);
        ServiceInvoiceForm::assertDiscount($data);

        DB::transaction(function () use ($invoice, $data, $locked) {
            $client = ServiceInvoiceForm::resolveClient($data);

            // invoice_no SENGAJA tidak ikut — nomor tidak pernah bisa diedit lewat form.
            $invoice->update(
                ServiceInvoiceForm::snapshotFrom($client, $data) + [
                    'issued_at'     => $data['issued_at'],
                    'due_at'        => $data['due_at'] ?? null,
                    'discount'      => $data['discount'] ?? 0,
                    'note'          => $data['note'] ?? null,
                    'internal_note' => $data['internal_note'] ?? null,
                    'updated_by'    => Auth::id(),
                ]
            );

            ServiceInvoiceForm::syncItems($invoice, $data);
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'updated',
                'note'       => $locked ? 'Koreksi superadmin: ' . $data['correction_reason'] : null,
                'changed_by' => Auth::id(),
            ]);
        });

        return redirect()->route('service.invoice.show', $invoice->id)->with('success', 'Invoice diperbarui.');
    }

    public function destroy(int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $no      = $invoice->invoice_no;

        // Soft delete: nomornya tetap terpakai selamanya (ServiceInvoiceNumber::next
        // memakai withTrashed), jadi tidak ada nomor invoice yang didaur ulang.
        $invoice->delete();

        return redirect()->route('service.invoice.index')->with('warning', 'Invoice ' . $no . ' dihapus.');
    }
```

- [ ] **Step 5: Daftarkan rute**

Tambahkan di grup `service.` di `routes/web.php`, setelah baris `invoice.show`:

```php
        Route::get   ('invoice/{id}/edit', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'edit'])   ->name('invoice.edit')->whereNumber('id');
        Route::put   ('invoice/{id}',      [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'update']) ->name('invoice.update')->whereNumber('id');
        Route::delete('invoice/{id}',      [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'destroy'])->name('invoice.destroy')->whereNumber('id');
```

- [ ] **Step 6: Tambahkan aksi permission**

Di `config/permissions.php`, tambahkan ke `actions` milik `'service_invoice'`:

```php
                'edit'   => ['service.invoice.edit', 'service.invoice.update'],
                'delete' => ['service.invoice.destroy'],
```

Di `database/seeders/AccessMatrixSeeder.php`, tambahkan ke array `$superadminOnly`:

```php
        // Invoice Layanan: hapus & batal hanya superadmin (spec §6.3).
        'service_invoice.delete',
```

- [ ] **Step 7: Tambahkan kolom alasan koreksi di form**

Di `resources/views/services/invoices/form.blade.php`, sisipkan tepat sebelum blok tombol `<div class="mt-3 d-flex gap-2">`:

```blade
                    @if($mode === 'edit' && ! $invoice->isEditable())
                        <div class="alert alert-warning py-2 mt-3">
                            Invoice ini sudah dibayar atau sudah dikirim ke klien. Koreksi tetap bisa dilakukan,
                            tapi wajib beralasan dan akan tercatat di riwayat.
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Alasan Koreksi <span class="text-danger">*</span></label>
                            <textarea name="correction_reason" rows="2" required
                                      class="form-control @error('correction_reason') is-invalid @enderror">{{ old('correction_reason') }}</textarea>
                            @error('correction_reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif

```

- [ ] **Step 8: Tambahkan tombol Edit & Hapus di detail**

Di `resources/views/services/invoices/show.blade.php`, ganti baris tombol kembali:

```blade
                    <a href="{{ route('service.invoice.index') }}" class="btn btn-sm btn-outline-secondary">← Daftar</a>
```

dengan:

```blade
                    <div class="d-flex gap-1">
                        @can('service_invoice.edit')
                            <a href="{{ route('service.invoice.edit', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endcan
                        @can('service_invoice.delete')
                            <form action="{{ route('service.invoice.destroy', $invoice->id) }}" method="POST"
                                  onsubmit="return confirm('Hapus invoice ini? Nomornya tidak akan dipakai ulang.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        @endcan
                        <a href="{{ route('service.invoice.index') }}" class="btn btn-sm btn-outline-secondary">← Daftar</a>
                    </div>
```

- [ ] **Step 9: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceEditLockTest`
Expected: PASS (5 tests)

- [ ] **Step 10: Commit**

```bash
git add app/Models/ServiceInvoice.php \
        app/Http/Controllers/Pages/ServiceInvoiceController.php \
        resources/views/services/invoices/form.blade.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php \
        tests/Feature/ServiceInvoiceEditLockTest.php
git commit -m "layanan: ubah & hapus invoice dengan kunci edit setelah terbayar/terkirim"
```

---

### Task 10: Ubah status pengerjaan + pembatalan

Menutup T-WS-4.

**Files:**
- Modify: `app/Services/ServiceInvoiceWorkflow.php` (tambah `cancel`)
- Modify: `app/Http/Controllers/Pages/ServiceInvoiceController.php` (tambah `status`, `cancel`)
- Modify: `resources/views/services/invoices/show.blade.php` (panel status)
- Modify: `routes/web.php`, `config/permissions.php`, `database/seeders/AccessMatrixSeeder.php`
- Test: `tests/Feature/ServiceInvoiceStatusRouteTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceStatusRouteTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceStatusRouteTest extends TestCase
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

    /** @test */
    public function manager_can_move_the_work_status(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.status', $inv->id), [
            'work_status' => 'proses', 'note' => 'mulai instalasi',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('proses', $inv->work_status);
        $this->assertNotNull($inv->work_started_at);
        $this->assertSame('mulai instalasi', $inv->logs->first()->note);
    }

    /** @test */
    public function batal_cannot_be_reached_through_the_status_route(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $this->actingAs($this->user('superadmin'))
            ->post(route('service.invoice.status', $inv->id), ['work_status' => 'batal'])
            ->assertSessionHasErrors('work_status');

        $this->assertSame('proses', $inv->fresh()->work_status);
    }

    /** @test */
    public function only_superadmin_can_cancel_and_a_reason_is_required(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        // Penolakan non-GET = redirect + flash (EnforcePermission::deny), bukan 403.
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();
        $this->assertSame('proses', $inv->fresh()->work_status);

        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->post(route('service.invoice.cancel', $inv->id), [])
            ->assertSessionHasErrors('cancel_reason');

        $this->actingAs($superadmin)
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();

        $inv->refresh();
        $this->assertSame('batal', $inv->work_status);
        $this->assertSame('klien mundur', $inv->cancel_reason);
        $this->assertSame($superadmin->id, $inv->cancelled_by);
        $this->assertNotNull($inv->cancelled_at);
        $this->assertSame('cancelled', $inv->logs->first()->event);
    }

    /** @test */
    public function cancelled_invoice_refuses_further_status_changes(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'batal']);

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.status', $inv->id), ['work_status' => 'proses'])
            ->assertRedirect();

        $this->assertSame('batal', $inv->fresh()->work_status);
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceStatusRouteTest`
Expected: FAIL — `Route [service.invoice.status] not defined.`

- [ ] **Step 3a: Tambahkan `cancel()` ke `ServiceInvoiceWorkflow`**

Sisipkan setelah `changeStatus()` di `app/Services/ServiceInvoiceWorkflow.php`:

```php
    /**
     * Batalkan invoice. Keadaan terminal: hanya bisa dimasuki, wajib beralasan.
     * Gerbang "siapa boleh" ada di permission rute (superadmin), bukan di sini.
     *
     * @return bool false bila invoice sudah dibatalkan sebelumnya.
     */
    public function cancel(ServiceInvoice $invoice, string $reason, ?int $userId): bool
    {
        if ($invoice->isCancelled()) {
            return false;
        }

        $from = $invoice->work_status;

        DB::transaction(function () use ($invoice, $reason, $userId, $from) {
            $invoice->update([
                'work_status'   => 'batal',
                'cancel_reason' => $reason,
                'cancelled_by'  => $userId,
                'cancelled_at'  => now(),
            ]);

            $invoice->logs()->create([
                'event'       => 'cancelled',
                'from_status' => $from,
                'to_status'   => 'batal',
                'note'        => $reason,
                'changed_by'  => $userId,
            ]);
        });

        return true;
    }
```

- [ ] **Step 3b: Tambahkan `status` & `cancel` ke controller**

Sisipkan setelah `destroy()` di `app/Http/Controllers/Pages/ServiceInvoiceController.php`. Controller-nya tipis: memvalidasi, menyerahkan ke workflow, lalu menerjemahkan hasilnya jadi pesan.

```php
    public function status(Request $request, int $id, ServiceInvoiceWorkflow $workflow)
    {
        $invoice = ServiceInvoice::findOrFail($id);

        // 'batal' SENGAJA tidak ada di daftar ini: pembatalan butuh alasan dan
        // pelaku superadmin, jalurnya cancel().
        $data = $request->validate([
            'work_status' => 'required|in:belum,proses,selesai',
            'note'        => 'nullable|string',
        ]);

        if ($invoice->isCancelled()) {
            return back()->with('error', 'Invoice yang dibatalkan tidak bisa diubah statusnya.');
        }

        $workflow->changeStatus($invoice, $data['work_status'], $data['note'] ?? null, Auth::id());

        return back()->with('success', 'Status pengerjaan diperbarui.');
    }

    public function cancel(Request $request, int $id, ServiceInvoiceWorkflow $workflow)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $data    = $request->validate(['cancel_reason' => 'required|string']);

        if (! $workflow->cancel($invoice, $data['cancel_reason'], Auth::id())) {
            return back()->with('error', 'Invoice ini sudah dibatalkan.');
        }

        return back()->with('warning', 'Invoice dibatalkan.');
    }
```

Tambahkan `use` di bagian atas controller:

```php
use App\Services\ServiceInvoiceWorkflow;
```

- [ ] **Step 4: Daftarkan rute**

Tambahkan di grup `service.` di `routes/web.php`, setelah baris `invoice.destroy`:

```php
        Route::post('invoice/{id}/status', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'status'])->name('invoice.status')->whereNumber('id');
        Route::post('invoice/{id}/cancel', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'cancel'])->name('invoice.cancel')->whereNumber('id');
```

- [ ] **Step 5: Tambahkan aksi permission**

Di `config/permissions.php`, tambahkan ke `actions` milik `'service_invoice'`:

```php
                'status' => ['service.invoice.status'],
                'cancel' => ['service.invoice.cancel'],
```

Di `database/seeders/AccessMatrixSeeder.php`, tambahkan ke `$superadminOnly` tepat di bawah `'service_invoice.delete'`:

```php
        'service_invoice.cancel',
```

- [ ] **Step 6: Tambahkan panel status di detail**

Di `resources/views/services/invoices/show.blade.php`, sisipkan blok berikut di kolom kanan, **sebelum** card "Riwayat":

```blade
        @can('service_invoice.status')
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Status Pengerjaan</h6>

                @if($invoice->isCancelled())
                    <div class="alert alert-danger py-2 mb-0">
                        Dibatalkan {{ $invoice->cancelled_at?->format('d/m/Y H:i') }}
                        oleh {{ $invoice->canceller->name ?? '-' }}.<br>
                        <small>{{ $invoice->cancel_reason }}</small>
                    </div>
                @else
                    <form method="POST" action="{{ route('service.invoice.status', $invoice->id) }}">
                        @csrf
                        <div class="mb-2">
                            <select name="work_status" class="form-select form-select-sm">
                                @foreach(['belum', 'proses', 'selesai'] as $key)
                                    <option value="{{ $key }}" {{ $invoice->work_status === $key ? 'selected' : '' }}>
                                        {{ \App\Models\ServiceInvoice::WORK_STATUS[$key] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)">
                        </div>
                        <button class="btn btn-sm btn-primary w-100">Perbarui Status</button>
                    </form>

                    <ul class="list-unstyled small text-muted mt-2 mb-0">
                        <li>Mulai: {{ $invoice->work_started_at?->format('d/m/Y H:i') ?? '—' }}</li>
                        <li>Selesai: {{ $invoice->work_finished_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    </ul>

                    @can('service_invoice.cancel')
                        <hr>
                        <form method="POST" action="{{ route('service.invoice.cancel', $invoice->id) }}"
                              onsubmit="return confirm('Batalkan invoice ini? Tindakan ini tidak bisa dibalik.')">
                            @csrf
                            <input type="text" name="cancel_reason" class="form-control form-control-sm mb-2"
                                   placeholder="Alasan pembatalan" required>
                            <button class="btn btn-sm btn-outline-danger w-100">Batalkan Invoice</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
        @endcan

```

> Blok ini berada di dalam `<div class="col-md-4 grid-margin stretch-card">`. Karena kolom itu kini berisi dua card, ubah kelasnya menjadi `<div class="col-md-4">` supaya kedua card tidak dipaksa setinggi satu baris flex.

- [ ] **Step 7: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceStatusRouteTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Services/ServiceInvoiceWorkflow.php \
        app/Http/Controllers/Pages/ServiceInvoiceController.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php \
        tests/Feature/ServiceInvoiceStatusRouteTest.php
git commit -m "layanan: ubah status pengerjaan + pembatalan superadmin beralasan"
```

---

### Task 11: Pencatatan pembayaran (DP → pelunasan)

**Files:**
- Create: `app/Http/Controllers/Pages/ServiceInvoicePaymentController.php`
- Modify: `resources/views/services/invoices/show.blade.php` (panel pembayaran)
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/ServiceInvoicePaymentTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoicePaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoicePaymentTest extends TestCase
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

    private function invoice(): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create();
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function recording_a_dp_then_a_pelunasan_walks_the_status_to_lunas(): void
    {
        $inv     = $this->invoice();
        $manager = $this->user('manager');

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '1.000.000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertEquals(1000000, $inv->paid_total);      // pemisah ribuan dibuang
        $this->assertEquals(1500000, $inv->remaining);
        $this->assertSame('dp', $inv->payment_status);
        $this->assertSame('payment_added', $inv->logs->first()->event);

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-25', 'type' => 'pelunasan', 'amount' => '1500000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(0, $inv->remaining);
        $this->assertCount(2, $inv->payments);
    }

    /** @test */
    public function deleting_a_payment_recalculates_and_leaves_a_trace(): void
    {
        $inv = $this->invoice();
        $pay = $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();

        $this->actingAs($this->user('manager'))
            ->delete(route('service.invoice.payment.destroy', [$inv->id, $pay->id]))
            ->assertRedirect();

        $inv->refresh();
        $this->assertEquals(0, $inv->paid_total);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertSoftDeleted('tb_service_invoice_payments', ['id' => $pay->id]);
        $this->assertSame('payment_deleted', $inv->logs->first()->event);
    }

    /** @test */
    public function overpayment_is_accepted_and_flagged(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'pelunasan', 'amount' => '2550000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertTrue($inv->isOverpaid());
        $this->assertEquals(50000, $inv->overpaidAmount());
    }

    /** @test */
    public function cancelled_invoice_refuses_new_payments(): void
    {
        $inv = $this->invoice();
        $inv->update(['work_status' => 'batal']);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '500000', 'method' => 'transfer',
        ])->assertRedirect();

        $this->assertCount(0, $inv->fresh()->payments);
    }

    /** @test */
    public function zero_and_negative_amounts_are_rejected(): void
    {
        $inv = $this->invoice();

        foreach (['0', '-500000'] as $amount) {
            $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
                'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => $amount, 'method' => 'transfer',
            ])->assertSessionHasErrors('amount');
        }

        $this->assertCount(0, $inv->fresh()->payments);
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoicePaymentTest`
Expected: FAIL — `Route [service.invoice.payment.store] not defined.`

- [ ] **Step 3: Tulis controller pembayaran**

`app/Http/Controllers/Pages/ServiceInvoicePaymentController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran invoice layanan. TIDAK ada kaitannya dengan tb_payments, approval,
 * atau Jurnal Kas — modul ini sengaja berdiri sendiri (spec §2, dikunci T-ISO).
 */
class ServiceInvoicePaymentController extends Controller
{
    public function store(Request $request, int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);

        if ($invoice->isCancelled()) {
            return back()->with('error', 'Invoice yang dibatalkan tidak bisa menerima pembayaran.');
        }

        // Buang pemisah ribuan; minus dipertahankan supaya min:1 tetap menolaknya.
        $request->merge(['amount' => preg_replace('/[.,\s]/', '', (string) $request->input('amount'))]);

        $data = $request->validate([
            'paid_at'   => 'required|date',
            'type'      => 'required|in:' . implode(',', array_keys(ServiceInvoicePayment::TYPES)),
            'amount'    => 'required|numeric|min:1|max:9999999999999.99',
            'method'    => 'required|in:' . implode(',', array_keys(ServiceInvoicePayment::METHODS)),
            'reference' => 'nullable|string|max:190',
            'note'      => 'nullable|string',
        ]);

        DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create($data + ['created_by' => Auth::id()]);

            // Lebih bayar TIDAK diblokir: remaining boleh negatif dan ditampilkan
            // sebagai "Lebih Bayar". Memblokirnya cuma memaksa operator memalsukan angka.
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'payment_added',
                'to_status'  => $invoice->payment_status,
                'note'       => $payment->typeLabel() . ' Rp ' . number_format((float) $payment->amount, 0, ',', '.'),
                'changed_by' => Auth::id(),
            ]);
        });

        return back()->with('success', 'Pembayaran dicatat.');
    }

    public function destroy(int $id, int $paymentId)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $payment = $invoice->payments()->findOrFail($paymentId);

        DB::transaction(function () use ($invoice, $payment) {
            $amount = (float) $payment->amount;

            $payment->delete();           // soft delete — barisnya tetap bisa ditelusuri
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'payment_deleted',
                'to_status'  => $invoice->payment_status,
                'note'       => 'Pembayaran Rp ' . number_format($amount, 0, ',', '.') . ' dihapus.',
                'changed_by' => Auth::id(),
            ]);
        });

        return back()->with('warning', 'Pembayaran dihapus dan total dihitung ulang.');
    }
}
```

> **Yang TIDAK dijamin `DB::transaction` di sini.** Transaksi memberi atomisitas (pembayaran + total + log jadi satu), **bukan** serialisasi. `SUM` di dalam `recalcTotals()` adalah consistent read tanpa kunci baris, jadi dua pencatatan pembayaran yang benar-benar bersamaan bisa saling menimpa: yang kedua membaca SUM sebelum yang pertama commit, lalu menulis angka basi. Hitungannya derivatif, jadi `recalcTotals()` berikutnya memulihkannya — tapi tak ada yang memicunya otomatis. Diterima sebagai risiko (alat internal, satu-dua operator); kalau kelak perlu ditutup, kuncinya `lockForUpdate()` pada baris invoice, bukan menambah transaksi.

- [ ] **Step 4: Daftarkan rute**

Tambahkan di grup `service.` di `routes/web.php`, setelah baris `invoice.cancel`:

```php
        Route::post  ('invoice/{id}/payment',              [\App\Http\Controllers\Pages\ServiceInvoicePaymentController::class, 'store'])  ->name('invoice.payment.store')->whereNumber('id');
        Route::delete('invoice/{id}/payment/{paymentId}',  [\App\Http\Controllers\Pages\ServiceInvoicePaymentController::class, 'destroy'])->name('invoice.payment.destroy')->whereNumber('id')->whereNumber('paymentId');
```

- [ ] **Step 5: Tambahkan aksi permission**

Di `config/permissions.php`, tambahkan ke `actions` milik `'service_invoice'`:

```php
                'payment' => ['service.invoice.payment.store', 'service.invoice.payment.destroy'],
```

- [ ] **Step 6: Tambahkan panel pembayaran di detail**

Di `resources/views/services/invoices/show.blade.php`, sisipkan di kolom kiri, tepat setelah `</div>` penutup card rincian layanan (masih di dalam `col-md-8`):

```blade
        <div class="card mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <h6 class="card-title mb-0">Riwayat Pembayaran</h6>
                    @can('service_invoice.payment')
                        @unless($invoice->isCancelled())
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                + Catat Pembayaran
                            </button>
                        @endunless
                    @endcan
                </div>

                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Tanggal</th><th>Jenis</th><th>Metode</th><th>Referensi</th>
                                <th class="text-end">Jumlah</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->payments as $p)
                            <tr>
                                <td>{{ $p->paid_at?->format('d M Y') }}</td>
                                <td>{{ $p->typeLabel() }}</td>
                                <td>{{ $p->methodLabel() }}</td>
                                <td><small>{{ $p->reference ?? '-' }}</small></td>
                                <td class="text-end">Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                                <td>
                                    @can('service_invoice.payment')
                                    <form action="{{ route('service.invoice.payment.destroy', [$invoice->id, $p->id]) }}"
                                          method="POST" onsubmit="return confirm('Hapus pembayaran ini? Total akan dihitung ulang.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">×</button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted">Belum ada pembayaran.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
```

Lalu tambahkan modal ini tepat sebelum `@endsection`:

```blade
@can('service_invoice.payment')
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('service.invoice.payment.store', $invoice->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Catat Pembayaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="paid_at" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Jenis</label>
                        <select name="type" class="form-select" required>
                            @foreach(\App\Models\ServiceInvoicePayment::TYPES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Label untuk cetakan saja — status bayar dihitung dari nominalnya.</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Jumlah (Rp)</label>
                        <input type="text" name="amount" class="form-control" required
                               value="{{ max((float) $invoice->remaining, 0) > 0 ? (int) $invoice->remaining : '' }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Metode</label>
                        <select name="method" class="form-select" required>
                            @foreach(\App\Models\ServiceInvoicePayment::METHODS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Referensi</label>
                        <input type="text" name="reference" class="form-control" maxlength="190"
                               placeholder="No. transaksi / rekening pengirim">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
```

- [ ] **Step 7: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoicePaymentTest`
Expected: PASS (5 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/ServiceInvoicePaymentController.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php \
        tests/Feature/ServiceInvoicePaymentTest.php
git commit -m "layanan: catat & hapus pembayaran, total dan status bayar ikut terhitung"
```

---

### Task 12: PDF invoice layanan

Menutup T-PDF-1.

**Files:**
- Create: `app/Support/ServiceInvoicePdfData.php`
- Create: `resources/views/services/invoices/invoice_pdf.blade.php`
- Modify: `app/Http/Controllers/Pages/ServiceInvoiceController.php` (tambah `pdf`)
- Modify: `resources/views/services/invoices/show.blade.php` (tombol unduh)
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/ServiceInvoicePdfTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoicePdfTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\ServiceInvoicePdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoicePdfTest extends TestCase
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

    private function invoice(): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create(['discount' => 100000]);
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function pdf_data_carries_invoice_items_and_payments(): void
    {
        $data = ServiceInvoicePdfData::for($this->invoice());

        $this->assertArrayHasKey('invoice', $data);
        $this->assertCount(1, $data['invoice']->items);
        $this->assertCount(1, $data['invoice']->payments);
        $this->assertEquals(2400000, $data['invoice']->total);
        $this->assertEquals(1400000, $data['invoice']->remaining);
    }

    /** @test */
    public function pdf_route_streams_a_pdf(): void
    {
        $inv = $this->invoice();

        $response = $this->actingAs($this->user('manager'))->get(route('service.invoice.pdf', $inv->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /** @test */
    public function pdf_renders_for_an_invoice_whose_client_and_catalog_were_deleted(): void
    {
        $inv = $this->invoice();
        $inv->update(['service_client_id' => null]);   // klien sudah dihapus

        $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.pdf', $inv->id))
            ->assertOk();
    }

    /** @test */
    public function other_roles_cannot_download(): void
    {
        $inv = $this->invoice();

        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.invoice.pdf', $inv->id))
                ->assertForbidden();
        }
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoicePdfTest`
Expected: FAIL — `Class "App\Support\ServiceInvoicePdfData" not found`

- [ ] **Step 3: Tulis `ServiceInvoicePdfData`**

`app/Support/ServiceInvoicePdfData.php`:

```php
<?php

namespace App\Support;

use App\Models\ServiceInvoice;

/**
 * Perakit data PDF invoice layanan. Satu sumber yang dipakai bersama oleh route
 * unduh dan SendServiceInvoiceJob, supaya dokumen yang diunduh dan yang dikirim
 * lewat email tidak pernah berbeda isi — peran yang sama seperti InvoicePdfData
 * di modul invoice order.
 *
 * Tidak ada penyaringan approval seperti di InvoicePdfData: pembayaran jasa dicatat
 * langsung tanpa alur persetujuan, jadi semua barisnya memang sah dihitung.
 */
class ServiceInvoicePdfData
{
    /** @return array{invoice: ServiceInvoice} */
    public static function for(ServiceInvoice $invoice): array
    {
        $invoice->load(['items', 'payments']);

        return compact('invoice');
    }
}
```

- [ ] **Step 4: Salin template PDF invoice buku**

```bash
cp resources/views/payments/invoices/book_invoice_pdf.blade.php \
   resources/views/services/invoices/invoice_pdf.blade.php
```

- [ ] **Step 5: Ganti isi `<body>` pada salinan itu**

Di `resources/views/services/invoices/invoice_pdf.blade.php`, **pertahankan apa adanya** seluruh bagian dari baris 1 sampai tag `<body>` (blok `<style>` lengkap — kop, warna `#003366`, tabel, tanda tangan, footer). Ganti judul halaman di `<head>` menjadi:

```blade
    <title>Invoice Layanan {{ $invoice->invoice_no }}</title>
```

Lalu ganti **seluruh isi** dari baris `<img class="background-logo" ...>` sampai `</body>` dengan:

```blade
    <img class="background-logo" src="{{ public_path('assets/images/bg-pdf.png') }}" alt="Background">

    <!-- Header -->
    <table class="header" width="100%">
        <tr>
            <td width="50%" valign="top">
                <img src="{{ public_path('assets/images/logo-sm.png') }}" alt="Logo Avidpedia">
                <div class="company-info">
                    <p><b>AVIDPEDIA PUBLISHING</b></p>
                    <p>Jasa Layanan Publikasi Buku &amp; Artikel Ilmiah</p>
                    <p>Simpang III Sipin, Kota Baru, Jambi, 36126</p>
                    <p>+62 851-5842-2426 | contact@avidpedia.com</p>
                </div>
            </td>
            <td width="50%" class="invoice-info" valign="bottom">
                <h2>INVOICE</h2>
                <p><strong>#{{ $invoice->invoice_no }}</strong></p>
                <p>Issue: {{ $invoice->issued_at?->format('d F Y') }}</p>
                <p>Expired: {{ $invoice->due_at?->format('d F Y') ?? '-' }}</p>
            </td>
        </tr>
    </table>

    <!-- Informasi Pelanggan & Total Cepat -->
    <table class="info-table">
        <tr>
            <td width="55%">
                <h4>Kepada Yth.</h4>
                <p>
                    {{ Str::title($invoice->client_name) }}<br>
                    @if ($invoice->client_institution)
                        {{ Str::title($invoice->client_institution) }}<br>
                    @endif
                    {{ $invoice->client_email ?? '-' }}<br>
                    {{ $invoice->client_phone ?? '-' }}
                    @if ($invoice->client_address)
                        <br>{{ $invoice->client_address }}
                    @endif
                </p>
            </td>
            <td width="45%" style="text-align: right;">
                <h4>Metode Pembayaran:</h4>
                <p>
                    Transfer Bank: BNI<br>
                    PT. AVID MEDIA INDONESIA<br>
                    <b>2017627745</b>
                </p>
                <h4>Total Tagihan</h4>
                <h5 style="color:#003366; margin:0;">Rp {{ number_format($invoice->total, 0, ',', '.') }}</h5>
                <h4 style="margin-top:10px;">Status</h4>
                <h5 style="color:{{ $invoice->remaining <= 0 ? '#28a745' : '#dc3545' }}; margin:0;">
                    {{ $invoice->remaining <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
                </h5>
            </td>
        </tr>
    </table>

    <!-- Rincian Layanan -->
    <h4 style="margin-top:20px;">Rincian Layanan</h4>
    <table class="detail">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="45%">Layanan</th>
                <th width="10%" class="text-right">Qty</th>
                <th width="20%" class="text-right">Harga</th>
                <th width="20%" class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        {{ $item->name }}
                        @if ($item->description)
                            <br><small>{{ $item->description }}</small>
                        @endif
                    </td>
                    <td class="text-right">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                    <td class="text-right">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4" class="text-right">Subtotal</td>
                <td class="text-right">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
            </tr>
            @if ((float) $invoice->discount > 0)
                <tr>
                    <td colspan="4" class="text-right">Diskon</td>
                    <td class="text-right">&minus; Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr style="background-color:#f0f4f8; font-weight:bold;">
                <td colspan="4" class="text-right">Total Tagihan</td>
                <td class="text-right">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <!-- Riwayat Pembayaran -->
    <h4 style="margin-top:30px;">Riwayat Pembayaran</h4>
    <table class="detail">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="25%">Tanggal</th>
                <th width="25%">Jenis</th>
                <th width="20%">Metode</th>
                <th width="25%" class="text-right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->payments as $index => $payment)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $payment->paid_at?->format('d M Y') }}</td>
                    <td>{{ $payment->typeLabel() }}</td>
                    <td>{{ $payment->methodLabel() }}</td>
                    <td class="text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;">Belum ada pembayaran.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Total Ringkasan -->
    <table class="total-table">
        <tr>
            <td class="label">Total Tagihan</td>
            <td class="value">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Total Terbayar</td>
            <td class="value">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Tagihan' }}</td>
            <td class="value {{ $invoice->remaining <= 0 ? 'status-lunas' : 'status-tagihan' }}">
                Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
            </td>
        </tr>
        <tr>
            <td class="label">Status Pengerjaan</td>
            <td class="value">{{ strtoupper($invoice->workStatusLabel()) }}</td>
        </tr>
        <tr>
            <td class="label">Status Invoice</td>
            <td class="value {{ $invoice->remaining <= 0 ? 'status-lunas' : 'status-tagihan' }}">
                {{ $invoice->remaining <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
            </td>
        </tr>
    </table>
    <div class="clear"></div>

    <!-- Tanda Tangan -->
    <div class="signature">
        <p>Jambi, {{ now()->format('d F Y') }}</p>
        <img src="{{ public_path('assets/images/ttd.png') }}" alt="Tanda tangan">
        <p><b><strong>PT AVID MEDIA INDONESIA</strong></b></p>
    </div>
    <div class="clear"></div>

    <!-- Catatan -->
    <div class="notes">
        <h4>Informasi Penting:</h4>
        <ul>
            @if ($invoice->note)
                <li>{{ $invoice->note }}</li>
            @endif
            <li>Bukti pembayaran silakan kirim ke WhatsApp Admin: <strong>+62 851-5842-2426</strong></li>
            <li>Pembayaran hanya ke rekening atas nama perusahaan.</li>
            <li>Invoice ini sah secara digital tanpa tanda tangan basah.</li>
            <li>Terima kasih atas kepercayaan Anda kepada Avidpedia Publishing!</li>
        </ul>
    </div>

    <div class="footer">
        <p>Terima kasih telah mempercayakan pekerjaan Anda kepada Avidpedia Publishing &mdash;
            <a href="https://avidpedia.com">www.avidpedia.com</a></p>
        <p>Dokumen ini dibuat secara otomatis pada {{ now()->format('d/m/Y H:i') }} WIB</p>
    </div>

</body>
```

> `internal_note` sengaja **tidak** dicetak di mana pun di berkas ini.

- [ ] **Step 6: Tambahkan `pdf()` ke controller**

Sisipkan setelah `cancel()` di `app/Http/Controllers/Pages/ServiceInvoiceController.php`:

```php
    public function pdf(int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);

        return Pdf::loadView('services.invoices.invoice_pdf', ServiceInvoicePdfData::for($invoice))
            ->stream('Invoice_Layanan_' . $invoice->invoice_no . '.pdf');
    }
```

Tambahkan dua `use` di bagian atas berkas yang sama:

```php
use App\Support\ServiceInvoicePdfData;
use Barryvdh\DomPDF\Facade\Pdf;
```

- [ ] **Step 7: Daftarkan rute & permission**

Di `routes/web.php`, tambahkan di grup `service.` setelah baris `invoice.payment.destroy`:

```php
        Route::get('invoice/{id}/pdf', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'pdf'])->name('invoice.pdf')->whereNumber('id')->middleware('throttle:export');
```

Di `config/permissions.php`, tambahkan ke `actions` milik `'service_invoice'`:

```php
                'export' => ['service.invoice.pdf'],
```

- [ ] **Step 8: Tambahkan tombol unduh di detail**

Di `resources/views/services/invoices/show.blade.php`, di dalam `<div class="d-flex gap-1">`, tepat sebelum blok `@can('service_invoice.edit')`:

```blade
                        @can('service_invoice.export')
                            <a href="{{ route('service.invoice.pdf', $invoice->id) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary">Unduh PDF</a>
                        @endcan
```

- [ ] **Step 9: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoicePdfTest`
Expected: PASS (4 tests)

- [ ] **Step 10: Commit**

```bash
git add app/Support/ServiceInvoicePdfData.php \
        resources/views/services/invoices/invoice_pdf.blade.php \
        app/Http/Controllers/Pages/ServiceInvoiceController.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php \
        tests/Feature/ServiceInvoicePdfTest.php
git commit -m "layanan: PDF invoice layanan mengikuti template invoice buku"
```

---

### Task 13: Kirim invoice via email

Menutup T-MAIL-1..3. Perhatikan urutan di dalam job: **email dulu, Drive belakangan** — kebalikan dari `SendInvoiceJob` yang ada, yang diam-diam tidak mengirim apa pun kalau Drive bermasalah.

**Files:**
- Create: `app/Mail/ServiceInvoiceMail.php`
- Create: `app/Jobs/SendServiceInvoiceJob.php`
- Create: `resources/views/pages/mails/service_invoice_mail.blade.php`
- Modify: `app/Http/Controllers/Pages/ServiceInvoiceController.php` (tambah `send`)
- Modify: `resources/views/services/invoices/show.blade.php` (tombol kirim)
- Modify: `routes/web.php`, `config/permissions.php`
- Test: `tests/Feature/ServiceInvoiceMailTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`tests/Feature/ServiceInvoiceMailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendServiceInvoiceJob;
use App\Mail\ServiceInvoiceMail;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceMailTest extends TestCase
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

    private function invoice(array $override = []): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create($override);
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function send_dispatches_the_job(): void
    {
        Queue::fake();
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.send', $inv->id))
            ->assertRedirect();

        Queue::assertPushed(SendServiceInvoiceJob::class);
    }

    /** @test */
    public function send_is_refused_when_the_client_has_no_email(): void
    {
        Queue::fake();
        $inv = $this->invoice(['client_email' => null]);

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.send', $inv->id))
            ->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertNull($inv->fresh()->sent_at);
    }

    /** @test */
    public function job_mails_the_invoice_and_stamps_the_send(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-id');
        $drive->shouldReceive('uploadFile')->andReturn(['url' => 'https://drive.example/x']);

        (new SendServiceInvoiceJob($inv->id))->handle($drive);

        Mail::assertSent(ServiceInvoiceMail::class, fn ($m) => $m->hasTo($inv->client_email));

        $inv->refresh();
        $this->assertNotNull($inv->sent_at);
        $this->assertSame(1, $inv->sent_count);
        $this->assertSame('https://drive.example/x', $inv->pdf_drive_url);
        $this->assertSame('emailed', $inv->logs->first()->event);
    }

    /** @test */
    public function drive_failure_does_not_stop_the_email(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andThrow(new \RuntimeException('Drive down'));

        (new SendServiceInvoiceJob($inv->id))->handle($drive);

        Mail::assertSent(ServiceInvoiceMail::class);

        $inv->refresh();
        $this->assertNotNull($inv->sent_at, 'Email terkirim, jadi pengirimannya harus tetap tercatat.');
        $this->assertNull($inv->pdf_drive_url);
    }

    /** @test */
    public function mailable_subject_uses_the_real_invoice_number(): void
    {
        $inv  = $this->invoice();
        $mail = new ServiceInvoiceMail($inv, 'PDFBYTES');

        // Jebakan yang TIDAK diwarisi: InvoiceMail lama memakai $invoice->inv_no
        // yang tidak ada, sehingga subjeknya terkirim tanpa nomor.
        $this->assertStringContainsString($inv->invoice_no, $mail->envelope()->subject);
    }

    /** @test */
    public function other_roles_cannot_send(): void
    {
        Queue::fake();
        $inv = $this->invoice();

        // Non-GET ditolak dengan redirect + flash, bukan 403 (EnforcePermission::deny),
        // jadi bukti sebenarnya ada di antrean yang tetap kosong.
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->post(route('service.invoice.send', $inv->id))
                ->assertRedirect();
        }

        Queue::assertNothingPushed();
        $this->assertNull($inv->fresh()->sent_at);
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal**

Run: `php artisan test --filter=ServiceInvoiceMailTest`
Expected: FAIL — `Class "App\Jobs\SendServiceInvoiceJob" not found`

- [ ] **Step 3: Tulis mailable**

`app/Mail/ServiceInvoiceMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\ServiceInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceInvoice $invoice,
        public string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        // invoice_no — BUKAN inv_no. Atribut itu tidak ada di model dan membuat
        // subjek terkirim tanpa nomor, seperti yang terjadi di InvoiceMail lama.
        return new Envelope(
            subject: 'Invoice Layanan #' . $this->invoice->invoice_no . ' — Avidpedia',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.service_invoice_mail');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, 'Invoice_Layanan_' . $this->invoice->invoice_no . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
```

- [ ] **Step 4: Tulis job**

`app/Jobs/SendServiceInvoiceJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\ServiceInvoiceMail;
use App\Models\ServiceInvoice;
use App\Services\GoogleDriveService;
use App\Support\ServiceInvoicePdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $invoiceId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $invoice = ServiceInvoice::with(['items', 'payments'])->find($this->invoiceId);
        if (! $invoice || ! $invoice->client_email) {
            return;
        }

        $pdfContent = Pdf::loadView('services.invoices.invoice_pdf', ServiceInvoicePdfData::for($invoice))->output();

        // URUTAN PENTING: email dikirim LEBIH DULU. SendInvoiceJob yang lama menaruh
        // Mail::to(...) di dalam if ($folderId), sehingga Google Drive bermasalah =
        // invoice tidak pernah sampai ke klien, tanpa jejak apa pun.
        Mail::to($invoice->client_email)->send(new ServiceInvoiceMail($invoice, $pdfContent));

        // Arsip Drive: best-effort. Gagal di sini TIDAK membatalkan apa pun.
        $driveUrl = null;
        try {
            $tempDir = storage_path('app/temp/service-invoices');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Invoice_Layanan_' . $invoice->invoice_no . '.pdf';
            file_put_contents($tempPath, $pdfContent);

            $folderId = $drive->getOrCreateFolderByPath('Application/ServiceInvoices/' . $invoice->issued_at->format('Y'));
            if ($folderId) {
                $result   = $drive->uploadFile($tempPath, $folderId, true);
                $driveUrl = $result['url'] ?? null;
            }

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendServiceInvoiceJob: arsip Drive gagal, email tetap terkirim. ' . $e->getMessage());
        }

        $invoice->forceFill([
            'sent_at'       => now(),
            'sent_count'    => $invoice->sent_count + 1,
            'pdf_drive_url' => $driveUrl ?? $invoice->pdf_drive_url,
        ])->save();

        $invoice->logs()->create([
            'event' => 'emailed',
            'note'  => 'Dikirim ke ' . $invoice->client_email . ($driveUrl ? '' : ' (arsip Drive gagal)'),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $invoice = ServiceInvoice::find($this->invoiceId);
        $invoice?->logs()->create([
            'event' => 'email_failed',
            'note'  => substr($e->getMessage(), 0, 500),
        ]);
    }
}
```

- [ ] **Step 5: Tulis view email**

`resources/views/pages/mails/service_invoice_mail.blade.php`:

```blade
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Layanan #{{ $invoice->invoice_no }}</title>
</head>

<body style="margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; background-color:#f4f4f4;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width:600px; background-color:#ffffff; border:1px solid #e0e0e0; margin:20px auto;">

        <tr>
            <td style="padding:10px; text-align:center; background-color:#055eb6;">
                <img src="{{ asset('assets/images/logo-sm-white.png') }}" alt="Avidpedia" style="width:50px;">
                <p style="font-size:12px; color:#ffffff; margin:8px 0 0;">
                    <b>AVIDPEDIA PUBLISHER</b><br>
                    +62 851-5842-2426 | contact@avidpedia.com
                </p>
            </td>
        </tr>

        <tr>
            <td style="padding:30px;">
                <h1 style="color:#333333; font-size:24px; margin:0 0 20px; text-align:center;">
                    Invoice Layanan #{{ $invoice->invoice_no }}
                </h1>

                <p style="color:#555555; font-size:16px; line-height:1.6; margin:0 0 20px; text-align:center;">
                    Terima kasih telah mempercayakan pekerjaan Anda kepada <strong>Avidpedia</strong>!<br>
                    Berikut ringkasan invoice Anda:
                </p>

                <table border="0" cellpadding="0" cellspacing="0" style="width:100%; margin-bottom:20px;">
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>No Invoice</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->invoice_no }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555; vertical-align:top;"><strong>Layanan</strong></td>
                        <td style="padding:8px 0; color:#555;">
                            {{ $invoice->items->pluck('name')->implode(', ') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Total Biaya</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jumlah Dibayar</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;">
                            <strong>{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Bayar' }}</strong>
                        </td>
                        <td style="padding:8px 0; color:#555;">
                            Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jatuh Tempo</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->due_at?->format('d F Y') ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Status Pengerjaan</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->workStatusLabel() }}</td>
                    </tr>
                </table>

                <p style="color:#555555; font-size:14px; line-height:1.6; margin:0;">
                    Invoice lengkap terlampir dalam berkas PDF. Bukti pembayaran dapat dikirim ke
                    WhatsApp Admin <strong>+62 851-5842-2426</strong>.
                </p>
            </td>
        </tr>

        <tr>
            <td style="padding:15px; text-align:center; background-color:#f4f4f4; color:#888; font-size:12px;">
                Avidpedia Publishing &mdash; www.avidpedia.com
            </td>
        </tr>
    </table>
</body>

</html>
```

- [ ] **Step 6: Tambahkan `send()` ke controller**

Sisipkan setelah `pdf()` di `app/Http/Controllers/Pages/ServiceInvoiceController.php`:

```php
    public function send(int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);

        if (! $invoice->client_email) {
            return back()->with('error', 'Klien belum punya alamat email — lengkapi dulu lewat Edit.');
        }

        SendServiceInvoiceJob::dispatch($invoice->id);

        return back()->with('success', 'Invoice sedang dikirim ke ' . $invoice->client_email . '.');
    }
```

Tambahkan `use` di bagian atas berkas yang sama:

```php
use App\Jobs\SendServiceInvoiceJob;
```

- [ ] **Step 7: Daftarkan rute & permission**

Di `routes/web.php`, tambahkan di grup `service.` setelah baris `invoice.pdf`:

```php
        Route::post('invoice/{id}/send', [\App\Http\Controllers\Pages\ServiceInvoiceController::class, 'send'])->name('invoice.send')->whereNumber('id');
```

Di `config/permissions.php`, tambahkan ke `actions` milik `'service_invoice'`:

```php
                'send' => ['service.invoice.send'],
```

- [ ] **Step 8: Tambahkan tombol kirim di detail**

Di `resources/views/services/invoices/show.blade.php`, di dalam `<div class="d-flex gap-1">`, tepat sebelum blok `@can('service_invoice.export')`:

```blade
                        @can('service_invoice.send')
                            <form action="{{ route('service.invoice.send', $invoice->id) }}" method="POST">
                                @csrf
                                <button class="btn btn-sm btn-outline-success"
                                        {{ $invoice->client_email ? '' : 'disabled title=Klien belum punya email' }}>
                                    Kirim Email
                                </button>
                            </form>
                        @endcan
```

Lalu, tepat di bawah baris badge status di header detail, tambahkan penanda pengiriman:

```blade
                        @if($invoice->sent_at)
                            <div><small class="text-muted">
                                Terkirim {{ $invoice->sent_at->format('d/m/Y H:i') }} ({{ $invoice->sent_count }}×)
                            </small></div>
                        @endif
```

- [ ] **Step 9: Jalankan tes, pastikan lulus**

Run: `php artisan test --filter=ServiceInvoiceMailTest`
Expected: PASS (6 tests)

- [ ] **Step 10: Commit**

```bash
git add app/Mail/ServiceInvoiceMail.php app/Jobs/SendServiceInvoiceJob.php \
        resources/views/pages/mails/service_invoice_mail.blade.php \
        app/Http/Controllers/Pages/ServiceInvoiceController.php \
        resources/views/services/invoices/show.blade.php \
        routes/web.php config/permissions.php \
        tests/Feature/ServiceInvoiceMailTest.php
git commit -m "layanan: kirim invoice via email, tak lagi bergantung pada Google Drive"
```

---

### Task 14: Kunci akses, snapshot, dan isolasi keuangan

Menutup T-ACL-1..2, T-SNAP-1..2, dan **T-ISO** — penjaga keputusan "modul ini tidak nyambung ke keuangan".

**Files:**
- Test: `tests/Feature/ServiceInvoiceAccessTest.php`
- Test: `tests/Feature/ServiceInvoiceIsolationTest.php`

- [ ] **Step 1: Tulis tes akses**

`tests/Feature/ServiceInvoiceAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\GoogleDriveService;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public static function shutOutRoles(): array
    {
        return [['admin'], ['marketing'], ['production'], ['accounting']];
    }

    /**
     * @test
     * @dataProvider shutOutRoles
     */
    public function roles_outside_the_module_see_nothing(string $role): void
    {
        $inv  = ServiceInvoice::factory()->create();
        $user = $this->user($role);

        foreach ([
            route('service.invoice.index'),
            route('service.invoice.create'),
            route('service.invoice.show', $inv->id),
            route('service.invoice.edit', $inv->id),
            route('service.invoice.pdf', $inv->id),
            route('service.catalog.index'),
            route('service.client.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /** @test */
    public function shut_out_roles_hold_none_of_the_module_permissions(): void
    {
        // Menu sidebar dijaga @canany atas ketiga permission ini, jadi memeriksa
        // permission-nya lebih tepat (dan jauh lebih tahan banting) daripada
        // merender dashboard yang isinya berbeda-beda per role.
        foreach (['admin', 'marketing', 'production', 'accounting'] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->can('service_invoice.view'), "{$role} tidak boleh punya service_invoice.view");
            $this->assertFalse($user->can('service_catalog.view'), "{$role} tidak boleh punya service_catalog.view");
            $this->assertFalse($user->can('service_client.view'),  "{$role} tidak boleh punya service_client.view");
        }
    }

    /** @test */
    public function manager_gets_everything_except_cancel_and_delete(): void
    {
        $manager = $this->user('manager');
        $inv     = ServiceInvoice::factory()->create();

        $this->assertTrue($manager->can('service_invoice.view'));
        $this->assertTrue($manager->can('service_invoice.create'));
        $this->assertTrue($manager->can('service_invoice.edit'));
        $this->assertTrue($manager->can('service_invoice.status'));
        $this->assertTrue($manager->can('service_invoice.payment'));
        $this->assertTrue($manager->can('service_invoice.export'));
        $this->assertTrue($manager->can('service_invoice.send'));
        $this->assertTrue($manager->can('service_catalog.manage'));
        $this->assertTrue($manager->can('service_client.manage'));

        $this->assertFalse($manager->can('service_invoice.cancel'));
        $this->assertFalse($manager->can('service_invoice.delete'));

        // Non-GET yang ditolak = redirect + flash, bukan 403 (EnforcePermission::deny).
        $this->actingAs($manager)->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'x'])->assertRedirect();
        $this->actingAs($manager)->delete(route('service.invoice.destroy', $inv->id))->assertRedirect();

        $inv->refresh();
        $this->assertNotSame('batal', $inv->work_status);
        $this->assertNotSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
    }

    /** @test */
    public function superadmin_passes_every_gate(): void
    {
        $inv = ServiceInvoice::factory()->create();

        $this->actingAs($this->user('superadmin'))->get(route('service.invoice.index'))->assertOk();
        $this->actingAs($this->user('superadmin'))
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();
    }
}
```

- [ ] **Step 2: Jalankan tes akses**

Run: `php artisan test --filter=ServiceInvoiceAccessTest`
Expected: PASS (7 tests — 4 dari data provider + 3 lainnya)

> Kalau `manager_gets_everything_except_cancel_and_delete` merah pada `cancel`/`delete`, artinya kedua permission itu belum masuk `$superadminOnly` di `AccessMatrixSeeder` (Task 9 Step 6 & Task 10 Step 5).

- [ ] **Step 3: Tulis tes snapshot & isolasi**

`tests/Feature/ServiceInvoiceIsolationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function manager(): User
    {
        $u = User::factory()->create();
        $u->assignRole('manager');
        return $u;
    }

    /** @test */
    public function changing_catalog_price_or_client_details_never_touches_issued_invoices(): void
    {
        $catalog = ServiceCatalog::factory()->create(['name' => 'Instalasi OJS Basic', 'price' => 500000]);
        $client  = ServiceClient::factory()->create(['name' => 'Dr. Sartika', 'institution' => 'UNBARI']);

        $this->actingAs($this->manager())->post(route('service.invoice.store'), [
            'service_client_id'  => $client->id,
            'client_name'        => 'Dr. Sartika',
            'client_institution' => 'UNBARI',
            'client_email'       => 'lama@unbari.ac.id',
            'issued_at'          => '2026-08-11',
            'items'              => [[
                'service_catalog_id' => $catalog->id,
                'name'               => 'Instalasi OJS Basic',
                'qty'                => 1,
                'unit_price'         => '500000',
            ]],
        ]);

        $invoice = ServiceInvoice::first();

        $catalog->update(['name' => 'Instalasi OJS Basic (baru)', 'price' => 900000]);
        $client->update(['institution' => 'Universitas Batanghari', 'email' => 'baru@unbari.ac.id']);

        $invoice->refresh();
        $item = $invoice->items->first();

        $this->assertSame('Instalasi OJS Basic', $item->name);
        $this->assertEquals(500000, $item->unit_price);
        $this->assertEquals(500000, $invoice->total);
        $this->assertSame('UNBARI', $invoice->client_institution);
        $this->assertSame('lama@unbari.ac.id', $invoice->client_email);
    }

    /** @test */
    public function deleting_catalog_and_client_leaves_the_invoice_printable(): void
    {
        $catalog = ServiceCatalog::factory()->create();
        $client  = ServiceClient::factory()->create();

        $invoice = ServiceInvoice::factory()->create(['service_client_id' => $client->id]);
        $invoice->items()->create([
            'service_catalog_id' => $catalog->id,
            'name' => 'Instalasi OJS Basic', 'qty' => 1, 'unit_price' => 500000, 'subtotal' => 500000,
        ]);
        $invoice->recalcTotals();

        $manager = $this->manager();
        $this->actingAs($manager)->delete(route('service.client.destroy', $client->id));
        $this->actingAs($manager)->delete(route('service.catalog.destroy', $catalog->id));

        $invoice->refresh();
        $this->assertNull($invoice->service_client_id);
        $this->assertSame('Instalasi OJS Basic', $invoice->items->first()->name);

        $this->actingAs($manager)->get(route('service.invoice.pdf', $invoice->id))->assertOk();
    }

    /**
     * T-ISO — penjaga keputusan produk: modul ini SENGAJA tidak tersambung ke
     * keuangan/order/payment. Kalau tes ini merah, seseorang menyambungkannya;
     * itu butuh keputusan produk baru, bukan tambalan supaya tesnya hijau.
     *
     * @test
     */
    public function service_invoices_never_leak_into_orders_payments_or_cash(): void
    {
        $before = [
            'tb_orders'       => DB::table('tb_orders')->count(),
            'tb_payments'     => DB::table('tb_payments')->count(),
            'tb_invoices'     => DB::table('tb_invoices')->count(),
            'tb_cash_entries' => DB::table('tb_cash_entries')->count(),
        ];

        $manager = $this->manager();

        $this->actingAs($manager)->post(route('service.invoice.store'), [
            'client_name' => 'Klien Jasa',
            'client_email' => 'klien@example.test',
            'issued_at'   => '2026-08-11',
            'items'       => [['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => '2500000']],
        ]);

        $invoice = ServiceInvoice::first();

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $invoice->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '1000000', 'method' => 'transfer',
        ]);
        $this->actingAs($manager)->post(route('service.invoice.status', $invoice->id), ['work_status' => 'selesai']);

        // Invoice jasanya sendiri memang tercatat...
        $this->assertEquals(1000000, $invoice->fresh()->paid_total);

        // ...tapi TIDAK satu baris pun bocor ke modul lain.
        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::table($table)->count(),
                "Modul layanan menulis ke {$table} — itu melanggar keputusan standalone di spec §2."
            );
        }
    }
}
```

- [ ] **Step 4: Jalankan tes isolasi**

Run: `php artisan test --filter=ServiceInvoiceIsolationTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ServiceInvoiceAccessTest.php tests/Feature/ServiceInvoiceIsolationTest.php
git commit -m "layanan: kunci akses, snapshot, dan isolasi dari keuangan lewat tes"
```

---

### Task 15: Verifikasi menyeluruh & penyiapan DB dev

**Files:** tidak ada berkas baru — ini gerbang akhir.

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS, nol kegagalan. Plan ini menambahkan sekitar 70 tes baru di 12 berkas (`tests/Feature/Service*.php`).

Kalau ada tes **lama** yang merah, hentikan dan telusuri sebelum lanjut. Tersangka yang paling mungkin:
- `PermissionMapCompletenessTest` — ada rute `service.*` yang belum dipetakan.
- `AccessParityTest` — hibah role bergeser; periksa `$superadminOnly` hanya bertambah dua baris yang dimaksud.
- `RouteSmokeTest` — rute GET baru memerlukan data; pastikan `service.invoice.show/edit/pdf` menerima `{id}` numerik dan mengembalikan 404 (bukan 5xx) saat data tidak ada.

- [ ] **Step 2: Migrasikan DB dev**

Suite hijau berjalan di `avidpedia_simapa_test`. Aplikasi yang hidup memakai `avidpedia_simapa` dan **belum punya tabel-tabel ini** — tanpa langkah ini setiap halaman Layanan akan 500.

Run: `php artisan migrate`
Expected: 6 migrasi `2026_08_11_*` berstatus `DONE`.

- [ ] **Step 3: Seed katalog & permission di DB dev**

```bash
php artisan db:seed --class=ServiceCatalogSeeder
php artisan db:seed --class=AccessMatrixSeeder
php artisan permission:cache-reset
```

Expected: katalog terisi ~30 baris; permission `service_invoice.*`, `service_catalog.*`, `service_client.*` terbentuk.

- [ ] **Step 4: Periksa manual di browser**

Masuk sebagai superadmin, lalu telusuri alurnya sekali penuh:

1. **Layanan → Katalog Layanan** — daftar harga tampil terkelompok per kategori; tambah satu layanan Turnitin (kategori Turnitin & Penurunan Plagiasi) untuk memastikan CRUD-nya hidup.
2. **Layanan → Invoice Layanan → Buat Invoice** — pilih layanan dari katalog, pastikan harga terisi otomatis dan pratinjau Subtotal/Total ikut berubah saat qty diubah.
3. Simpan → halaman detail muncul dengan nomor `INV-JS-<bulan ini>-0001`.
4. **Catat Pembayaran** DP separuh → badge berubah jadi **DP**, sisa tagihan turun.
5. **Perbarui Status** ke Proses lalu Selesai → riwayat mencatat keduanya; ubah balik ke Proses → tanggal selesai kosong lagi.
6. **Unduh PDF** — periksa kop, rincian layanan, riwayat pembayaran, sisa tagihan, dan bahwa catatan internal **tidak** ikut tercetak.
7. **Kirim Email** (kalau kredensial mail dev tersedia) — periksa subjeknya memuat nomor invoice.
8. Masuk sebagai **admin** → menu Layanan tidak muncul, dan `/layanan/invoice` menampilkan halaman 403.

- [ ] **Step 5: Konfirmasi keuangan tidak tersentuh**

Buka **Keuangan → Jurnal Kas** dan **Dashboard**. Nominal invoice jasa yang baru dibuat tadi **tidak boleh** muncul di mana pun. Ini yang diminta di spec §2 — bukan bug.

- [ ] **Step 6: Commit akhir bila ada penyesuaian**

```bash
git add -u
git commit -m "layanan: penyesuaian akhir setelah verifikasi menyeluruh"
```

Kalau tidak ada perubahan, lewati langkah ini.

---

## Verifikasi Cakupan terhadap Spec

| Bagian spec | Ditutup oleh |
|---|---|
| §3.1 tb_service_clients | Task 1 |
| §3.2 tb_service_catalogs | Task 1 |
| §3.3–3.6 invoice/item/payment/log | Task 2 |
| §4 model & relasi | Task 1, 2 |
| §5.1 penomoran | Task 4 |
| §5.2 recalcTotals | Task 3 |
| §5.3 mesin status kerja | Task 5 (`ServiceInvoiceWorkflow::changeStatus`), Task 10 (`::cancel`) |
| §5.4 kunci edit | Task 9 |
| §5.5 alur kirim email | Task 13 |
| §5.6 aturan kecil (diskon nominal, label bayar, klien baru, soft delete, hapus katalog) | Task 6, 7, 8, 9, 11 |
| §6.1 rute | Task 6–13 (bertahap) |
| §6.2 permission | Task 6–13 (bertahap) |
| §6.3 $superadminOnly | Task 9, 10 |
| §6.4 menu sidebar | Task 6, 7, 8 |
| §7 lima layar | Task 6 (katalog), 7 (klien ×2), 8 (daftar/form/detail) |
| §8 PDF | Task 12 |
| §9 email | Task 13 |
| §10 21 kasus uji | Task 1–14 |
| §11 seed katalog | Task 6 |
| §12 berkas yang disentuh | seluruh task |
| §13 risiko yang diterima | didokumentasikan; risiko #1 ditambatkan tes Task 3 |

