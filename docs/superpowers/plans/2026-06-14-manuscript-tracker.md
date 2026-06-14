# Manuscript Tracker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan menu **Manuscript Tracker** — papan Kanban interaktif tempat tim `production` memajukan progres naskah, dibangun di atas data `TitleProgress` yang sudah ada.

**Architecture:** Papan/daftar adalah view baru di atas `tb_title_progress`. Logika pindah-stage dipindah dari controller ke `TitleProgressService` agar dipakai bersama oleh form detail (web) dan endpoint papan (AJAX). Role `production` (sudah di-seed, belum di-wire) diaktifkan: ia memiliki stage editorial dan boleh maju 1 langkah. Ditambah kolom `assigned_user_id` + `priority` pada `tb_title_progress`.

**Tech Stack:** Laravel 10, Spatie Permission, Blade + Bootstrap 5 + Alpine.js, SortableJS (sudah ter-vendor di `public/assets/plugins/sortablejs/Sortable.min.js`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-14-manuscript-tracker-design.md`

> **Catatan testing (penting):** jalankan test dengan `php artisan test` — otomatis memakai `.env.testing` (DB `avidpedi_simapa_test`), **bukan** DB asli. Suite saat ini hijau (41 passed); harus tetap hijau.

---

## File Map

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php` | Kolom `assigned_user_id` + `priority` + index |
| Modify | `app/Models/TitleProgress.php` | `STAGE_HANDLER`→production, fillable, `PRIORITIES`, relasi `assignedUser` |
| Create | `app/Services/TitleProgressService.php` | `changeStatus`/`assignEditor`/`setPriority` + aturan akses |
| Modify | `app/Http/Controllers/Pages/TitleProgressController.php` | Pakai service |
| Create | `app/Http/Controllers/Pages/ManuscriptTrackerController.php` | `index`/`move`/`assign`/`priority` |
| Modify | `routes/web.php` | Route papan + 3 aksi; perluas middleware update-status |
| Create | `resources/views/manuscript/partials/toolbar.blade.php` | Header + filter + toggle (dipakai board & list) |
| Create | `resources/views/manuscript/partials/card.blade.php` | Kartu naskah |
| Create | `resources/views/manuscript/board.blade.php` | Papan Kanban + SortableJS |
| Create | `resources/views/manuscript/list.blade.php` | View daftar |
| Modify | `resources/views/orders/detail-title.blade.php` | Production di form + kontrol assign/prioritas |
| Modify | `resources/views/layouts/sidebar.blade.php` | Menu "Produksi → Manuscript Tracker" |
| Create | `tests/Unit/TitleProgressServiceTest.php` | Unit test aturan service |
| Create | `tests/Feature/ManuscriptTrackerTest.php` | Feature test endpoint & render |

---

## Task 1: Migration — kolom assignment & prioritas

**Files:**
- Create: `database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php`

- [ ] **Step 1: Buat file migration**

```php
<?php
// database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable()->after('updated_by');
            $table->string('priority', 10)->default('normal')->after('assigned_user_id');

            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['assigned_user_id']);
            $table->dropColumn(['assigned_user_id', 'priority']);
        });
    }
};
```

- [ ] **Step 2: Jalankan migrasi**

Run: `php artisan migrate`
Expected: `... add_assignment_to_tb_title_progress ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php
git commit -m "feat: add assigned_user_id and priority to tb_title_progress"
```

---

## Task 2: Model `TitleProgress` — handler production, kolom, relasi

**Files:**
- Modify: `app/Models/TitleProgress.php`

- [ ] **Step 1: Tambah `assigned_user_id` & `priority` ke `$fillable`**

Ganti blok `$fillable`:

```php
    protected $fillable = [
        'order_detail_id', 'status', 'assigned_role',
        'note', 'updated_by', 'started_at',
        'assigned_user_id', 'priority',
    ];
```

- [ ] **Step 2: Ubah `STAGE_HANDLER` (stage editorial → production) dan tambah konstanta `PRIORITIES`**

Ganti seluruh konstanta `STAGE_HANDLER` dan tambahkan `PRIORITIES` tepat setelahnya:

```php
    const STAGE_HANDLER = [
        'menunggu_proses' => 'marketing',
        'templating'      => 'production',
        'editing'         => 'production',
        'revisi'          => 'production',
        'submit'          => 'production',
        'loa'             => 'superadmin',
        'publish'         => 'superadmin',
        'layout'          => 'production',
        'proofreading'    => 'production',
        'isbn'            => 'production',
        'cetak'           => 'superadmin',
        'terbit'          => 'superadmin',
    ];

    const PRIORITIES = ['low', 'normal', 'high'];
```

- [ ] **Step 3: Tambah relasi `assignedUser()`**

Tambahkan method tepat setelah method `updatedBy()`:

```php
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
```

- [ ] **Step 4: Jalankan suite — pastikan tidak ada regresi dari perubahan handler**

Run: `php artisan test --filter=TitleProgressTest`
Expected: PASS (4 test lama tetap hijau — mereka tidak meng-assert `assigned_role` untuk stage editorial)

- [ ] **Step 5: Commit**

```bash
git add app/Models/TitleProgress.php
git commit -m "feat: assign editorial stages to production role; add assignedUser relation and PRIORITIES"
```

---

## Task 3: `TitleProgressService` — logika + aturan akses (TDD)

**Files:**
- Create: `tests/Unit/TitleProgressServiceTest.php`
- Create: `app/Services/TitleProgressService.php`

- [ ] **Step 1: Tulis unit test (failing)**

```php
<?php
// tests/Unit/TitleProgressServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleProgressService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new TitleProgressService();
    }

    private function progress(string $status, string $type = 'bk_mandiri'): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $type]);
        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function production_advances_editorial_stage(): void
    {
        $p = $this->progress('editing');
        $this->svc->changeStatus($p, 'layout', $this->user('production'));

        $this->assertEquals('layout', $p->fresh()->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'to_status' => 'layout', 'is_correction' => false,
        ]);
    }

    /** @test */
    public function production_can_hand_off_last_editorial_stage_to_finalization(): void
    {
        $p = $this->progress('isbn'); // next = cetak (superadmin)
        $this->svc->changeStatus($p, 'cetak', $this->user('production'));
        $this->assertEquals('cetak', $p->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_card_after_handoff(): void
    {
        $p = $this->progress('cetak'); // handler superadmin
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'terbit', $this->user('production'));
    }

    /** @test */
    public function production_cannot_make_correction(): void
    {
        $p = $this->progress('layout');
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('production')); // mundur
    }

    /** @test */
    public function production_cannot_move_menunggu_proses(): void
    {
        $p = $this->progress('menunggu_proses'); // handler marketing
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('production'));
    }

    /** @test */
    public function manager_advances_any_stage(): void
    {
        $p = $this->progress('menunggu_proses');
        $this->svc->changeStatus($p, 'editing', $this->user('manager'));
        $this->assertEquals('editing', $p->fresh()->status);
    }

    /** @test */
    public function manager_cannot_make_correction(): void
    {
        $p = $this->progress('layout');
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('manager'));
    }

    /** @test */
    public function superadmin_correction_requires_note(): void
    {
        $p = $this->progress('isbn');
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), null);
    }

    /** @test */
    public function superadmin_correction_with_note_is_logged(): void
    {
        $p = $this->progress('isbn');
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), 'alasan koreksi');
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'to_status' => 'editing', 'is_correction' => true,
        ]);
    }

    /** @test */
    public function assign_rejects_user_outside_production_or_manager(): void
    {
        $p = $this->progress('editing');
        $marketing = $this->user('marketing');
        $this->expectException(ValidationException::class);
        $this->svc->assignEditor($p, $marketing->id, $this->user('manager'));
    }

    /** @test */
    public function assign_accepts_production_user_and_null(): void
    {
        $p = $this->progress('editing');
        $editor = $this->user('production');
        $manager = $this->user('manager');

        $this->svc->assignEditor($p, $editor->id, $manager);
        $this->assertEquals($editor->id, $p->fresh()->assigned_user_id);

        $this->svc->assignEditor($p, null, $manager);
        $this->assertNull($p->fresh()->assigned_user_id);
    }

    /** @test */
    public function set_priority_validates_value(): void
    {
        $p = $this->progress('editing');
        $manager = $this->user('manager');

        $this->svc->setPriority($p, 'high', $manager);
        $this->assertEquals('high', $p->fresh()->priority);

        $this->expectException(ValidationException::class);
        $this->svc->setPriority($p, 'urgent', $manager);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan FAIL**

Run: `php artisan test --filter=TitleProgressServiceTest`
Expected: FAIL — `App\Services\TitleProgressService` belum ada.

- [ ] **Step 3: Buat service**

```php
<?php
// app/Services/TitleProgressService.php

namespace App\Services;

use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TitleProgressService
{
    /**
     * Maju 1 langkah (advance) atau koreksi (superadmin). Menulis log.
     */
    public function changeStatus(TitleProgress $progress, string $target, User $actor, ?string $note = null): TitleProgress
    {
        if (!$progress->isValidStatus($target)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid untuk tipe naskah ini.']);
        }

        $next = $progress->getNextStatus();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $current      = $progress->status;
        $isCorrection = ($target !== $next);

        $this->authorizeChange($actor, $current, $isCorrection);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi status.']);
        }

        return DB::transaction(function () use ($progress, $target, $current, $actor, $note, $isCorrection) {
            $progress->update([
                'status'        => $target,
                'assigned_role' => TitleProgress::getHandlerForStatus($target),
                'note'          => $note,
                'updated_by'    => $actor->id,
                'started_at'    => now(),
            ]);

            TitleProgressLog::create([
                'title_progress_id' => $progress->id,
                'from_status'       => $current,
                'to_status'         => $target,
                'changed_by'        => $actor->id,
                'note'              => $note,
                'is_correction'     => $isCorrection,
            ]);

            return $progress;
        });
    }

    private function authorizeChange(User $actor, string $current, bool $isCorrection): void
    {
        if ($actor->hasRole('superadmin')) {
            return; // bebas: maju, mundur, lompat
        }
        if ($isCorrection) {
            throw new AuthorizationException('Hanya superadmin yang dapat melakukan koreksi.');
        }
        if ($actor->hasRole('manager')) {
            return; // maju stage apa pun
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return; // maju stage yang sedang jadi domain production
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan naskah pada tahap ini.');
    }

    public function assignEditor(TitleProgress $progress, ?int $userId, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin'])) {
            throw new AuthorizationException();
        }

        if ($userId !== null) {
            $assignee = User::find($userId);
            if (!$assignee || !$assignee->hasAnyRole(['production', 'manager'])) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'Editor harus user dengan role production atau manager.',
                ]);
            }
        }

        $progress->update(['assigned_user_id' => $userId]);
        return $progress;
    }

    public function setPriority(TitleProgress $progress, string $priority, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin'])) {
            throw new AuthorizationException();
        }
        if (!in_array($priority, TitleProgress::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Prioritas tidak valid.']);
        }

        $progress->update(['priority' => $priority]);
        return $progress;
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan PASS**

Run: `php artisan test --filter=TitleProgressServiceTest`
Expected: PASS (12 test)

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/TitleProgressServiceTest.php app/Services/TitleProgressService.php
git commit -m "feat: add TitleProgressService with role-based stage rules (unit-tested)"
```

---

## Task 4: Refactor `TitleProgressController@update` ke service + perluas middleware

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleProgressController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Ganti method `update()` agar memakai service**

Ganti seluruh isi method `update()` (biarkan `logs()` apa adanya):

```php
    public function update(Request $request, int $id, \App\Services\TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);

        $service->changeStatus(
            $progress,
            (string) $request->input('status'),
            Auth::user(),
            $request->input('note')
        );

        return back()->with('success', 'Status berhasil diperbarui.');
    }
```

> Exception dari service ditangani otomatis oleh Laravel: `AuthorizationException` → 403, `ValidationException` → redirect-back-with-errors (web). Ini mempertahankan perilaku test lama (`manager_cannot_make_correction_jump` → 403, `superadmin_can_make_correction_with_note` → redirect).

- [ ] **Step 2: Perluas middleware route `title.progress.update`**

Di `routes/web.php`, ubah baris middleware route update-status:

```php
        Route::post('title/{id}/update-status', [TitleProgressController::class, 'update'])
            ->name('title.progress.update')
            ->middleware('role:production|manager|superadmin');
```

- [ ] **Step 3: Jalankan test — pastikan suite TitleProgress tetap hijau**

Run: `php artisan test --filter=TitleProgressTest`
Expected: PASS (4 test)

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Pages/TitleProgressController.php routes/web.php
git commit -m "refactor: TitleProgressController uses TitleProgressService; allow production on update-status"
```

---

## Task 5: `ManuscriptTrackerController` — move/assign/priority + routes (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ManuscriptTrackerTest.php`

- [ ] **Step 1: Tulis feature test untuk endpoint aksi (failing)**

```php
<?php
// tests/Feature/ManuscriptTrackerTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ManuscriptTrackerTest extends TestCase
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

    private function progress(string $status, string $type = 'bk_mandiri'): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $type]);
        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    /** @test */
    public function production_moves_card_via_ajax(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'layout'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'layout']);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'layout']);
    }

    /** @test */
    public function rejected_move_keeps_status(): void
    {
        $p = $this->progress('cetak'); // milik superadmin
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'terbit'])
            ->assertStatus(403);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'cetak']);
    }

    /** @test */
    public function assign_endpoint_sets_editor(): void
    {
        $p = $this->progress('editing');
        $editor = $this->user('production');
        $this->actingAs($this->user('manager'));

        $this->postJson(route('manuscript.assign', $p->id), ['assigned_user_id' => $editor->id])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'assigned_user_id' => $editor->id]);
    }

    /** @test */
    public function priority_endpoint_sets_priority(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.priority', $p->id), ['priority' => 'high'])
            ->assertOk()->assertJson(['ok' => true, 'priority' => 'high']);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'priority' => 'high']);
    }

    /** @test */
    public function marketing_cannot_use_move_endpoint(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('marketing'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'layout'])
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan FAIL**

Run: `php artisan test --filter=ManuscriptTrackerTest`
Expected: FAIL — route/controller belum ada.

- [ ] **Step 3: Buat controller (method aksi dulu; `index` di Task 6)**

```php
<?php
// app/Http/Controllers/Pages/ManuscriptTrackerController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\TitleProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ManuscriptTrackerController extends Controller
{
    public function move(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $service->changeStatus($progress, (string) $request->input('status'), Auth::user(), $request->input('note'));
        $progress->refresh();

        $label = Str::title(str_replace('_', ' ', $progress->status));
        if ($request->expectsJson()) {
            return response()->json([
                'ok'            => true,
                'id'            => $progress->id,
                'status'        => $progress->status,
                'assigned_role' => $progress->assigned_role,
                'message'       => "Naskah dipindahkan ke {$label}.",
            ]);
        }
        return back()->with('success', "Naskah dipindahkan ke {$label}.");
    }

    public function assign(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        $service->assignEditor($progress, $userId, Auth::user());
        $progress->refresh()->load('assignedUser');

        if ($request->expectsJson()) {
            return response()->json([
                'ok'            => true,
                'id'            => $progress->id,
                'assigned_user' => $progress->assignedUser
                    ? ['id' => $progress->assignedUser->id, 'name' => $progress->assignedUser->name]
                    : null,
                'message'       => 'Editor diperbarui.',
            ]);
        }
        return back()->with('success', 'Editor diperbarui.');
    }

    public function priority(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::findOrFail($id);
        $service->setPriority($progress, (string) $request->input('priority'), Auth::user());
        $progress->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $progress->id,
                'priority' => $progress->priority,
                'message'  => 'Prioritas diperbarui.',
            ]);
        }
        return back()->with('success', 'Prioritas diperbarui.');
    }
}
```

- [ ] **Step 4: Tambah import controller di atas `routes/web.php`**

Setelah baris `use App\Http\Controllers\Pages\TitleProgressController;`:

```php
use App\Http\Controllers\Pages\ManuscriptTrackerController;
```

- [ ] **Step 5: Tambah grup route manuscript** (di dalam `Route::middleware('auth')->group`, tepat setelah grup `Route::prefix('management')` yang berisi title.progress)

```php
    Route::prefix('management')->group(function () {
        Route::get('manuscript', [ManuscriptTrackerController::class, 'index'])
            ->name('manuscript.board')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/move', [ManuscriptTrackerController::class, 'move'])
            ->name('manuscript.move')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/assign', [ManuscriptTrackerController::class, 'assign'])
            ->name('manuscript.assign')
            ->middleware('role:production|manager|superadmin');
        Route::post('manuscript/{id}/priority', [ManuscriptTrackerController::class, 'priority'])
            ->name('manuscript.priority')
            ->middleware('role:production|manager|superadmin');
    });
```

- [ ] **Step 6: Jalankan test — pastikan 5 test aksi PASS** (test `index` belum ada — itu Task 6)

Run: `php artisan test --filter=ManuscriptTrackerTest`
Expected: PASS (5 test)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php routes/web.php tests/Feature/ManuscriptTrackerTest.php
git commit -m "feat: manuscript tracker move/assign/priority endpoints (feature-tested)"
```

---

## Task 6: `ManuscriptTrackerController@index` — data papan & daftar (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`
- Modify: `tests/Feature/ManuscriptTrackerTest.php`

> Catatan: test render butuh view ada. Agar test bisa hijau di task ini, buat **stub view minimal** dulu (Step 3) lalu sempurnakan di Task 7–8.

- [ ] **Step 1: Tambah test render & akses ke `ManuscriptTrackerTest`**

Tambahkan method berikut ke kelas `ManuscriptTrackerTest`:

```php
    /** @test */
    public function board_renders_for_production(): void
    {
        $this->progress('editing'); // satu kartu di kolom editing (buku)
        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Manuscript Tracker')
            ->assertSee('Editing');
    }

    /** @test */
    public function marketing_cannot_access_board(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('manuscript.board'))->assertStatus(403);
    }

    /** @test */
    public function guest_is_redirected_from_board(): void
    {
        $this->get(route('manuscript.board'))->assertRedirect(route('login'));
    }

    /** @test */
    public function list_view_renders(): void
    {
        $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['view' => 'list']))
            ->assertOk()
            ->assertSee('Manuscript Tracker');
    }

    /** @test */
    public function priority_filter_narrows_results(): void
    {
        $high = $this->progress('editing');
        $high->update(['priority' => 'high']);
        $high->orderDetail->update(['title' => 'NASKAH PRIORITAS TINGGI']);

        $normal = $this->progress('editing');
        $normal->orderDetail->update(['title' => 'NASKAH BIASA']);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku', 'priority' => 'high']))
            ->assertOk()
            ->assertSee('NASKAH PRIORITAS TINGGI')
            ->assertDontSee('NASKAH BIASA');
    }
```

- [ ] **Step 2: Tambah method `index()` ke `ManuscriptTrackerController`**

Tambahkan method (di atas `move()`):

```php
    public function index(Request $request)
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        $tipe = $request->query('tipe') === 'artikel' ? 'artikel' : 'buku';
        $view = $request->query('view') === 'list' ? 'list' : 'board';

        $editorFilter = $request->query('editor');
        if ($editorFilter === 'me') {
            $editorFilter = (string) Auth::id();
        }

        $details = OrderDetail::query()
            ->with(['order.user', 'authors', 'scopes', 'titleProgress.assignedUser'])
            ->whereHas('titleProgress')
            ->when(
                $tipe === 'buku',
                fn ($q) => $q->whereIn('type', $bookTypes),
                fn ($q) => $q->whereNotIn('type', $bookTypes)
            )
            ->when($editorFilter !== null && $editorFilter !== '', fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('assigned_user_id', $editorFilter)))
            ->when($request->filled('priority'), fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('priority', $request->query('priority'))))
            ->get();

        $stages   = $tipe === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;
        $byStatus = $details->groupBy(fn ($d) => $d->titleProgress->status);
        $editors  = User::role(['production', 'manager'])->orderBy('name')->get(['id', 'name']);

        return view('manuscript.' . $view, compact('details', 'stages', 'byStatus', 'tipe', 'view', 'editors'));
    }
```

- [ ] **Step 3: Buat stub view minimal agar test render hijau**

Buat `resources/views/manuscript/board.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')
@section('content')
<div class="page-content">
    <h4>Manuscript Tracker</h4>
    @foreach($stages as $stage)
        <div>{{ Str::title(str_replace('_', ' ', $stage)) }}</div>
        @foreach(($byStatus[$stage] ?? collect()) as $detail)
            <div data-id="{{ $detail->titleProgress->id }}">{{ $detail->title }}</div>
        @endforeach
    @endforeach
</div>
@endsection
```

Buat `resources/views/manuscript/list.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')
@section('content')
<div class="page-content">
    <h4>Manuscript Tracker</h4>
    @foreach($details as $detail)
        <div>{{ $detail->title }}</div>
    @endforeach
</div>
@endsection
```

- [ ] **Step 4: Jalankan test — pastikan seluruh `ManuscriptTrackerTest` PASS**

Run: `php artisan test --filter=ManuscriptTrackerTest`
Expected: PASS (10 test)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php tests/Feature/ManuscriptTrackerTest.php resources/views/manuscript/board.blade.php resources/views/manuscript/list.blade.php
git commit -m "feat: manuscript tracker index (board/list data) with filters (feature-tested)"
```

---

## Task 7: Papan Kanban final — toolbar, kartu, SortableJS

**Files:**
- Create: `resources/views/manuscript/partials/toolbar.blade.php`
- Create: `resources/views/manuscript/partials/card.blade.php`
- Modify: `resources/views/manuscript/board.blade.php`

- [ ] **Step 1: Buat partial toolbar (dipakai board & list)**

```blade
{{-- resources/views/manuscript/partials/toolbar.blade.php --}}
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0">Manuscript Tracker</h4>
        <small class="text-muted">{{ $details->count() }} naskah aktif · geser kartu untuk memajukan tahap</small>
    </div>
    <form method="GET" action="{{ route('manuscript.board') }}" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="tipe" value="{{ $tipe }}">
        <input type="hidden" name="view" value="{{ $view }}">

        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['tipe' => 'buku'])) }}"
               class="btn btn-{{ $tipe === 'buku' ? 'primary' : 'outline-primary' }}">Buku</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['tipe' => 'artikel'])) }}"
               class="btn btn-{{ $tipe === 'artikel' ? 'primary' : 'outline-primary' }}">Artikel</a>
        </div>

        <select name="editor" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua Editor</option>
            <option value="me" {{ request('editor') === 'me' ? 'selected' : '' }}>Tugas saya</option>
            @foreach($editors as $ed)
                <option value="{{ $ed->id }}" {{ (string) request('editor') === (string) $ed->id ? 'selected' : '' }}>
                    {{ $ed->name }}
                </option>
            @endforeach
        </select>

        <select name="priority" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua Prioritas</option>
            @foreach(['high', 'normal', 'low'] as $pr)
                <option value="{{ $pr }}" {{ request('priority') === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
            @endforeach
        </select>

        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'board'])) }}"
               class="btn btn-{{ $view === 'board' ? 'dark' : 'outline-dark' }}">Papan</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'list'])) }}"
               class="btn btn-{{ $view === 'list' ? 'dark' : 'outline-dark' }}">Daftar</a>
        </div>
    </form>
</div>

@if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger py-2">{{ session('error') }}</div>
@endif
```

- [ ] **Step 2: Buat partial kartu**

```blade
{{-- resources/views/manuscript/partials/card.blade.php --}}
@php
    $p       = $detail->titleProgress;
    $next    = $p->getNextStatus();
    $primary = $detail->authors->sortBy('pivot.position')->first();
    $service = optional($detail->scopes->first())->name ?? strtoupper($detail->type);
@endphp
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-primary fw-bold" style="font-size:11px">{{ $detail->order->code_order ?? '—' }}</span>
            @if($p->priority === 'high')<span class="badge bg-danger">High</span>@endif
        </div>

        <a href="{{ route('order.indexJudul.progress', $detail->id) }}"
           class="d-block fw-semibold text-dark text-decoration-none mt-1" style="font-size:13px; line-height:1.3">
            {{ Str::limit($detail->title, 60) }}
        </a>

        <div class="d-flex align-items-center gap-2 mt-2">
            <span class="badge bg-light text-secondary">{{ strtoupper(Str::substr(optional($primary)->name ?? '?', 0, 1)) }}</span>
            <div class="flex-grow-1" style="min-width:0">
                <div class="text-truncate" style="font-size:11px">{{ optional($primary)->name ?? '—' }}</div>
                <div class="text-muted text-truncate" style="font-size:10px">{{ optional($primary)->affiliation ?? '' }}</div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="badge bg-info">{{ Str::limit($service, 18) }}</span>
            <small class="text-muted">{{ optional($p->started_at)->diffForHumans() }}</small>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted text-truncate" style="max-width:140px">
                Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong>
            </small>
            <div class="dropdown">
                <button class="btn btn-sm btn-link p-0 text-muted" type="button" data-bs-toggle="dropdown" aria-label="Aksi naskah">⋯</button>
                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:230px">
                    @if($next)
                    <li>
                        <form method="POST" action="{{ route('manuscript.move', $p->id) }}">@csrf
                            <input type="hidden" name="status" value="{{ $next }}">
                            <button type="submit" class="dropdown-item">
                                Majukan ke {{ Str::title(str_replace('_', ' ', $next)) }}
                            </button>
                        </form>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    @endif
                    <li>
                        <form method="POST" action="{{ route('manuscript.assign', $p->id) }}" class="px-2">@csrf
                            <label class="form-label mb-1" style="font-size:11px">Editor</label>
                            <select name="assigned_user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">— Belum —</option>
                                @foreach($editors as $ed)
                                    <option value="{{ $ed->id }}" {{ $p->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                    <li>
                        <form method="POST" action="{{ route('manuscript.priority', $p->id) }}" class="px-2 mt-2">@csrf
                            <label class="form-label mb-1" style="font-size:11px">Prioritas</label>
                            <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                                @foreach(['low', 'normal', 'high'] as $pr)
                                    <option value="{{ $pr }}" {{ $p->priority === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Ganti seluruh `board.blade.php` dengan versi final**

```blade
@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')

@section('content')
@php
    $statusColors = [
        'menunggu_proses' => '#94A3B8',
        'templating' => '#F59E0B', 'editing' => '#F59E0B', 'layout' => '#F59E0B',
        'revisi' => '#FB923C', 'proofreading' => '#FB923C', 'isbn' => '#FB923C',
        'submit' => '#4C5FD5', 'cetak' => '#4C5FD5', 'loa' => '#4C5FD5',
        'publish' => '#22C55E', 'terbit' => '#22C55E',
    ];
@endphp
<div class="page-content">
    @include('manuscript.partials.toolbar')

    <div style="overflow-x:auto">
        <div class="d-flex gap-3 pb-2" style="min-width:max-content">
            @foreach($stages as $stage)
                @php $cards = $byStatus[$stage] ?? collect(); @endphp
                <div data-stage-col style="width:280px; flex-shrink:0">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:{{ $statusColors[$stage] ?? '#94A3B8' }}"></span>
                        <strong style="font-size:13px">{{ Str::title(str_replace('_', ' ', $stage)) }}</strong>
                        <span class="badge bg-light text-muted" data-count>{{ $cards->count() }}</span>
                    </div>
                    <div data-column data-status="{{ $stage }}" style="min-height:60px; background:#F8FAFC; border-radius:8px; padding:8px">
                        @forelse($cards as $detail)
                            @include('manuscript.partials.card')
                        @empty
                            <div class="text-muted text-center py-3" style="font-size:11px">Tidak ada naskah</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    const base  = "{{ url('management/manuscript') }}";

    function toast(msg, ok) {
        const el = document.createElement('div');
        el.className = 'alert alert-' + (ok ? 'success' : 'danger');
        el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;min-width:240px;box-shadow:0 2px 8px rgba(0,0,0,.15)';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    function refreshCounts() {
        document.querySelectorAll('[data-stage-col]').forEach((col) => {
            const list = col.querySelector('[data-column]');
            const badge = col.querySelector('[data-count]');
            if (list && badge) badge.textContent = list.querySelectorAll('[data-id]').length;
        });
    }

    document.querySelectorAll('[data-column]').forEach((col) => {
        new Sortable(col, {
            group: 'manuscript',
            animation: 150,
            ghostClass: 'opacity-50',
            onEnd: function (evt) {
                const item = evt.item;
                const toCol = evt.to;
                const fromCol = evt.from;
                if (toCol === fromCol) return;

                const target = toCol.getAttribute('data-status');
                const id = item.getAttribute('data-id');

                fetch(base + '/' + id + '/move', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ status: target }),
                })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Gagal memindahkan naskah.');
                    item.setAttribute('data-status', target);
                    toast(data.message || 'Naskah dipindahkan.', true);
                    refreshCounts();
                })
                .catch((err) => {
                    // revert ke kolom & posisi semula
                    const ref = fromCol.children[evt.oldIndex] || null;
                    fromCol.insertBefore(item, ref);
                    toast(err.message, false);
                });
            },
        });
    });

    // Mobile: default ke view daftar
    if (!location.search.includes('view=') && window.innerWidth < 768) {
        const sep = location.search ? '&' : '?';
        location.replace(location.pathname + location.search + sep + 'view=list');
    }
})();
</script>
@endpush
```

- [ ] **Step 4: Jalankan test — pastikan masih hijau**

Run: `php artisan test --filter=ManuscriptTrackerTest`
Expected: PASS (10 test)

- [ ] **Step 5: Commit**

```bash
git add resources/views/manuscript/partials/toolbar.blade.php resources/views/manuscript/partials/card.blade.php resources/views/manuscript/board.blade.php
git commit -m "feat: final Kanban board with cards, drag-to-advance, quick actions"
```

---

## Task 8: View Daftar final

**Files:**
- Modify: `resources/views/manuscript/list.blade.php`

- [ ] **Step 1: Ganti seluruh `list.blade.php` dengan versi final**

```blade
@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')

@section('content')
@php
    $statusBadge = [
        'menunggu_proses' => 'secondary',
        'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
        'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
        'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
        'publish' => 'success', 'terbit' => 'success',
    ];
    $prioBadge = ['high' => 'danger', 'normal' => 'secondary', 'low' => 'info'];
@endphp
<div class="page-content">
    @include('manuscript.partials.toolbar')

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Judul Naskah</th>
                            <th>Stage</th>
                            <th>Editor</th>
                            <th>Prioritas</th>
                            <th>Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($details as $detail)
                            @php $p = $detail->titleProgress; @endphp
                            <tr>
                                <td>
                                    <strong>{{ Str::limit($detail->title, 50) }}</strong><br>
                                    <small class="text-muted">{{ $detail->order->code_order ?? '—' }}</small>
                                </td>
                                <td><span class="badge bg-{{ $statusBadge[$p->status] ?? 'secondary' }}">{{ Str::title(str_replace('_', ' ', $p->status)) }}</span></td>
                                <td>{{ optional($p->assignedUser)->name ?? '—' }}</td>
                                <td><span class="badge bg-{{ $prioBadge[$p->priority] ?? 'secondary' }}">{{ ucfirst($p->priority) }}</span></td>
                                <td><small>{{ optional($p->started_at)->diffForHumans() }}</small></td>
                                <td class="text-end">
                                    <a href="{{ route('order.indexJudul.progress', $detail->id) }}" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada naskah.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 2: Jalankan test — pastikan `list_view_renders` tetap PASS**

Run: `php artisan test --filter=ManuscriptTrackerTest`
Expected: PASS (10 test)

- [ ] **Step 3: Commit**

```bash
git add resources/views/manuscript/list.blade.php
git commit -m "feat: manuscript tracker list view with editor and priority columns"
```

---

## Task 9: Halaman detail — production di form + kontrol assign/prioritas

**Files:**
- Modify: `resources/views/orders/detail-title.blade.php`

- [ ] **Step 1: Izinkan `production` melihat form update status**

Ganti baris `@hasanyrole('manager|superadmin')` (pembuka form, sekitar baris 72) menjadi:

```blade
                    @hasanyrole('production|manager|superadmin')
```

Dan ganti baris penutup `@endhasanyrole` yang berpasangan (sekitar baris 106) — tetap `@endhasanyrole` (tidak berubah, hanya pastikan masih ada).

- [ ] **Step 2: Pastikan dropdown status untuk production hanya menampilkan tahap berikutnya**

Di dalam form, blok `@role('superadmin') ... @else ... @endrole` saat ini menampilkan semua stage untuk superadmin dan hanya `getNextStatus()` untuk selain superadmin. Ini sudah benar untuk production (masuk cabang `@else`). **Tidak ada perubahan** pada blok ini — cukup verifikasi visual saat QA.

- [ ] **Step 3: Tambah kartu "Penugasan & Prioritas" tepat setelah card Timeline (setelah `</div>` penutup card Progress, sebelum card Daftar Penulis)**

Sisipkan blok berikut sebelum komentar `{{-- Daftar Penulis --}}`:

```blade
            {{-- Penugasan & Prioritas --}}
            @hasanyrole('production|manager|superadmin')
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Penugasan & Prioritas</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('manuscript.assign', $progress->id) }}">
                                @csrf
                                <label class="form-label form-label-sm">Editor / Penanggung Jawab</label>
                                <div class="d-flex gap-2">
                                    <select name="assigned_user_id" class="form-select form-select-sm">
                                        <option value="">— Belum ditugaskan —</option>
                                        @foreach(\App\Models\User::role(['production','manager'])->orderBy('name')->get() as $ed)
                                            <option value="{{ $ed->id }}" {{ $progress->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </div>
                                @error('assigned_user_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form method="POST" action="{{ route('manuscript.priority', $progress->id) }}">
                                @csrf
                                <label class="form-label form-label-sm">Prioritas</label>
                                <div class="d-flex gap-2">
                                    <select name="priority" class="form-select form-select-sm">
                                        @foreach(['low','normal','high'] as $pr)
                                            <option value="{{ $pr }}" {{ $progress->priority === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endhasanyrole
```

- [ ] **Step 3b: Tambah penanda agar QA mudah** — tidak ada test otomatis baru di sini; perubahan diverifikasi di Task 11 (QA manual). Pastikan tidak ada error Blade dengan render cepat:

Run: `php artisan view:clear` lalu buka halaman detail naskah saat QA.

- [ ] **Step 4: Jalankan seluruh suite — pastikan tidak ada regresi**

Run: `php artisan test`
Expected: PASS (semua, termasuk 41 lama + test baru)

- [ ] **Step 5: Commit**

```bash
git add resources/views/orders/detail-title.blade.php
git commit -m "feat: detail-title page supports production status update + assign/priority controls"
```

---

## Task 10: Menu sidebar — "Produksi → Manuscript Tracker"

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1: Tambah kategori & item menu**

Sisipkan blok berikut tepat setelah `@endrole` penutup blok `@role(['superadmin', 'manager', 'marketing'])` (setelah item "Arsip Judul", sekitar baris 52):

```blade
            @role(['superadmin', 'manager', 'production'])
                <li class="nav-item nav-category">Produksi</li>
                <li class="nav-item {{ active_class(['management/manuscript']) }}">
                    <a href="{{ route('manuscript.board') }}" class="nav-link">
                        <i class="link-icon" data-feather="trello"></i>
                        <span class="link-title">Manuscript Tracker</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 2: Verifikasi render sidebar tidak error**

Run: `php artisan view:clear`
Lalu (di Task 11) login sebagai production → menu "Manuscript Tracker" tampil; login sebagai marketing → menu tidak tampil.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php
git commit -m "feat: add Produksi > Manuscript Tracker menu for production/manager/superadmin"
```

---

## Task 11: Verifikasi end-to-end & QA manual (Definition of Done)

**Files:** —

- [ ] **Step 1: Jalankan seluruh suite otomatis**

Run: `php artisan test`
Expected: PASS — 41 test lama + 12 unit service + 10 feature tracker = semua hijau.

- [ ] **Step 2: QA manual — role `production`**

Login sebagai production (lihat `database/seeders/PermissionSeed.php` untuk kredensial production):
- [ ] Menu "Manuscript Tracker" muncul; buka papan.
- [ ] Toggle Buku/Artikel mengubah kolom (stage) dengan benar.
- [ ] Drag kartu editorial ke kolom berikutnya → kartu tetap di sana setelah refresh (persist).
- [ ] Drag kartu ke kolom yang melanggar (mundur / lompat / `menunggu_proses`) → kartu **kembali** ke kolom asal + toast merah.
- [ ] Quick action: assign diri sendiri sebagai editor → nama muncul di kartu.
- [ ] Quick action: set prioritas High → badge "High" muncul.
- [ ] Tombol fallback "Majukan ke …" (dropdown ⋯) memindahkan stage tanpa drag.

- [ ] **Step 3: QA manual — role `manager` & `superadmin`**

- [ ] manager: bisa memajukan stage apa pun; tidak bisa koreksi mundur (toast/403).
- [ ] superadmin: di halaman detail naskah, koreksi mundur **dengan catatan** → log "Koreksi" merah muncul di Riwayat.
- [ ] superadmin: koreksi tanpa catatan → pesan "catatan wajib".

- [ ] **Step 4: QA manual — role `marketing`**

- [ ] Menu "Manuscript Tracker" **tidak** tampil.
- [ ] Akses langsung `…/management/manuscript` → ditolak (403).

- [ ] **Step 5: QA manual — UX & responsif**

- [ ] Toggle Papan/Daftar bolak-balik tanpa kehilangan filter (tipe/editor/prioritas).
- [ ] Di layar sempit (<768px), papan bisa di-scroll horizontal; membuka tanpa `view=` otomatis ke daftar.
- [ ] View Daftar menampilkan kolom Editor & Prioritas.

- [ ] **Step 6: Cek log error kosong**

Run: `php artisan view:clear` lalu jalankan alur di atas; pastikan tidak ada error baru di `storage/logs/laravel.log`.

- [ ] **Step 7: Commit penutup (jika ada penyesuaian kecil dari QA)**

```bash
git add -A
git commit -m "chore: manuscript tracker QA fixes"
```

> Jika tidak ada perubahan dari QA, lewati Step 7.

---

## Self-Review Coverage (spec → task)

| Bagian Spec | Task |
|-------------|------|
| 1 Arsitektur, `TitleProgressService` | Task 3, 4 |
| 2 Data (kolom, model) | Task 1, 2 |
| 3 Kepemilikan stage & aturan akses | Task 2 (handler), Task 3 (service) |
| 4 Routes + JSON | Task 5 |
| 5 UI Papan + View Daftar + query | Task 6, 7, 8 |
| 6 Detail page assign/prioritas | Task 9 |
| 7 Menu & akses | Task 10 |
| 8 Error handling | Task 3 (exceptions) + Task 7 (revert/toast) |
| 9 QA/QC (test + manual + DoD) | Task 3, 5, 6 (otomatis) + Task 11 (manual) |
| 10 YAGNI | Tidak diimplementasi (sesuai) |
