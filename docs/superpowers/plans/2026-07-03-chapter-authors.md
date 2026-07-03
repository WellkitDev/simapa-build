# Author per Bab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Tiap bab buku bisa punya author sendiri (banyak, terurut) via pivot `tb_title_chapter_authors`; dikelola di panel "Bab & Author" pada detail judul (superadmin/manager/admin); papan manuskrip + daftar bab menampilkan (read).

**Architecture:** Pivot bab↔author + `ChapterAuthorService::syncChapterAuthors` (author existing by id / nama baru → `Author::firstOrCreate`). Panel di `titles/show.blade.php` (select2 multi per bab). Author level order (tb_author_orders) tak berubah.

**Tech Stack:** Laravel 11, Eloquent belongsToMany, Blade + select2 (bundled).

**Spec:** `docs/superpowers/specs/2026-07-03-chapter-authors-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell). Commit: `git add <path>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic". Migrasi terakhir `2026_07_03_000001`; baru = `2026_07_03_000002`. Setelah selesai: `php artisan migrate` di dev.

**Fakta:**
- `App\Models\Author` (tb_authors): fillable name/email/phone/affiliation; `orderDetails()` belongsToMany. `App\Models\TitleChapter` (tb_title_chapters): fillable title_id/judul/urutan; `title()`, `progress()` (ChapterProgress). `App\Models\Title::chapters()` hasMany (urut urutan).
- `TitleController@show` eager-loads `[... 'chapters', ...]` dan mengirim `title/canManage/isApprover/ordersCount/authorsCount/canViewInfo(superadmin|manager|admin|production)/canEditInfo(superadmin|manager|admin)/canOpenBoard/journals`. Grup route `role:superadmin|manager|admin` (berisi `title.info.update`) ada di `routes/web.php`.
- `titles/show.blade.php`: blok bab buku `@if($title->jenis === 'buku') <h6 ...>Bab</h6> <ol class="mb-3">@forelse($title->chapters as $ch)<li>{{ $ch->judul }}</li>@empty<li ...>Belum ada bab.</li>@endforelse</ol> @endif` (sekitar baris 51–60). Push blocks di akhir memuat flatpickr saja (BUKAN select2); `@push('custom-scripts')` punya `$(function(){ ... })` (flatpickr + joList `initJournalSelect`).
- Board: `ManuscriptTrackerController::index` (tipe buku) eager-load `titleRef.chapters.progress.assignedUser`; `manuscript/partials/card.blade.php` chapter panel `@foreach($chapters as $ch)` menampilkan `{{ $ch->urutan }}. {{ $ch->judul }}` + `<div class="text-muted mt-1">Editor: …</div>`.

---

## Task 1: Migration + models + `ChapterAuthorService` (TDD)

**Files:**
- Create: `database/migrations/2026_07_03_000002_create_tb_title_chapter_authors_table.php`, `app/Services/ChapterAuthorService.php`
- Modify: `app/Models/TitleChapter.php`, `app/Models/Author.php`
- Test: `tests/Unit/ChapterAuthorServiceTest.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_03_000002_create_tb_title_chapter_authors_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_chapter_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_chapter_id')->constrained('tb_title_chapters')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('tb_authors')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_chapter_authors');
    }
};
```

- [ ] **Step 2: Models — relations**

In `app/Models/TitleChapter.php`, add:
```php
    public function authors()
    {
        return $this->belongsToMany(Author::class, 'tb_title_chapter_authors', 'title_chapter_id', 'author_id')
            ->withPivot('position')->orderByPivot('position');
    }
```
In `app/Models/Author.php`, add:
```php
    public function chapters()
    {
        return $this->belongsToMany(TitleChapter::class, 'tb_title_chapter_authors', 'author_id', 'title_chapter_id');
    }
```

- [ ] **Step 3: Write failing test** — create `tests/Unit/ChapterAuthorServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\Author;
use App\Services\ChapterAuthorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChapterAuthorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChapterAuthorService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChapterAuthorService();
    }

    private function book(int $chapters = 2): Title
    {
        $book = Title::create(['title' => 'Buku Author', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        for ($i = 1; $i <= $chapters; $i++) {
            $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
        }
        return $book;
    }

    /** @test */
    public function sync_links_existing_and_creates_new_authors_with_position(): void
    {
        $book = $this->book(2);
        $ch1 = $book->chapters()->orderBy('urutan')->first();
        $existing = Author::create(['name' => 'Penulis Lama']);

        $this->svc->syncChapterAuthors($book, [
            $ch1->id => [(string) $existing->id, 'Penulis Baru'],
        ]);

        $authors = $ch1->authors()->get();
        $this->assertSame(2, $authors->count());
        $this->assertSame($existing->id, $authors[0]->id);
        $this->assertSame(1, (int) $authors[0]->pivot->position);
        $this->assertSame('Penulis Baru', $authors[1]->name); // Author baru dibuat
        $this->assertSame(2, (int) $authors[1]->pivot->position);
        $this->assertSame(1, Author::where('name', 'Penulis Baru')->count());
    }

    /** @test */
    public function sync_replaces_previous_set_and_clears_when_absent(): void
    {
        $book = $this->book(2);
        $chapters = $book->chapters()->orderBy('urutan')->get();
        $a = Author::create(['name' => 'A']);
        $b = Author::create(['name' => 'B']);

        $this->svc->syncChapterAuthors($book, [$chapters[0]->id => [(string) $a->id], $chapters[1]->id => [(string) $b->id]]);
        $this->assertSame(1, $chapters[0]->authors()->count());

        // Re-sync: bab 0 diganti ke [B], bab 1 tak disebut → dikosongkan
        $this->svc->syncChapterAuthors($book, [$chapters[0]->id => [(string) $b->id]]);
        $this->assertSame([$b->id], $chapters[0]->authors()->pluck('tb_authors.id')->all());
        $this->assertSame(0, $chapters[1]->authors()->count());
    }

    /** @test */
    public function non_book_is_ignored(): void
    {
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->svc->syncChapterAuthors($art, [999 => ['X']]);
        $this->assertSame(0, Author::count());
    }
}
```

- [ ] **Step 4: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterAuthorServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 5: Implement** — create `app/Services/ChapterAuthorService.php`:

```php
<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Title;

class ChapterAuthorService
{
    /**
     * Sinkron author per bab untuk buku. Panel otoritatif atas SEMUA bab:
     * bab tanpa entri di $chapterAuthors → dikosongkan.
     * @param array $chapterAuthors [title_chapter_id => [authorRef, …]] (authorRef = id existing atau nama baru)
     */
    public function syncChapterAuthors(Title $book, array $chapterAuthors): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        foreach ($book->chapters()->get() as $chapter) {
            $refs  = $chapterAuthors[$chapter->id] ?? [];
            $pivot = [];
            $pos   = 1;

            foreach ((array) $refs as $ref) {
                $ref = is_string($ref) ? trim($ref) : $ref;
                if ($ref === '' || $ref === null) {
                    continue;
                }
                $authorId = is_numeric($ref)
                    ? (int) $ref
                    : Author::firstOrCreate(['name' => $ref])->id;
                $pivot[$authorId] = ['position' => $pos++];
            }

            $chapter->authors()->sync($pivot);
        }
    }
}
```

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=ChapterAuthorServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```
git add database/migrations/2026_07_03_000002_create_tb_title_chapter_authors_table.php app/Services/ChapterAuthorService.php app/Models/TitleChapter.php app/Models/Author.php tests/Unit/ChapterAuthorServiceTest.php
git commit -m "feat(chapter-author): pivot + relations + ChapterAuthorService sync

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Controller `updateChapterAuthors` + route + show data (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php`, `routes/web.php`
- Test: `tests/Feature/ChapterAuthorTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Feature/ChapterAuthorTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Author;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterAuthorTest extends TestCase
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

    private function book(): Title
    {
        $book = Title::create(['title' => 'Buku Bab Author', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        return $book;
    }

    /** @test */
    public function manager_saves_chapter_authors(): void
    {
        $book = $this->book();
        $ch = $book->chapters()->first();
        $author = Author::create(['name' => 'Dr. Contoh']);

        $this->actingAs($this->user('manager'))->put(route('title.chapters.authors', $book->id), [
            'chapter_authors' => [$ch->id => [(string) $author->id, 'Penulis Baru']],
        ])->assertRedirect();

        $this->assertSame(2, $ch->authors()->count());
        $this->assertTrue($ch->authors()->where('tb_authors.id', $author->id)->exists());
    }

    /** @test */
    public function marketing_cannot_save_chapter_authors(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('marketing'))
            ->put(route('title.chapters.authors', $book->id), ['chapter_authors' => []])
            ->assertForbidden();
    }

    /** @test */
    public function show_displays_chapter_authors(): void
    {
        $book = $this->book();
        $ch = $book->chapters()->first();
        $author = Author::create(['name' => 'Nama Tercantum']);
        $ch->authors()->attach($author->id, ['position' => 1]);

        $this->actingAs($this->user('manager'))->get(route('title.show', $book->id))
            ->assertOk()->assertSee('Nama Tercantum');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterAuthorTest`
Expected: FAIL — route `title.chapters.authors` undefined.

- [ ] **Step 3: Controller**

In `app/Http/Controllers/Pages/TitleController.php`:
- Add `use App\Models\Author;` near the imports (if absent).
- In `show()`: change `'chapters'` in the eager-load array to `'chapters.authors'`, and add `'allAuthors' => Author::orderBy('name')->get()` to the array passed to `view('titles.show', [...])`.
- Add the method (e.g. after `updateInfo`):
```php
    public function updateChapterAuthors(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']), 403);
        $title = Title::findOrFail($id);

        $request->validate([
            'chapter_authors'     => 'nullable|array',
            'chapter_authors.*'   => 'nullable|array',
            'chapter_authors.*.*' => 'nullable|string',
        ]);

        app(\App\Services\ChapterAuthorService::class)->syncChapterAuthors($title, $request->input('chapter_authors', []));

        return redirect()->route('title.show', $title->id)->with('success', 'Author bab diperbarui.');
    }
```

- [ ] **Step 4: Route**

In `routes/web.php`, inside the `Route::middleware('role:superadmin|manager|admin')->group(...)` block that holds `title.info.update`, add:
```php
        Route::put('titles/{id}/chapter-authors', [TitleController::class, 'updateChapterAuthors'])->name('title.chapters.authors')->whereNumber('id');
```
Run `php artisan route:list --name=title.chapters` to confirm it registered with the role middleware.

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=ChapterAuthorTest`
Expected: `show_displays_chapter_authors` may still FAIL until Task 3 renders authors; `manager_saves_chapter_authors` + `marketing_cannot_save_chapter_authors` PASS. (Proceed to Task 3, then re-run.)

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/TitleController.php routes/web.php tests/Feature/ChapterAuthorTest.php
git commit -m "feat(chapter-author): updateChapterAuthors endpoint + route + show data

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Views — panel Bab & Author + board display

**Files:**
- Modify: `resources/views/titles/show.blade.php`
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`, `resources/views/manuscript/partials/card.blade.php`

- [ ] **Step 1: `titles/show.blade.php` — bab read + edit panel**

Replace the book chapters block:
```blade
    @if($title->jenis === 'buku')
        <h6 class="card-title mt-3">Bab</h6>
        <ol class="mb-3">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
    @endif
```
with:
```blade
    @if($title->jenis === 'buku')
        <div class="d-flex justify-content-between align-items-center mt-3">
            <h6 class="card-title mb-0">Bab & Author</h6>
            @if($canEditInfo && $title->chapters->isNotEmpty())
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#chapAuthorForm">Edit Author Bab</button>
            @endif
        </div>
        <ol class="mb-2">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}@if($ch->authors->isNotEmpty()) — <span class="text-muted">{{ $ch->authors->pluck('name')->join(', ') }}</span>@endif</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
        @if($canEditInfo && $title->chapters->isNotEmpty())
            <div class="collapse mb-3" id="chapAuthorForm">
                <form method="POST" action="{{ route('title.chapters.authors', $title->id) }}">
                    @csrf @method('PUT')
                    @foreach($title->chapters as $ch)
                        <div class="mb-2">
                            <label class="form-label small mb-1">{{ $ch->urutan }}. {{ $ch->judul }}</label>
                            <select name="chapter_authors[{{ $ch->id }}][]" multiple class="form-select form-select-sm select2-authors" data-tags="true">
                                @foreach($allAuthors as $a)
                                    <option value="{{ $a->id }}" {{ $ch->authors->contains($a->id) ? 'selected' : '' }}>{{ $a->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <button type="submit" class="btn btn-sm btn-primary">Simpan Author Bab</button>
                    <small class="text-muted d-block mt-1">Pilih author yang ada atau ketik nama baru. Urutan pilihan = urutan author.</small>
                </form>
            </div>
        @endif
    @endif
```

- [ ] **Step 2: `titles/show.blade.php` — load select2 assets + init**

The `@push('plugin-styles')`/`@push('plugin-scripts')` currently load only flatpickr. Add select2 to both (this also makes the Fase C journal select searchable):
```blade
@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
```
In the `@push('custom-scripts')` `$(function () { ... })`, add (near the top, after the flatpickr line) a select2 init for the author multi-selects:
```javascript
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.select2-authors').select2({ tags: true, width: '100%', placeholder: 'Pilih / ketik author…' });
    }
```

- [ ] **Step 3: Board — eager-load + display authors**

In `app/Http/Controllers/Pages/ManuscriptTrackerController.php` `index()`, where the buku block loads chapters (`$groups->each(fn ($g) => optional($g->titleRef)->load('chapters.progress.assignedUser'))` and/or the `with([...])` includes `'titleRef.chapters.progress.assignedUser'`), add `chapters.authors` alongside — i.e. change the eager-load/`load` to also include `'titleRef.chapters.authors'` (and in the `$groups->each(...)->load(...)` call add `'chapters.authors'`). Both the query `with` and the post-build `load` should include chapters.authors so the card can read them.

In `resources/views/manuscript/partials/card.blade.php`, in the chapter panel `@foreach($chapters as $ch)`, after the Editor line `<div class="text-muted mt-1">Editor: …</div>`, add:
```blade
                            <div class="text-muted">Author: {{ $ch->authors->pluck('name')->join(', ') ?: '—' }}</div>
```

- [ ] **Step 4: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="ChapterAuthorTest|ChapterAuthorServiceTest|TitlePublicationInfoTest|ManuscriptTrackerTest|ChapterBoardTest"`
Expected: PASS all (incl. `show_displays_chapter_authors`).

- [ ] **Step 5: Commit**

```
git add resources/views/titles/show.blade.php app/Http/Controllers/Pages/ManuscriptTrackerController.php resources/views/manuscript/partials/card.blade.php
git commit -m "feat(chapter-author): title panel manage + board/detail author display + select2 assets

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Full verification + migrate dev

**Files:** none.

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (366 + ChapterAuthorServiceTest (3) + ChapterAuthorTest (3) = ~372).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (tabel `tb_title_chapter_authors`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login manager → detail judul buku (ber-bab) → seksi "Bab & Author" → Edit Author Bab → tiap bab pilih author (select2) / ketik baru → Simpan → daftar bab menampilkan author; papan manuskrip (expand bab) menampilkan author (read).

---

## Catatan & Risiko

- Author per bab = pivot independen; `tb_author_orders` (author level order) tak berubah.
- Panel otoritatif atas semua bab (mengosongkan select = author bab dikosongkan).
- Author baru dibuat name-only (`firstOrCreate` by name → dedup nama); email/afiliasi di luar scope.
- Menambah aset select2 di `titles/show.blade.php` juga membuat dropdown jurnal Fase C (`.select2-journal`) benar-benar searchable (sebelumnya native karena select2 tak dimuat di halaman ini).
- Papan hanya menampilkan author bab (read); kelola tunggal di detail judul.
