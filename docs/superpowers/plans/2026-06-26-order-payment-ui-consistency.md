# Order/Payment UI Consistency + Journal Edit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selaraskan tampilan Order/Pembayaran/Tagihan/Invoice/Detail ke pola template **NobleUI BS5** (kartu `grid-margin stretch-card`, DataTables, tanpa `container py-5`/`card-header bg-primary`), dan implementasi **Edit Order Jurnal** (controller masih stub) + gate role pada route `show`.

**Architecture:** Restyle adalah transformasi presentasi murni (ubah pembungkus/kelas, JANGAN ubah data/field/teks yang di-assert test). Edit Jurnal meniru `OrderBookController::edit/update`. Eksekusi bertahap dengan **suite penuh sebagai gerbang tiap grup**.

**Tech Stack:** Laravel 11, Blade + Bootstrap 5 (NobleUI), DataTables (`assets/libs/datatables.net*`), select2/flatpickr (`assets/plugins/...`), Spatie roles.

**Spec:** `docs/superpowers/specs/2026-06-26-order-payment-ui-consistency-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`); `GoogleDriveService` di-mock. DB error → MySQL/XAMPP mati: `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Tanpa migrasi baru.

---

## Konvensi restyle (acuan WAJIB untuk Task 2–4)

Contoh "benar" yang sudah ada: `resources/views/announcements/index.blade.php`, `resources/views/tasks/board.blade.php`, `resources/views/marketing-target/index.blade.php`, `resources/views/orders/book/index.blade.php` (DataTables).

Aturan transformasi tiap file:
1. **Hapus** pembungkus `<div class="container py-5">` ... `</div>` (konten langsung di bawah `@section('content')`; master sudah memberi padding).
2. **Ganti judul `<h1>`** → header NobleUI: `<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><div><h5 class="mb-0">JUDUL</h5></div> ...tombol aksi (Kembali/Simpan)... </div>`.
3. **Kartu:** bungkus tiap kartu dengan `<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body"> ... </div></div></div></div>`. **Hapus** `<div class="card-header bg-primary text-white"><h5>...</h5></div>` → pindahkan judul seksi jadi `<h6 class="card-title">...</h6>` di dalam `card-body`.
4. **Tabel list:** `class="table table-hover datatable"` + (jika belum) init DataTables di `@push('custom-scripts')` dengan aset `datatables.net*` + `language: { emptyTable: '...' }`. (Tiru `orders/book/index`.)
5. **Form:** pertahankan SEMUA `name`, `value`, hidden input, `enctype`, `@csrf`, `@method`, tombol submit, validasi error display. Hanya rapikan kelas: `form-control`/`form-select`/`form-label`, tombol `btn btn-sm btn-primary` / `btn-outline-secondary`.
6. **Badge status:** palet konsisten (`bg-success`/`bg-warning text-dark`/`bg-danger`/`bg-secondary`).

**PRESERVE (kritis):** Jangan mengubah teks/label/nilai data yang ditampilkan (mis. kode order, nominal, status, nama). Test lama meng-assert teks ini; restyle hanya mengubah markup pembungkus. **Gerbang tiap grup: `php artisan test` harus tetap hijau.**

**Gerbang kompilasi Blade (otomatis, tiap grup):** setelah restyle, jalankan `php artisan view:cache` (meng-compile SEMUA template Blade — menangkap error sintaks `@if`/`@foreach`/`@push` yang tak seimbang walau halaman tak punya test) lalu `php artisan view:clear`. `view:cache` harus selesai tanpa error.

---

## Task 1: Edit Order Jurnal (fitur) + gating show (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderJournalController.php`, `routes/web.php`, `resources/views/orders/book/index.blade.php` (tombol Edit jurnal)
- Create: `resources/views/orders/journal/edit.blade.php`
- Test: `tests/Feature/OrderJournalEditTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/OrderJournalEditTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Author;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class OrderJournalEditTest extends TestCase
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

    private function journalOrder(User $u): Order
    {
        $order = Order::create([
            'code_order' => 'ORD-TEST-0001', 'user_id' => $u->id, 'status' => 'pending',
            'note' => 'awal', 'ordered_at' => today()->toDateString(),
        ]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'title' => 'Judul Lama',
            'slug' => 'judul-lama-' . $order->id, 'indexation' => 'Scopus',
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
        OrderContact::create(['order_id' => $order->id, 'cp_phone' => '0811', 'cp_email' => 'cp@example.com']);
        $author = Author::create(['name' => 'Penulis A', 'email' => 'a@example.com', 'phone' => '0812', 'affiliation' => 'Univ']);
        $detail->authors()->attach($author->id, ['position' => 1]);

        return $order;
    }

    /** @test */
    public function marketing_can_open_and_update_journal_order(): void
    {
        $u = $this->user('marketing');
        $order = $this->journalOrder($u);

        $this->actingAs($u)->get(route('order.journal.edit', $order->code_order))
            ->assertOk()->assertSee('Judul Lama');

        $this->actingAs($u)->put(route('order.journal.update', $order->code_order), [
            'type' => 'at_kolab', 'title' => 'Judul Baru', 'scope_id' => '',
            'indexation' => 'Scopus', 'naskah_type' => 'mandiri', 'publication_type' => 'fastrack',
            'issued_at' => today()->toDateString(), 'cost_amount' => 2000000,
            'contact_phone' => '0899', 'contact_email' => 'new@example.com',
            'authors' => [['name' => 'Penulis B', 'email' => 'b@example.com', 'phone' => '0813', 'affiliation' => 'Univ2', 'position' => 1]],
            'note' => 'updated',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('Judul Baru', $order->details->title);
        $this->assertSame('fastrack', $order->details->publication_type);
        $this->assertSame('new@example.com', $order->contact->cp_email);
    }

    /** @test */
    public function unauthorized_role_cannot_edit_journal(): void
    {
        $this->actingAs($this->user('production'))
            ->get(route('order.journal.edit', 'ORD-TEST-0001'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=OrderJournalEditTest`
Expected: FAIL — route `order.journal.edit` not defined.

- [ ] **Step 3: Controller edit/update**

In `app/Http/Controllers/Pages/OrderJournalController.php`, replace the stub `edit()`/`update()` (the ones with `//` bodies / `return view('pages.order.journals.edit')`) with:

```php
    public function edit(string $code_order)
    {
        $order = Order::with(['details.authors', 'details.scopes', 'contact'])
            ->where('code_order', $code_order)->firstOrFail();
        $scopes = Scope::all();

        return view('orders.journal.edit', compact('order', 'scopes'));
    }

    public function update(Request $request, string $code_order)
    {
        $request->validate([
            'type'                  => 'required|in:at_mandiri,at_kolab',
            'title'                 => 'required|string|max:255',
            'scope_id'              => 'nullable',
            'indexation'            => 'required|string',
            'naskah_type'           => 'required|in:dibuatkan,mandiri',
            'publication_type'      => 'required|in:regular,fastrack',
            'issued_at'             => 'required|date',
            'cost_amount'           => 'required|numeric|min:0',
            'contact_phone'         => 'required|string',
            'contact_email'         => 'required|email',
            'authors'               => 'required|array|min:1',
            'authors.*.name'        => 'required|string',
            'authors.*.email'       => 'nullable|email',
            'authors.*.phone'       => 'nullable|string',
            'authors.*.affiliation' => 'nullable|string',
            'authors.*.position'    => 'required|integer|min:1',
            'note'                  => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($request, $code_order) {
                $order = Order::where('code_order', $code_order)->firstOrFail();
                $order->update([
                    'note'       => $request->note,
                    'ordered_at' => $request->issued_at,
                ]);

                $order->details()->update([
                    'title'            => $request->title,
                    'type'             => $request->type,
                    'indexation'       => $request->indexation,
                    'naskah_type'      => $request->naskah_type,
                    'publication_type' => $request->publication_type,
                    'cost_amount'      => $request->cost_amount,
                ]);

                $detail = $order->details;

                if ($request->filled('scope_id')) {
                    $scopeId = $request->scope_id;
                    if (! is_numeric($scopeId)) {
                        $scopeId = Scope::firstOrCreate(['scope' => $scopeId])->id;
                    }
                    $detail->scopes()->sync([$scopeId]);
                }

                $order->contact()->update([
                    'cp_phone' => $request->contact_phone,
                    'cp_email' => $request->contact_email,
                ]);

                $detail->authors()->detach();
                foreach ($request->authors as $authorData) {
                    $author = Author::updateOrCreate(
                        ['email' => $authorData['email']],
                        [
                            'name'        => $authorData['name'],
                            'affiliation' => $authorData['affiliation'] ?? null,
                            'phone'       => $authorData['phone'] ?? null,
                        ]
                    );
                    $detail->authors()->attach($author->id, ['position' => $authorData['position'] ?? 1]);
                }
            });

            return redirect()->route('order.book.index')->with('success', 'Order #' . $code_order . ' berhasil diperbarui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }
```

(`Order`, `OrderDetail`, `OrderContact`, `Author`, `Scope`, `DB`, `Auth`, `Str` are already imported in this controller — they're used by `store()`.)

- [ ] **Step 4: Routes (edit jurnal + gating show)**

In `routes/web.php`, inside the `Route::prefix('order')->name('order.')->group(...)` block, after the `jurnal/show` line add:

```php
        Route::get('jurnal/update/{code_order}', [OrderJournalController::class, 'edit'])->name('journal.edit')->middleware('role:marketing|manager|superadmin');
        Route::put('jurnal/update/{code_order}', [OrderJournalController::class, 'update'])->name('journal.update')->middleware('role:marketing|manager|superadmin');
```

And add `->middleware('role:marketing|manager|superadmin')` to the two existing `show` routes:
```php
        Route::get('buku/show/{code_order}', [OrderBookController::class, 'show'])->name('book.show')->middleware('role:marketing|manager|superadmin');
        Route::get('jurnal/show/{code_order}', [OrderJournalController::class, 'show'])->name('journal.show')->middleware('role:marketing|manager|superadmin');
```

- [ ] **Step 5: Journal edit view**

Create `resources/views/orders/journal/edit.blade.php` by copying `resources/views/orders/journal/create.blade.php` as the base, then adapting:
- `@section('title', 'Edit Order Jurnal - SiMAPA')`.
- Form: `action="{{ route('order.journal.update', $order->code_order) }}"`, add `@method('PUT')` after `@csrf`. Remove the `from_tagihan` hidden block (edit isn't created from tagihan).
- **Prefill values** from the loaded `$order` (use `$d = $order->details;`):
  - Code Order: tampilkan `{{ $order->code_order }}` (read-only).
  - `type` select: mark selected `{{ $d->type === 'at_mandiri' ? 'selected' : '' }}` / `at_kolab`.
  - `title`: `value="{{ old('title', $d->title) }}"`.
  - `scope_id` select2: preselect `$d->scopes->first()?->id`.
  - `indexation`, `naskah_type`, `publication_type`: preselect current values.
  - `issued_at`: `value="{{ old('issued_at', optional($order->ordered_at)->format('Y-m-d')) }}"`.
  - `cost_amount`: `value="{{ old('cost_amount', $d->cost_amount) }}"`.
  - `contact_phone`/`contact_email`: from `$order->contact->cp_phone` / `cp_email`.
  - **Authors repeater**: render one block per `$d->authors` (sorted by pivot `position`) with name/email/phone/affiliation/position prefilled; KEEP the same `name="authors[i][...]"` indexing + the add/remove-row JS from the create view (copy it verbatim).
- **Restyle to NobleUI** per the Konvensi above (remove `container py-5`, drop `card-header bg-primary text-white` → `h6.card-title`, wrap cards in `row > col-12 grid-margin stretch-card`). Keep select2 plugin css/js push blocks.
- Add a "Kembali" button (`btn btn-sm btn-outline-secondary` → `route('order.book.index')`) and the submit button "Simpan Perubahan".

- [ ] **Step 6: Edit button for journal orders in Daftar Order**

In `resources/views/orders/book/index.blade.php`, the action column lists orders. For rows that are **journal** orders (article type — `type` in `at_mandiri`/`at_kolab`), add an Edit link to `route('order.journal.edit', $row->code_order)`; book rows keep `route('order.book.edit', ...)`. Match the existing button styling in that file. (Read the file to see how it currently distinguishes book vs journal / renders the edit action, and mirror it.)

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=OrderJournalEditTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Run full suite (no regression)**

Run: `php artisan test`
Expected: PASS all (274 + 2 = 276).

- [ ] **Step 9: Commit**

```
git add app/Http/Controllers/Pages/OrderJournalController.php routes/web.php resources/views/orders/journal/edit.blade.php resources/views/orders/book/index.blade.php tests/Feature/OrderJournalEditTest.php
git commit -m "feat(order): implement Order Jurnal edit + gate show routes

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Restyle grup Order (form/detail/arsip)

**Files (restyle to NobleUI per Konvensi):**
- `resources/views/orders/book/create.blade.php`
- `resources/views/orders/journal/create.blade.php`
- `resources/views/orders/edit.blade.php` (edit buku)
- `resources/views/orders/journal/edit.blade.php` (selaraskan dgn create setelah Task 1)
- `resources/views/orders/book/show.blade.php`
- `resources/views/orders/index-title.blade.php`
- `resources/views/orders/detail-title.blade.php`
- `resources/views/orders/detail-title-group.blade.php`
- `resources/views/orders/book/index.blade.php` (normalisasi ringan bila perlu — sudah DataTables)

Also check `OrderJournalController::show()` — if `order.journal.show` renders a dedicated view, restyle it; if it reuses `orders/book/show`, no extra file.

- [ ] **Step 1: Restyle each file above**

For EACH file: apply the Konvensi transformation (remove `container py-5`; convert colored card-headers to `h6.card-title`; wrap cards in `row > col-12 grid-margin stretch-card`; normalize buttons/badges). **Do NOT change any displayed data/labels/field names/values, form `action`/`method`/hidden inputs, or JS behavior.** When a file already conforms (e.g. `book/index`), leave it.

- [ ] **Step 2: Acceptance — no leftover legacy wrappers**

Run (git-bash):
```
grep -rn "container py-5\|card-header bg-primary text-white" resources/views/orders/
```
Expected: NO matches.

- [ ] **Step 3: Full suite green (regression gate)**

Run: `php artisan test`
Expected: PASS all (276). If any order-related test fails, a restyle removed/changed asserted text — restore that text (keep markup change, put the data back).

- [ ] **Step 4: Manual smoke (the UX gate)**

Login a marketing user on the running dev app; open Buat Order Buku, Buat Order Jurnal, Edit (buku & jurnal), Detail Order, Arsip Judul + detail. Confirm: layout matches the newer pages (cards, spacing, buttons), no broken layout, forms submit correctly. Fix any visual roughness (alignment, spacing, responsive `flex-wrap`/`table-responsive`).

- [ ] **Step 5: Commit**

```
git add resources/views/orders/
git commit -m "style(order): align order create/edit/detail pages to NobleUI

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Restyle grup Pembayaran (Disetujui/DP/Pelunasan/Ajukan)

**Files:**
- `resources/views/payments/book/index.blade.php` (Disetujui)
- `resources/views/payments/book/create.blade.php` (Ajukan Pembayaran)
- `resources/views/payments/dp/index.blade.php`
- `resources/views/payments/lunas/index.blade.php`

- [ ] **Step 1: Restyle each file**

Apply the Konvensi to each. Lists → `table.datatable` + DataTables init (these likely already have it — verify; tiru `orders/book/index`). The Ajukan-Pembayaran form keeps all fields/actions/`enctype` (file upload bukti) — only restyle wrappers/classes. Preserve all displayed data/labels.

- [ ] **Step 2: Acceptance — no leftover legacy wrappers**

Run:
```
grep -rn "container py-5\|card-header bg-primary text-white" resources/views/payments/book/ resources/views/payments/dp/ resources/views/payments/lunas/
```
Expected: NO matches.

- [ ] **Step 3: Full suite green**

Run: `php artisan test`
Expected: PASS all (276). (`DetailOrderPaymentInvoiceTest`, `PaymentBookCleanupTest` exercise these — keep their asserted text.)

- [ ] **Step 4: Manual smoke**

Login marketing: buka Pembayaran → Disetujui, DP/Pembayaran, Pelunasan, dan form Ajukan Pembayaran (dari sebuah order). Confirm konsistensi + form upload bukti tetap jalan.

- [ ] **Step 5: Commit**

```
git add resources/views/payments/book/ resources/views/payments/dp/ resources/views/payments/lunas/
git commit -m "style(payment): align payment pages to NobleUI

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Restyle grup Tagihan & Invoice

**Files:**
- `resources/views/payments/tagihan/index.blade.php`
- `resources/views/payments/tagihan/create.blade.php`
- `resources/views/payments/tagihan/show.blade.php`
- `resources/views/payments/invoices/index.blade.php`
- `resources/views/payments/invoices/show.blade.php`
- `resources/views/payments/invoices/edit.blade.php`

**Excluded (do NOT touch):** `payments/invoices/book_invoice_pdf.blade.php`, `payments/tagihan/tagihan_pdf.blade.php` (print templates).

- [ ] **Step 1: Restyle each file**

Apply the Konvensi. Tagihan/Invoice index → DataTables. Show/edit → cards NobleUI. Preserve all data/labels/actions (status badges, nominal, links to PDF). Do not alter the `*_pdf` views.

- [ ] **Step 2: Acceptance — no leftover legacy wrappers**

Run:
```
grep -rn "container py-5\|card-header bg-primary text-white" resources/views/payments/tagihan/ resources/views/payments/invoices/
```
Expected: NO matches (the two `*_pdf` files are exempt — if they contain those classes that's fine for print; restrict the grep findings to the non-pdf files).

- [ ] **Step 3: Full suite green**

Run: `php artisan test`
Expected: PASS all (276). (`TagihanLifecycleTest`, `SidebarTagihanTest`, `DetailOrderPaymentInvoiceTest` exercise these.)

- [ ] **Step 4: Manual smoke**

Login marketing/manager: Tagihan (index/create/show), Invoice (index/show + edit as manager). Confirm konsistensi; PDF (cetak) tetap normal.

- [ ] **Step 5: Commit**

```
git add resources/views/payments/tagihan/ resources/views/payments/invoices/
git commit -m "style(tagihan,invoice): align to NobleUI

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS all (276).

- [ ] **Step 2: Sapu legacy wrapper di semua view scope**

Run:
```
grep -rn "container py-5\|card-header bg-primary text-white" resources/views/orders/ resources/views/payments/ | grep -v "_pdf.blade.php"
```
Expected: NO matches.

- [ ] **Step 3: Smoke akhir**

Telusuri sekali lagi semua halaman scope sebagai marketing & manager: konsisten dengan Pengumuman/Tugas/Report, tidak ada layout rusak, semua form (order buku/jurnal create & edit, ajukan pembayaran, tagihan) submit dengan benar; Edit Order Jurnal berfungsi.

---

## Catatan & Risiko

- Restyle = presentasi murni; risiko utama memutus assertion test → mitigasi: gerbang suite penuh hijau tiap grup + pertahankan teks/data.
- Edit Jurnal meniru Edit Buku; gunakan field jurnal (tanpa `chapters`). Lookup order via `where('code_order', ...)` (bukan `findOrFail($code_order)`).
- Tanpa migrasi/skema baru → tak perlu `php artisan migrate` di dev untuk fitur ini.
- Template PDF dikecualikan agar hasil cetak tak berubah.
- UI/UX adalah inti: setiap grup punya langkah **manual smoke** sebagai gerbang kualitas visual, bukan hanya hijau-nya test.
