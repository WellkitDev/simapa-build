# Registri ISBN Buku Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registri satu record ISBN per buku (lifecycle nomor pendaftaran → ISBN → cetak) dengan menu "Direktori ISBN" (worklist buku yang manuskripnya sudah mencapai tahap `isbn`) dan kartu kelola di panel detail judul.

**Architecture:** Tabel `tb_book_isbns` (1:1 ke `tb_titles` via `title_id` unik). Gate kelayakan `Title::isbnEligible()` = tahap manuskrip bottleneck ≥ `isbn`. `BookIsbnController` menyajikan direktori (semua staf) + mutasi (superadmin/manager/admin/production). Edit UI tunggal di panel judul (DRY); direktori = worklist + link. Tanpa service (CRUD sederhana).

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5 + DataTables + flatpickr, Spatie roles. Test: PHPUnit feature/unit via `.env.testing`.

---

## File Structure

- `database/migrations/2026_07_03_000003_create_tb_book_isbns_table.php` (**create**) — skema registri.
- `app/Models/BookIsbn.php` (**create**) — model + STATUSES + statusLabel + relasi.
- `app/Models/Title.php` (**modify**) — `bookIsbn()` hasOne + `isbnEligible()`.
- `app/Http/Controllers/Pages/BookIsbnController.php` (**create**) — index/store/update/destroy.
- `routes/web.php` (**modify**) — rute `isbn.*` + import controller.
- `resources/views/isbn/index.blade.php` (**create**) — direktori DataTables.
- `resources/views/layouts/sidebar.blade.php` (**modify**) — menu "Direktori ISBN".
- `resources/views/titles/show.blade.php` (**modify**) — kartu "Registrasi ISBN".
- `app/Http/Controllers/Pages/TitleController.php` (**modify**) — `show()` eager-load `bookIsbn` + `$canManageIsbn`.
- `tests/Unit/TitleIsbnEligibleTest.php` (**create**), `tests/Feature/BookIsbnTest.php` (**create**).

---

## Konteks untuk implementer

- **`Title::manuscriptStatus()`** kembalikan tahap **bottleneck** (paling awal antar order) atau `null`. `TitleProgress::BOOK_STAGES = ['menunggu_proses','editing','layout','proofreading','isbn','cetak','terbit']`. Title.php sudah `use App\Models\TitleProgress`.
- **Fixture buku pada tahap** (dipakai di test): Title buku + Order + OrderDetail(`title_id`) + TitleProgress(`status`). Pola dari `tests/Feature/ChapterProgressControllerTest.php`.
- **Panel judul** (`titles/show.blade.php`) sudah init `flatpickr('.flatpickr-date', …)` di `@push('custom-scripts')` → input tanggal ISBN cukup pakai class `flatpickr-date`.
- **`titles/show.blade.php`**: kartu Informasi Publikasi ditutup di `</div></div></div></div>` + `@endif` (baris ~239–240), lalu `@endsection` (baris ~241). Sisipkan kartu ISBN **sebelum `@endsection`**.
- **`ConvertEmptyStringsToNull`** aktif → input kosong jadi `null`; tetap pakai guard `($x ?? '') ?: null` untuk tanggal (konsisten pola panel publikasi).

---

### Task 1: Migrasi + Model BookIsbn + Title::bookIsbn/isbnEligible + unit test

**Files:**
- Create: `database/migrations/2026_07_03_000003_create_tb_book_isbns_table.php`
- Create: `app/Models/BookIsbn.php`
- Modify: `app/Models/Title.php`
- Create: `tests/Unit/TitleIsbnEligibleTest.php`

- [ ] **Step 1: Tulis unit test kelayakan (gagal dulu)**

Create `tests/Unit/TitleIsbnEligibleTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleIsbnEligibleTest extends TestCase
{
    use RefreshDatabase;

    private function bookAtStage(?string $stage): Title
    {
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        if ($stage !== null) {
            $owner = User::factory()->create();
            $order = Order::create(['code_order' => 'ORD-EL-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
            $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
            TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $stage, 'assigned_role' => 'production', 'started_at' => now()]);
        }
        return $book->fresh();
    }

    /** @test */
    public function eligible_when_manuscript_reached_isbn_or_beyond(): void
    {
        foreach (['isbn', 'cetak', 'terbit'] as $stage) {
            $this->assertTrue($this->bookAtStage($stage)->isbnEligible(), "gagal utk tahap {$stage}");
        }
    }

    /** @test */
    public function ineligible_before_isbn_or_without_orders_or_article(): void
    {
        foreach (['editing', 'layout', 'proofreading'] as $stage) {
            $this->assertFalse($this->bookAtStage($stage)->isbnEligible(), "seharusnya belum layak: {$stage}");
        }
        $this->assertFalse($this->bookAtStage(null)->isbnEligible()); // tanpa order
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertFalse($art->isbnEligible());
    }
}
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (method belum ada)**

Run: `php artisan test --env=testing tests/Unit/TitleIsbnEligibleTest.php`
Expected: FAIL — `Call to undefined method App\Models\Title::isbnEligible()`.

- [ ] **Step 3: Buat migrasi**

Create `database/migrations/2026_07_03_000003_create_tb_book_isbns_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_book_isbns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
            $table->string('status')->default('pendaftaran'); // pendaftaran | ber_isbn | cetak
            $table->string('no_pendaftaran')->nullable();
            $table->string('no_isbn')->nullable();
            $table->string('no_buku_cetak')->nullable();
            $table->string('penerbit')->nullable();
            $table->date('tgl_daftar')->nullable();
            $table->date('tgl_isbn')->nullable();
            $table->date('tgl_terbit')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_book_isbns');
    }
};
```

- [ ] **Step 4: Buat model `app/Models/BookIsbn.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookIsbn extends Model
{
    protected $table = 'tb_book_isbns';

    protected $fillable = [
        'title_id', 'status', 'no_pendaftaran', 'no_isbn', 'no_buku_cetak',
        'penerbit', 'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'catatan', 'created_by',
    ];

    protected $casts = [
        'tgl_daftar' => 'date',
        'tgl_isbn'   => 'date',
        'tgl_terbit' => 'date',
    ];

    const STATUSES = [
        'pendaftaran' => 'Pendaftaran',
        'ber_isbn'    => 'Ber-ISBN',
        'cetak'       => 'Cetak/Terbit',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: Tambah relasi + kelayakan di `app/Models/Title.php`**

Setelah method `manuscriptStatusLabel()` (sekitar baris 102), tambahkan:

```php
    public function bookIsbn()
    {
        return $this->hasOne(BookIsbn::class);
    }

    /** Buku yang manuskripnya sudah mencapai tahap 'isbn' (bottleneck ≥ index 'isbn'). */
    public function isbnEligible(): bool
    {
        if ($this->jenis !== 'buku') {
            return false;
        }
        $status = $this->manuscriptStatus();
        if ($status === null) {
            return false;
        }
        $stages  = TitleProgress::BOOK_STAGES;
        $reached = array_search($status, $stages, true);
        $isbnIdx = array_search('isbn', $stages, true);
        return $reached !== false && $reached >= $isbnIdx;
    }
```

- [ ] **Step 6: Jalankan unit test — diharapkan PASS**

Run: `php artisan test --env=testing tests/Unit/TitleIsbnEligibleTest.php`
Expected: 2 passed.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_03_000003_create_tb_book_isbns_table.php app/Models/BookIsbn.php app/Models/Title.php tests/Unit/TitleIsbnEligibleTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(isbn): tabel tb_book_isbns + model BookIsbn + Title::bookIsbn/isbnEligible

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: BookIsbnController + rute + feature test CRUD/gate/role

**Files:**
- Create: `app/Http/Controllers/Pages/BookIsbnController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/BookIsbnTest.php`

- [ ] **Step 1: Tulis feature test (gagal dulu)**

Create `tests/Feature/BookIsbnTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\BookIsbn;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class BookIsbnTest extends TestCase
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

    private function bookAtStage(string $stage): Title
    {
        $book = Title::create(['title' => 'Buku ISBN ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-IS-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $stage, 'assigned_role' => 'production', 'started_at' => now()]);
        return $book;
    }

    /** @test */
    public function eligible_book_appears_in_directory_ineligible_hidden(): void
    {
        $eligible = $this->bookAtStage('isbn');
        $notYet   = $this->bookAtStage('editing');

        $this->actingAs($this->user('manager'))->get(route('isbn.index'))
            ->assertOk()
            ->assertSee($eligible->title)
            ->assertDontSee($notYet->title);
    }

    /** @test */
    public function production_registers_isbn_for_eligible_book(): void
    {
        $book = $this->bookAtStage('isbn');
        $prod = $this->user('production');

        $this->actingAs($prod)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-623-000-0', 'penerbit' => 'Avidpedia Press',
        ])->assertRedirect(route('title.show', $book->id));

        $isbn = BookIsbn::where('title_id', $book->id)->first();
        $this->assertNotNull($isbn);
        $this->assertSame('ber_isbn', $isbn->status);
        $this->assertSame('978-623-000-0', $isbn->no_isbn);
        $this->assertSame($prod->id, $isbn->created_by);
    }

    /** @test */
    public function store_rejected_for_ineligible_book(): void
    {
        $book = $this->bookAtStage('editing');
        $this->actingAs($this->user('production'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran',
        ])->assertForbidden();
        $this->assertSame(0, BookIsbn::where('title_id', $book->id)->count());
    }

    /** @test */
    public function duplicate_isbn_per_book_rejected(): void
    {
        $book = $this->bookAtStage('isbn');
        BookIsbn::create(['title_id' => $book->id, 'status' => 'pendaftaran']);

        $this->actingAs($this->user('production'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn',
        ]);

        $this->assertSame(1, BookIsbn::where('title_id', $book->id)->count());
    }

    /** @test */
    public function marketing_cannot_register(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('marketing'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran',
        ])->assertForbidden();
    }

    /** @test */
    public function production_updates_and_deletes(): void
    {
        $book = $this->bookAtStage('isbn');
        $isbn = BookIsbn::create(['title_id' => $book->id, 'status' => 'pendaftaran']);

        $this->actingAs($this->user('production'))->put(route('isbn.update', $isbn->id), ['status' => 'ber_isbn', 'no_isbn' => '978-1'])
            ->assertRedirect(route('title.show', $book->id));
        $this->assertSame('ber_isbn', $isbn->fresh()->status);

        $this->actingAs($this->user('production'))->delete(route('isbn.destroy', $isbn->id))->assertRedirect();
        $this->assertNull(BookIsbn::find($isbn->id));
    }
}
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (route/controller belum ada)**

Run: `php artisan test --env=testing tests/Feature/BookIsbnTest.php`
Expected: FAIL — `Route [isbn.index] not defined` / class tidak ada.

- [ ] **Step 3: Buat `app/Http/Controllers/Pages/BookIsbnController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\BookIsbn;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookIsbnController extends Controller
{
    public function index()
    {
        $books = Title::where('jenis', 'buku')
            ->with(['orderDetails.titleProgress', 'bookIsbn'])
            ->latest()->get()
            ->filter->isbnEligible()
            ->values();

        return view('isbn.index', [
            'books'     => $books,
            'canManage' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'status'         => 'required|in:pendaftaran,ber_isbn,cetak',
            'no_pendaftaran' => 'nullable|string|max:100',
            'no_isbn'        => 'nullable|string|max:100',
            'no_buku_cetak'  => 'nullable|string|max:100',
            'penerbit'       => 'nullable|string|max:150',
            'tgl_daftar'     => 'nullable|date',
            'tgl_isbn'       => 'nullable|date',
            'tgl_terbit'     => 'nullable|date',
            'catatan'        => 'nullable|string',
        ]);
        foreach (['tgl_daftar', 'tgl_isbn', 'tgl_terbit'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }
        return $data;
    }

    public function store(Request $request)
    {
        $title = Title::findOrFail($request->input('title_id'));
        abort_unless($title->jenis === 'buku' && $title->isbnEligible(), 403);
        if ($title->bookIsbn()->exists()) {
            return back()->with('error', 'Buku sudah punya registrasi ISBN.');
        }
        $data = $this->validated($request);
        $data['title_id']   = $title->id;
        $data['created_by'] = Auth::id();
        BookIsbn::create($data);

        return redirect()->route('title.show', $title->id)->with('success', 'Registrasi ISBN disimpan.');
    }

    public function update(Request $request, int $id)
    {
        $isbn = BookIsbn::findOrFail($id);
        $isbn->update($this->validated($request));

        return redirect()->route('title.show', $isbn->title_id)->with('success', 'Registrasi ISBN diperbarui.');
    }

    public function destroy(int $id)
    {
        $isbn = BookIsbn::findOrFail($id);
        $titleId = $isbn->title_id;
        $isbn->delete();

        return redirect()->route('title.show', $titleId)->with('success', 'Registrasi ISBN dihapus.');
    }
}
```

- [ ] **Step 4: Tambah rute di `routes/web.php`**

Tambahkan import di dekat baris `use App\Http\Controllers\Pages\JournalController;` (baris 23):

```php
use App\Http\Controllers\Pages\BookIsbnController;
```

Lalu sisipkan SETELAH blok rute `journal.destroy` (baris ~264, masih di dalam grup auth yang sama):

```php
    // Direktori ISBN — index utk semua staf; mutasi utk pengelola (production ikut, pemegang tahap isbn)
    Route::get('management/isbn', [BookIsbnController::class, 'index'])->name('isbn.index');
    Route::middleware('role:superadmin|manager|admin|production')->group(function () {
        Route::post('management/isbn', [BookIsbnController::class, 'store'])->name('isbn.store');
        Route::put('management/isbn/{id}', [BookIsbnController::class, 'update'])->name('isbn.update')->whereNumber('id');
        Route::delete('management/isbn/{id}', [BookIsbnController::class, 'destroy'])->name('isbn.destroy')->whereNumber('id');
    });
```

- [ ] **Step 5: Buat view minimal `resources/views/isbn/index.blade.php`** (agar `isbn.index` bisa dirender oleh test index)

```blade
@extends('layouts.master')
@section('title', 'Direktori ISBN - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori ISBN</h5>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="text-muted small mb-3">Buku yang manuskripnya telah mencapai tahap ISBN. Kelola registrasi di detail judul.</p>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>No. ISBN</th><th>Status</th><th>Penerbit</th><th>Tgl ISBN</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($books as $b)
                    <tr>
                        <td>{{ $b->code ?? '—' }}</td>
                        <td>{{ $b->title }}</td>
                        <td>{{ $b->bookIsbn?->no_isbn ?: '—' }}</td>
                        <td>@if($b->bookIsbn)<span class="badge bg-info">{{ $b->bookIsbn->statusLabel() }}</span>@else<span class="badge bg-light text-dark border">Belum didaftarkan</span>@endif</td>
                        <td>{{ $b->bookIsbn?->penerbit ?: '—' }}</td>
                        <td>{{ optional($b->bookIsbn?->tgl_isbn)->format('d M Y') ?? '—' }}</td>
                        <td><a href="{{ route('title.show', $b->id) }}" class="btn btn-xs btn-outline-primary">Kelola</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada buku yang mencapai tahap ISBN.' } }); });</script>
@endpush
```

- [ ] **Step 6: Jalankan feature test — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/BookIsbnTest.php`
Expected: 6 passed.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php routes/web.php resources/views/isbn/index.blade.php tests/Feature/BookIsbnTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(isbn): BookIsbnController + rute isbn.* + direktori worklist (gate kelayakan)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Kartu "Registrasi ISBN" di panel judul + sidebar + show()

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (method `show`, sekitar baris 72–95)
- Modify: `resources/views/titles/show.blade.php` (sisip kartu sebelum `@endsection`, baris ~241)
- Modify: `resources/views/layouts/sidebar.blade.php` (setelah blok Direktori Jurnal, baris ~101)
- Modify: `tests/Feature/BookIsbnTest.php` (tambah 1 test panel)

- [ ] **Step 1: Tambah test panel (gagal dulu)**

Tambahkan method ini di dalam `BookIsbnTest` (sebelum kurung tutup kelas):

```php
    /** @test */
    public function panel_shows_form_when_eligible_and_note_when_not(): void
    {
        $eligible = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))->get(route('title.show', $eligible->id))
            ->assertOk()->assertSee('Registrasi ISBN')->assertSee('Simpan Registrasi ISBN');

        $notYet = $this->bookAtStage('editing');
        $this->actingAs($this->user('production'))->get(route('title.show', $notYet->id))
            ->assertOk()->assertSee('setelah manuskrip mencapai tahap ISBN');
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL**

Run: `php artisan test --env=testing tests/Feature/BookIsbnTest.php::panel_shows_form_when_eligible_and_note_when_not`
Expected: FAIL — teks "Registrasi ISBN"/"Simpan Registrasi ISBN" belum ada.

- [ ] **Step 3: `TitleController@show` — eager-load `bookIsbn` + kirim `$canManageIsbn`**

Di `app/Http/Controllers/Pages/TitleController.php`, pada `show()`, ubah eager-load menambahkan `'bookIsbn'`:

Cari:
```php
        $title = Title::with(['chapters.authors', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'orderDetails.authors', 'journalOptions.journal', 'logs.changedBy'])->findOrFail($id);
```
Ganti menjadi (tambah `'bookIsbn'` di akhir daftar with):
```php
        $title = Title::with(['chapters.authors', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'orderDetails.authors', 'journalOptions.journal', 'logs.changedBy', 'bookIsbn'])->findOrFail($id);
```

Lalu di array `return view('titles.show', [ … ])`, setelah `'orderAuthors' => $orderAuthors,`, tambahkan:
```php
            'canManageIsbn' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
```

- [ ] **Step 4: Sisipkan kartu "Registrasi ISBN" di `resources/views/titles/show.blade.php`**

Tepat SEBELUM baris `@endsection` (baris ~241, setelah `@endif` penutup kartu Informasi Publikasi), sisipkan:

```blade
@if($title->jenis === 'buku' && $canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Registrasi ISBN</h6>
        @if($canManageIsbn && $title->isbnEligible())
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#isbnForm">Edit Registrasi ISBN</button>
        @endif
    </div>

    @if(! $title->isbnEligible())
        <p class="text-muted mb-0">Registrasi ISBN tersedia setelah manuskrip mencapai tahap ISBN.</p>
    @else
        @php $isbn = $title->bookIsbn; @endphp
        <dl class="row mb-2">
            <dt class="col-sm-4 text-muted small">Status</dt><dd class="col-sm-8">@if($isbn)<span class="badge bg-info">{{ $isbn->statusLabel() }}</span>@else<span class="text-muted">Belum didaftarkan</span>@endif</dd>
            <dt class="col-sm-4 text-muted small">No. Pendaftaran</dt><dd class="col-sm-8">{{ $isbn?->no_pendaftaran ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">No. ISBN</dt><dd class="col-sm-8">{{ $isbn?->no_isbn ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">No. Buku Cetak</dt><dd class="col-sm-8">{{ $isbn?->no_buku_cetak ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Penerbit</dt><dd class="col-sm-8">{{ $isbn?->penerbit ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl Daftar</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_daftar)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl ISBN</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_isbn)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl Terbit</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_terbit)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $isbn?->catatan ?: '—' }}</dd>
        </dl>

        @if($canManageIsbn)
            <div class="collapse" id="isbnForm">
                <form method="POST" action="{{ $isbn ? route('isbn.update', $isbn->id) : route('isbn.store') }}">
                    @csrf
                    @if($isbn) @method('PUT') @else <input type="hidden" name="title_id" value="{{ $title->id }}"> @endif
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                @foreach(\App\Models\BookIsbn::STATUSES as $val => $lbl)
                                    <option value="{{ $val }}" {{ optional($isbn)->status === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Pendaftaran</label><input name="no_pendaftaran" value="{{ optional($isbn)->no_pendaftaran }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. ISBN</label><input name="no_isbn" value="{{ optional($isbn)->no_isbn }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Buku Cetak</label><input name="no_buku_cetak" value="{{ optional($isbn)->no_buku_cetak }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Penerbit</label><input name="penerbit" value="{{ optional($isbn)->penerbit }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Daftar</label><input type="text" name="tgl_daftar" value="{{ optional(optional($isbn)->tgl_daftar)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl ISBN</label><input type="text" name="tgl_isbn" value="{{ optional(optional($isbn)->tgl_isbn)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Terbit</label><input type="text" name="tgl_terbit" value="{{ optional(optional($isbn)->tgl_terbit)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-12"><label class="form-label small mb-1">Catatan</label><textarea name="catatan" rows="2" class="form-control form-control-sm">{{ optional($isbn)->catatan }}</textarea></div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary mt-2">Simpan Registrasi ISBN</button>
                </form>
            </div>
        @endif
    @endif
</div></div></div></div>
@endif
```

- [ ] **Step 5: Tambah menu sidebar `resources/views/layouts/sidebar.blade.php`**

Tepat SETELAH blok `@endrole` penutup "Direktori Jurnal" (baris ~101), tambahkan:

```blade
            @role(['superadmin', 'manager', 'admin', 'production', 'marketing'])
                <li class="nav-item {{ active_class(['management/isbn', 'management/isbn/*']) }}">
                    <a href="{{ route('isbn.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="hash"></i>
                        <span class="link-title">Direktori ISBN</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 6: Jalankan test panel + view:cache**

Run: `php artisan test --env=testing tests/Feature/BookIsbnTest.php`
Expected: 7 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: "Blade templates cached successfully." lalu "Compiled views cleared successfully." (tanpa error).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Pages/TitleController.php resources/views/titles/show.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/BookIsbnTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(isbn): kartu Registrasi ISBN di panel judul + menu sidebar Direktori ISBN

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev** (agar app live tak 500 saat query tb_book_isbns)

Run: `php artisan migrate`
Expected: `2026_07_03_000003_create_tb_book_isbns_table ... DONE`.

- [ ] **Step 2: Jalankan seluruh suite**

Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 381 + 9 test baru = 390 passed).

- [ ] **Step 3: Konfirmasi kompilasi view bersih**

Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §1(1) gate kelayakan directory+server → Task 1 `isbnEligible` + Task 2 index filter/store abort + test `eligible_book_appears…` & `store_rejected_for_ineligible_book`. ✓
- §1(2) CRUD 4 role → Task 2 controller + rute role group; test `production_registers…`, `production_updates_and_deletes`. ✓
- §1(3) satu record/buku → migrasi `title_id` unik + store guard; test `duplicate_isbn_per_book_rejected`. ✓
- §1(4) panel tampil form/catatan → Task 3 kartu + test `panel_shows_form_when_eligible_and_note_when_not`. ✓
- §1(5) marketing 403 / staf lihat → rute role group + test `marketing_cannot_register` + index dibuka manager. ✓
- §2 helper isbnEligible → Task 1. §3 model/migrasi → Task 1. §4 controller/rute → Task 2. §5 view → Task 2/3. §6 test → semua task. ✓

**2. Placeholder scan:** tak ada TBD/TODO; semua langkah berisi kode/perintah nyata.

**3. Type/nama konsistensi:** tabel `tb_book_isbns`; model `BookIsbn` (STATUSES keys `pendaftaran|ber_isbn|cetak`) konsisten di migrasi/controller `in:`/view select. Relasi `Title::bookIsbn()` dipakai di controller (`$title->bookIsbn()->exists()`, eager `'bookIsbn'`) & view (`$title->bookIsbn`). Rute `isbn.index|store|update|destroy` konsisten controller↔view↔test. `$canManageIsbn` dikirim show() & dipakai view. `isbnEligible()` dipakai controller+view+test.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 4 Step 1). Test via `.env.testing`.
