# Sinkronisasi Status Order ↔ Naskah — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Order akhirnya tahu naskahnya sudah terbit, dan order yang di-refund keluar dari perhitungan judul tanpa melumpuhkan order lain yang sejudul.

**Architecture:** `tb_orders` dapat kolom `fulfillment_status` yang terpisah dari kolom uang `status`. Satu service (`OrderFulfillmentService`) menulis kolom itu, dikaitkan ke satu-satunya tempat tahap naskah ditulis. Order yang di-refund penuh ditandai `ditarik` lewat `withdrawn_at` di `tb_title_progress`, dan satu scope (`OrderDetail::notWithdrawn()`) dipasang di setiap tempat yang menjelajah "semua order sejudul".

**Tech Stack:** Laravel 10, MariaDB 10.4 (XAMPP), Blade + Bootstrap 4, DataTables `datatables.net-bs4`, PHPUnit lewat `php artisan test`.

**Spec:** [2026-08-20-sinkronisasi-status-order-naskah-design.md](../specs/2026-08-20-sinkronisasi-status-order-naskah-design.md)

**Branch:** `feat/sinkronisasi-status-order-naskah` (sudah ada, spec ter-commit di `34f016d`)

---

## Penyimpangan dari spec

Dua, keduanya disengaja:

**1. §5.4 menyebut snapshot penarikan disimpan ke `TitleProgressLog`.** Rencana ini
menyimpannya ke kolom baru `tb_title_progress.withdrawal_snapshot` (JSON, nullable).
Alasannya: `tb_title_progress_logs.note` ditampilkan apa adanya di halaman Riwayat naskah,
jadi menaruh JSON di sana berarti pengguna melihat `{"authors":[{"id":12,...` di layar.
Log tetap dicatat sebagai baris `penarikan` yang terbaca manusia; hanya datanya yang pindah.

**2. §4.3 menyebut enam titik yang harus disaring, termasuk `applyGroup()` dan
`onGroup()`.** Keduanya tidak diubah dalam rencana ini karena masing-masing mengambil
anggota grupnya lewat `groupOf()` dan `group()` — menyaring di dua tempat itu sudah
menutup keduanya. Sebaliknya rencana ini menyaring **tujuh tempat lain** yang tidak
disebut spec (`createForDetail`, `ChapterRollupService`, `ChapterManuscriptService` ×3,
`ManuscriptFileService`, `AssignmentService:362`), yang ditemukan saat menelusuri seluruh
penjelajahan `orderDetails->titleProgress`.

**Catatan penamaan:** spec §8 menyebut berkas uji `ArchiveIndexTest`; rencana ini
memakai `ArchiveSiapDiarsipkanTest` supaya tak tertukar dengan `TitleArchiveTest` yang
sudah ada.

---

## Struktur berkas

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `app/Services/OrderFulfillmentService.php` | Satu-satunya penulis `tb_orders.fulfillment_status` + `completed_at` |
| `app/Services/OrderWithdrawalService.php` | Menarik & memulihkan satu order (penanda, bab, penulis, snapshot) |
| `database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php` | Kolom `fulfillment_status` |
| `database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php` | `withdrawn_at`, `withdrawn_reason`, `withdrawal_snapshot` |
| `database/migrations/2026_08_20_000004_backfill_order_fulfillment.php` | Isi kolom baru untuk data lama (`DB::table()` saja) |
| `tests/Feature/OrderFulfillmentTest.php` | Task 3 |
| `tests/Feature/WithdrawnExclusionTest.php` | Task 4 |
| `tests/Feature/OrderWithdrawalTest.php` | Task 5 & 6 |
| `tests/Feature/WithdrawalUndoTest.php` | Task 7 |
| `tests/Feature/ChapterAuthorCleanupTest.php` | Task 8 |
| `tests/Feature/ArchiveSiapDiarsipkanTest.php` | Task 10 |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Models/Order.php` | `const STATUSES`, `const FULFILLMENTS`, `fillable`, `isWithdrawn()` |
| `app/Models/OrderDetail.php` | `scopeNotWithdrawn()` |
| `app/Models/TitleProgress.php` | `fillable`, `casts`, `scopeActive()`, `isWithdrawn()` |
| `app/Models/Title.php` | `manuscriptStatus()`, `isPaidOff()`, `sisaTagihan()` |
| `app/Services/TitleProgressService.php` | `groupOf()`, `createForDetail()`, hook `OrderFulfillmentService`, `chapterTitleProgress` |
| `app/Services/AssignmentService.php` | `group()` |
| `app/Services/ChapterRollupService.php` | `recalc()` |
| `app/Services/ChapterManuscriptService.php` | `advanceBookToStage()` + dua penjelajahan lain |
| `app/Services/ManuscriptFileService.php` | pemilihan progress |
| `app/Services/OrderCancellationService.php` | cabut & pasang ulang penulis bab |
| `app/Services/AdminDashboardService.php` | ubin `arsip_menunggu_artefak` |
| `app/Http/Controllers/Pages/RefundController.php` | panggil `OrderWithdrawalService`, aksi `undo` |
| `app/Http/Controllers/Pages/TitleArchiveController.php` | daftar `siap` di `index()` |
| `routes/web.php` | `order.refund.undo` |
| `config/permissions.php` | daftarkan `order.refund.undo` |
| `resources/views/archive/index.blade.php` | bagian "Siap Diarsipkan" |
| `resources/views/archive/show.blade.php` | kekurangan bayar + baris ditarik |
| `resources/views/orders/book/index.blade.php` | lencana kedua |
| `resources/views/naskah/arsip.blade.php` | lencana "Ditarik" |

---

## Task 1: Kolom `fulfillment_status` di `tb_orders`

**Files:**
- Create: `database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php`
- Modify: `app/Models/Order.php`
- Test: `tests/Feature/OrderFulfillmentTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/OrderFulfillmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function order_baru_berstatus_berjalan(): void
    {
        $order = Order::factory()->create();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($order->fresh()->completed_at);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderFulfillmentTest`
Expected: FAIL — `Unknown column 'fulfillment_status'` atau property null.

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php`:

```php
<?php
// database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keadaan PEKERJAAN order, terpisah dari keadaan UANG di kolom `status`.
 *
 * Satu kolom `status` selama ini mencampur keduanya (pending/lunas = uang,
 * dibatalkan = keduanya) sehingga "naskahnya sudah terbit" tak punya tempat ditulis
 * dan `completed_at` tak pernah terisi. Memisahkannya membuat setiap query lama
 * `status = 'lunas'` tetap benar tanpa diaudit ulang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)
                  ->default('berjalan')
                  ->after('status')
                  ->index();
        });
    }

    public function down(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn('fulfillment_status');
        });
    }
};
```

- [ ] **Step 4: Tambah konstanta + fillable di model**

Di `app/Models/Order.php`, ganti blok `$fillable` dan tambahkan konstanta tepat di atasnya:

```php
    /** Keadaan UANG. Dibaca Laporan Keuangan & Piutang — jangan tambahi nilai baru. */
    public const STATUSES = ['pending', 'lunas', 'dibatalkan'];

    /**
     * Keadaan PEKERJAAN. `ditarik` = order di-refund penuh dan tak lagi dihitung
     * sebagai bagian judul. Ditulis HANYA oleh OrderFulfillmentService dan
     * OrderWithdrawalService.
     */
    public const FULFILLMENTS = ['berjalan', 'selesai', 'ditarik', 'dibatalkan'];

    protected $fillable = [
        'code_order', 'user_id', 'status', 'fulfillment_status',
        'note', 'ordered_at', 'completed_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];
```

Lalu tambahkan helper di bawah `isCancelled()`:

```php
    /** Order ditarik dari judul karena refund penuh. */
    public function isWithdrawn(): bool
    {
        return $this->fulfillment_status === 'ditarik';
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderFulfillmentTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php app/Models/Order.php tests/Feature/OrderFulfillmentTest.php
git commit -m "order: keadaan pekerjaan dapat kolomnya sendiri, lepas dari kolom uang"
```

---

## Task 2: Penanda tarik di `tb_title_progress`

**Files:**
- Create: `database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php`
- Modify: `app/Models/TitleProgress.php`
- Test: `tests/Feature/WithdrawnExclusionTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/WithdrawnExclusionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawnExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function scope_active_menyembunyikan_baris_yang_ditarik(): void
    {
        $title  = Title::create(['title' => 'Judul Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Judul Uji', 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        $this->assertSame(1, TitleProgress::active()->count());

        $progress->update(['withdrawn_at' => now(), 'withdrawn_reason' => 'Refund penuh']);

        $this->assertSame(0, TitleProgress::active()->count());
        $this->assertTrue($progress->fresh()->isWithdrawn());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=WithdrawnExclusionTest`
Expected: FAIL — `Unknown column 'withdrawn_at'`.

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php`:

```php
<?php
// database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "order ini ditarik dari judul" (refund penuh).
 *
 * Denormalisasi DISENGAJA, mengikuti pola archived_at/cancelled_at di tabel yang sama:
 * alternatifnya JOIN ke tb_orders di groupOf(), manuscriptStatus(), dan applyGroup() —
 * tiga jalur terpanas modul naskah.
 *
 * `withdrawal_snapshot` menyimpan keadaan bab & penulis sebelum dicabut, supaya
 * "Batalkan Penarikan" bisa memasangnya kembali persis.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('cancel_reason')->index();
            $table->string('withdrawn_reason')->nullable()->after('withdrawn_at');
            $table->json('withdrawal_snapshot')->nullable()->after('withdrawn_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropIndex(['withdrawn_at']);
            $table->dropColumn(['withdrawn_at', 'withdrawn_reason', 'withdrawal_snapshot']);
        });
    }
};
```

- [ ] **Step 4: Perbarui model**

Di `app/Models/TitleProgress.php`:

Tambahkan ke `$fillable` (setelah `'cancelled_at', 'cancelled_by', 'cancel_reason',`):

```php
        'withdrawn_at', 'withdrawn_reason', 'withdrawal_snapshot',
```

Tambahkan ke `$casts`:

```php
        'withdrawn_at'        => 'datetime',
        'withdrawal_snapshot' => 'array',
```

Ganti `scopeActive()`:

```php
    /** Naskah yang masih hidup di papan: belum diarsip, dibatalkan, atau ditarik. */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at')
                     ->whereNull('cancelled_at')
                     ->whereNull('withdrawn_at');
    }
```

Tambahkan helper tepat di bawah `isOverdue()`:

```php
    /** Ordernya di-refund penuh: baris ini tak lagi dihitung sebagai bagian judul. */
    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WithdrawnExclusionTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_20_000003_add_withdrawn_to_tb_title_progress.php app/Models/TitleProgress.php tests/Feature/WithdrawnExclusionTest.php
git commit -m "naskah: baris progress bisa ditandai ditarik dan hilang dari papan"
```

---

## Task 3: Order jadi "selesai" saat naskah terbit

**Files:**
- Create: `app/Services/OrderFulfillmentService.php`
- Modify: `app/Services/TitleProgressService.php` (method `applyStatus`, sekitar baris 315)
- Test: `tests/Feature/OrderFulfillmentTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/OrderFulfillmentTest.php`. Ganti seluruh isi berkas dengan:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u->fresh();
    }

    /** Artikel di tahap `loa` — satu langkah sebelum `publish`. */
    private function naskah(string $status = 'loa'): TitleProgress
    {
        $title  = Title::create(['title' => 'Artikel Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Artikel Uji', 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'artikel', 'started_at' => now(),
        ]);
    }

    /** @test */
    public function order_baru_berstatus_berjalan(): void
    {
        $order = Order::factory()->create();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($order->fresh()->completed_at);
    }

    /** @test */
    public function naskah_publish_membuat_ordernya_selesai(): void
    {
        $progress = $this->naskah('loa');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $order = $progress->orderDetail->order->fresh();
        $this->assertSame('publish', $progress->fresh()->status);
        $this->assertSame('selesai', $order->fulfillment_status);
        $this->assertNotNull($order->completed_at);
    }

    /** @test */
    public function koreksi_mundur_mengembalikan_order_ke_berjalan(): void
    {
        $progress = $this->naskah('publish');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);

        app(TitleProgressService::class)
            ->correct($progress, 'revisi', $this->superadmin(), 'Salah tandai');

        $order->refresh();
        $this->assertSame('berjalan', $order->fulfillment_status);
        $this->assertNull($order->completed_at);
    }

    /** @test */
    public function order_dibatalkan_tidak_ditimpa_jadi_selesai(): void
    {
        $progress = $this->naskah('loa');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'dibatalkan']);

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('dibatalkan', $order->fresh()->fulfillment_status);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderFulfillmentTest`
Expected: FAIL pada 3 test baru — `fulfillment_status` masih `berjalan` setelah advance.

- [ ] **Step 3: Buat service**

Buat `app/Services/OrderFulfillmentService.php`:

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;

/**
 * Satu-satunya penulis `tb_orders.fulfillment_status` untuk perpindahan yang dipicu
 * NASKAH. Penarikan (refund) ditulis OrderWithdrawalService; pembatalan ditulis
 * OrderCancellationService.
 *
 * Dikaitkan ke TitleProgressService::applyStatus() — satu-satunya tempat
 * tb_title_progress.status ditulis — sehingga tak ada jalur perpindahan tahap yang
 * bisa lolos tanpa memperbarui ordernya.
 */
class OrderFulfillmentService
{
    /**
     * Selaraskan order dengan tahap naskahnya.
     *
     * Order yang sudah `dibatalkan` atau `ditarik` TIDAK disentuh: keduanya keadaan
     * akhir yang ditetapkan manusia, dan naskah yang kebetulan masih bergerak (mis.
     * order lain sejudul memajukannya) tidak boleh menghidupkannya kembali.
     */
    public function syncFromProgress(TitleProgress $progress): void
    {
        $order = $progress->orderDetail?->order;
        if ($order === null) {
            return;
        }

        if (in_array($order->fulfillment_status, ['dibatalkan', 'ditarik'], true)) {
            return;
        }

        $final = TitleProgress::isFinal((string) $progress->status);

        $this->apply($order, $final ? 'selesai' : 'berjalan', $final ? now() : null);
    }

    /** Tulis hanya bila benar-benar berubah, supaya `updated_at` order tidak berisik. */
    private function apply(Order $order, string $status, $completedAt): void
    {
        if ($order->fulfillment_status === $status) {
            return;
        }

        $order->update([
            'fulfillment_status' => $status,
            'completed_at'       => $completedAt,
        ]);
    }
}
```

- [ ] **Step 4: Kaitkan ke `applyStatus()`**

Di `app/Services/TitleProgressService.php`, method `applyStatus()`. Sisipkan pemanggilan
tepat sebelum `return $progress;`:

```php
        app(OrderFulfillmentService::class)->syncFromProgress($progress);

        return $progress;
    }
```

Pastikan `use App\Services\OrderFulfillmentService;` tidak diperlukan — keduanya sudah
di namespace `App\Services`, jadi `OrderFulfillmentService::class` langsung terbaca.

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderFulfillmentTest`
Expected: PASS (4 test).

- [ ] **Step 6: Pastikan modul naskah tidak rusak**

Run: `php artisan test --filter="Naskah|TitleProgress"`
Expected: PASS semua.

- [ ] **Step 7: Commit**

```bash
git add app/Services/OrderFulfillmentService.php app/Services/TitleProgressService.php tests/Feature/OrderFulfillmentTest.php
git commit -m "order: naskah yang terbit menutup ordernya, completed_at akhirnya terisi"
```

---

## Task 4: Baris ditarik dikeluarkan dari perhitungan grup

Ini inti perbaikan R1–R6. Satu scope dipasang di setiap tempat yang menjelajah "semua
order sejudul".

**Files:**
- Modify: `app/Models/OrderDetail.php`
- Modify: `app/Models/Title.php` (`manuscriptStatus`, `isPaidOff`)
- Modify: `app/Services/TitleProgressService.php` (`groupOf`, `createForDetail`, baris 188)
- Modify: `app/Services/AssignmentService.php` (`group`, baris 362)
- Modify: `app/Services/ChapterRollupService.php` (`recalc`, baris 48)
- Modify: `app/Services/ChapterManuscriptService.php` (baris 35, 89, 103)
- Modify: `app/Services/ManuscriptFileService.php` (baris 97)
- Test: `tests/Feature/WithdrawnExclusionTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan tiga test ini ke `tests/Feature/WithdrawnExclusionTest.php`, di bawah test yang
sudah ada. Tambahkan juga `use App\Models\Author;`, `use App\Models\Payment;`,
`use App\Models\User;`, `use App\Services\TitleProgressService;` di bagian atas berkas.

```php
    /**
     * Buku kolaborasi $jumlah bab: satu Title, satu order + satu progress per bab.
     *
     * @return array{0: \App\Models\Title, 1: \Illuminate\Support\Collection<int,TitleProgress>}
     */
    private function bukuKolaborasi(int $jumlah, string $status = 'editing'): array
    {
        $book = Title::create(['title' => 'Buku Kolaborasi', 'jenis' => 'buku',
                               'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $progresses = collect();
        for ($bab = 1; $bab <= $jumlah; $bab++) {
            $book->chapters()->create(['judul' => "Bab {$bab}", 'urutan' => $bab]);

            $order  = Order::factory()->create();
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => 'Buku Kolaborasi', 'title_id' => $book->id,
                'chapters' => $bab, 'cost_amount' => 1_000_000,
            ]);
            Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                             'amount' => 1_000_000, 'status' => 'paid', 'paid_at' => now()]);

            $progresses->push(TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'bidang' => 'buku', 'started_at' => now(),
            ]));
        }

        return [$book->fresh(), $progresses];
    }

    /** @test */
    public function baris_ditarik_tidak_menahan_bottleneck_judul(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(3, 'terbit');

        // Satu order tertinggal jauh di belakang, lalu ditarik.
        $progresses->first()->update(['status' => 'menunggu_proses']);
        $this->assertSame('menunggu_proses', $book->fresh()->manuscriptStatus());

        $progresses->first()->update(['withdrawn_at' => now()]);

        $this->assertSame('terbit', $book->fresh()->manuscriptStatus());
        $this->assertTrue($book->fresh()->manuscriptIsFinal());
    }

    /** @test */
    public function satu_refund_tidak_mematikan_arsip_judul(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(3, 'terbit');

        // Order pertama di-refund: uangnya kembali, jadi tidak lunas lagi.
        $ditarik = $progresses->first();
        Payment::create(['order_id' => $ditarik->orderDetail->order_id,
                         'payment_type' => 'refund', 'amount' => 1_000_000,
                         'status' => 'paid', 'paid_at' => now()]);
        $ditarik->orderDetail->order->update(['fulfillment_status' => 'ditarik']);

        $this->assertFalse($book->fresh()->isPaidOff(), 'sebelum ditandai, arsip mati');

        $ditarik->update(['withdrawn_at' => now()]);

        $this->assertTrue($book->fresh()->isPaidOff());
        $this->assertTrue($book->fresh()->archiveEligible());
    }

    /** @test */
    public function baris_ditarik_tidak_ikut_maju_saat_order_lain_dimajukan(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi(2, 'proofreading');

        $ditarik = $progresses->first();
        $ditarik->update(['withdrawn_at' => now()]);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        app(TitleProgressService::class)->advance($progresses->last(), $sa->fresh());

        $this->assertSame('isbn', $progresses->last()->fresh()->status);
        $this->assertSame('proofreading', $ditarik->fresh()->status,
            'baris yang ditarik tidak boleh ikut terseret maju');
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=WithdrawnExclusionTest`
Expected: FAIL pada tiga test baru.

- [ ] **Step 3: Tambah scope di `OrderDetail`**

Di `app/Models/OrderDetail.php`, tambahkan tepat di bawah method `titleProgress()`:

```php
    /**
     * Detail yang ordernya masih dihitung sebagai bagian judul — mengecualikan order
     * yang ditarik karena refund penuh.
     *
     * Dipasang di SETIAP tempat yang menjelajah "semua order sejudul". Tanpa ini satu
     * refund menahan bottleneck judul (manuscriptStatus) dan mematikan kelayakan arsip
     * (isPaidOff) untuk seluruh penulis lain.
     */
    public function scopeNotWithdrawn($query)
    {
        return $query->whereDoesntHave(
            'titleProgress',
            fn ($q) => $q->whereNotNull('withdrawn_at')
        );
    }
```

- [ ] **Step 4: Perbarui `Title::manuscriptStatus()` dan `isPaidOff()`**

Di `app/Models/Title.php`, ganti kedua method:

```php
    /** Tahap manuskrip judul = bottleneck (stage paling awal) di antara order tertaut. Null bila belum ada progress. */
    public function manuscriptStatus(): ?string
    {
        $stages = $this->jenis === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;

        $statuses = $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->map(fn ($d) => optional($d->titleProgress)->status)
            ->filter();

        if ($statuses->isEmpty()) {
            return null;
        }

        return $statuses
            ->sortBy(fn ($s) => ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();
    }
```

```php
    /** Semua order tertaut sudah lunas (tak ada sisa/DP). Order yang ditarik diabaikan. */
    public function isPaidOff(): bool
    {
        $orders = $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->map->order->filter()->unique('id');

        return $orders->isNotEmpty() && $orders->every(fn ($o) => $o->isLunas());
    }
```

Keduanya memakai `reject()` di koleksi, bukan scope, karena `$this->orderDetails` di sini
adalah relasi yang sudah di-eager load oleh pemanggil (Direktori Judul memuat ratusan
baris sekaligus) — menambah query per judul akan mengembalikan N+1 yang sudah dibereskan.

- [ ] **Step 5: Perbarui `groupOf()` di `TitleProgressService`**

```php
    /** @return Collection<int,TitleProgress> */
    private function groupOf(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        return TitleProgress::with('orderDetail')
            ->whereNull('withdrawn_at')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }
```

`applyGroup()` tidak perlu diubah: ia mengambil anggotanya lewat `groupOf()`.

- [ ] **Step 6: Perbarui `group()` di `AssignmentService`**

```php
    /** @return Collection<int,TitleProgress> */
    private function group(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        return TitleProgress::with(['orderDetail', 'pj', 'pelaksana'])
            ->whereNull('withdrawn_at')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }
```

`onGroup()` tidak perlu diubah: ia mengambil anggotanya lewat `group()`.

- [ ] **Step 7: Pasang scope di enam penjelajahan yang tersisa**

Di setiap baris berikut, sisipkan `->notWithdrawn()` tepat setelah `orderDetails()`:

| Berkas | Baris | Sebelum → Sesudah |
|---|---|---|
| `app/Services/AssignmentService.php` | 362 | `->orderDetails()->with('titleProgress')` → `->orderDetails()->notWithdrawn()->with('titleProgress')` |
| `app/Services/ChapterRollupService.php` | 48 | idem |
| `app/Services/ChapterManuscriptService.php` | 35 | idem |
| `app/Services/ChapterManuscriptService.php` | 89 | idem |
| `app/Services/ChapterManuscriptService.php` | 103 | idem |
| `app/Services/ManuscriptFileService.php` | 97 | idem |
| `app/Services/TitleProgressService.php` | 188 | idem |

Contoh hasil di `ChapterManuscriptService.php:89`:

```php
        foreach ($book->orderDetails()->notWithdrawn()->with('titleProgress')->get() as $detail) {
```

- [ ] **Step 8: Perbarui pewarisan sibling di `createForDetail()`**

Di `app/Services/TitleProgressService.php`, di dalam `createForDetail()`, ganti pencarian
`$sibling`:

```php
        $sibling = OrderDetail::where('group_key', $detail->group_key)
            ->where('id', '!=', $detail->id)
            ->notWithdrawn()
            ->whereHas('titleProgress')
            ->with('titleProgress')
            ->get()
            ->map->titleProgress
            ->sortBy(fn ($p) => array_search($p->status, $stages, true))
            ->first();
```

Order baru tidak boleh mewarisi tahap atau pelaksana dari order yang sudah ditarik —
bab yang dijual ulang harus mulai bersih.

- [ ] **Step 9: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WithdrawnExclusionTest`
Expected: PASS (4 test).

- [ ] **Step 10: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua. Perubahan ini menyentuh banyak jalur; kalau ada yang merah, itu
sinyal ada penjelajahan grup yang terlewat, bukan test yang salah.

- [ ] **Step 11: Commit**

```bash
git add app/Models/OrderDetail.php app/Models/Title.php app/Services/TitleProgressService.php app/Services/AssignmentService.php app/Services/ChapterRollupService.php app/Services/ChapterManuscriptService.php app/Services/ManuscriptFileService.php tests/Feature/WithdrawnExclusionTest.php
git commit -m "naskah: order yang ditarik tak lagi menahan judul untuk penulis lain"
```

---

## Task 5: Refund penuh menarik ordernya

**Files:**
- Create: `app/Services/OrderWithdrawalService.php`
- Modify: `app/Http/Controllers/Pages/RefundController.php`
- Test: `tests/Feature/OrderWithdrawalTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/OrderWithdrawalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        Queue::fake();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u->fresh();
    }

    /** Artikel mandiri: satu order, satu progress, sudah dibayar penuh 500rb. */
    private function orderArtikel(string $status = 'editing'): TitleProgress
    {
        $title  = Title::create(['title' => 'Artikel Refund', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Artikel Refund', 'title_id' => $title->id,
            'cost_amount' => 500_000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 500_000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'artikel', 'started_at' => now(),
        ]);
    }

    /** @test */
    public function refund_penuh_menarik_ordernya(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500_000, 'reason' => 'Klien mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertNotNull($progress->fresh()->withdrawn_at);
        $this->assertSame('Klien mundur', $progress->fresh()->withdrawn_reason);
    }

    /** @test */
    public function refund_sebagian_tidak_menarik_apa_pun(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 200_000, 'reason' => 'Potongan harga',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($progress->fresh()->withdrawn_at);
    }

    /** @test */
    public function penarikan_tercatat_di_riwayat_naskah(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500_000, 'reason' => 'Klien mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $progress->id,
            'event'             => 'penarikan',
            'to_value'          => 'Ditarik',
        ]);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: FAIL — `fulfillment_status` masih `berjalan`.

- [ ] **Step 3: Buat service**

Buat `app/Services/OrderWithdrawalService.php`:

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menarik satu order dari judulnya karena refund penuh.
 *
 * "Ditarik" BUKAN "ditahan": rancangan pertama memakai AssignmentService::hold(), tapi
 * hold bekerja se-grup (onGroup) sehingga satu penulis yang mundur akan membekukan buku
 * untuk semua penulis lain. Baris yang ditarik dikeluarkan dari perhitungan grup lewat
 * `withdrawn_at` + OrderDetail::scopeNotWithdrawn().
 *
 * Refund SEBAGIAN tidak menarik apa pun — bisa jadi potongan harga atau kompensasi,
 * bukan pembatalan.
 */
class OrderWithdrawalService
{
    /** Uang masuk (bukan refund) yang pernah diterima order ini. */
    public function paidIn(Order $order): int
    {
        return (int) $order->payments()
            ->where('status', 'paid')
            ->where('payment_type', '!=', 'refund')
            ->sum('amount');
    }

    /** Refund ini mengembalikan SELURUH uang yang pernah masuk. */
    public function isFullRefund(Order $order, Payment $refund): bool
    {
        return (int) $refund->amount >= $this->paidIn($order);
    }

    /**
     * @return bool true bila ordernya benar-benar ditarik (refund penuh)
     */
    public function withdraw(Order $order, Payment $refund, User $actor): bool
    {
        if (! $this->isFullRefund($order, $refund)) {
            return false;
        }

        $progress = $order->details?->titleProgress;

        DB::transaction(function () use ($order, $refund, $actor, $progress) {
            $order->update(['fulfillment_status' => 'ditarik']);

            if ($progress === null) {
                return;
            }

            $dari = $progress->stageLabelId();

            $progress->update([
                'withdrawn_at'     => now(),
                'withdrawn_reason' => $refund->refund_reason,
            ]);

            TitleProgressLog::create([
                'title_progress_id' => $progress->id,
                'event'             => 'penarikan',
                'from_value'        => $dari,
                'to_value'          => 'Ditarik',
                'changed_by'        => $actor->id,
                'note'              => 'Order ' . $order->code_order . ' di-refund penuh: '
                                     . $refund->refund_reason,
            ]);

            $progress->update(['last_log_at' => now()]);
        });

        return true;
    }
}
```

- [ ] **Step 4: Daftarkan label event log**

Di `app/Models/TitleProgressLog.php`, tambahkan ke `const EVENT_LABELS`, setelah
`'chapters_done' => 'Semua bab selesai',`:

```php
        'penarikan'        => 'Ditarik (refund)',
        'batal_penarikan'  => 'Batalkan penarikan',
```

- [ ] **Step 5: Panggil dari `RefundController::store()`**

Di `app/Http/Controllers/Pages/RefundController.php`, tepat setelah blok
`$payment = Payment::create([...]);` dan **sebelum** `SendRefundJob::dispatch(...)`,
sisipkan:

```php
        app(\App\Services\OrderWithdrawalService::class)
            ->withdraw($order, $payment, Auth::user());
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: PASS (3 test).

- [ ] **Step 7: Pastikan refund lama tidak rusak**

Run: `php artisan test --filter=Refund`
Expected: PASS semua (`RefundFlowTest`, `RefundDeliveryTest`, `RefundNotifierTest`,
`RefundPaymentModelTest`).

- [ ] **Step 8: Commit**

```bash
git add app/Services/OrderWithdrawalService.php app/Models/TitleProgressLog.php app/Http/Controllers/Pages/RefundController.php tests/Feature/OrderWithdrawalTest.php
git commit -m "refund: pengembalian penuh menarik ordernya dari judul"
```

---

## Task 6: Pencabutan bab & penulis, dengan batas ISBN

**Files:**
- Modify: `app/Services/OrderWithdrawalService.php`
- Test: `tests/Feature/OrderWithdrawalTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/OrderWithdrawalTest.php`. Tambahkan dulu
`use App\Models\Author;` di bagian atas berkas, lalu tambahkan helper dan tiga test:

```php
    /**
     * Buku kolaborasi 3 bab, tiap bab satu order + satu penulis, semua lunas.
     *
     * @return array{0: \App\Models\Title, 1: \Illuminate\Support\Collection<int,TitleProgress>}
     */
    private function bukuKolaborasi(string $status = 'editing'): array
    {
        $book = Title::create(['title' => 'Buku Refund', 'jenis' => 'buku',
                               'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $progresses = collect();
        for ($bab = 1; $bab <= 3; $bab++) {
            $chapter = $book->chapters()->create(['judul' => "Bab {$bab}", 'urutan' => $bab]);
            $author  = Author::create(['name' => "Penulis Bab {$bab}"]);
            $chapter->authors()->attach($author->id, ['position' => 1]);

            $order  = Order::factory()->create();
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => 'Buku Refund', 'title_id' => $book->id,
                'chapters' => $bab, 'cost_amount' => 1_000_000,
            ]);
            $detail->authors()->attach($author->id, ['position' => 1]);
            Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                             'amount' => 1_000_000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

            $progresses->push(TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'bidang' => 'buku', 'started_at' => now(),
            ]));
            $chapter->progress()->create(['status' => 'editing', 'started_at' => now()]);
        }

        return [$book->fresh(), $progresses];
    }

    /** @test */
    public function refund_sebelum_isbn_mencabut_bab_dan_penulisnya(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('editing');
        $order = $progresses->first()->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 1_000_000, 'reason' => 'Penulis mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $bab1 = $book->chapters()->where('urutan', 1)->first();
        $bab2 = $book->chapters()->where('urutan', 2)->first();

        $this->assertSame(0, $bab1->authors()->count(), 'penulis bab 1 harus dicabut');
        $this->assertSame('menunggu', $bab1->progress->fresh()->status);
        $this->assertSame(1, $bab2->authors()->count(), 'bab lain tidak boleh tersentuh');
    }

    /** @test */
    public function refund_setelah_isbn_hanya_mencatat_tanpa_mencabut(): void
    {
        [$book, $progresses] = $this->bukuKolaborasi('cetak');
        $order = $progresses->first()->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 1_000_000, 'reason' => 'Terlambat mundur',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $bab1 = $book->chapters()->where('urutan', 1)->first();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertSame(1, $bab1->authors()->count(),
            'ISBN sudah terdaftar: susunan bab tidak boleh diubah');
    }

    /** @test */
    public function artikel_ditarik_tanpa_menyentuh_bab(): void
    {
        $progress = $this->orderArtikel();
        $order    = $progress->orderDetail->order;

        $this->actingAs($this->superadmin())
            ->post(route('order.refund.store', $order->code_order), [
                'amount' => 500_000, 'reason' => 'Batal submit',
                'method' => 'transfer', 'tanggal' => '2026-06-05',
            ])->assertRedirect();

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
        $this->assertSame(0, $progress->orderDetail->titleRef->chapters()->count());
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: FAIL pada `refund_sebelum_isbn_mencabut_bab_dan_penulisnya` — penulis bab 1
masih 1.

- [ ] **Step 3: Tambah pencabutan bab ke service**

Di `app/Services/OrderWithdrawalService.php`, tambahkan `use App\Models\OrderDetail;`,
`use App\Models\TitleChapter;`, lalu sisipkan pemanggilan di dalam transaksi `withdraw()`,
tepat setelah `$progress->update(['last_log_at' => now()]);`:

```php
            $progress->update(['withdrawal_snapshot' => $this->lepasBab($order, $progress)]);
```

Lalu tambahkan tiga method berikut ke kelas yang sama:

```php
    /**
     * Tahap buku setelah mana bab TIDAK boleh dicabut lagi: sejak ISBN terdaftar,
     * susunan bab sudah resmi dan bukunya mungkin sudah dicetak. Uang boleh kembali,
     * karyanya tidak bisa ditarik dari peredaran.
     */
    private const BATAS_CABUT = 'isbn';

    /**
     * Cabut bab + penulis milik order yang ditarik, lalu kembalikan snapshotnya.
     *
     * Mengembalikan null (dan tidak mengubah apa pun) untuk artikel, buku mandiri,
     * atau buku yang sudah melewati BATAS_CABUT.
     */
    private function lepasBab(Order $order, TitleProgress $progress): ?array
    {
        $detail = $order->details;
        if ($detail === null || $detail->type !== 'bk_kolab') {
            return null;
        }

        if (! $this->bolehCabut($progress)) {
            return null;
        }

        $book    = $detail->titleRef;
        $chapter = $book?->chapters()->where('urutan', (int) $detail->chapters)->first();
        if ($chapter === null) {
            return null;
        }

        $snapshot = [
            'chapter_id'      => $chapter->id,
            'authors'         => $chapter->authors()
                                    ->get()
                                    ->map(fn ($a) => ['id' => $a->id, 'position' => $a->pivot->position])
                                    ->all(),
            'chapter_status'  => optional($chapter->progress)->status,
            'pelaksana_id'    => optional($chapter->progress)->pelaksana_user_id,
        ];

        $chapter->authors()->detach();

        if ($chapter->progress) {
            $chapter->progress->update([
                'status'            => 'menunggu',
                'pelaksana_user_id' => null,
                'sla_due_at'        => null,
                'started_at'        => now(),
            ]);
        }

        return $snapshot;
    }

    /** Tahap buku masih di bawah BATAS_CABUT. */
    private function bolehCabut(TitleProgress $progress): bool
    {
        $stages  = TitleProgress::BOOK_STAGES;
        $sekarang = array_search((string) $progress->status, $stages, true);
        $batas    = array_search(self::BATAS_CABUT, $stages, true);

        return $sekarang !== false && $sekarang < $batas;
    }
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: PASS (6 test).

- [ ] **Step 5: Pastikan modul bab tidak rusak**

Run: `php artisan test --filter="ChapterAuthor|NaskahBabMandiri|ProductionWorkspace"`
Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add app/Services/OrderWithdrawalService.php tests/Feature/OrderWithdrawalTest.php
git commit -m "refund: penulis yang mundur keluar dari babnya, kecuali ISBN sudah terbit"
```

---

## Task 7: Batalkan Penarikan

**Files:**
- Modify: `app/Services/OrderWithdrawalService.php`
- Modify: `app/Http/Controllers/Pages/RefundController.php`
- Modify: `routes/web.php`
- Modify: `config/permissions.php`
- Test: `tests/Feature/WithdrawalUndoTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/WithdrawalUndoTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawalUndoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        Queue::fake();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u->fresh();
    }

    /**
     * Satu bab buku kolaborasi yang sudah ditarik lewat refund penuh.
     *
     * @return array{0: Order, 1: TitleProgress, 2: \App\Models\TitleChapter, 3: Author}
     */
    private function babDitarik(): array
    {
        $book    = Title::create(['title' => 'Buku Undo', 'jenis' => 'buku',
                                  'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $chapter = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $author  = Author::create(['name' => 'Dr. Mundur']);
        $chapter->authors()->attach($author->id, ['position' => 1]);
        $chapter->progress()->create(['status' => 'editing', 'started_at' => now()]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => 'Buku Undo', 'title_id' => $book->id,
            'chapters' => 1, 'cost_amount' => 1_000_000,
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 1_000_000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        $refund = Payment::create(['order_id' => $order->id, 'payment_type' => 'refund',
                                   'amount' => 1_000_000, 'status' => 'paid',
                                   'paid_at' => '2026-06-05', 'refund_reason' => 'Salah klik']);

        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->user('superadmin'));

        return [$order->fresh(), $progress->fresh(), $chapter->fresh(), $author];
    }

    /** @test */
    public function undo_memasang_kembali_penulis_dan_bab(): void
    {
        [$order, $progress, $chapter, $author] = $this->babDitarik();

        $this->assertSame(0, $chapter->authors()->count(), 'prasyarat: sudah tercabut');

        $this->actingAs($this->user('superadmin'))
            ->post(route('order.refund.undo', $order->code_order))
            ->assertRedirect();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($progress->fresh()->withdrawn_at);
        $this->assertSame(1, $chapter->authors()->count());
        $this->assertTrue($chapter->authors()->where('tb_authors.id', $author->id)->exists());
        $this->assertSame('editing', $chapter->progress->fresh()->status);
    }

    /** @test */
    public function undo_ditolak_bila_bab_sudah_dipesan_order_lain(): void
    {
        [$order, $progress, $chapter] = $this->babDitarik();

        // Bab 1 dijual ulang ke penulis lain.
        $penerus = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $penerus->id, 'type' => 'bk_kolab',
            'title' => 'Buku Undo', 'title_id' => $progress->orderDetail->title_id,
            'chapters' => 1, 'cost_amount' => 1_000_000,
        ]);

        $this->actingAs($this->user('superadmin'))
            ->post(route('order.refund.undo', $order->code_order))
            ->assertRedirect()
            ->assertSessionHasErrors('undo');

        $this->assertSame('ditarik', $order->fresh()->fulfillment_status);
    }

    /** @test */
    public function selain_superadmin_tidak_boleh_undo(): void
    {
        [$order] = $this->babDitarik();

        $this->actingAs($this->user('admin'))
            ->post(route('order.refund.undo', $order->code_order))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=WithdrawalUndoTest`
Expected: FAIL — route `order.refund.undo` tidak ada.

- [ ] **Step 3: Tambah `undo()` ke service**

Di `app/Services/OrderWithdrawalService.php`, tambahkan
`use Illuminate\Validation\ValidationException;` lalu dua method ini:

```php
    /**
     * Batalkan penarikan: order kembali dihitung, penulis & bab dipasang ulang dari
     * snapshot.
     *
     * DITOLAK bila babnya sudah dipesan order lain sejak penarikan — memulihkannya akan
     * menabrak pemilik baru, dan pesan errornya menyebut order penabrak itu supaya
     * petugas tahu harus bicara dengan siapa.
     */
    public function undo(Order $order, User $actor): void
    {
        if (! $order->isWithdrawn()) {
            throw ValidationException::withMessages([
                'undo' => 'Order ini tidak sedang ditarik.',
            ]);
        }

        $progress = $order->details?->titleProgress;
        $snapshot = $progress?->withdrawal_snapshot;

        if ($snapshot !== null) {
            $penerus = $this->penerusBab($order);
            if ($penerus !== null) {
                throw ValidationException::withMessages([
                    'undo' => 'Bab ini sudah dipesan order ' . $penerus->order?->code_order
                            . '. Batalkan order itu dulu bila memang mau dipulihkan.',
                ]);
            }
        }

        DB::transaction(function () use ($order, $actor, $progress, $snapshot) {
            $order->update(['fulfillment_status' => 'berjalan']);

            if ($progress === null) {
                return;
            }

            if ($snapshot !== null) {
                $this->pasangUlangBab($snapshot);
            }

            $progress->update([
                'withdrawn_at'        => null,
                'withdrawn_reason'    => null,
                'withdrawal_snapshot' => null,
            ]);

            TitleProgressLog::create([
                'title_progress_id' => $progress->id,
                'event'             => 'batal_penarikan',
                'from_value'        => 'Ditarik',
                'to_value'          => $progress->stageLabelId(),
                'changed_by'        => $actor->id,
                'note'              => 'Penarikan order ' . $order->code_order . ' dibatalkan.',
            ]);

            $progress->update(['last_log_at' => now()]);
        });

        // Order tanpa detail/progress sudah dibereskan di dalam transaksi; tak ada
        // tahap naskah yang bisa diselaraskan.
        if ($progress !== null) {
            app(OrderFulfillmentService::class)->syncFromProgress($progress->fresh());
        }
    }

    /** OrderDetail lain yang sudah memesan bab yang sama sejak penarikan. */
    private function penerusBab(Order $order): ?OrderDetail
    {
        $detail = $order->details;
        if ($detail === null || $detail->type !== 'bk_kolab') {
            return null;
        }

        return OrderDetail::with('order')
            ->where('title_id', $detail->title_id)
            ->where('type', 'bk_kolab')
            ->where('chapters', $detail->chapters)
            ->where('id', '!=', $detail->id)
            ->first();
    }

    /** Pasang kembali penulis & status bab persis seperti sebelum penarikan. */
    private function pasangUlangBab(array $snapshot): void
    {
        $chapter = TitleChapter::find($snapshot['chapter_id'] ?? null);
        if ($chapter === null) {
            return;
        }

        $pivot = [];
        foreach ($snapshot['authors'] ?? [] as $a) {
            $pivot[$a['id']] = ['position' => $a['position']];
        }
        $chapter->authors()->sync($pivot);

        if ($chapter->progress && ($snapshot['chapter_status'] ?? null) !== null) {
            $chapter->progress->update([
                'status'            => $snapshot['chapter_status'],
                'pelaksana_user_id' => $snapshot['pelaksana_id'] ?? null,
            ]);
        }
    }
```

- [ ] **Step 4: Tambah aksi di controller**

Di `app/Http/Controllers/Pages/RefundController.php`, tambahkan method di bawah `store()`:

```php
    public function undo(string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order = $this->findOrder($code);

        app(\App\Services\OrderWithdrawalService::class)->undo($order, Auth::user());

        return redirect()->route('order.book.index')
            ->with('success', 'Penarikan order dibatalkan; bab dan penulisnya dipasang kembali.');
    }
```

- [ ] **Step 5: Daftarkan route**

Di `routes/web.php`, tepat setelah baris 78 (`refund.pdf`):

```php
        Route::post('refund/{code_order}/undo', [\App\Http\Controllers\Pages\RefundController::class, 'undo'])->name('refund.undo');
```

- [ ] **Step 6: Daftarkan izin**

Di `config/permissions.php`, ganti baris `'refund' => [...]`:

```php
                'refund' => ['order.refund.form', 'order.refund.store', 'order.refund.pdf',
                             'order.refund.undo'],
```

`EnforcePermission` fail-closed — tanpa langkah ini route baru langsung 403 dan
`PermissionMapCompletenessTest` merah.

- [ ] **Step 7: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WithdrawalUndoTest`
Expected: PASS (3 test).

- [ ] **Step 8: Pastikan peta izin utuh**

Run: `php artisan test --filter="PermissionMapCompleteness|AccessParity|RouteSmoke"`
Expected: PASS semua.

- [ ] **Step 9: Commit**

```bash
git add app/Services/OrderWithdrawalService.php app/Http/Controllers/Pages/RefundController.php routes/web.php config/permissions.php tests/Feature/WithdrawalUndoTest.php
git commit -m "refund: penarikan yang salah bisa dibatalkan, penulis dan bab kembali utuh"
```

---

## Task 8: Order dibatalkan juga mencabut penulis basi (R3)

Bug yang sudah aktif hari ini, bukan bawaan fitur baru: `OrderCancellationService::cancel()`
tidak pernah menyentuh `tb_title_chapter_authors`.

**Files:**
- Modify: `app/Services/OrderCancellationService.php`
- Test: `tests/Feature/ChapterAuthorCleanupTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ChapterAuthorCleanupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChapterAuthorCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u->fresh();
    }

    /** @return array{0: Order, 1: \App\Models\TitleChapter, 2: Author} */
    private function babBerpenulis(): array
    {
        $book    = Title::create(['title' => 'Buku Batal', 'jenis' => 'buku',
                                  'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $chapter = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $author  = Author::create(['name' => 'Dr. Batal']);
        $chapter->authors()->attach($author->id, ['position' => 1]);
        $chapter->progress()->create(['status' => 'editing', 'started_at' => now()]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => 'Buku Batal', 'title_id' => $book->id,
            'chapters' => 1, 'cost_amount' => 1_000_000,
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        return [$order->fresh(), $chapter->fresh(), $author];
    }

    /** @test */
    public function order_dibatalkan_mencabut_penulis_dari_babnya(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis();

        app(OrderCancellationService::class)->cancel($order, 'Klien batal', $this->superadmin());

        $this->assertSame(0, $chapter->authors()->count());
    }

    /** @test */
    public function order_dibatalkan_juga_berhenti_sebagai_pekerjaan(): void
    {
        [$order] = $this->babBerpenulis();

        app(OrderCancellationService::class)->cancel($order, 'Klien batal', $this->superadmin());

        $this->assertSame('dibatalkan', $order->fresh()->fulfillment_status);
    }

    /** @test */
    public function pemulihan_order_mengembalikan_status_pekerjaan(): void
    {
        [$order] = $this->babBerpenulis();
        $sa = $this->superadmin();

        app(OrderCancellationService::class)->cancel($order, 'Klien batal', $sa);
        app(OrderCancellationService::class)->restore($order->fresh(), $sa);

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
    }

    /** @test */
    public function pemulihan_order_memasang_penulisnya_kembali(): void
    {
        [$order, $chapter, $author] = $this->babBerpenulis();
        $sa = $this->superadmin();

        app(OrderCancellationService::class)->cancel($order, 'Klien batal', $sa);
        app(OrderCancellationService::class)->restore($order->fresh(), $sa);

        $this->assertSame(1, $chapter->authors()->count());
        $this->assertTrue($chapter->authors()->where('tb_authors.id', $author->id)->exists());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ChapterAuthorCleanupTest`
Expected: FAIL pada test pertama — penulis masih 1 setelah pembatalan.

- [ ] **Step 3: Cabut saat cancel**

Di `app/Services/OrderCancellationService.php`, di dalam `DB::transaction` milik `cancel()`,
sisipkan **sebelum** `TitleProgress::whereIn('order_detail_id', $detailIds)->delete();`:

```php
            $this->lepasPenulisBab($order);
```

Lalu tambahkan method ini ke kelas yang sama:

```php
    /**
     * Cabut penulis order ini dari babnya, dengan menyimpan snapshotnya di
     * tb_title_progress.withdrawal_snapshot supaya restore() bisa memasangnya kembali.
     *
     * Tanpa ini penulis dari order yang dibatalkan tetap tercantum di bab selamanya:
     * ChapterAuthorService::remapFromOrders() sengaja MELEWATI bab yang ordernya sudah
     * hilang, jadi ia tidak akan pernah membersihkannya.
     */
    private function lepasPenulisBab(Order $order): void
    {
        $detail = $order->details;
        if ($detail === null || $detail->type !== 'bk_kolab') {
            return;
        }

        $chapter = $detail->titleRef?->chapters()
            ->where('urutan', (int) $detail->chapters)->first();
        if ($chapter === null) {
            return;
        }

        $progress = $detail->titleProgress;
        if ($progress !== null) {
            $progress->update(['withdrawal_snapshot' => [
                'chapter_id'     => $chapter->id,
                'authors'        => $chapter->authors()->get()
                                        ->map(fn ($a) => ['id' => $a->id, 'position' => $a->pivot->position])
                                        ->all(),
                'chapter_status' => optional($chapter->progress)->status,
                'pelaksana_id'   => optional($chapter->progress)->pelaksana_user_id,
            ]]);
        }

        $chapter->authors()->detach();
    }
```

- [ ] **Step 3b: Tulis `fulfillment_status` saat cancel**

Ditemukan saat review Task 1: `cancel()` tidak pernah menyentuh `fulfillment_status`, dan
karena TitleProgress ikut soft-deleted, `OrderFulfillmentService::syncFromProgress()` tak
akan pernah menyalakannya. Tanpa langkah ini setiap order yang dibatalkan terbaca
`berjalan` selamanya — persis perpecahan makna yang kolom ini dibuat untuk mencegah.
Task 9 hanya menambal data lama; ini menambal pembatalan yang akan datang.

Di `app/Services/OrderCancellationService.php`, di dalam `cancel()`, ubah blok
`$order->update([...])` menjadi:

```php
            $order->update([
                'status'             => 'dibatalkan',
                'fulfillment_status' => 'dibatalkan',
                'cancel_reason'      => $reason,
                'cancelled_by'       => $actor->id,
                'cancelled_at'       => now(),
            ]);
```

- [ ] **Step 4: Pasang kembali saat restore**

Di `app/Services/OrderCancellationService.php`, di dalam `DB::transaction` milik `restore()`,
sisipkan **setelah** `TitleProgress::onlyTrashed()->whereIn('order_detail_id', $detailIds)->restore();`:

```php
            $this->pasangPenulisBab($order->fresh());
```

Lalu tambahkan method:

```php
    /** Kebalikan lepasPenulisBab(): pasang kembali dari snapshot, lalu buang snapshotnya. */
    private function pasangPenulisBab(Order $order): void
    {
        $progress = $order->details?->titleProgress;
        $snapshot = $progress?->withdrawal_snapshot;
        if ($snapshot === null) {
            return;
        }

        $chapter = \App\Models\TitleChapter::find($snapshot['chapter_id'] ?? null);
        if ($chapter !== null) {
            $pivot = [];
            foreach ($snapshot['authors'] ?? [] as $a) {
                $pivot[$a['id']] = ['position' => $a['position']];
            }
            $chapter->authors()->sync($pivot);
        }

        $progress->update(['withdrawal_snapshot' => null]);
    }
```

- [ ] **Step 4b: Kembalikan `fulfillment_status` saat restore**

Di `app/Services/OrderCancellationService.php`, di dalam `restore()`, ubah blok
`$order->update([...])` menjadi:

```php
            $order->update([
                'status'             => $this->statusAfterRestore($order),
                'fulfillment_status' => 'berjalan',
                'cancel_reason'      => null,
                'cancelled_by'       => null,
                'cancelled_at'       => null,
            ]);
```

`berjalan` bukan `selesai`, meski naskahnya mungkin sudah final: pemanggilan
`OrderFulfillmentService::syncFromProgress()` di baris berikutnya yang menentukan
nilai sebenarnya, dan ia perlu menemukan order dalam keadaan bukan-akhir supaya
tidak keluar lebih awal lewat gerbang `in_array(['dibatalkan','ditarik'])`.

Tepat setelah blok `DB::transaction(...)` di `restore()` (di luar transaksi, sebelum
blok notifikasi `try`), tambahkan:

```php
        $progress = $order->fresh()->details?->titleProgress;
        if ($progress !== null) {
            app(OrderFulfillmentService::class)->syncFromProgress($progress);
        }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ChapterAuthorCleanupTest`
Expected: PASS (4 test).

- [ ] **Step 6: Pastikan pembatalan lama tidak rusak**

Run: `php artisan test --filter="OrderCancel|OrderRestore"`
Expected: PASS semua.

- [ ] **Step 7: Commit**

```bash
git add app/Services/OrderCancellationService.php tests/Feature/ChapterAuthorCleanupTest.php
git commit -m "order: pembatalan tak lagi meninggalkan penulis basi di daftar bab"
```

---

## Task 9: Backfill data lama

**Files:**
- Create: `database/migrations/2026_08_20_000004_backfill_order_fulfillment.php`
- Test: `tests/Feature/OrderFulfillmentTest.php`

- [ ] **Step 1: Tulis migrasi**

Migrasi backfill tidak diuji lewat unit test (di DB test ia berjalan atas tabel kosong).
Yang menguji kebenarannya adalah langkah verifikasi manual di Step 3.

Buat `database/migrations/2026_08_20_000004_backfill_order_fulfillment.php`:

```php
<?php
// database/migrations/2026_08_20_000004_backfill_order_fulfillment.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi fulfillment_status untuk order yang sudah ada.
 *
 * WAJIB memakai DB::table(), BUKAN model Eloquent: Order, OrderDetail, dan TitleProgress
 * ketiganya memakai SoftDeletes, dan migrasi yang meng-query modelnya pecah saat
 * `migrate:fresh` — membuat seluruh suite merah dengan gejala yang menyesatkan. Sudah
 * terjadi tiga kali di repo ini.
 *
 * Urutan penilaian dari yang paling menang: dibatalkan > ditarik > selesai > berjalan.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Naskah yang sudah final → selesai. completed_at dari archived_at progress.
        DB::statement("
            UPDATE tb_orders o
            JOIN tb_order_details d ON d.order_id = o.id
            JOIN tb_title_progress p ON p.order_detail_id = d.id
            SET o.fulfillment_status = 'selesai',
                o.completed_at = COALESCE(o.completed_at, p.archived_at, p.updated_at)
            WHERE p.status IN ('terbit', 'publish')
        ");

        // 2. Order yang pernah di-refund → ditarik (menang atas 'selesai').
        DB::statement("
            UPDATE tb_orders o
            JOIN tb_payments pay ON pay.order_id = o.id
            SET o.fulfillment_status = 'ditarik'
            WHERE pay.payment_type = 'refund' AND pay.status = 'paid'
        ");

        // 3. Order dibatalkan → dibatalkan (menang atas semuanya).
        DB::statement("
            UPDATE tb_orders
            SET fulfillment_status = 'dibatalkan'
            WHERE status = 'dibatalkan' OR deleted_at IS NOT NULL
        ");

        // 4. Tandai withdrawn_at pada progress milik order yang ditarik, supaya
        //    scopeActive & notWithdrawn langsung konsisten dengan kolom order.
        DB::statement("
            UPDATE tb_title_progress p
            JOIN tb_order_details d ON d.id = p.order_detail_id
            JOIN tb_orders o ON o.id = d.order_id
            SET p.withdrawn_at = COALESCE(p.withdrawn_at, o.updated_at),
                p.withdrawn_reason = COALESCE(p.withdrawn_reason, 'Refund (backfill)')
            WHERE o.fulfillment_status = 'ditarik'
        ");
    }

    public function down(): void
    {
        DB::table('tb_orders')->update(['fulfillment_status' => 'berjalan', 'completed_at' => null]);
        DB::table('tb_title_progress')->update(['withdrawn_at' => null, 'withdrawn_reason' => null]);
    }
};
```

- [ ] **Step 2: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua. Kalau merah dengan galat SQL, kemungkinan besar nama tabel/kolom
salah ketik — periksa terhadap `database/migrations/2025_12_19_040542_create_tb_orders_table.php`.

- [ ] **Step 3: Jalankan di DB dev dan periksa hasilnya**

```bash
php artisan migrate
```

Lalu periksa sebarannya:

```bash
php artisan tinker --execute="print_r(\DB::table('tb_orders')->selectRaw('fulfillment_status, COUNT(*) c')->groupBy('fulfillment_status')->get()->toArray());"
```

Expected: mayoritas `berjalan`, sebagian `selesai`, dan jumlah `dibatalkan` sama dengan
`SELECT COUNT(*) FROM tb_orders WHERE status='dibatalkan' OR deleted_at IS NOT NULL`.

Tanpa langkah ini aplikasi live 500 di kolom yang belum ada — test hijau memakai DB test,
bukan `avidpedi_simapa`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_20_000004_backfill_order_fulfillment.php
git commit -m "order: data lama ikut menyandang status pekerjaan"
```

---

## Task 10: Arsip Judul dapat bagian "Siap Diarsipkan"

**Files:**
- Modify: `app/Models/Title.php` (helper `sisaTagihan`)
- Modify: `app/Http/Controllers/Pages/TitleArchiveController.php` (`index`, `show`)
- Modify: `app/Services/AdminDashboardService.php`
- Modify: `resources/views/archive/index.blade.php`
- Modify: `resources/views/archive/show.blade.php`
- Test: `tests/Feature/ArchiveSiapDiarsipkanTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ArchiveSiapDiarsipkanTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArchiveSiapDiarsipkanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u->fresh();
    }

    /** Artikel yang naskahnya sudah publish, dibayar $dibayar dari biaya 500rb. */
    private function judulFinal(string $judul, int $dibayar): Title
    {
        $title  = Title::create(['title' => $judul, 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $judul, 'title_id' => $title->id, 'cost_amount' => 500_000,
        ]);
        if ($dibayar > 0) {
            Payment::create(['order_id' => $order->id, 'payment_type' => 'dp',
                             'amount' => $dibayar, 'status' => 'paid', 'paid_at' => now()]);
        }
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'publish',
            'bidang' => 'artikel', 'started_at' => now(), 'archived_at' => now(),
        ]);

        return $title->fresh();
    }

    /** @test */
    public function judul_final_tanpa_arsip_muncul_di_siap_diarsipkan(): void
    {
        $this->judulFinal('Sudah Publish', 500_000);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee('Siap Diarsipkan')
            ->assertSee('Sudah Publish');
    }

    /** @test */
    public function kekurangan_bayar_disebut_angkanya(): void
    {
        $this->judulFinal('Kurang Bayar', 200_000);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee('300.000');
    }

    /** @test */
    public function judul_yang_belum_final_tidak_muncul(): void
    {
        $title = $this->judulFinal('Masih Editing', 500_000);
        $title->orderDetails->first()->titleProgress
              ->update(['status' => 'editing', 'archived_at' => null]);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertDontSee('Masih Editing');
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ArchiveSiapDiarsipkanTest`
Expected: FAIL — halaman belum memuat "Siap Diarsipkan".

- [ ] **Step 3: Tambah helper sisa tagihan di `Title`**

Di `app/Models/Title.php`, tambahkan tepat di bawah `isPaidOff()`:

```php
    /**
     * Total kekurangan bayar seluruh order judul ini (0 bila lunas).
     * Order yang ditarik tidak dihitung — uangnya sudah dikembalikan.
     */
    public function sisaTagihan(): int
    {
        return (int) $this->orderDetails
            ->reject(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->sum(function ($d) {
                $order = $d->order;
                if ($order === null) {
                    return 0;
                }

                return max(0, (int) $d->cost_amount - $order->paidNet());
            });
    }

    /** Jumlah order judul ini yang ditarik karena refund. */
    public function jumlahDitarik(): int
    {
        return $this->orderDetails
            ->filter(fn ($d) => optional($d->titleProgress)->withdrawn_at !== null)
            ->count();
    }
```

- [ ] **Step 4: Isi daftar `siap` di controller**

Di `app/Http/Controllers/Pages/TitleArchiveController.php`, ganti seluruh `index()`:

```php
    public function index()
    {
        abort_unless($this->canManage(), 403);

        $approved = Title::whereHas('archive', fn ($q) => $q->where('status', 'disetujui'))
            ->with('archive.approver')->latest()->get();

        $pending = $this->canApprove()
            ? Title::whereHas('archive', fn ($q) => $q->where('status', 'diajukan'))->with('archive.submitter')->latest()->get()
            : collect();

        return view('archive.index', [
            'siap'       => $this->siapDiarsipkan(),
            'approved'   => $approved,
            'pending'    => $pending,
            'canApprove' => $this->canApprove(),
        ]);
    }

    /**
     * Judul yang naskahnya sudah final tapi belum diajukan/disetujui arsipnya.
     *
     * Inilah satu-satunya pintu masuk ke halaman detail arsip untuk judul baru: sebelum
     * ini `archive.show` hanya ditaut dari daftar judul yang SUDAH punya baris arsip,
     * sehingga arsip praktis tak bisa diajukan sama sekali.
     *
     * Penyaringan tahap final dilakukan di PHP, bukan SQL: `manuscriptStatus()` adalah
     * bottleneck lintas order yang tak punya padanan SQL. Pra-saring `whereHas` menekan
     * jumlah baris yang perlu dihitung.
     */
    private function siapDiarsipkan()
    {
        return Title::query()
            ->whereDoesntHave('archive', fn ($q) => $q->whereIn('status', ['diajukan', 'disetujui']))
            ->whereHas('orderDetails.titleProgress', fn ($q) => $q->whereIn('status', ['terbit', 'publish']))
            ->with(['orderDetails.titleProgress', 'orderDetails.order.payments'])
            ->latest()
            ->get()
            ->filter->manuscriptIsFinal()
            ->values();
    }
```

- [ ] **Step 5: Tambah bagian di view**

Di `resources/views/archive/index.blade.php`, sisipkan tepat setelah baris
`<h5 class="mb-3">Arsip Judul</h5>`:

```blade
@if($siap->isNotEmpty())
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card border-info"><div class="card-body">
    <h6 class="card-title">Siap Diarsipkan ({{ $siap->count() }})</h6>
    <p class="text-muted small mb-2">Naskahnya sudah final. Lengkapi artefaknya lalu ajukan ke arsip.</p>
    <div class="table-responsive">
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Naskah</th><th>Pembayaran</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($siap as $t)
                    @php $sisa = $t->sisaTagihan(); $ditarik = $t->jumlahDitarik(); @endphp
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td><span class="badge bg-success">{{ $t->manuscriptStatusLabel() }}</span></td>
                        <td>
                            @if($sisa > 0)
                                <span class="badge bg-danger">Kurang Rp {{ number_format($sisa, 0, ',', '.') }}</span>
                            @else
                                <span class="badge bg-success">Lunas</span>
                            @endif
                            @if($ditarik > 0)
                                <span class="badge bg-secondary">{{ $ditarik }} ditarik</span>
                            @endif
                        </td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-info">Siapkan</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endif
```

- [ ] **Step 6: Perjelas kartu kelayakan di halaman detail**

Di `app/Http/Controllers/Pages/TitleArchiveController.php`, method `show()`, tambahkan dua
kunci ke array yang dikirim ke view (setelah `'isPaidOff' => $title->isPaidOff(),`):

```php
            'sisaTagihan'  => $title->sisaTagihan(),
            'jumlahDitarik' => $title->jumlahDitarik(),
```

Di `resources/views/archive/show.blade.php`, ganti baris lencana pembayaran:

```blade
    <span class="badge {{ $isPaidOff ? 'bg-success' : 'bg-danger' }}">
        Pembayaran {{ $isPaidOff ? 'Lunas' : 'Belum Lunas — kurang Rp ' . number_format($sisaTagihan, 0, ',', '.') }}
    </span>
    @if($jumlahDitarik > 0)
        <span class="badge bg-secondary">{{ $jumlahDitarik }} order ditarik</span>
    @endif
```

Lalu di tabel **Info Order**, ganti sel Pembayaran:

```blade
                <td>
                    @if($od->order?->isWithdrawn())
                        <span class="badge bg-secondary">Ditarik · Refund</span>
                    @elseif($od->order && $od->order->isLunas())
                        <span class="badge bg-success">Lunas</span>
                    @else
                        <span class="badge bg-danger">Kurang Rp {{ number_format(max(0, (int) $od->cost_amount - (int) optional($od->order)->paidNet()), 0, ',', '.') }}</span>
                    @endif
                </td>
```

- [ ] **Step 7: Hidupkan ubin dashboard**

Di `app/Services/AdminDashboardService.php`, ganti baris `arsip_menunggu_artefak`:

```php
            // Judul final yang arsipnya belum diajukan/disetujui. Dulu menghitung
            // TitleArchive berstatus 'draft' — baris yang tak pernah dibuat kode mana pun,
            // sehingga ubinnya selalu 0.
            'arsip_menunggu_artefak' => Title::query()
                ->whereDoesntHave('archive', fn ($q) => $q->whereIn('status', ['diajukan', 'disetujui']))
                ->whereHas('orderDetails.titleProgress', fn ($q) => $q->whereIn('status', ['terbit', 'publish']))
                ->count(),
```

- [ ] **Step 8: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ArchiveSiapDiarsipkanTest`
Expected: PASS (3 test).

- [ ] **Step 9: Pastikan halaman arsip lama tidak rusak**

Run: `php artisan test --filter="TitleArchive|ArchivePdf|ArchiveGroupedTitles|AdminDashboard|DashboardRoleRouting"`
Expected: PASS semua.

- [ ] **Step 10: Commit**

```bash
git add app/Models/Title.php app/Http/Controllers/Pages/TitleArchiveController.php app/Services/AdminDashboardService.php resources/views/archive/index.blade.php resources/views/archive/show.blade.php tests/Feature/ArchiveSiapDiarsipkanTest.php
git commit -m "arsip: judul yang siap diarsipkan akhirnya punya daftar dan pintu masuk"
```

---

## Task 11: Lencana di daftar order dan Arsip Naskah

**Files:**
- Modify: `resources/views/orders/book/index.blade.php`
- Modify: `resources/views/naskah/arsip.blade.php`
- Modify: `app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php` (baris 65-66)
- Test: `tests/Feature/OrderWithdrawalTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/OrderWithdrawalTest.php`:

```php
    /** @test */
    public function daftar_order_menampilkan_lencana_pekerjaan(): void
    {
        $progress = $this->orderArtikel('publish');
        $progress->orderDetail->order->update([
            'fulfillment_status' => 'selesai', 'completed_at' => now(),
        ]);

        $this->actingAs($this->superadmin())->get(route('order.book.index'))
            ->assertOk()
            ->assertSee('Selesai');
    }

    /** @test */
    public function order_ditarik_tampil_berlencana_ditarik(): void
    {
        $progress = $this->orderArtikel();
        $progress->orderDetail->order->update(['fulfillment_status' => 'ditarik']);

        $this->actingAs($this->superadmin())->get(route('order.book.index'))
            ->assertOk()
            ->assertSee('Ditarik');
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: FAIL pada dua test baru.

- [ ] **Step 3: Tambah kolom lencana di daftar order**

Di `resources/views/orders/book/index.blade.php`, tambahkan header baru setelah
`<th>Status Order</th>` (baris 43):

```blade
                                        <th>Pekerjaan</th>
```

Lalu tambahkan sel baru tepat setelah `</td>` penutup sel Status Order (setelah baris 82):

```blade
                                            <td>
                                                @switch($order->fulfillment_status)
                                                    @case('selesai')
                                                        <span class="badge bg-success">Selesai</span>
                                                        @break
                                                    @case('ditarik')
                                                        <span class="badge bg-secondary">Ditarik</span>
                                                        @break
                                                    @case('dibatalkan')
                                                        <span class="badge bg-dark">Dibatalkan</span>
                                                        @break
                                                    @default
                                                        <span class="badge bg-info">Berjalan</span>
                                                @endswitch
                                            </td>
```

- [ ] **Step 4: Tampilkan naskah ditarik di Arsip Naskah**

Di `app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php`, ganti blok baris
65-66:

```php
                fn (Builder $q) => $q->whereNotNull('cancelled_at'),
                fn (Builder $q) => $q->whereNotNull('archived_at')
                                     ->whereNull('cancelled_at')
                                     ->whereNull('withdrawn_at'))
```

Lalu di `resources/views/naskah/arsip.blade.php`, pada sel "Tahap Akhir", bungkus nilainya:

```blade
                            {{ $p->stageLabelId() }}
                            @if($p->isWithdrawn())
                                <span class="badge bg-secondary">Ditarik — Refund</span>
                            @endif
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderWithdrawalTest`
Expected: PASS (8 test).

- [ ] **Step 6: Pastikan halaman order & pelacakan tidak rusak**

Run: `php artisan test --filter="OrderCancel|OrderRestore|OrderPemilik|NaskahPelacakan|NaskahLayar|DetailOrderPaymentInvoice"`
Expected: PASS semua. Suite penuh dijalankan sekali di Pemeriksaan akhir, bukan di sini
(~18 menit sekali jalan).

- [ ] **Step 7: Commit**

```bash
git add resources/views/orders/book/index.blade.php resources/views/naskah/arsip.blade.php app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php tests/Feature/OrderWithdrawalTest.php
git commit -m "tampilan: keadaan pekerjaan order terbaca sekilas di daftar dan arsip"
```

---

## Pemeriksaan akhir

- [ ] **Seluruh suite hijau**

Run: `php artisan test`
Expected: PASS semua. Baseline sebelum pekerjaan ini: **1111 lulus, 1 dilewati, 0 gagal**
(diukur 2026-08-20). Angkanya harus naik sekitar 21 test baru, tak ada yang hilang.

Suite penuh butuh **~18 menit**. Itu sebabnya hanya Task 4 dan pemeriksaan akhir ini yang
menjalankannya utuh; tugas lain memakai `--filter` yang menyasar modul terdampak.

- [ ] **DB dev sudah dimigrasikan**

Run: `php artisan migrate:status | tail -5`
Expected: tiga migrasi baru berstatus `Ran`.

- [ ] **Periksa di browser**

Buka dan pastikan tidak ada galat:
1. `/order/book` — kolom Pekerjaan muncul dengan lencana
2. `/management/archive` — bagian "Siap Diarsipkan" muncul dan tabelnya ber-DataTables
3. `/management/archive/{id}` — kekurangan bayar tersebut angkanya
4. `/naskah/arsip` — naskah ditarik berlencana
5. Dashboard admin — ubin "Arsip Menunggu Artefak" tidak lagi 0 bila ada judul final

- [ ] **Belum di-push**

Branch `feat/sinkronisasi-status-order-naskah` tetap lokal sampai pengguna memutuskan.
Gunakan skill `superpowers:finishing-a-development-branch` untuk memilih merge / PR.
