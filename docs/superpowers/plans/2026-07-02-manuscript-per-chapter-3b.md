# Manuskrip per Bab — Fase 3b (Papan Expand-Panel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Di papan manuskrip, kartu **buku** menampilkan status roll-up + tidak bisa di-drag + **expand-panel** daftar bab (per bab: tahap + editor + tombol Maju + assign). Artikel tak berubah. Aksi bab via endpoint AJAX baru.

**Architecture:** `ChapterProgressController` (advance/assign) memakai `ChapterManuscriptService` (3a). `ManuscriptTrackerController::index` memuat bab (lazy `ensureChapters`) untuk kartu buku. Card partial + board JS diberi cabang buku (non-drag + panel bab).

**Tech Stack:** Laravel 11, Blade, SortableJS (bundled), Bootstrap collapse.

**Spec:** `docs/superpowers/specs/2026-07-02-manuscript-per-chapter-design.md` (§3). Fondasi (3a) sudah merged.

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell). Commit: `git add <path>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic". Tanpa migrasi.

**Fakta:**
- `ChapterManuscriptService` (3a): `ensureChapters(Title)`, `changeStatus(ChapterProgress,$target,User,?note)`, `assignEditor(ChapterProgress,?int,User)`, `syncBookStatus(Title)`. Otorisasi: superadmin/manager bebas; production hanya tahap production.
- `ChapterProgress::chapter()->title`; `TitleChapter` punya `progress()` hasOne, `judul`, `urutan`; `Title::chapters()` (urut urutan).
- `ManuscriptTrackerController::index` mengirim `groups` (koleksi repr `OrderDetail` dgn atribut grup), `stages`, `byStatus`, `tipe`, `editors`, `zones`, dst. Kartu = `manuscript/partials/card.blade.php`; `$detail` = repr OrderDetail; `$detail->titleRef` = Title. Buku type = `bk_mandiri|bk_kolab`.
- Manuscript routes ada di grup ber-prefix `management` (board JS `base = url('management/manuscript')`); aksi existing `manuscript/{id}/move|assign|...` name `manuscript.*`, gated `role:superadmin|manager|production`.
- `runOrFlash(Request, Closure)` pola di `ManuscriptTrackerController` (JSON untuk AJAX, redirect+flash untuk non-JSON) — tiru di controller baru.

---

## Task 1: `ChapterProgressController` + routes (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/ChapterProgressController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChapterProgressControllerTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Feature/ChapterProgressControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\ChapterManuscriptService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterProgressControllerTest extends TestCase
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

    /** Buku ber-bab @ editing, kembalikan bab pertama. */
    private function firstChapter(): \App\Models\ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Bab', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-CP-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Bab', 'slug' => 'buku-bab', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        return $book->chapters()->with('progress')->orderBy('urutan')->first()->progress;
    }

    /** @test */
    public function production_advances_chapter(): void
    {
        $cp = $this->firstChapter(); // editing
        $this->actingAs($this->user('production'))
            ->post(route('chapter.advance', $cp->id), ['status' => 'layout'])
            ->assertRedirect();
        $this->assertSame('layout', $cp->fresh()->status);
    }

    /** @test */
    public function marketing_cannot_advance_chapter(): void
    {
        $cp = $this->firstChapter();
        $this->actingAs($this->user('marketing'))
            ->post(route('chapter.advance', $cp->id), ['status' => 'layout'])
            ->assertForbidden();
    }

    /** @test */
    public function manager_assigns_chapter_editor(): void
    {
        $cp = $this->firstChapter();
        $editor = $this->user('production');
        $this->actingAs($this->user('manager'))
            ->post(route('chapter.assign', $cp->id), ['assigned_user_id' => $editor->id])
            ->assertRedirect();
        $this->assertSame($editor->id, $cp->fresh()->assigned_user_id);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterProgressControllerTest`
Expected: FAIL — route `chapter.advance` undefined.

- [ ] **Step 3: Controller** — create `app/Http/Controllers/Pages/ChapterProgressController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChapterProgressController extends Controller
{
    public function __construct(private ChapterManuscriptService $service) {}

    private function runOrFlash(Request $request, \Closure $action): ?RedirectResponse
    {
        try {
            $action();
            return null;
        } catch (AuthorizationException | ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');
            return back()->with('error', $message);
        }
    }

    public function advance(Request $request, int $id)
    {
        $cp = ChapterProgress::findOrFail($id);
        $target = (string) $request->input('status');

        if ($redirect = $this->runOrFlash($request, fn () =>
            $this->service->changeStatus($cp, $target, Auth::user(), $request->input('note'))
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $cp->id, 'status' => $target, 'message' => 'Bab diperbarui.']);
        }
        return back()->with('success', 'Bab diperbarui.');
    }

    public function assign(Request $request, int $id)
    {
        $cp = ChapterProgress::findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        if ($redirect = $this->runOrFlash($request, fn () =>
            $this->service->assignEditor($cp, $userId, Auth::user())
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $cp->id, 'message' => 'Editor bab diperbarui.']);
        }
        return back()->with('success', 'Editor bab diperbarui.');
    }
}
```

- [ ] **Step 4: Routes** — in `routes/web.php`, add `use App\Http\Controllers\Pages\ChapterProgressController;` near the other Pages imports. Then locate the manuscript route group (where `manuscript/{id}/move` name `manuscript.move` is registered, gated `role:superadmin|manager|production`) and add, inside that same group:

```php
        Route::post('manuscript/chapter/{id}/advance', [ChapterProgressController::class, 'advance'])
            ->name('chapter.advance')->whereNumber('id');
        Route::post('manuscript/chapter/{id}/assign', [ChapterProgressController::class, 'assign'])
            ->name('chapter.assign')->whereNumber('id');
```
Run `php artisan route:list --name=chapter` to confirm both registered with the correct role middleware.

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=ChapterProgressControllerTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/ChapterProgressController.php routes/web.php tests/Feature/ChapterProgressControllerTest.php
git commit -m "feat(chapter-ms): ChapterProgressController advance/assign + routes

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Board — book expand-panel + non-drag + JS

**Files:**
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php` (index: load chapters + ensureChapters lazy for books)
- Modify: `resources/views/manuscript/partials/card.blade.php` (book branch: non-drag + chapter panel)
- Modify: `resources/views/manuscript/board.blade.php` (JS: chapter actions + SortableJS filter for non-drag)
- Test: `tests/Feature/ChapterBoardTest.php`

- [ ] **Step 1: Write failing smoke test** — create `tests/Feature/ChapterBoardTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterBoardTest extends TestCase
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

    /** @test */
    public function book_board_renders_chapter_panel(): void
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Papan', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-BB-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Papan', 'slug' => 'buku-papan', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        app(\App\Services\TitleProgressService::class)->createForDetail($detail, $owner->id);

        $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()->assertSee('Bab')->assertSee('data-no-drag', false);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterBoardTest`
Expected: FAIL (no chapter panel / no data-no-drag yet).

- [ ] **Step 3: Controller index — load chapters + lazy ensure (books only)**

In `ManuscriptTrackerController::index`, the `$details` query `->with([...])` chain: add `'titleRef.chapters.progress.assignedUser'` to the eager-load array. Then, AFTER `$groups = $this->buildGroupCards($details, $stages);` and BEFORE the sort, when `$tipe === 'buku'`, ensure chapters for each group's book (idempotent lazy seed):
```php
        if ($tipe === 'buku') {
            $svc = app(\App\Services\ChapterManuscriptService::class);
            foreach ($groups as $g) {
                if ($g->titleRef) {
                    $svc->ensureChapters($g->titleRef);
                }
            }
            // muat ulang relasi bab yang mungkin baru dibuat
            $groups->each(fn ($g) => optional($g->titleRef)->load('chapters.progress.assignedUser'));
        }
```
(`$g` = repr OrderDetail; `$g->titleRef` = its Title.)

- [ ] **Step 4: Card partial — book branch**

In `resources/views/manuscript/partials/card.blade.php`:

(a) At the top `@php` block, add:
```php
    $isBook   = in_array($detail->type, ['bk_mandiri', 'bk_kolab'], true);
    $chapters = $isBook ? optional($detail->titleRef)->chapters ?? collect() : collect();
```

(b) On the root card `<div class="card mb-2 mt-card" ...>`, add `@if($isBook) data-no-drag @endif` so book cards are excluded from drag.

(c) For books, HIDE the whole-book "Majukan ke {{ next }}" form (status is derived from chapters). Wrap the existing `@if($next) ... @endif` move `<li>` block with `@unless($isBook) ... @endunless` (i.e., only articles get the whole-book advance).

(d) Append, just before the card's closing `</div></div>` (after the needs_review block), a chapter panel for books:
```blade
        @if($isBook && $chapters->isNotEmpty())
            <div class="mt-2 pt-2 border-top">
                <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#chapters-{{ $p->id }}" style="font-size:11px">
                    📖 Bab ({{ $chapters->count() }})
                </button>
                <div class="collapse mt-1" id="chapters-{{ $p->id }}">
                    @foreach($chapters as $ch)
                        @php
                            $cp = $ch->progress;
                            $cstatus = optional($cp)->status ?? 'menunggu_proses';
                            $cnext = ($ix = array_search($cstatus, \App\Models\TitleProgress::BOOK_STAGES, true)) === false ? null : (\App\Models\TitleProgress::BOOK_STAGES[$ix + 1] ?? null);
                        @endphp
                        <div class="border rounded p-2 mb-1" style="font-size:11px">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold text-truncate" style="max-width:150px">{{ $ch->urutan }}. {{ $ch->judul }}</span>
                                <span class="badge {{ in_array($cstatus, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ \App\Models\Title::stageLabel($cstatus) }}</span>
                            </div>
                            <div class="text-muted mt-1">Editor: {{ optional(optional($cp)->assignedUser)->name ?? 'Belum' }}</div>
                            @if($cp)
                            <div class="d-flex gap-1 mt-1">
                                @if($cnext)
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0" data-chapter-advance data-cp="{{ $cp->id }}" data-next="{{ $cnext }}" style="font-size:10px">Maju → {{ \App\Models\Title::stageLabel($cnext) }}</button>
                                @endif
                                <select class="form-select form-select-sm py-0" data-chapter-assign data-cp="{{ $cp->id }}" style="font-size:10px; max-width:130px">
                                    <option value="">Editor…</option>
                                    @foreach($editors as $ed)
                                        <option value="{{ $ed->id }}" {{ optional($cp)->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
```

- [ ] **Step 5: Board JS — chapter actions + non-drag filter**

In `resources/views/manuscript/board.blade.php` `@push('custom-scripts')` script:

(a) Add `filter: '[data-no-drag]'` and `preventOnFilter: false` to EACH `new Sortable(col, { ... })` config (so book cards can't be dragged).

(b) Append, before the closing `})();`, chapter action handlers (event delegation on document):
```javascript
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-chapter-advance]');
        if (!btn) return;
        e.preventDefault();
        fetch(base + '/chapter/' + btn.getAttribute('data-cp') + '/advance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ status: btn.getAttribute('data-next') }),
        })
        .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Bab diperbarui.', true); setTimeout(() => location.reload(), 500); })
        .catch((err) => toast(err.message, false));
    });

    document.addEventListener('change', function (e) {
        const sel = e.target.closest('[data-chapter-assign]');
        if (!sel) return;
        fetch(base + '/chapter/' + sel.getAttribute('data-cp') + '/assign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ assigned_user_id: sel.value }),
        })
        .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Editor bab diperbarui.', true); })
        .catch((err) => toast(err.message, false));
    });
```
(`base`, `token`, `toast` already defined in that script.)

- [ ] **Step 6: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="ChapterBoardTest|ChapterProgressControllerTest|ManuscriptTrackerTest"`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php resources/views/manuscript/partials/card.blade.php resources/views/manuscript/board.blade.php tests/Feature/ChapterBoardTest.php
git commit -m "feat(chapter-ms): board book expand-panel (per-chapter advance/assign) + non-drag

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Full verification

**Files:** none.

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (347 + ChapterProgressControllerTest (3) + ChapterBoardTest (1) = ~351). Perhatikan `ManuscriptTrackerTest` tetap hijau (kartu artikel tak berubah; kartu buku kini non-drag + panel bab).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Smoke (opsional)**

Login production → Manuscript Tracker (tipe Buku) → kartu buku tampil status roll-up + "📖 Bab (n)"; expand → tiap bab punya tahap + editor + tombol Maju + select editor; klik Maju → bab maju, kartu buku roll-up. Kartu buku tak bisa di-drag; artikel tetap bisa.

---

## Catatan & Risiko

- `ensureChapters` dipanggil lazy di index (buku) → idempotent; write-on-GET untuk seed bab buku lama (dapat dioptimasi kelak).
- Kartu buku non-drag (`data-no-drag` + SortableJS `filter`); artikel tetap drag.
- Aksi bab reload halaman saat sukses (sederhana & andal untuk v1); refinement update-DOM parsial di luar scope.
- Lompat/mundur tahap bab (butuh catatan) belum diekspos di UI v1 — hanya "Maju → next" (tak perlu catatan). Koreksi via service tetap ada bila diperlukan nanti.
- Alur artikel & papan artikel tak tersentuh.
