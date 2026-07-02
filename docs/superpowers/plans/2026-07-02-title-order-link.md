# Title Directory Fase 2a (Order ↔ Judul) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tautkan `OrderDetail` ke entitas `Title` — form order memilih judul disetujui (select2 + auto-isi) atau membuat judul baru inline (asal=order), dan Direktori Judul menampilkan jml order/author turunan.

**Architecture:** Kolom `tb_order_details.title_id` (nullable FK) + `TitleService::resolveForOrder` (pakai id yang ada / buat Title baru dari field order). Order controllers (buku+jurnal) create/edit kirim judul eligible, store/update meresolusi `title_id`. `OrderDetail.title` (string) dipertahankan → group_key/manuskrip/arsip tak berubah.

**Tech Stack:** Laravel 11, Eloquent, Spatie roles, Blade + select2 (bundled), DataTables.

**Spec:** `docs/superpowers/specs/2026-07-02-title-order-link-design.md`

**Catatan env:** Tests pakai `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell), tunggu ~6 dtk. Migrasi terakhir: `2026_07_02_000004`. Setelah selesai: `php artisan migrate` di dev. Commit: `git add <path eksplisit>` + trailer `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic", jangan `git add .`.

**Konvensi order (temuan):** `Order->details` = satu `OrderDetail` (hasOne-style; kode memakai `$order->details->title` & `$order->details()->update(...)`). Form: `book/create` (type l.39, title l.49, scope l.55), `orders/edit` = book edit (type l.39, title l.65, scope l.72), `journal/create` (type l.39, title l.49, scope l.55, indexation l.65), `journal/edit` (pakai `$d=$order->details`; type l.50, title l.60, scope l.66, indexation l.76). Judul order buku **tak** punya indeksasi; jurnal punya `indexation` (vocab lowercase `sinta 1`).

---

## Task 1: Migration + model relations

**Files:**
- Create: `database/migrations/2026_07_02_000005_add_title_id_to_tb_order_details.php`
- Modify: `app/Models/OrderDetail.php`, `app/Models/Title.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_02_000005_add_title_id_to_tb_order_details.php`:

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
            $table->foreignId('title_id')->nullable()->after('type')->constrained('tb_titles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('title_id');
        });
    }
};
```

- [ ] **Step 2: OrderDetail — fillable + relation**

In `app/Models/OrderDetail.php`, add `'title_id'` to `$fillable` (after `'type'`):

```php
    protected $fillable = [
        'order_id', 'type', 'title_id', 'title', 'slug',
        'chapters', 'indexation',
        'naskah_type', 'publication_type',
        'cost_amount', 'group_key',
    ];
```

And add this relation (after the `order()` method):

```php
    public function titleRef()
    {
        return $this->belongsTo(Title::class, 'title_id');
    }
```

- [ ] **Step 3: Title — orderDetails relation**

In `app/Models/Title.php`, add (after the `assignedMarketing()` method):

```php
    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class, 'title_id');
    }
```

- [ ] **Step 4: Verify migration healthy**

Run: `php artisan test --filter=OrderDetailGroupKeyTest`
Expected: PASS (RefreshDatabase migrates the new column cleanly).

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_07_02_000005_add_title_id_to_tb_order_details.php app/Models/OrderDetail.php app/Models/Title.php
git commit -m "feat(order-title): title_id FK on order details + relations

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `TitleService::resolveForOrder` (TDD)

**Files:**
- Modify: `app/Services/TitleService.php`
- Test: `tests/Unit/TitleServiceTest.php`

- [ ] **Step 1: Write failing tests** — append inside `TitleServiceTest` (before the final closing `}`):

```php
    /** @test */
    public function resolve_for_order_returns_existing_title_by_id(): void
    {
        $prod = $this->user('production');
        $existing = $this->svc->create(['title' => 'Judul Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri'], [], $prod);

        $resolved = $this->svc->resolveForOrder((string) $existing->id, ['jenis' => 'buku', 'order_type' => 'bk_mandiri'], $prod);

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, \App\Models\Title::count());
    }

    /** @test */
    public function resolve_for_order_creates_new_title_from_order_fields(): void
    {
        $mkt = $this->user('marketing');
        $scope = \App\Models\Scope::create(['scope' => 'Hukum']);

        $resolved = $this->svc->resolveForOrder('Judul Baru Order', [
            'jenis' => 'buku', 'order_type' => 'bk_kolab', 'scope_id' => $scope->id, 'indeksasi' => null,
        ], $mkt);

        $this->assertSame('Judul Baru Order', $resolved->title);
        $this->assertSame('buku', $resolved->jenis);
        $this->assertSame('kolaborasi', $resolved->tipe_naskah);
        $this->assertSame('order', $resolved->asal);
        $this->assertSame('disetujui', $resolved->status);
        $this->assertSame($scope->id, $resolved->scope_id);
        $this->assertSame($mkt->id, $resolved->created_by);
        $this->assertSame($mkt->id, $resolved->approved_by);
    }

    /** @test */
    public function resolve_for_order_new_article_title_is_mandiri_from_at_mandiri(): void
    {
        $mkt = $this->user('marketing');
        $resolved = $this->svc->resolveForOrder('Artikel X', ['jenis' => 'artikel', 'order_type' => 'at_mandiri'], $mkt);

        $this->assertSame('artikel', $resolved->jenis);
        $this->assertSame('mandiri', $resolved->tipe_naskah);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleServiceTest`
Expected: FAIL — `Method ... resolveForOrder does not exist`.

- [ ] **Step 3: Implement** — add to `app/Services/TitleService.php` (after `reject()`):

```php
    /**
     * Resolusi judul untuk order: id yang ada → judul tsb; nama baru → buat Title (asal=order, disetujui)
     * dari field order yang sedang diisi. $ctx: jenis, order_type, scope_id?, indeksasi?.
     */
    public function resolveForOrder(int|string $value, array $ctx, User $actor): Title
    {
        if (is_numeric($value)) {
            $existing = Title::find((int) $value);
            if ($existing) {
                return $existing;
            }
        }

        return Title::create([
            'title'       => (string) $value,
            'jenis'       => $ctx['jenis'],
            'tipe_naskah' => str_contains($ctx['order_type'] ?? '', 'kolab') ? 'kolaborasi' : 'mandiri',
            'scope_id'    => $ctx['scope_id'] ?? null,
            'indeksasi'   => $ctx['indeksasi'] ?? null,
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
    }
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS (all, incl. 3 new).

- [ ] **Step 5: Commit**

```
git add app/Services/TitleService.php tests/Unit/TitleServiceTest.php
git commit -m "feat(order-title): TitleService::resolveForOrder (existing id or create from order)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Order STORE (buku+jurnal) resolves title_id + tests

**Files:**
- Modify: `app/Http/Controllers/Pages/OrderBookController.php` (create, store), `app/Http/Controllers/Pages/OrderJournalController.php` (create, store)
- Modify (fix contract): `tests/Feature/TitleProgressTest.php`, `tests/Feature/TagihanLifecycleTest.php`
- Test: `tests/Feature/TitleOrderLinkTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Feature/TitleOrderLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleOrderLinkTest extends TestCase
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

    private function bookPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'bk_mandiri', 'title_id' => 'Judul Order Buku', 'scope_id' => '',
            'chapters' => 3, 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 1000000,
            'contact_phone' => '08123', 'contact_email' => 'c@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ], $over);
    }

    /** @test */
    public function book_store_with_new_name_creates_order_title(): void
    {
        $mkt = $this->user('marketing');
        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload());

        $title = Title::where('title', 'Judul Order Buku')->first();
        $this->assertNotNull($title);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
        $this->assertSame('buku', $title->jenis);

        $detail = OrderDetail::where('title', 'Judul Order Buku')->first();
        $this->assertSame($title->id, $detail->title_id);
    }

    /** @test */
    public function book_store_with_existing_id_links_without_new_title(): void
    {
        $mkt = $this->user('marketing');
        $existing = Title::create(['title' => 'Judul Sudah Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'created_by' => $mkt->id]);

        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload(['title_id' => (string) $existing->id]));

        $this->assertSame(1, Title::count());
        $detail = OrderDetail::where('title_id', $existing->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('Judul Sudah Ada', $detail->title);
    }

    /** @test */
    public function journal_store_with_new_name_creates_article_title(): void
    {
        $mkt = $this->user('marketing');
        $payload = [
            'type' => 'at_kolab', 'title_id' => 'Artikel Order', 'scope_id' => '',
            'indexation' => 'sinta 2', 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 500000,
            'contact_phone' => '08123', 'contact_email' => 'j@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ];
        $this->actingAs($mkt)->post(route('order.journal.store'), $payload);

        $title = Title::where('title', 'Artikel Order')->first();
        $this->assertNotNull($title);
        $this->assertSame('artikel', $title->jenis);
        $this->assertSame('kolaborasi', $title->tipe_naskah);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleOrderLinkTest`
Expected: FAIL (validation error: `title_id`… actually store still expects `title`; details won't link).

- [ ] **Step 3: OrderBookController@create — pass eligible titles**

In `app/Http/Controllers/Pages/OrderBookController.php`, add `use App\Models\Title;` near the other `use App\Models\...;` lines. Then in `create()`, before the `return`, add and include `$titles` in the compact:

```php
        $titles = Title::where('status', 'disetujui')->where('jenis', 'buku')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return \view('orders.book.create', \compact('scopes', 'prefill', 'fromTagihan', 'titles'));
```

- [ ] **Step 4: OrderBookController@store — validate title_id + resolve**

In `store()`, change the validation line `'title' => 'required|string|max:255',` to:

```php
            'title_id'           => 'required|string|max:255',
```

Replace the duplicate-check block (the `$isDuplicate = Order::whereHas('details', … 'title' … $validate['title'] …)`) so it resolves the title name first. Immediately AFTER `$validate = $request->validate([...]);` insert:

```php
        // Nama judul untuk cek duplikat (id lama → nama judulnya; selain itu = nama baru yang diketik).
        $titleName = is_numeric($validate['title_id'])
            ? (\App\Models\Title::find($validate['title_id'])?->title ?? $validate['title_id'])
            : $validate['title_id'];
```

and change the duplicate `whereHas('details', …)` closure to use `$titleName`:

```php
        $isDuplicate = Order::whereHas('details', function ($query) use ($titleName) {
                $query->where('title', $titleName);
            })
            ->whereHas('contact', function ($query) use ($validate) {
                $query->where('cp_email', $validate['contact_email']);
            })
            ->exists();
```

Inside the `DB::transaction(function () use ($validate) {`, change the closure `use` to `use ($validate, $request)` is NOT needed (Auth used directly). Reorder so scope + title resolve BEFORE `OrderDetail::create`. Replace the block from `// ORDER DETAIL` through the scope-attach with:

```php
                // Resolusi scope dulu (dipakai untuk judul baru dari order).
                $scope_id = $validate['scope_id'] ?? null;
                if (!is_numeric($scope_id) && !empty($scope_id)) {
                    $scope_id = Scope::firstOrCreate(['scope' => $scope_id])->id;
                }

                // Resolusi judul: id yang ada, atau buat Title baru (asal=order) dari field order.
                $title = app(\App\Services\TitleService::class)->resolveForOrder($validate['title_id'], [
                    'jenis'      => 'buku',
                    'order_type' => $validate['type'],
                    'scope_id'   => $scope_id ?: null,
                    'indeksasi'  => null,
                ], Auth::user());

                // Generate slug
                $baseSlug = Str::slug($title->title);
                $finalSlug = $baseSlug . '-' . $order->id;

                // ORDER DETAIL
                $detail = OrderDetail::create([
                    'order_id' => $order->id,
                    'type' => $validate['type'],
                    'title_id' => $title->id,
                    'title' => $title->title,
                    'slug' => $finalSlug,
                    'chapters' => $validate['chapters'] ?? null,
                    'naskah_type' => $validate['naskah_type'],
                    'publication_type' => $validate['publication_type'],
                    'cost_amount' => $validate['cost_amount'],
                ]);

                if ($scope_id) {
                    $detail->scopes()->attach($scope_id);
                }
```

(The old `$cleanTitle`/`$baseSlug`/`$finalSlug` lines above the old `OrderDetail::create`, the old `OrderDetail::create` with `'title' => $validate['title']`, and the old scope block are all replaced by the above.)

- [ ] **Step 5: OrderJournalController@create + store — mirror for artikel**

In `app/Http/Controllers/Pages/OrderJournalController.php`, add `use App\Models\Title;`. In `create()`, before `return`, add and include `$titles`:

```php
        $titles = Title::where('status', 'disetujui')->where('jenis', 'artikel')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.journal.create', \compact('scopes', 'prefill', 'fromTagihan', 'titles'));
```

In `store()`, change `'title' => 'required|string|max:255',` → `'title_id' => 'required|string|max:255',`. After `$validate = $request->validate([...]);` insert the same `$titleName` block, and change the duplicate `whereHas('details', …)` to use `$titleName` (identical to Step 4). Reorder inside the transaction — replace from `// Generate slug` through the scope-attach with:

```php
                // Resolusi scope dulu.
                $scope_id = $validate['scope_id'] ?? null;
                if (!is_numeric($scope_id) && !empty($scope_id)) {
                    $scope_id = Scope::firstOrCreate(['scope' => $scope_id])->id;
                }

                // Resolusi judul (jenis artikel).
                $title = app(\App\Services\TitleService::class)->resolveForOrder($validate['title_id'], [
                    'jenis'      => 'artikel',
                    'order_type' => $validate['type'],
                    'scope_id'   => $scope_id ?: null,
                    'indeksasi'  => $validate['indexation'] ?? null,
                ], Auth::user());

                $finalSlug = Str::slug($title->title) . '-' . $order->id;

                // ORDER DETAIL
                $detail = OrderDetail::create([
                    'order_id' => $order->id,
                    'type' => $validate['type'],
                    'title_id' => $title->id,
                    'title' => $title->title,
                    'slug' => $finalSlug,
                    'indexation' => $validate['indexation'],
                    'naskah_type' => $validate['naskah_type'],
                    'publication_type' => $validate['publication_type'],
                    'cost_amount' => $validate['cost_amount'],
                ]);

                if ($scope_id) {
                    $detail->scopes()->attach($scope_id);
                }
```

- [ ] **Step 6: Fix existing store-endpoint tests (contract change)**

In `tests/Feature/TitleProgressTest.php` and `tests/Feature/TagihanLifecycleTest.php`, every `$payload` (or inline array) posted to `route('order.book.store')` / `route('order.journal.store')` currently has a `'title' => '...'` key. Rename that key to `'title_id'` (keep the same string value — it becomes a new-name that creates a Title). There are 3 posts in TitleProgressTest (lines ~154, ~191, ~237) and 2 in TagihanLifecycleTest (lines ~224, ~252). Only rename the key on the payloads posted to those store routes; do NOT touch `OrderDetail::create([... 'title' => ...])` calls (those keep `title`).

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter="TitleOrderLinkTest|TitleProgressTest|TagihanLifecycleTest|TitleServiceTest"`
Expected: PASS all.

- [ ] **Step 8: Commit**

```
git add app/Http/Controllers/Pages/OrderBookController.php app/Http/Controllers/Pages/OrderJournalController.php tests/Feature/TitleOrderLinkTest.php tests/Feature/TitleProgressTest.php tests/Feature/TagihanLifecycleTest.php
git commit -m "feat(order-title): order store resolves title_id (buku+jurnal)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Order UPDATE (buku+jurnal) resolves title_id + test

**Files:**
- Modify: `OrderBookController.php` (edit, update), `OrderJournalController.php` (edit, update)
- Modify (fix contract): `tests/Feature/OrderJournalEditTest.php`
- Test: `tests/Feature/TitleOrderLinkTest.php` (add edit test)

- [ ] **Step 1: Add failing edit test** — append inside `TitleOrderLinkTest` (before final `}`):

```php
    /** @test */
    public function journal_update_relinks_title(): void
    {
        $mkt = $this->user('marketing');
        $order = Order::create(['code_order' => 'ORD-202607-0009', 'user_id' => $mkt->id, 'status' => 'pending', 'ordered_at' => '2026-07-02']);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'title' => 'Judul Lama',
            'slug' => 'judul-lama-' . $order->id, 'indexation' => 'sinta 3',
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 100,
        ]);
        \App\Models\OrderContact::create(['order_id' => $order->id, 'cp_phone' => '08', 'cp_email' => 'e@example.com']);

        $this->actingAs($mkt)->put(route('order.journal.update', $order->code_order), [
            'type' => 'at_kolab', 'title_id' => 'Judul Baru Relink', 'scope_id' => '',
            'indexation' => 'sinta 2', 'naskah_type' => 'mandiri', 'publication_type' => 'fastrack',
            'issued_at' => '2026-07-03', 'cost_amount' => 200,
            'contact_phone' => '09', 'contact_email' => 'e2@example.com',
            'authors' => [['name' => 'B', 'email' => 'b@example.com', 'position' => 1]],
        ])->assertRedirect();

        $detail->refresh();
        $title = Title::where('title', 'Judul Baru Relink')->first();
        $this->assertNotNull($title);
        $this->assertSame($title->id, $detail->title_id);
        $this->assertSame('Judul Baru Relink', $detail->title);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="TitleOrderLinkTest::journal_update_relinks_title"`
Expected: FAIL (update still validates `title`, ignores `title_id`).

- [ ] **Step 3: OrderJournalController@edit + update**

In `edit()`, add eligible titles (mirror create; `$order` already loaded) — change the return to:

```php
        $scopes = Scope::all();
        $titles = Title::where('status', 'disetujui')->where('jenis', 'artikel')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.journal.edit', compact('order', 'scopes', 'titles'));
```

In `update()`, change validation `'title' => 'required|string|max:255',` → `'title_id' => 'required|string|max:255',`. Inside the `DB::transaction`, after `$order->update([...])` and BEFORE `$order->details()->update([...])`, resolve scope then title, and change the details update to set `title_id` + `title`:

```php
                $detail = $order->details;

                $scopeId = null;
                if ($request->filled('scope_id')) {
                    $scopeId = $request->scope_id;
                    if (! is_numeric($scopeId)) {
                        $scopeId = Scope::firstOrCreate(['scope' => $scopeId])->id;
                    }
                }

                $title = app(\App\Services\TitleService::class)->resolveForOrder($request->title_id, [
                    'jenis'      => 'artikel',
                    'order_type' => $request->type,
                    'scope_id'   => $scopeId,
                    'indeksasi'  => $request->indexation,
                ], Auth::user());

                $order->details()->update([
                    'title_id'         => $title->id,
                    'title'            => $title->title,
                    'type'             => $request->type,
                    'indexation'       => $request->indexation,
                    'naskah_type'      => $request->naskah_type,
                    'publication_type' => $request->publication_type,
                    'cost_amount'      => $request->cost_amount,
                ]);

                if ($scopeId) {
                    $detail->scopes()->sync([$scopeId]);
                }
```

(Remove the old separate `$detail = $order->details;` and the old scope `if ($request->filled('scope_id'))` block below the details update — they are merged above. Keep the contact update + authors block unchanged.)

- [ ] **Step 4: OrderBookController@edit + update**

In `OrderBookController@edit`, add `$titles` (jenis=buku, same pattern) and pass to the view (the book edit view is `orders.edit`):

```php
        $scopes = Scope::all();
        $titles = Title::where('status', 'disetujui')->where('jenis', 'buku')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.edit', compact('order', 'scopes', 'titles'));
```

In `OrderBookController@update`, change validation `'title' => ...` → `'title_id' => 'required|string|max:255',`. Inside its transaction, resolve scope + title and set `title_id`+`title` on the details update — mirror Step 3 but with `'jenis' => 'buku'` and `'indeksasi' => null` (book has no indexation). Use the same reorder: resolve `$scopeId`, then `$title = resolveForOrder(...)`, then `$order->details()->update(['title_id'=>$title->id,'title'=>$title->title, ...])`, then `$detail->scopes()->sync([$scopeId])` when set. (Match the existing book update structure; keep author/contact blocks intact.)

- [ ] **Step 5: Fix OrderJournalEditTest contract**

In `tests/Feature/OrderJournalEditTest.php` (~line 63), the `put(route('order.journal.update', …), [...])` payload has `'title' => 'Judul Baru'`. Rename that key to `'title_id' => 'Judul Baru'`. If the test asserts `OrderDetail ... title == 'Judul Baru'`, that still holds (resolved title name). Leave `OrderDetail::create([... 'title' => 'Judul Lama'])` untouched.

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter="TitleOrderLinkTest|OrderJournalEditTest"`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/OrderBookController.php app/Http/Controllers/Pages/OrderJournalController.php tests/Feature/TitleOrderLinkTest.php tests/Feature/OrderJournalEditTest.php
git commit -m "feat(order-title): order update relinks title_id (buku+jurnal)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Order form views — title select2 + auto-fill JS

**Files:**
- Modify: `resources/views/orders/book/create.blade.php`, `resources/views/orders/edit.blade.php`, `resources/views/orders/journal/create.blade.php`, `resources/views/orders/journal/edit.blade.php`

Each view: replace the free-text Judul input with a select2 `title_id` whose options carry `data-tipe-naskah`, `data-scope-id`, `data-indeksasi`, plus a small autofill script. Book uses prefix `bk`, journal uses `at`.

- [ ] **Step 1: book/create — replace title input**

In `resources/views/orders/book/create.blade.php`, replace:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required value="{{ old('title', $prefill['title'] ?? '') }}">
                        </div>
```

with:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <select name="title_id" id="title_id" class="form-select select2" data-tags="true" required>
                                <option value="">Pilih judul disetujui / ketik judul baru</option>
                                @foreach ($titles as $t)
                                    <option value="{{ $t->id }}"
                                        data-tipe-naskah="{{ $t->tipe_naskah }}"
                                        data-scope-id="{{ $t->scope_id }}"
                                        data-indeksasi="{{ $t->indeksasi }}"
                                        {{ (string) old('title_id') === (string) $t->id ? 'selected' : '' }}>{{ $t->title }}</option>
                                @endforeach
                                @if($prefill['title'] ?? false)
                                    <option value="{{ $prefill['title'] }}" selected>{{ $prefill['title'] }}</option>
                                @endif
                            </select>
                            <small class="text-muted">Pilih dari daftar judul disetujui, atau ketik judul baru bila belum ada.</small>
                        </div>
```

- [ ] **Step 2: book/create — autofill script**

At the very end of `resources/views/orders/book/create.blade.php`, inside the existing `@push('custom-scripts')` block (append before its closing `@endpush`), add:

```blade
    <script>
    (function () {
        var el = document.getElementById('title_id');
        if (!el) return;
        function applyTitle() {
            var opt = el.options[el.selectedIndex];
            if (!opt || !opt.dataset || !opt.dataset.tipeNaskah) return; // judul baru / kosong
            var typeSel = document.querySelector('[name="type"]');
            if (typeSel) typeSel.value = 'bk_' + (opt.dataset.tipeNaskah === 'kolaborasi' ? 'kolab' : 'mandiri');
            if (opt.dataset.scopeId) {
                var sc = document.getElementById('scope_id');
                if (sc) { sc.value = opt.dataset.scopeId; if (window.jQuery) jQuery(sc).trigger('change'); }
            }
        }
        if (window.jQuery) { jQuery(el).on('change', applyTitle); } else { el.addEventListener('change', applyTitle); }
    })();
    </script>
```

(If `book/create` has no `@push('custom-scripts')`, add one after the `@endsection`. Verify the view already loads select2 via `assets/js/select2.js` — it does, for the scope field.)

- [ ] **Step 3: journal/create — replace title input + script**

In `resources/views/orders/journal/create.blade.php`, replace the `<input type="text" name="title" …>` block (same shape as book) with the same select2 markup as Step 1 **but** add `data-indeksasi` usage stays; keep the `@if($prefill['title'] ?? false)` fallback. Then append an autofill script like Step 2 but: prefix `at_`, and also set indexation case-insensitively:

```blade
    <script>
    (function () {
        var el = document.getElementById('title_id');
        if (!el) return;
        function applyTitle() {
            var opt = el.options[el.selectedIndex];
            if (!opt || !opt.dataset || !opt.dataset.tipeNaskah) return;
            var typeSel = document.querySelector('[name="type"]');
            if (typeSel) typeSel.value = 'at_' + (opt.dataset.tipeNaskah === 'kolaborasi' ? 'kolab' : 'mandiri');
            if (opt.dataset.scopeId) {
                var sc = document.getElementById('scope_id');
                if (sc) { sc.value = opt.dataset.scopeId; if (window.jQuery) jQuery(sc).trigger('change'); }
            }
            var ix = (opt.dataset.indeksasi || '').toLowerCase();
            if (ix) {
                var idxSel = document.querySelector('[name="indexation"]');
                if (idxSel) {
                    for (var i = 0; i < idxSel.options.length; i++) {
                        if (idxSel.options[i].value.toLowerCase() === ix) { idxSel.selectedIndex = i; if (window.jQuery) jQuery(idxSel).trigger('change'); break; }
                    }
                }
            }
        }
        if (window.jQuery) { jQuery(el).on('change', applyTitle); } else { el.addEventListener('change', applyTitle); }
    })();
    </script>
```

- [ ] **Step 4: orders/edit (book edit) — replace title input**

In `resources/views/orders/edit.blade.php`, replace:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" value="{{ $order->details->title }}"
                                required>
                        </div>
```

with a select2 `title_id` that preselects the current linked title (or shows the current title text as a selected option so it round-trips):

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <select name="title_id" id="title_id" class="form-select select2" data-tags="true" required>
                                @php $curId = old('title_id', $order->details->title_id); @endphp
                                @foreach ($titles as $t)
                                    <option value="{{ $t->id }}"
                                        data-tipe-naskah="{{ $t->tipe_naskah }}"
                                        data-scope-id="{{ $t->scope_id }}"
                                        data-indeksasi="{{ $t->indeksasi }}"
                                        {{ (string) $curId === (string) $t->id ? 'selected' : '' }}>{{ $t->title }}</option>
                                @endforeach
                                @unless($order->details->title_id)
                                    <option value="{{ $order->details->title }}" selected>{{ $order->details->title }}</option>
                                @endunless
                            </select>
                        </div>
```

Then append the same autofill script as Step 2 (book, `bk_` prefix) inside this view's scripts stack.

- [ ] **Step 5: journal/edit — replace title input**

In `resources/views/orders/journal/edit.blade.php` (uses `$d = $order->details`), replace the `<input type="text" name="title" … value="{{ old('title', $d->title) }}">` block with the same select2 as Step 4 but using `$d`:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <select name="title_id" id="title_id" class="form-select select2" data-tags="true" required>
                                @php $curId = old('title_id', $d->title_id); @endphp
                                @foreach ($titles as $t)
                                    <option value="{{ $t->id }}"
                                        data-tipe-naskah="{{ $t->tipe_naskah }}"
                                        data-scope-id="{{ $t->scope_id }}"
                                        data-indeksasi="{{ $t->indeksasi }}"
                                        {{ (string) $curId === (string) $t->id ? 'selected' : '' }}>{{ $t->title }}</option>
                                @endforeach
                                @unless($d->title_id)
                                    <option value="{{ $d->title }}" selected>{{ $d->title }}</option>
                                @endunless
                            </select>
                        </div>
```

Then append the journal autofill script (Step 3, `at_` prefix + indexation).

- [ ] **Step 6: Compile + smoke**

Run: `php artisan view:cache` (no error) then `php artisan view:clear`.
Run: `php artisan test --filter="OrderJournalEditTest|TitleOrderLinkTest"`
Expected: PASS (create/edit pages render, forms still post successfully).

- [ ] **Step 7: Commit**

```
git add resources/views/orders/book/create.blade.php resources/views/orders/edit.blade.php resources/views/orders/journal/create.blade.php resources/views/orders/journal/edit.blade.php
git commit -m "feat(order-title): order forms pick title (select2 + auto-fill)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 6: Directory counts (jml order / jml author)

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (index, show)
- Modify: `resources/views/titles/index.blade.php`, `resources/views/titles/show.blade.php`
- Test: `tests/Feature/TitleOrderLinkTest.php` (add counts test)

- [ ] **Step 1: Add failing counts test** — append inside `TitleOrderLinkTest` (before final `}`):

```php
    /** @test */
    public function directory_shows_order_and_author_counts(): void
    {
        $mkt = $this->user('marketing');
        // dua order menaut judul yang sama (nama baru) → 1 Title, 2 order detail, 2 author
        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload(['contact_email' => 'x1@example.com']));
        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload([
            'contact_email' => 'x2@example.com',
            'authors' => [['name' => 'B', 'email' => 'b2@example.com', 'position' => 1], ['name' => 'C', 'email' => 'c2@example.com', 'position' => 2]],
        ]));

        $title = Title::where('title', 'Judul Order Buku')->first();
        $this->assertSame(2, $title->orderDetails()->count());

        $mgr = $this->user('manager');
        $this->actingAs($mgr)->get(route('title.show', $title->id))
            ->assertOk()->assertSee('Jml Order')->assertSee('Jml Author');
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="TitleOrderLinkTest::directory_shows_order_and_author_counts"`
Expected: FAIL (show page lacks the count labels).

- [ ] **Step 3: TitleController@index — counts**

In `app/Http/Controllers/Pages/TitleController.php`, change the index query to include counts. Replace:

```php
        $query = Title::with(['creator', 'scope', 'assignedMarketing'])->latest();
```

with:

```php
        $query = Title::with(['creator', 'scope', 'assignedMarketing'])
            ->withCount('orderDetails as orders_count')
            ->withCount(['orderDetails as authors_count' => function ($q) {
                $q->join('tb_author_orders', 'tb_author_orders.order_detail_id', '=', 'tb_order_details.id');
            }])
            ->latest();
```

> `withCount` with the join counts author-pivot rows across linked order details. (If the join form misbehaves on the grouping, fall back to a `selectSub` counting `tb_author_orders` where `order_detail_id in (select id from tb_order_details where title_id = tb_titles.id)`.)

- [ ] **Step 4: TitleController@show — counts + linked orders**

In `show()`, eager-load linked orders and pass counts. Change the `$title = Title::with([...])->findOrFail($id);` to also load `orderDetails.order.user`:

```php
        $title = Title::with(['chapters', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user'])->findOrFail($id);
```

and add before the `return view('titles.show', [...])`:

```php
        $ordersCount = $title->orderDetails->count();
        $authorsCount = \App\Models\OrderDetail::where('title_id', $title->id)
            ->withCount('authors')->get()->sum('authors_count');
```

then include `'ordersCount' => $ordersCount, 'authorsCount' => $authorsCount` in the view array.

- [ ] **Step 5: index view — columns**

In `resources/views/titles/index.blade.php`, add two headers after `<th>Tipe</th>`:

```blade
<th>Jml Order</th><th>Jml Author</th>
```

and two cells after the `<td>{{ ucfirst($t->tipe_naskah) }}</td>` cell:

```blade
                        <td>{{ $t->orders_count ?? 0 }}</td>
                        <td>{{ $t->authors_count ?? 0 }}</td>
```

- [ ] **Step 6: show view — counts + linked orders**

In `resources/views/titles/show.blade.php`, after the Distribusi `<p>` line, add:

```blade
    <p class="mb-2">Order tertaut: <strong>Jml Order</strong> {{ $ordersCount }} · <strong>Jml Author</strong> {{ $authorsCount }}</p>
    @if($title->orderDetails->isNotEmpty())
        <h6 class="card-title mt-3">Order Tertaut</h6>
        <div class="table-responsive">
            <table class="table table-sm table-borderless mb-3">
                <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th></tr></thead>
                <tbody>
                    @foreach($title->orderDetails as $od)
                        <tr>
                            <td>{{ $od->order?->code_order ?? '—' }}</td>
                            <td>{{ $od->order?->user?->name ?? '—' }}</td>
                            <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
```

- [ ] **Step 7: Compile + run**

Run: `php artisan view:cache` (no error) then `php artisan view:clear`.
Run: `php artisan test --filter="TitleOrderLinkTest|TitlePagesTest|TitleControllerTest"`
Expected: PASS all.

- [ ] **Step 8: Commit**

```
git add app/Http/Controllers/Pages/TitleController.php resources/views/titles/index.blade.php resources/views/titles/show.blade.php tests/Feature/TitleOrderLinkTest.php
git commit -m "feat(order-title): directory shows jml order/author + linked orders

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 7: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (302 sebelumnya + TitleOrderLinkTest (~5) + 3 service tests baru = ~310).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (adds `title_id` to `tb_order_details`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login marketing → Buat Order Buku → dropdown Judul memuat judul buku disetujui yang didistribusi ke dia; pilih satu → tipe & scope terisi; atau ketik judul baru → order lanjut, judul baru muncul di Direktori Judul (asal=order) dengan Jml Order 1.

---

## Catatan & Risiko

- `OrderDetail.title` (string) dipertahankan → `group_key`/manuskrip/Arsip Judul tak berubah. Manuskrip pindah ke `title_id` = **Fase 2b**.
- Kontrak form order berubah `title`→`title_id`; test endpoint order (TitleProgress/TagihanLifecycle/OrderJournalEdit) disesuaikan; `OrderDetail::create(['title'=>…])` lain tetap valid.
- Judul inline dari order langsung disetujui (`asal=order`); duplikat judul mungkin muncul (penggabungan di luar scope).
- Indeksasi order buku = null; jurnal memakai vocab lowercase (`sinta n`) — auto-isi indexation dilakukan case-insensitive best-effort.
