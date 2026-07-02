# Manuskrip per Bab — Fase 3a (Fondasi) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Fondasi manuskrip per bab (buku): tabel `tb_chapter_progress`, `ChapterManuscriptService` (auto-generate bab, ubah status bab dgn otorisasi, assign editor, roll-up status buku = bottleneck bab), integrasi `createForDetail`. Tanpa UI papan (itu 3b).

**Architecture:** `ChapterProgress` (per `tb_title_chapters`) menyimpan status+editor per bab. `ChapterManuscriptService` memakai ulang `TitleProgress::BOOK_STAGES`/`STAGE_HANDLER` + pola otorisasi `TitleProgressService`. `syncBookStatus` menjaga `TitleProgress` buku = bottleneck bab, jadi papan/arsip/dashboard existing tetap benar tanpa diubah.

**Tech Stack:** Laravel 11, Eloquent, Spatie roles.

**Spec:** `docs/superpowers/specs/2026-07-02-manuscript-per-chapter-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell), tunggu ~6 dtk. Commit: `git add <path eksplisit>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic", jangan `git add .`. Migrasi terakhir `2026_07_02_000010`; baru = `2026_07_02_000011`. Setelah selesai: `php artisan migrate` di dev.

**Fakta:**
- `TitleProgress::BOOK_STAGES = ['menunggu_proses','editing','layout','proofreading','isbn','cetak','terbit']`; `STAGE_HANDLER` (menunggu_proses→marketing; editing/layout/proofreading/isbn→production; cetak/terbit→superadmin); `getHandlerForStatus($s)`.
- Otorisasi existing (`TitleProgressService::authorizeChange`): superadmin/manager bebas; production hanya bila `getHandlerForStatus($current)==='production'`; lainnya (mis. marketing) ditolak.
- `App\Models\TitleChapter` (tb_title_chapters): fillable [title_id, judul, urutan]; belongsTo Title. `Title::chapters()` hasMany(TitleChapter urut urutan), `orderDetails()` hasMany(OrderDetail,'title_id'). `OrderDetail`: kolom `chapters` (int), `type`, `title_id`, `titleRef()` belongsTo(Title), `titleProgress()` hasOne. `TitleProgressLog` fillable [title_progress_id, event, from_value, to_value, changed_by, note, is_correction] (`$timestamps=false`, isi created_at manual? — model sets created_at cast; TitleProgressService::log tak set created_at → default now via model? existing log() doesn't set created_at, so keep consistent: don't set it).
- `TitleProgressService::createForDetail(OrderDetail $detail, ?int $actorId=null): TitleProgress` diawali `$bookTypes=['bk_mandiri','bk_kolab'];` dan diakhiri `return TitleProgress::create([...]);`.

---

## Task 1: Migration + `ChapterProgress` model + relation

**Files:**
- Create: `database/migrations/2026_07_02_000011_create_tb_chapter_progress_table.php`, `app/Models/ChapterProgress.php`
- Modify: `app/Models/TitleChapter.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_02_000011_create_tb_chapter_progress_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_chapter_id')->unique()->constrained('tb_title_chapters')->cascadeOnDelete();
            $table->string('status', 16)->default('menunggu_proses');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('needs_review')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_log_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_chapter_progress');
    }
};
```

- [ ] **Step 2: Model `ChapterProgress`**

Create `app/Models/ChapterProgress.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChapterProgress extends Model
{
    use HasFactory;

    protected $table = 'tb_chapter_progress';

    protected $fillable = [
        'title_chapter_id', 'status', 'assigned_user_id',
        'needs_review', 'note', 'updated_by', 'started_at', 'last_log_at',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'started_at'   => 'datetime',
        'last_log_at'  => 'datetime',
    ];

    public function chapter()
    {
        return $this->belongsTo(TitleChapter::class, 'title_chapter_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

- [ ] **Step 3: `TitleChapter::progress()`**

In `app/Models/TitleChapter.php`, add (after the existing `title()` relation):

```php
    public function progress()
    {
        return $this->hasOne(ChapterProgress::class, 'title_chapter_id');
    }
```

- [ ] **Step 4: Verify migration healthy**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS (RefreshDatabase applies the new migration cleanly).

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_07_02_000011_create_tb_chapter_progress_table.php app/Models/ChapterProgress.php app/Models/TitleChapter.php
git commit -m "feat(chapter-ms): tb_chapter_progress table + ChapterProgress model + relation

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `ChapterManuscriptService::ensureChapters` + createForDetail integration (TDD)

**Files:**
- Create: `app/Services/ChapterManuscriptService.php`
- Modify: `app/Services/TitleProgressService.php` (createForDetail)
- Test: `tests/Unit/ChapterManuscriptServiceTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Unit/ChapterManuscriptServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChapterManuscriptServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChapterManuscriptService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChapterManuscriptService();
    }

    private function bookWithOrder(int $chapters = 3, string $progressStatus = 'menunggu_proses'): Title
    {
        $user = User::factory()->create();
        $book = Title::create(['title' => 'Buku Uji', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-C-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri',
            'title' => 'Buku Uji', 'slug' => 'buku-uji', 'chapters' => $chapters, 'cost_amount' => 0,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $progressStatus, 'assigned_role' => 'marketing', 'started_at' => now()]);

        return $book;
    }

    /** @test */
    public function ensure_generates_chapters_and_progress_from_order_count(): void
    {
        $book = $this->bookWithOrder(3);
        $this->svc->ensureChapters($book);

        $this->assertSame(3, $book->chapters()->count());
        $this->assertSame(3, ChapterProgress::count());
        $this->assertSame('menunggu_proses', $book->chapters()->first()->progress->status);
    }

    /** @test */
    public function ensure_is_idempotent(): void
    {
        $book = $this->bookWithOrder(2);
        $this->svc->ensureChapters($book);
        $this->svc->ensureChapters($book->fresh());

        $this->assertSame(2, $book->chapters()->count());
        $this->assertSame(2, ChapterProgress::count());
    }

    /** @test */
    public function ensure_uses_existing_chapter_list(): void
    {
        $book = $this->bookWithOrder(5);
        $book->chapters()->create(['judul' => 'Pendahuluan', 'urutan' => 1]);
        $book->chapters()->create(['judul' => 'Isi', 'urutan' => 2]);

        $this->svc->ensureChapters($book->fresh());

        $this->assertSame(2, $book->chapters()->count()); // pakai daftar yang ada, bukan 5
        $this->assertSame('Pendahuluan', $book->chapters()->first()->judul);
        $this->assertSame(2, ChapterProgress::count());
    }

    /** @test */
    public function ensure_skips_articles(): void
    {
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->svc->ensureChapters($art);
        $this->assertSame(0, ChapterProgress::count());
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `ensureChapters`** — create `app/Services/ChapterManuscriptService.php`:

```php
<?php

namespace App\Services;

use App\Models\Title;

class ChapterManuscriptService
{
    /** Pastikan buku punya daftar bab + ChapterProgress. Auto-generate dari OrderDetail.chapters bila kosong. Idempotent. */
    public function ensureChapters(Title $book): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        $chapters = $book->chapters()->get();

        if ($chapters->isEmpty()) {
            $n = max(1, (int) $book->orderDetails()->max('chapters'));
            for ($i = 1; $i <= $n; $i++) {
                $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
            }
            $chapters = $book->chapters()->get();
        }

        $seedStatus = optional(
            $book->orderDetails()->with('titleProgress')->get()
                ->map->titleProgress->filter()->first()
        )->status ?? 'menunggu_proses';

        foreach ($chapters as $chapter) {
            if (! $chapter->progress()->exists()) {
                $chapter->progress()->create(['status' => $seedStatus, 'started_at' => now()]);
            }
        }
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Integrate into `createForDetail`**

In `app/Services/TitleProgressService.php` `createForDetail()`, replace the final `return TitleProgress::create([...]);` — capture the progress, ensure chapters for book orders, then return. Change:
```php
        return TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => $status,
            'assigned_role'    => TitleProgress::getHandlerForStatus($status),
            'assigned_user_id' => $sibling->assigned_user_id ?? null,
            'priority'         => $sibling->priority ?? 'normal',
            'updated_by'       => $actorId,
            'started_at'       => now(),
        ]);
```
to:
```php
        $progress = TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => $status,
            'assigned_role'    => TitleProgress::getHandlerForStatus($status),
            'assigned_user_id' => $sibling->assigned_user_id ?? null,
            'priority'         => $sibling->priority ?? 'normal',
            'updated_by'       => $actorId,
            'started_at'       => now(),
        ]);

        // Buku: pastikan bab + progress bab (roll-up). Artikel: tak ada bab.
        if (in_array($detail->type, $bookTypes, true) && $detail->title_id) {
            app(\App\Services\ChapterManuscriptService::class)->ensureChapters($detail->titleRef);
        }

        return $progress;
```
(`$bookTypes` is already defined at the top of `createForDetail`.)

- [ ] **Step 6: Add an integration test** — append inside `ChapterManuscriptServiceTest`:

```php
    /** @test */
    public function create_for_detail_seeds_chapters_for_book_order(): void
    {
        $user = User::factory()->create();
        $book = Title::create(['title' => 'Buku Order', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-CD-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri',
            'title' => 'Buku Order', 'slug' => 'buku-order', 'chapters' => 2, 'cost_amount' => 0,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        app(\App\Services\TitleProgressService::class)->createForDetail($detail, $user->id);

        $this->assertSame(2, $book->chapters()->count());
        $this->assertSame(2, ChapterProgress::count());
    }
```

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```
git add app/Services/ChapterManuscriptService.php app/Services/TitleProgressService.php tests/Unit/ChapterManuscriptServiceTest.php
git commit -m "feat(chapter-ms): ensureChapters (auto-generate) + createForDetail integration

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: `changeStatus` + `syncBookStatus` + `assignEditor` (TDD)

**Files:**
- Modify: `app/Services/ChapterManuscriptService.php`
- Test: `tests/Unit/ChapterManuscriptServiceTest.php`

- [ ] **Step 1: Write failing tests** — append inside `ChapterManuscriptServiceTest` (add role setup + helpers). Add at the TOP of the class a role setup by replacing the existing `setUp()` with:

```php
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            \Spatie\Permission\Models\Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new ChapterManuscriptService();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }
```

Then append these tests:

```php
    /** @test */
    public function production_advances_a_production_stage_chapter_and_rolls_up(): void
    {
        $prod = $this->user('production');
        $book = $this->bookWithOrder(2, 'editing'); // progress buku 'editing'
        $this->svc->ensureChapters($book); // 2 bab @ editing

        $chapters = $book->chapters()->with('progress')->orderBy('urutan')->get();
        // Majukan bab pertama editing -> layout (keduanya handler production)
        $this->svc->changeStatus($chapters[0]->progress, 'layout', $prod);

        $this->assertSame('layout', $chapters[0]->progress->fresh()->status);
        // roll-up buku = bottleneck = 'editing' (bab kedua masih editing)
        $bookProgress = $book->orderDetails()->first()->titleProgress;
        $this->assertSame('editing', $bookProgress->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_a_superadmin_stage_chapter(): void
    {
        $prod = $this->user('production');
        $book = $this->bookWithOrder(1, 'cetak'); // handler superadmin
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->svc->changeStatus($cp, 'terbit', $prod);
    }

    /** @test */
    public function correction_requires_note(): void
    {
        $mgr = $this->user('manager');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc->changeStatus($cp, 'menunggu_proses', $mgr); // lompat mundur tanpa note
    }

    /** @test */
    public function assign_editor_sets_chapter_editor(): void
    {
        $mgr = $this->user('manager');
        $editor = $this->user('production');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->svc->assignEditor($cp, $editor->id, $mgr);
        $this->assertSame($editor->id, $cp->fresh()->assigned_user_id);
    }

    /** @test */
    public function assign_editor_rejects_non_editor_role(): void
    {
        $mgr = $this->user('manager');
        $marketing = $this->user('marketing');
        $book = $this->bookWithOrder(1, 'editing');
        $this->svc->ensureChapters($book);
        $cp = $book->chapters()->first()->progress;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc->assignEditor($cp, $marketing->id, $mgr);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: FAIL — `changeStatus`/`assignEditor` undefined.

- [ ] **Step 3: Implement** — add to `app/Services/ChapterManuscriptService.php`. Add imports at top:
```php
use App\Models\ChapterProgress;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
```
Add these methods:

```php
    /** Ubah status bab (maju/koreksi) dengan aturan & otorisasi seperti TitleProgress; roll-up buku. */
    public function changeStatus(ChapterProgress $cp, string $target, User $actor, ?string $note = null): ChapterProgress
    {
        $stages = TitleProgress::BOOK_STAGES;
        if (! in_array($target, $stages, true)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid.']);
        }

        $current = $cp->status;
        $idx     = array_search($current, $stages, true);
        $next    = $idx === false ? null : ($stages[$idx + 1] ?? null);

        if ($next === null && $target === $current) {
            throw ValidationException::withMessages(['status' => 'Bab sudah di tahap akhir.']);
        }

        $isCorrection = ($target !== $next);
        $this->authorize($actor, $current);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib untuk koreksi/lompat.']);
        }

        DB::transaction(function () use ($cp, $current, $target, $actor, $note, $isCorrection) {
            $cp->update([
                'status'       => $target,
                'note'         => $note,
                'updated_by'   => $actor->id,
                'started_at'   => now(),
                'needs_review' => $isCorrection && ! $actor->hasRole('superadmin'),
                'last_log_at'  => now(),
            ]);
            $this->log($cp, $current, $target, $actor, $note, $isCorrection);
            $this->syncBookStatus($cp->chapter->title);
        });

        return $cp;
    }

    /** Assign editor bab (production/manager). */
    public function assignEditor(ChapterProgress $cp, ?int $userId, User $actor): ChapterProgress
    {
        if (! $actor->hasAnyRole(['production', 'manager', 'superadmin'])) {
            throw new AuthorizationException();
        }
        if ($userId !== null) {
            $u = User::find($userId);
            if (! $u || ! $u->hasAnyRole(['production', 'manager'])) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Editor harus role production atau manager.']);
            }
        }

        $cp->update(['assigned_user_id' => $userId]);
        return $cp;
    }

    /** Sinkron status TitleProgress buku (tiap order-variant) = bottleneck status bab. */
    public function syncBookStatus(Title $book): void
    {
        $stages = TitleProgress::BOOK_STAGES;
        $statuses = $book->chapters()->with('progress')->get()
            ->map(fn ($c) => optional($c->progress)->status)
            ->filter();

        if ($statuses->isEmpty()) {
            return;
        }

        $bottleneck = $statuses
            ->sortBy(fn ($s) => ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();

        foreach ($book->orderDetails()->with('titleProgress')->get() as $detail) {
            if ($detail->titleProgress) {
                $detail->titleProgress->update([
                    'status'        => $bottleneck,
                    'assigned_role' => TitleProgress::getHandlerForStatus($bottleneck),
                ]);
            }
        }
    }

    private function authorize(User $actor, string $current): void
    {
        if ($actor->hasAnyRole(['superadmin', 'manager'])) {
            return;
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return;
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan bab pada tahap ini.');
    }

    /** Catat perubahan bab ke log manuskrip buku (TitleProgress representatif). */
    private function log(ChapterProgress $cp, string $from, string $to, User $actor, ?string $note, bool $isCorrection): void
    {
        $progress = $cp->chapter->title->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();
        if (! $progress) {
            return;
        }

        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => 'chapter_status',
            'from_value'        => Str::title(str_replace('_', ' ', $from)),
            'to_value'          => "Bab '{$cp->chapter->judul}' → " . Str::title(str_replace('_', ' ', $to)),
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => $isCorrection,
        ]);
    }
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=ChapterManuscriptServiceTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/ChapterManuscriptService.php tests/Unit/ChapterManuscriptServiceTest.php
git commit -m "feat(chapter-ms): per-chapter changeStatus/assign + book roll-up sync

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Full verification + migrate dev

**Files:** none (verification only)

- [ ] **Step 1: Whole suite (regression)**

Run: `php artisan test`
Expected: PASS all (337 sebelumnya + ChapterManuscriptServiceTest (10) = ~347). Perhatikan tetap hijau: `TitleProgressTest`, `TagihanLifecycleTest`, `ManuscriptTrackerTest`, `ArchiveGroupedTitlesTest` (createForDetail kini juga membuat bab untuk order buku — hanya menambah baris ChapterProgress/TitleChapter, tak mengubah TitleProgress count/status).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (buat tabel `tb_chapter_progress`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional, via tinker)**

`php artisan tinker` → ambil satu order buku, panggil `app(\App\Services\ChapterManuscriptService::class)->ensureChapters($orderDetail->titleRef)`, cek `tb_title_chapters` + `tb_chapter_progress` terisi; ubah status satu bab via `changeStatus`, cek TitleProgress buku ikut roll-up.

---

## Catatan & Risiko

- `TitleProgress` buku dipertahankan sebagai roll-up (di-sync ke bottleneck bab) → papan/arsip/dashboard existing benar tanpa diubah. 3b menambah UI interaksi bab.
- `createForDetail` kini membuat bab untuk order buku (idempotent per Title) — hanya menambah `tb_title_chapters`/`tb_chapter_progress`, tak mengubah TitleProgress.
- Otorisasi/aturan tahap bab meniru `TitleProgressService` (production hanya tahap production; manager/superadmin bebas).
- Artikel & non-buku tak tersentuh.
- 3b (papan expand + endpoint aksi bab) menyusul; 3a mergeable & bernilai (fondasi + roll-up).
