# Tagihan + Invoice Marketing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti alur "proforma invoice" yang membingungkan dengan entitas **Tagihan** (buat manual → approve superadmin → download PDF → Buat Order dari Tagihan via alur normal), perbaiki bug DataTables di Invoice index, dan restrukturisasi sidebar role-aware.

**Architecture:** Tabel baru `tb_tagihan` + `tb_tagihan_logs` (berdiri sendiri, tak menumpang `tb_invoices`). `TagihanController` CRUD + approval + PDF + `buatOrder` (redirect ke order create dengan `?from_tagihan=` untuk prefill ringan + link-back). Invoice jadi murni sistem (form manual dihapus). Reuse DomPDF, DataTables (`datatables.net-bs4`), Spatie Permission. Order existing di-extend prefill, tidak dirombak; `PaymentBookController` auto-invoice tak diubah.

**Tech Stack:** Laravel 10, Spatie Permission, Blade + Bootstrap, DomPDF, DataTables, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-17-tagihan-invoice-marketing-design.md`

> **Branch:** `Fitur` (jangan merge). **Commit:** author `WellkitDev` (git config sudah di-set); akhiri tiap pesan commit dengan `Co-Authored-By: Mira <admin@avidpedia.com>` (BUKAN "Claude"). `git add` path eksplisit saja; jangan commit file lokal-only (`template-web/`, `avidpedi_simapa.sql`, `database/seeders/*`, `.gitignore`, `public/error_log`, design HTML).
>
> **Testing:** `php artisan test` (otomatis `.env.testing`). Suite saat ini **137 passed** — harus tetap hijau.

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `database/migrations/2026_06_17_000001_create_tb_tagihan_table.php` | Tabel tagihan |
| Create | `database/migrations/2026_06_17_000002_create_tb_tagihan_logs_table.php` | Audit log |
| Create | `app/Models/Tagihan.php` | Model + relasi + STATUSES + helper |
| Create | `app/Models/TagihanLog.php` | Model log |
| Create | `database/factories/TagihanFactory.php` | Factory test |
| Create | `app/Http/Controllers/Pages/TagihanController.php` | CRUD + approve/reject/cancel/pdf/buatOrder |
| Create | `app/Support/TagihanPdfData.php` | Data PDF |
| Create | `resources/views/payments/tagihan/index.blade.php` | Daftar (DataTables) |
| Create | `resources/views/payments/tagihan/create.blade.php` | Form buat/edit |
| Create | `resources/views/payments/tagihan/show.blade.php` | Detail + log + aksi |
| Create | `resources/views/payments/tagihan/tagihan_pdf.blade.php` | Template PDF |
| Modify | `routes/web.php` | Routes tagihan + hapus route create/store invoice |
| Modify | `app/Http/Controllers/Pages/InvoiceController.php` | Hapus `create()`/`store()` |
| Modify | `resources/views/payments/invoices/index.blade.php` | Fix bug DataTables + Download gated + hapus tombol Buat |
| Delete | `resources/views/payments/invoices/create.blade.php` | Form manual invoice |
| Modify | `app/Http/Controllers/Pages/OrderBookController.php` | `create()` prefill + `store()` link-back |
| Modify | `resources/views/orders/book/create.blade.php` | Field skalar pakai `old(...,$prefill)` + hidden from_tagihan |
| Modify | `resources/views/layouts/sidebar.blade.php` | Restructure role-aware + rename |
| Modify | `tests/Feature/InvoiceLifecycleTest.php` | Hapus test `marketing_can_create_proforma_invoice` |
| Create | `tests/Feature/TagihanLifecycleTest.php` | Lifecycle/approval/scoping/prefill/no-double-count |
| Create | `tests/Feature/InvoiceIndexTest.php` | Empty tanpa error, download gating, route create absen |

---

## Task 1: Migrations + Models + Factory

**Files:**
- Create: `database/migrations/2026_06_17_000001_create_tb_tagihan_table.php`
- Create: `database/migrations/2026_06_17_000002_create_tb_tagihan_logs_table.php`
- Create: `app/Models/Tagihan.php`
- Create: `app/Models/TagihanLog.php`
- Create: `database/factories/TagihanFactory.php`
- Create: `tests/Unit/TagihanModelTest.php`

- [ ] **Step 1: Tulis unit test (failing)** — `tests/Unit/TagihanModelTest.php`

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use App\Models\TagihanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TagihanModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_tagihan_with_creator_and_log(): void
    {
        $user = User::factory()->create();

        $tagihan = Tagihan::create([
            'tagihan_no'  => 'TAG-202606-0001',
            'created_by'  => $user->id,
            'client_name' => 'PT Contoh',
            'title'       => 'Naskah X',
            'type'        => 'buku',
            'amount'      => 1500000,
            'status'      => 'diajukan',
        ]);

        $log = TagihanLog::create([
            'tagihan_id'  => $tagihan->id,
            'from_status' => '',
            'to_status'   => 'diajukan',
            'changed_by'  => $user->id,
            'note'        => 'Tagihan dibuat.',
        ]);

        $this->assertEquals($user->id, $tagihan->creator->id);
        $this->assertEquals(1, $tagihan->logs()->count());
        $this->assertEquals('diajukan', $log->to_status);
        $this->assertTrue($tagihan->isEditable());           // diajukan → editable

        $tagihan->update(['status' => 'disetujui']);
        $this->assertFalse($tagihan->isEditable());          // disetujui → terkunci
        $this->assertTrue($tagihan->isDownloadable());       // disetujui → boleh download
    }

    /** @test */
    public function factory_makes_valid_tagihan(): void
    {
        $t = Tagihan::factory()->create();
        $this->assertNotNull($t->tagihan_no);
        $this->assertContains($t->status, Tagihan::STATUSES);
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=TagihanModelTest`
Expected: FAIL — class `App\Models\Tagihan` belum ada.

- [ ] **Step 3: Buat migration tabel** — `database/migrations/2026_06_17_000001_create_tb_tagihan_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('tagihan_no', 50)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->string('client_phone', 50)->nullable();
            $table->string('client_institution')->nullable();
            $table->string('title');
            $table->enum('type', ['buku', 'jurnal'])->default('buku');
            $table->text('author_names')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('due_at')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('diajukan');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_note')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('tb_orders')->nullOnDelete();
            $table->string('order_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan');
    }
};
```

- [ ] **Step 4: Buat migration log** — `database/migrations/2026_06_17_000002_create_tb_tagihan_logs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_tagihan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_id')->constrained('tb_tagihan')->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan_logs');
    }
};
```

- [ ] **Step 5: Buat model** — `app/Models/Tagihan.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tagihan extends Model
{
    use HasFactory;

    protected $table = 'tb_tagihan';

    protected $fillable = [
        'tagihan_no', 'created_by',
        'client_name', 'client_email', 'client_phone', 'client_institution',
        'title', 'type', 'author_names', 'description', 'amount', 'due_at', 'note',
        'status', 'approved_by', 'approved_at', 'reject_note',
        'order_id', 'order_code',
    ];

    protected $casts = [
        'amount'      => 'integer',
        'due_at'      => 'date',
        'approved_at' => 'datetime',
    ];

    const STATUSES = ['diajukan', 'disetujui', 'ditolak', 'jadi_order', 'dibatalkan'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function logs()
    {
        return $this->hasMany(TagihanLog::class);
    }

    /** Boleh diedit hanya saat menunggu (diajukan) atau ditolak. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['diajukan', 'ditolak']);
    }

    /** PDF & kirim ke klien hanya setelah disetujui. */
    public function isDownloadable(): bool
    {
        return in_array($this->status, ['disetujui', 'jadi_order']);
    }

    /** Boleh dikonversi jadi order. */
    public function canConvert(): bool
    {
        return $this->status === 'disetujui';
    }
}
```

- [ ] **Step 6: Buat model log** — `app/Models/TagihanLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagihanLog extends Model
{
    protected $table = 'tb_tagihan_logs';

    public $timestamps = false;

    protected $fillable = [
        'tagihan_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tagihan()
    {
        return $this->belongsTo(Tagihan::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

- [ ] **Step 7: Buat factory** — `database/factories/TagihanFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagihanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tagihan_no'  => 'TAG-' . date('Ym') . '-' . $this->faker->unique()->numerify('####'),
            'created_by'  => User::factory(),
            'client_name' => $this->faker->company(),
            'client_email' => $this->faker->safeEmail(),
            'client_phone' => $this->faker->numerify('08##########'),
            'title'       => $this->faker->sentence(3),
            'type'        => 'buku',
            'amount'      => $this->faker->numberBetween(500000, 5000000),
            'status'      => 'diajukan',
        ];
    }
}
```

> Catatan: model `Tagihan` butuh `use HasFactory` (sudah ada di Step 5) agar `Tagihan::factory()` ter-resolve ke `Database\Factories\TagihanFactory`.

- [ ] **Step 8: Jalankan — pastikan PASS**

Run: `php artisan test --filter=TagihanModelTest`
Expected: PASS (2 test).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_06_17_000001_create_tb_tagihan_table.php database/migrations/2026_06_17_000002_create_tb_tagihan_logs_table.php app/Models/Tagihan.php app/Models/TagihanLog.php database/factories/TagihanFactory.php tests/Unit/TagihanModelTest.php
git commit -m "$(printf 'feat: add Tagihan + TagihanLog models, migrations, factory\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 2: TagihanController CRUD + routes + views + scoping

**Files:**
- Create: `app/Http/Controllers/Pages/TagihanController.php`
- Modify: `routes/web.php`
- Create: `resources/views/payments/tagihan/index.blade.php`
- Create: `resources/views/payments/tagihan/create.blade.php`
- Create: `resources/views/payments/tagihan/show.blade.php`
- Create: `tests/Feature/TagihanLifecycleTest.php`

- [ ] **Step 1: Tulis feature test (failing)** — `tests/Feature/TagihanLifecycleTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TagihanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'client_name'  => 'PT Klien',
            'client_email' => 'klien@contoh.id',
            'client_phone' => '08123456789',
            'title'        => 'Naskah Tagihan',
            'type'         => 'buku',
            'author_names' => 'Budi, Sari',
            'description'  => 'Jasa penerbitan',
            'amount'       => 2000000,
            'due_at'       => now()->addDays(14)->toDateString(),
            'note'         => 'Catatan',
        ], $override);
    }

    /** @test */
    public function marketing_creates_tagihan_as_diajukan_with_log(): void
    {
        $me = $this->user('marketing');
        $this->actingAs($me);

        $this->post(route('tagihan.store'), $this->validPayload())->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', [
            'created_by' => $me->id, 'title' => 'Naskah Tagihan', 'status' => 'diajukan',
        ]);
        $tagihan = Tagihan::where('created_by', $me->id)->first();
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $tagihan->id, 'to_status' => 'diajukan']);
        $this->assertStringStartsWith('TAG-', $tagihan->tagihan_no);
    }

    /** @test */
    public function marketing_only_sees_own_tagihan_in_index(): void
    {
        $me  = $this->user('marketing');
        $other = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $me->id, 'title' => 'MILIK SAYA']);
        Tagihan::factory()->create(['created_by' => $other->id, 'title' => 'MILIK ORANG LAIN']);

        $this->actingAs($me);
        $this->get(route('tagihan.index'))
            ->assertOk()
            ->assertSee('MILIK SAYA')
            ->assertDontSee('MILIK ORANG LAIN');
    }

    /** @test */
    public function manager_sees_all_tagihan(): void
    {
        $mkt = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $mkt->id, 'title' => 'TAGIHAN MARKETING']);

        $this->actingAs($this->user('manager'));
        $this->get(route('tagihan.index'))->assertOk()->assertSee('TAGIHAN MARKETING');
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: FAIL — route `tagihan.store` belum ada.

- [ ] **Step 3: Buat controller** — `app/Http/Controllers/Pages/TagihanController.php`

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Models\Tagihan;
use App\Models\TagihanLog;
use App\Support\TagihanPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TagihanController extends Controller
{
    public function index()
    {
        $tagihan = Tagihan::with('creator')
            ->when($this->marketingOnly(), fn ($q) => $q->where('created_by', Auth::id()))
            ->latest()
            ->get();

        return view('payments.tagihan.index', compact('tagihan'));
    }

    public function create()
    {
        return view('payments.tagihan.create', ['tagihan' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $id = DB::transaction(function () use ($data) {
            $tagihan = Tagihan::create([
                ...$data,
                'tagihan_no' => $this->generateNo(),
                'created_by' => Auth::id(),
                'status'     => 'diajukan',
            ]);
            $this->log($tagihan, '', 'diajukan', 'Tagihan dibuat.');
            return $tagihan->id;
        });

        return redirect()->route('tagihan.show', $id)->with('success', 'Tagihan dibuat & diajukan.');
    }

    public function show(int $id)
    {
        $tagihan = $this->scopedQuery()->with(['creator', 'approver', 'logs.changedBy'])->findOrFail($id);
        return view('payments.tagihan.show', compact('tagihan'));
    }

    public function edit(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isEditable() && $this->canManage($tagihan), 403);
        return view('payments.tagihan.create', compact('tagihan'));
    }

    public function update(Request $request, int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isEditable() && $this->canManage($tagihan), 403);

        $data = $this->validateData($request);
        DB::transaction(function () use ($tagihan, $data) {
            // Edit setelah ditolak → ajukan ulang.
            $resubmit = $tagihan->status === 'ditolak';
            $tagihan->update([...$data, 'status' => $resubmit ? 'diajukan' : $tagihan->status]);
            if ($resubmit) {
                $this->log($tagihan, 'ditolak', 'diajukan', 'Diajukan ulang setelah revisi.');
            }
        });

        return redirect()->route('tagihan.show', $tagihan->id)->with('success', 'Tagihan diperbarui.');
    }

    public function approve(int $id)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $tagihan = Tagihan::findOrFail($id);
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from) {
            $tagihan->update(['status' => 'disetujui', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->log($tagihan, $from, 'disetujui', 'Tagihan disetujui.');
        });

        return back()->with('success', 'Tagihan disetujui.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $request->validate(['note' => 'required|string']);
        $tagihan = Tagihan::findOrFail($id);
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from, $request) {
            $tagihan->update(['status' => 'ditolak', 'reject_note' => $request->note]);
            $this->log($tagihan, $from, 'ditolak', $request->note);
        });

        return back()->with('warning', 'Tagihan ditolak.');
    }

    public function cancel(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($this->canManage($tagihan), 403);
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from) {
            $tagihan->update(['status' => 'dibatalkan']);
            $this->log($tagihan, $from, 'dibatalkan', 'Tagihan dibatalkan.');
        });

        return back()->with('warning', 'Tagihan dibatalkan.');
    }

    public function pdf(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isDownloadable(), 403, 'Tagihan belum disetujui.');

        $data = TagihanPdfData::for($tagihan);
        return Pdf::loadView('payments.tagihan.tagihan_pdf', $data)
            ->stream('Tagihan_' . $tagihan->tagihan_no . '.pdf');
    }

    public function buatOrder(int $id)
    {
        $tagihan = Tagihan::where('id', $id)->where('created_by', Auth::id())->firstOrFail();
        if (! $tagihan->canConvert()) {
            return back()->with('error', 'Tagihan harus disetujui dan belum jadi order.');
        }
        $route = $tagihan->type === 'jurnal' ? 'order.journal.create' : 'order.book.create';
        return redirect()->route($route, ['from_tagihan' => $tagihan->id]);
    }

    // --- helpers ---

    private function marketingOnly(): bool
    {
        return Auth::user()->hasRole('marketing') && ! Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    private function scopedQuery()
    {
        return Tagihan::query()->when($this->marketingOnly(), fn ($q) => $q->where('created_by', Auth::id()));
    }

    private function canManage(Tagihan $tagihan): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']) || $tagihan->created_by === Auth::id();
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'client_name'        => 'required|string|max:255',
            'client_email'       => 'nullable|email',
            'client_phone'       => 'nullable|string|max:50',
            'client_institution' => 'nullable|string|max:255',
            'title'              => 'required|string|max:255',
            'type'               => 'required|in:buku,jurnal',
            'author_names'       => 'nullable|string',
            'description'        => 'nullable|string',
            'amount'             => 'required|integer|min:0',
            'due_at'             => 'nullable|date',
            'note'               => 'nullable|string',
        ]);
    }

    private function generateNo(): string
    {
        $ym   = date('Ym');
        $last = Tagihan::where('tagihan_no', 'like', "TAG-{$ym}-%")->lockForUpdate()->latest('id')->first();
        $seq  = $last ? intval(substr($last->tagihan_no, -4)) + 1 : 1;
        return "TAG-{$ym}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    private function log(Tagihan $tagihan, string $from, string $to, ?string $note): void
    {
        TagihanLog::create([
            'tagihan_id'  => $tagihan->id,
            'from_status' => $from,
            'to_status'   => $to,
            'changed_by'  => Auth::id(),
            'note'        => $note,
        ]);
    }
}
```

- [ ] **Step 4: Tambah routes** — di `routes/web.php`, TEPAT SETELAH penutup grup `Route::prefix('invoices')...` (baris dengan `});` di akhir grup invoice), sisipkan grup baru:

```php
    Route::prefix('tagihan')->name('tagihan.')->group(function () {
        Route::get('',              [\App\Http\Controllers\Pages\TagihanController::class, 'index'])->name('index');
        Route::get('create',        [\App\Http\Controllers\Pages\TagihanController::class, 'create'])->name('create');
        Route::post('',             [\App\Http\Controllers\Pages\TagihanController::class, 'store'])->name('store');
        Route::get('{id}',          [\App\Http\Controllers\Pages\TagihanController::class, 'show'])->name('show');
        Route::get('{id}/edit',     [\App\Http\Controllers\Pages\TagihanController::class, 'edit'])->name('edit');
        Route::put('{id}',          [\App\Http\Controllers\Pages\TagihanController::class, 'update'])->name('update');
        Route::post('{id}/approve', [\App\Http\Controllers\Pages\TagihanController::class, 'approve'])->name('approve');
        Route::post('{id}/reject',  [\App\Http\Controllers\Pages\TagihanController::class, 'reject'])->name('reject');
        Route::post('{id}/cancel',  [\App\Http\Controllers\Pages\TagihanController::class, 'cancel'])->name('cancel');
        Route::get('{id}/pdf',      [\App\Http\Controllers\Pages\TagihanController::class, 'pdf'])->name('pdf');
        Route::get('{id}/buat-order', [\App\Http\Controllers\Pages\TagihanController::class, 'buatOrder'])->name('buatOrder');
    });
```

> Pastikan grup ini berada di dalam middleware-group yang sama dengan invoice (auth). Jika file memakai `use App\Http\Controllers\Pages\TagihanController;` di atas, boleh ganti FQCN panjang dengan `TagihanController::class`.

- [ ] **Step 5: Buat view index** — `resources/views/payments/tagihan/index.blade.php`

```blade
@extends('layouts.master')
@section('title', 'Daftar Tagihan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $statusColors = [
        'diajukan' => 'secondary', 'disetujui' => 'success', 'ditolak' => 'danger',
        'jadi_order' => 'primary', 'dibatalkan' => 'dark',
    ];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Daftar Tagihan</h6>
                    <a href="{{ route('tagihan.create') }}" class="btn btn-sm btn-primary">+ Buat Tagihan</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>No Tagihan</th><th>Klien</th><th>Judul</th><th>Tipe</th>
                                <th>Nominal</th><th>Status</th><th>Jatuh Tempo</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tagihan as $t)
                            <tr>
                                <td><strong>{{ $t->tagihan_no }}</strong></td>
                                <td>{{ $t->client_name }}</td>
                                <td>{{ Str::limit($t->title, 30) }}</td>
                                <td><span class="badge bg-{{ $t->type === 'jurnal' ? 'info' : 'secondary' }}">{{ ucfirst($t->type) }}</span></td>
                                <td>Rp {{ number_format($t->amount, 0, ',', '.') }}</td>
                                <td><span class="badge bg-{{ $statusColors[$t->status] ?? 'secondary' }}">{{ Str::title(str_replace('_',' ',$t->status)) }}</span></td>
                                <td data-order="{{ $t->due_at ? $t->due_at->timestamp : 0 }}">{{ $t->due_at ? $t->due_at->format('d M Y') : '-' }}</td>
                                <td>
                                    <a href="{{ route('tagihan.show', $t->id) }}" class="btn btn-xs btn-primary">Detail</a>
                                    @if($t->isDownloadable())
                                        <a href="{{ route('tagihan.pdf', $t->id) }}" class="btn btn-xs btn-outline-secondary" target="_blank">PDF</a>
                                    @endif
                                    @if($t->canConvert() && $t->created_by === auth()->id())
                                        <a href="{{ route('tagihan.buatOrder', $t->id) }}" class="btn btn-xs btn-success">Buat Order</a>
                                    @endif
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
            pageLength: 25,
            responsive: true,
            columnDefs: [{ orderable: false, targets: 7 }],
            language: { emptyTable: "Belum ada tagihan." }
        });
    });
</script>
@endpush
```

- [ ] **Step 6: Buat view create/edit** — `resources/views/payments/tagihan/create.blade.php`

```blade
@extends('layouts.master')
@section('title', ($tagihan ? 'Edit' : 'Buat') . ' Tagihan - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-9">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">{{ $tagihan ? 'Edit Tagihan' : 'Buat Tagihan Baru' }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ $tagihan ? route('tagihan.update', $tagihan->id) : route('tagihan.store') }}">
                    @csrf
                    @if($tagihan) @method('PUT') @endif

                    <h6 class="text-muted">Data Klien</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Klien <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" class="form-control @error('client_name') is-invalid @enderror" value="{{ old('client_name', $tagihan->client_name ?? '') }}" required>
                            @error('client_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Institusi</label>
                            <input type="text" name="client_institution" class="form-control" value="{{ old('client_institution', $tagihan->client_institution ?? '') }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="client_email" class="form-control" value="{{ old('client_email', $tagihan->client_email ?? '') }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Telepon</label>
                            <input type="text" name="client_phone" class="form-control" value="{{ old('client_phone', $tagihan->client_phone ?? '') }}">
                        </div>
                    </div>

                    <h6 class="text-muted mt-2">Data Naskah & Tagihan</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $tagihan->title ?? '') }}" required>
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tipe <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required>
                                @foreach(['buku' => 'Buku', 'jurnal' => 'Jurnal'] as $val => $lbl)
                                    <option value="{{ $val }}" {{ old('type', $tagihan->type ?? 'buku') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Author</label>
                            <input type="text" name="author_names" class="form-control" placeholder="pisahkan dengan koma" value="{{ old('author_names', $tagihan->author_names ?? '') }}">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Deskripsi / Rincian</label>
                            <textarea name="description" class="form-control" rows="2">{{ old('description', $tagihan->description ?? '') }}</textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror" min="0" value="{{ old('amount', $tagihan->amount ?? '') }}" required>
                            @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Jatuh Tempo</label>
                            <input type="date" name="due_at" class="form-control" value="{{ old('due_at', optional($tagihan->due_at ?? null)->toDateString()) }}">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Catatan</label>
                            <textarea name="note" class="form-control" rows="2">{{ old('note', $tagihan->note ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $tagihan ? 'Simpan Perubahan' : 'Simpan & Ajukan' }}</button>
                        <a href="{{ route('tagihan.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 7: Buat view show** — `resources/views/payments/tagihan/show.blade.php`

```blade
@extends('layouts.master')
@section('title', 'Detail Tagihan - SiMAPA')

@section('content')
@php
    $statusColors = [
        'diajukan' => 'secondary', 'disetujui' => 'success', 'ditolak' => 'danger',
        'jadi_order' => 'primary', 'dibatalkan' => 'dark',
    ];
    $isSuperadmin = auth()->user()->hasRole('superadmin');
    $isOwner = $tagihan->created_by === auth()->id();
@endphp
<div class="row">
    <div class="col-md-8">
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between">
                <h5 class="mb-0">{{ $tagihan->tagihan_no }}</h5>
                <span class="badge bg-{{ $statusColors[$tagihan->status] ?? 'secondary' }}">{{ Str::title(str_replace('_',' ',$tagihan->status)) }}</span>
            </div>
            <hr>
            <dl class="row mb-0">
                <dt class="col-sm-3">Klien</dt><dd class="col-sm-9">{{ $tagihan->client_name }} @if($tagihan->client_institution) — {{ $tagihan->client_institution }} @endif</dd>
                <dt class="col-sm-3">Kontak</dt><dd class="col-sm-9">{{ $tagihan->client_email ?: '-' }} / {{ $tagihan->client_phone ?: '-' }}</dd>
                <dt class="col-sm-3">Judul</dt><dd class="col-sm-9">{{ $tagihan->title }} ({{ ucfirst($tagihan->type) }})</dd>
                <dt class="col-sm-3">Author</dt><dd class="col-sm-9">{{ $tagihan->author_names ?: '-' }}</dd>
                <dt class="col-sm-3">Deskripsi</dt><dd class="col-sm-9">{{ $tagihan->description ?: '-' }}</dd>
                <dt class="col-sm-3">Nominal</dt><dd class="col-sm-9"><strong>Rp {{ number_format($tagihan->amount, 0, ',', '.') }}</strong></dd>
                <dt class="col-sm-3">Jatuh Tempo</dt><dd class="col-sm-9">{{ $tagihan->due_at ? $tagihan->due_at->format('d M Y') : '-' }}</dd>
                @if($tagihan->status === 'ditolak')<dt class="col-sm-3 text-danger">Alasan Tolak</dt><dd class="col-sm-9">{{ $tagihan->reject_note }}</dd>@endif
                @if($tagihan->order_code)<dt class="col-sm-3">Order</dt><dd class="col-sm-9">{{ $tagihan->order_code }}</dd>@endif
            </dl>
        </div></div>

        <div class="card"><div class="card-body">
            <h6 class="card-title">Log Status</h6>
            <ul class="list-unstyled mb-0">
                @foreach($tagihan->logs->sortByDesc('created_at') as $log)
                    <li class="mb-2">
                        <span class="badge bg-light text-dark border">{{ $log->from_status ?: '—' }} → {{ $log->to_status }}</span>
                        <small class="text-muted">oleh {{ optional($log->changedBy)->name ?? 'sistem' }} · {{ $log->created_at->format('d M Y H:i') }}</small>
                        @if($log->note)<div><small>{{ $log->note }}</small></div>@endif
                    </li>
                @endforeach
            </ul>
        </div></div>
    </div>

    <div class="col-md-4">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Aksi</h6>
            @if($tagihan->isDownloadable())
                <a href="{{ route('tagihan.pdf', $tagihan->id) }}" target="_blank" class="btn btn-outline-secondary w-100 mb-2">Download PDF</a>
            @endif
            @if($tagihan->canConvert() && $isOwner)
                <a href="{{ route('tagihan.buatOrder', $tagihan->id) }}" class="btn btn-success w-100 mb-2">Buat Order dari Tagihan</a>
            @endif
            @if($tagihan->isEditable() && ($isOwner || auth()->user()->hasAnyRole(['manager','superadmin'])))
                <a href="{{ route('tagihan.edit', $tagihan->id) }}" class="btn btn-outline-primary w-100 mb-2">Edit</a>
            @endif
            @if($isSuperadmin && $tagihan->status === 'diajukan')
                <form method="POST" action="{{ route('tagihan.approve', $tagihan->id) }}" class="mb-2">@csrf
                    <button class="btn btn-success w-100">Approve</button>
                </form>
                <form method="POST" action="{{ route('tagihan.reject', $tagihan->id) }}">@csrf
                    <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="Alasan tolak" required>
                    <button class="btn btn-danger w-100">Tolak</button>
                </form>
            @endif
            @if(in_array($tagihan->status, ['diajukan','disetujui']) && ($isOwner || $isSuperadmin))
                <form method="POST" action="{{ route('tagihan.cancel', $tagihan->id) }}" class="mt-2">@csrf
                    <button class="btn btn-outline-dark w-100">Batalkan</button>
                </form>
            @endif
        </div></div>
    </div>
</div>
@endsection
```

- [ ] **Step 8: Jalankan — pastikan PASS**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: PASS (3 test). (Test approval/pdf/buatOrder ditambah di Task 3–5.)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Pages/TagihanController.php routes/web.php resources/views/payments/tagihan/index.blade.php resources/views/payments/tagihan/create.blade.php resources/views/payments/tagihan/show.blade.php tests/Feature/TagihanLifecycleTest.php
git commit -m "$(printf 'feat: Tagihan CRUD (index/create/store/show/edit/update) + routes + views\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 3: Approval lifecycle (approve/reject/cancel) — tests

**Files:**
- Modify: `tests/Feature/TagihanLifecycleTest.php`

> Controller approve/reject/cancel + view aksi sudah dibuat di Task 2. Task ini menambah coverage dan memverifikasi gating.

- [ ] **Step 1: Tambah test ke `tests/Feature/TagihanLifecycleTest.php`** (di dalam class, setelah test terakhir)

```php
    /** @test */
    public function superadmin_approves_tagihan_sets_disetujui_with_log(): void
    {
        $t = Tagihan::factory()->create(['status' => 'diajukan']);
        $this->actingAs($this->user('superadmin'));

        $this->post(route('tagihan.approve', $t->id))->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'disetujui']);
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $t->id, 'to_status' => 'disetujui']);
        $this->assertNotNull($t->fresh()->approved_at);
    }

    /** @test */
    public function marketing_cannot_approve_tagihan(): void
    {
        $t = Tagihan::factory()->create(['status' => 'diajukan']);
        $this->actingAs($this->user('marketing'));
        $this->post(route('tagihan.approve', $t->id))->assertStatus(403);
    }

    /** @test */
    public function superadmin_rejects_with_note_then_owner_resubmits(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);

        $this->actingAs($this->user('superadmin'));
        $this->post(route('tagihan.reject', $t->id), ['note' => 'Nominal salah'])->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'ditolak', 'reject_note' => 'Nominal salah']);

        // owner edit & ajukan ulang
        $this->actingAs($owner);
        $this->put(route('tagihan.update', $t->id), $this->validPayload(['amount' => 3000000]))->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'diajukan', 'amount' => 3000000]);
    }

    /** @test */
    public function owner_can_cancel_tagihan(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);

        $this->actingAs($owner);
        $this->post(route('tagihan.cancel', $t->id))->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'dibatalkan']);
    }

    /** @test */
    public function cannot_edit_tagihan_after_approved(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);
        $this->actingAs($owner);
        $this->get(route('tagihan.edit', $t->id))->assertStatus(403);
    }
```

- [ ] **Step 2: Jalankan — pastikan PASS**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: PASS (8 test total).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/TagihanLifecycleTest.php
git commit -m "$(printf 'test: tagihan approval lifecycle (approve/reject/resubmit/cancel/edit-gating)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 4: Tagihan PDF (data + blade + gating)

**Files:**
- Create: `app/Support/TagihanPdfData.php`
- Create: `resources/views/payments/tagihan/tagihan_pdf.blade.php`
- Modify: `tests/Feature/TagihanLifecycleTest.php`

- [ ] **Step 1: Tambah test (failing)** ke `tests/Feature/TagihanLifecycleTest.php`

```php
    /** @test */
    public function pdf_downloadable_only_after_approved_by_owner(): void
    {
        $owner = $this->user('marketing');
        $diajukan = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);
        $disetujui = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);

        $this->actingAs($owner);
        $this->get(route('tagihan.pdf', $diajukan->id))->assertStatus(403);
        $resp = $this->get(route('tagihan.pdf', $disetujui->id))->assertOk();
        $this->assertEquals('application/pdf', $resp->headers->get('content-type'));
    }
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=pdf_downloadable_only_after_approved_by_owner`
Expected: FAIL — `App\Support\TagihanPdfData` / view PDF belum ada.

- [ ] **Step 3: Buat support** — `app/Support/TagihanPdfData.php`

```php
<?php

namespace App\Support;

use App\Models\Tagihan;

class TagihanPdfData
{
    /** @return array{tagihan: Tagihan} */
    public static function for(Tagihan $tagihan): array
    {
        $tagihan->loadMissing('creator');
        return compact('tagihan');
    }
}
```

- [ ] **Step 4: Buat blade PDF** — `resources/views/payments/tagihan/tagihan_pdf.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .head { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }
        .head h1 { margin: 0; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td, th { padding: 6px 8px; border: 1px solid #ccc; text-align: left; }
        .right { text-align: right; }
        .muted { color: #666; }
        .total { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="head">
        <h1>TAGIHAN</h1>
        <div class="muted">No: {{ $tagihan->tagihan_no }} · {{ $tagihan->created_at->format('d M Y') }}</div>
    </div>

    <table>
        <tr><th width="30%">Kepada</th><td>{{ $tagihan->client_name }}@if($tagihan->client_institution) — {{ $tagihan->client_institution }}@endif</td></tr>
        <tr><th>Kontak</th><td>{{ $tagihan->client_email ?: '-' }} / {{ $tagihan->client_phone ?: '-' }}</td></tr>
        <tr><th>Judul</th><td>{{ $tagihan->title }} ({{ ucfirst($tagihan->type) }})</td></tr>
        <tr><th>Author</th><td>{{ $tagihan->author_names ?: '-' }}</td></tr>
        <tr><th>Rincian</th><td>{{ $tagihan->description ?: '-' }}</td></tr>
        <tr><th>Jatuh Tempo</th><td>{{ $tagihan->due_at ? $tagihan->due_at->format('d M Y') : '-' }}</td></tr>
    </table>

    <table>
        <tr><th class="right" width="70%">TOTAL TAGIHAN</th><td class="right total">Rp {{ number_format($tagihan->amount, 0, ',', '.') }}</td></tr>
    </table>

    @if($tagihan->note)
        <p class="muted" style="margin-top:16px">Catatan: {{ $tagihan->note }}</p>
    @endif

    <p class="muted" style="margin-top:24px">Diterbitkan oleh {{ optional($tagihan->creator)->name }} · SiMAPA Avidpedia</p>
</body>
</html>
```

- [ ] **Step 5: Jalankan — pastikan PASS**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: PASS (9 test).

- [ ] **Step 6: Commit**

```bash
git add app/Support/TagihanPdfData.php resources/views/payments/tagihan/tagihan_pdf.blade.php tests/Feature/TagihanLifecycleTest.php
git commit -m "$(printf 'feat: tagihan PDF (DomPDF) with download gated to approved\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 5: Buat Order dari Tagihan (prefill ringan + link-back)

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php`
- Modify: `resources/views/orders/book/create.blade.php`
- Modify: `tests/Feature/TagihanLifecycleTest.php`

> v1 mendukung penuh jalur **buku** (prefill + link-back). Tagihan tipe **jurnal**: `buatOrder` mengarah ke `order.journal.create` dengan `from_tagihan` dan link-back diterapkan di journal store (langkah opsional di akhir); prefill form jurnal ditunda (lihat §13 spec).

- [ ] **Step 1: Tambah test (failing)** ke `tests/Feature/TagihanLifecycleTest.php`

```php
    /** @test */
    public function buat_order_redirects_to_book_create_with_prefill_param(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create([
            'created_by' => $owner->id, 'status' => 'disetujui',
            'type' => 'buku', 'title' => 'JUDUL TAGIHAN', 'client_email' => 'a@b.id',
        ]);

        $this->actingAs($owner);
        $this->get(route('tagihan.buatOrder', $t->id))
            ->assertRedirect(route('order.book.create', ['from_tagihan' => $t->id]));

        // form create menampilkan nilai prefill
        $this->get(route('order.book.create', ['from_tagihan' => $t->id]))
            ->assertOk()
            ->assertSee('JUDUL TAGIHAN', false)
            ->assertSee('a@b.id', false);
    }

    /** @test */
    public function buat_order_blocked_when_not_disetujui(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);
        $this->actingAs($owner);
        $this->get(route('tagihan.buatOrder', $t->id))->assertRedirect(); // back with error, tidak ke create
        $this->assertEquals('diajukan', $t->fresh()->status);
    }

    /** @test */
    public function approved_tagihan_not_counted_as_income_until_order(): void
    {
        $owner = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui', 'amount' => 5000000]);

        $svc = app(\App\Services\MarketingDashboardService::class)->forUser($owner);
        $this->assertEquals(0, $svc['pemasukan_tahun_ini']); // tagihan ≠ pemasukan (tak ada Payment)
    }
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: FAIL — `from_tagihan` belum di-handle di `OrderBookController@create`.

- [ ] **Step 3: Modify `OrderBookController@create()`** — ganti method `create()` (saat ini di sekitar baris 122-127) menjadi:

```php
    public function create(Request $request)
    {
        $scopes = Scope::all();

        $prefill = [];
        $fromTagihan = null;
        if ($request->filled('from_tagihan')) {
            $t = \App\Models\Tagihan::where('id', $request->integer('from_tagihan'))
                ->where('created_by', Auth::id())
                ->where('status', 'disetujui')
                ->first();
            if ($t) {
                $fromTagihan = $t->id;
                $prefill = [
                    'title'         => $t->title,
                    'contact_email' => $t->client_email,
                    'contact_phone' => $t->client_phone,
                    'cost_amount'   => $t->amount,
                    'note'          => $t->note,
                ];
            }
        }

        return \view('orders.book.create', \compact('scopes', 'prefill', 'fromTagihan'));
    }
```

> `Request $request` perlu di-import — `use Illuminate\Http\Request;` sudah ada di file (dipakai `store`). `Auth` sudah di-import.

- [ ] **Step 4: Modify `OrderBookController@store()`** — di dalam blok `try {`, SETELAH `$newOrder = DB::transaction(...)` selesai dan SEBELUM `return redirect()->route('payment.create', ...)` (sekitar baris 238), sisipkan link-back:

```php
            // Link-back: jika order dibuat dari tagihan, tandai tagihan jadi_order.
            if ($request->filled('from_tagihan')) {
                $t = \App\Models\Tagihan::where('id', $request->integer('from_tagihan'))
                    ->where('created_by', Auth::id())
                    ->where('status', 'disetujui')
                    ->first();
                if ($t) {
                    $t->update([
                        'status'     => 'jadi_order',
                        'order_id'   => $newOrder->id,
                        'order_code' => $newOrder->code_order,
                    ]);
                    \App\Models\TagihanLog::create([
                        'tagihan_id'  => $t->id,
                        'from_status' => 'disetujui',
                        'to_status'   => 'jadi_order',
                        'changed_by'  => Auth::id(),
                        'note'        => 'Order ' . $newOrder->code_order . ' dibuat dari tagihan.',
                    ]);
                }
            }
```

- [ ] **Step 5: Modify `resources/views/orders/book/create.blade.php`** — dua perubahan:

(a) Tepat setelah `@csrf` (baris ~13), tambahkan hidden input:
```blade
            @if(!empty($fromTagihan))<input type="hidden" name="from_tagihan" value="{{ $fromTagihan }}">@endif
```

(b) Tambahkan default prefill ke 5 input berikut (cari berdasarkan atribut `name`), ubah/menambahkan `value`:
- `name="title"` → `value="{{ old('title', $prefill['title'] ?? '') }}"`
- `name="cost_amount"` → `value="{{ old('cost_amount', $prefill['cost_amount'] ?? '') }}"`
- `name="contact_email"` → `value="{{ old('contact_email', $prefill['contact_email'] ?? '') }}"`
- `name="contact_phone"` → `value="{{ old('contact_phone', $prefill['contact_phone'] ?? '') }}"`
- `name="note"` (textarea) → isi konten: `{{ old('note', $prefill['note'] ?? '') }}`

> `$prefill` selalu didefinisikan controller (default `[]`), jadi `$prefill['x'] ?? ''` aman saat form dibuka tanpa tagihan. Field `title` saat ini `<input type="text" name="title" class="form-control" required>` (baris ~40) — tambahkan atribut `value`.

- [ ] **Step 6: Jalankan — pastikan PASS**

Run: `php artisan test --filter=TagihanLifecycleTest`
Expected: PASS (12 test).

- [ ] **Step 7 (opsional jurnal — link-back saja):** Buka controller di balik `order.journal.store` (cari `->name('order.journal.store')` di `routes/web.php` untuk controller@method-nya). Di method store-nya, setelah order jurnal tersimpan & sebelum redirect, sisipkan blok link-back yang IDENTIK dengan Step 4 (variabel order hasil mungkin bernama beda — sesuaikan `$newOrder` ke variabel order yang benar). Jika struktur store jurnal berbeda jauh, lewati langkah ini dan catat di laporan (prefill+link jurnal = follow-up).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/OrderBookController.php resources/views/orders/book/create.blade.php tests/Feature/TagihanLifecycleTest.php
git commit -m "$(printf 'feat: Buat Order dari Tagihan (book prefill + jadi_order link-back)\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 6: Fix Invoice index + hapus create manual

**Files:**
- Modify: `resources/views/payments/invoices/index.blade.php`
- Modify: `app/Http/Controllers/Pages/InvoiceController.php`
- Modify: `routes/web.php`
- Delete: `resources/views/payments/invoices/create.blade.php`
- Modify: `tests/Feature/InvoiceLifecycleTest.php`
- Create: `tests/Feature/InvoiceIndexTest.php`

- [ ] **Step 1: Tulis test (failing)** — `tests/Feature/InvoiceIndexTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function empty_invoice_index_renders_without_manual_colspan_row(): void
    {
        $this->actingAs($this->user('manager'));
        $html = $this->get(route('invoice.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('colspan="7"', $html); // baris empty colspan manual (sumber bug) sudah hilang
    }

    /** @test */
    public function download_button_shown_only_for_issued_or_paid_invoice(): void
    {
        $manager = $this->user('manager');
        $order = Order::factory()->create();
        OrderDetail::factory()->create(['order_id' => $order->id]);
        $draft  = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'draft', 'invoice_no' => 'INV-DRAFT']);
        $issued = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'diterbitkan', 'invoice_no' => 'INV-ISSUED']);

        $this->actingAs($manager);
        $html = $this->get(route('invoice.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('invoice.pdf', $issued->id), $html);
        $this->assertStringNotContainsString(route('invoice.pdf', $draft->id), $html);
    }

    /** @test */
    public function manual_invoice_create_route_is_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('invoice.create'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('invoice.store'));
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=InvoiceIndexTest`
Expected: FAIL — empty-state colspan masih ada / route create masih ada / Download belum digate.

- [ ] **Step 3: Hapus route create/store invoice** — di `routes/web.php` grup `Route::prefix('invoices')...`, HAPUS dua baris:
```php
        Route::get('create',       [InvoiceController::class, 'create'])->name('create');
        Route::post('',            [InvoiceController::class, 'store'])->name('store');
```

- [ ] **Step 4: Hapus method `create()` dan `store()`** di `app/Http/Controllers/Pages/InvoiceController.php` (baris ~30-68). Method lain (`index`, `show`, `edit`, `update`, `updateStatus`, `cancel`, `refund`, `logs`, `pdf`) tetap.

- [ ] **Step 5: Hapus file** `resources/views/payments/invoices/create.blade.php`

```bash
git rm resources/views/payments/invoices/create.blade.php
```

- [ ] **Step 6: Perbaiki `resources/views/payments/invoices/index.blade.php`**:

(a) Hapus tombol Buat Invoice — baris:
```blade
                    <a href="{{ route('invoice.create') }}" class="btn btn-sm btn-primary">+ Buat Invoice</a>
```

(b) Ganti `@forelse ... @empty <tr><td colspan="7">...</td></tr> @endforelse` menjadi `@foreach ... @endforeach` (hilangkan baris `@empty` colspan). Yakni: ubah `@forelse($invoices as $inv)` → `@foreach($invoices as $inv)`, dan hapus blok:
```blade
                            @empty
                            <tr><td colspan="7" class="text-center text-muted">Belum ada invoice.</td></tr>
                            @endforelse
```
ganti penutup loop menjadi `@endforeach`.

(c) Tambah tombol Download (gated) di sel Aksi, setelah tombol Detail:
```blade
                                    @if(in_array($inv->status, ['diterbitkan','lunas']))
                                        <a href="{{ route('invoice.pdf', $inv->id) }}" target="_blank" class="btn btn-xs btn-outline-secondary">Download</a>
                                    @endif
```

(d) Aktifkan DataTables penuh — ganti blok `@push('custom-scripts')` init:
```blade
@push('custom-scripts')
<script>
    $(function() {
        $(".datatable").DataTable({
            pageLength: 25,
            responsive: true,
            columnDefs: [{ orderable: false, targets: 6 }],
            language: { emptyTable: "Belum ada invoice." }
        });
    });
</script>
@endpush
```
(Sebelumnya `searching:false, ordering:false` → sekarang cari/sort aktif; kolom Aksi (index 6) non-orderable.)

- [ ] **Step 7: Hapus test create proforma** — di `tests/Feature/InvoiceLifecycleTest.php`, HAPUS seluruh method `marketing_can_create_proforma_invoice()` (baris 37-55). Test lain (pakai `Invoice::factory()`) tetap — `proforma` masih ada di `Invoice::TYPES`/factory, jadi tidak rusak.

- [ ] **Step 8: Jalankan — pastikan PASS + tak ada regresi**

Run: `php artisan test --filter=InvoiceIndexTest`
Expected: PASS (3 test).
Run: `php artisan test --filter=InvoiceLifecycleTest`
Expected: PASS (5 test — create proforma sudah dihapus).

- [ ] **Step 9: Commit**

```bash
git add resources/views/payments/invoices/index.blade.php app/Http/Controllers/Pages/InvoiceController.php routes/web.php resources/views/payments/invoices/create.blade.php tests/Feature/InvoiceLifecycleTest.php tests/Feature/InvoiceIndexTest.php
git commit -m "$(printf 'fix: invoice index DataTables (empty-state + sort) + gated download; retire manual invoice create\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 7: Sidebar restructure (role-aware) + rename

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`
- Create: `tests/Feature/SidebarTagihanTest.php`

- [ ] **Step 1: Tulis test (failing)** — `tests/Feature/SidebarTagihanTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class SidebarTagihanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function marketing_sidebar_shows_tagihan_link(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('tagihan.index'))
            ->assertSee('Tagihan');
    }

    /** @test */
    public function production_sidebar_hides_tagihan(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('tagihan.index'));
    }
}
```

- [ ] **Step 2: Jalankan — pastikan FAIL**

Run: `php artisan test --filter=SidebarTagihanTest`
Expected: FAIL — link tagihan belum ada di sidebar.

- [ ] **Step 3: Restruktur `resources/views/layouts/sidebar.blade.php`** sesuai spec §9. Perubahan:

(a) Bungkus grup Order/Naskah, Produksi, Pembayaran agar urut: **Pembayaran** dulu (di atas), lalu Order & Naskah, lalu Produksi, lalu Laporan. (Pindahkan blok `<li class="nav-item nav-category">Pembayaran</li>` + isinya ke ATAS sebelum blok `Order & Naskah`.)

(b) Di grup Pembayaran, jadikan strukturnya (role `superadmin|manager|marketing`):
```blade
            @role(['superadmin', 'manager', 'marketing'])
                <li class="nav-item nav-category">Pembayaran</li>
                <li class="nav-item {{ request()->routeIs('tagihan.*') ? 'active' : '' }}">
                    <a href="{{ route('tagihan.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-plus"></i>
                        <span class="link-title">Tagihan</span>
                    </a>
                </li>
                <li class="nav-item {{ request()->routeIs('invoice.*') ? 'active' : '' }}">
                    <a href="{{ route('invoice.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="file-text"></i>
                        <span class="link-title">Invoice</span>
                    </a>
                </li>
                <li class="nav-item {{ active_class(['payments/*']) }}">
                    <a class="nav-link" data-bs-toggle="collapse" href="#menuPayment" role="button"
                        aria-expanded="{{ is_active_route(['payments/*']) }}" aria-controls="menuPayment">
                        <i class="link-icon" data-feather="credit-card"></i>
                        <span class="link-title">Pembayaran</span>
                        <i class="link-arrow" data-feather="chevron-down"></i>
                    </a>
                    <div class="collapse {{ show_class(['payments/*']) }}" id="menuPayment">
                        <ul class="nav sub-menu">
                            <li class="nav-item"><a href="{{ route('payment.dp.index') }}" class="nav-link">DP/Pembayaran</a></li>
                            <li class="nav-item"><a href="{{ route('payment.fp.index') }}" class="nav-link">Pelunasan</a></li>
                            <li class="nav-item"><a href="{{ route('payment.index') }}" class="nav-link">Disetujui</a></li>
                        </ul>
                    </div>
                </li>
            @endrole
```
(Catatan: label "DP / Tagihan" lama → "DP/Pembayaran". Hapus blok Pembayaran lama yang non-role-gated agar tak dobel.)

(c) Di grup **Order & Naskah** (sudah ada, role `superadmin|manager|marketing`), pastikan tetap berisi Buat Order (Buku/Jurnal), Daftar Order, Arsip Judul — biarkan apa adanya, hanya posisinya kini SETELAH Pembayaran.

(d) Grup **Produksi** & **Laporan** & **Akun** dibiarkan (Produksi tetap role `production|manager|superadmin`; Laporan tetap; Manajemen User tetap role `manager|superadmin`).

> Jangan ubah `<nav class="massege">...</nav>` di bawah (alert session) — biarkan utuh.

- [ ] **Step 4: Jalankan — pastikan PASS + tak ada regresi**

Run: `php artisan test --filter=SidebarTagihanTest`
Expected: PASS (2 test).
Run: `php artisan test --filter=ArchiveGroupedTitlesTest`
Expected: PASS (sidebar label test existing tetap hijau; jika ada assert "DP / Tagihan" yang berubah, sesuaikan — lihat catatan).

> Jika `ArchiveGroupedTitlesTest` (atau test lain) meng-assert teks "DP / Tagihan" atau urutan menu lama dan kini gagal karena rename/reorder yang DISENGAJA, JANGAN melemahkan diam-diam — laporkan test + assert-nya; itu perubahan perilaku yang diinginkan dan test perlu di-update ke teks baru.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php tests/Feature/SidebarTagihanTest.php
git commit -m "$(printf 'feat: sidebar restructure role-aware (Tagihan menu) + rename DP/Pembayaran\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
```

---

## Task 8: Verifikasi end-to-end

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS — 137 lama (−1 proforma-create +1 invoice-index sisanya) + Tagihan/Invoice/Sidebar baru. Tidak ada regresi.

- [ ] **Step 2: QA manual (browser)**
- [ ] Marketing: menu Pembayaran → Tagihan → Buat Tagihan (isi data) → tersimpan `diajukan`.
- [ ] Superadmin: buka tagihan → Approve. Marketing: Download PDF muncul → unduh PDF tagihan.
- [ ] Marketing: "Buat Order dari Tagihan" → form order buku terisi (judul/email/telp/nominal/catatan) → lengkapi author → simpan → tagihan jadi `jadi_order` + order masuk pipeline.
- [ ] Invoice index: saat kosong tak ada error DataTables; saat ada invoice diterbitkan/lunas, tombol Download muncul; tak ada tombol "Buat Invoice".
- [ ] Production: sidebar tak menampilkan Tagihan/Invoice/Order; hanya Produksi.

- [ ] **Step 3: Cek log error kosong**

Run: `php artisan view:clear` lalu jalankan alur; pastikan `storage/logs/laravel.log` tak ada error baru.

---

## Self-Review Coverage (spec → task)

| Bagian Spec | Task |
|-------------|------|
| §1/§4 Model & tabel tagihan | Task 1 |
| §2/§3/§6 Lifecycle, role, controller CRUD | Task 2, 3 |
| §6 PDF tagihan (gated) | Task 4 |
| §1/§7 Buat Order dari Tagihan (prefill ringan + link, no double-count) | Task 5 |
| §1/§8 Fix Invoice index + retire manual create | Task 6 |
| §9 Sidebar restructure + rename | Task 7 |
| §12 QA/testing | Task 1–7 (otomatis) + Task 8 (manual) |
| §13 YAGNI (auto-order, DP-tagihan, prefill penuh, merge link bayar, notifikasi) | tidak diimplementasi |
