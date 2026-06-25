# Task Mgmt Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kunci tugas selesai yang sudah dilaporkan (board), alert deadline H‑7 (notif sekali + kartu dashboard + SweetAlert), SweetAlert2 untuk semua confirm, report wajib bukti, dan keterangan lampiran kosong + daftar bukti pengawas.

**Architecture:** Lock dihitung live dari `tb_daily_reports` (submitted) — board mengurut done by `completed_at` + flag `is_locked`; controller menolak mutasi tugas terkunci. Deadline: kolom `deadline_notified_at` (anti-spam) + `TaskService::notifyDueSoon` dipicu composer dashboard, kartu partial + popup SweetAlert. SweetAlert2 global di master via `form[data-confirm]`.

**Tech Stack:** Laravel 11, Spatie roles, Blade + Bootstrap 5, SweetAlert2/Dropzone/DataTables/SortableJS (semua ter-bundle), `GoogleDriveService`, `Notifier`.

**Spec:** `docs/superpowers/specs/2026-06-24-task-mgmt-hardening-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`); `GoogleDriveService` di-mock. DB error → MySQL/XAMPP mati: `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Setelah selesai: `php artisan migrate` di dev. Migrasi sebelumnya: announcements `000001/000002`, tasks `000003`, reports `000004/000005` — yang baru `000006`.

---

## Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_06_24_000006_add_deadline_notified_at_to_tb_tasks.php`
- Modify: `app/Models/Task.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_06_24_000006_add_deadline_notified_at_to_tb_tasks.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tasks', function (Blueprint $table) {
            $table->timestamp('deadline_notified_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tasks', function (Blueprint $table) {
            $table->dropColumn('deadline_notified_at');
        });
    }
};
```

- [ ] **Step 2: Model**

In `app/Models/Task.php`, add `deadline_notified_at` to `$fillable` and `$casts`.

Change the `$fillable` array to include it:
```php
    protected $fillable = [
        'user_id', 'title', 'description', 'status', 'priority',
        'due_date', 'position', 'completed_at', 'deadline_notified_at', 'created_by',
    ];
```
Change `$casts` to add the datetime cast:
```php
    protected $casts = [
        'due_date'             => 'date',
        'completed_at'         => 'datetime',
        'deadline_notified_at' => 'datetime',
        'position'             => 'integer',
    ];
```

- [ ] **Step 3: Verify migration healthy**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS (RefreshDatabase applies the new column cleanly).

- [ ] **Step 4: Commit**

```
git add database/migrations/2026_06_24_000006_add_deadline_notified_at_to_tb_tasks.php app/Models/Task.php
git commit -m "feat(task): add deadline_notified_at column

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: TaskService (lock + due-soon + notify) + Notifier (TDD)

**Files:**
- Modify: `app/Services/TaskService.php`, `app/Services/Notifier.php`
- Test: `tests/Unit/TaskServiceTest.php`, `tests/Unit/NotifierTest.php`

- [ ] **Step 1: Write failing unit tests**

In `tests/Unit/TaskServiceTest.php`, add these imports near the top (if missing): `use App\Models\DailyReport;` and `use App\Services\Notifier;`. Then add these test methods inside the class:

```php
    /** @test */
    public function board_done_sorted_by_completed_and_locked_when_reported(): void
    {
        $u = User::factory()->create();
        $today = today();
        $done = $this->task($u, ['status' => 'done', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);
        $olderUnlocked = $this->task($u, ['status' => 'done', 'completed_at' => $today->copy()->subDay()]);

        $board = $this->svc->board($u);

        $this->assertSame($done->id, $board['done'][0]->id);                        // completed_at desc
        $this->assertTrue($board['done']->firstWhere('id', $done->id)->is_locked);  // hari ini dilaporkan
        $this->assertFalse($board['done']->firstWhere('id', $olderUnlocked->id)->is_locked);
    }

    /** @test */
    public function is_locked_reflects_submitted_report(): void
    {
        $u = User::factory()->create();
        $today = today();
        $t = $this->task($u, ['status' => 'done', 'completed_at' => $today]);

        $this->assertFalse($this->svc->isLocked($t));

        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);
        $this->assertTrue($this->svc->isLocked($t->fresh()));
    }

    /** @test */
    public function due_soon_scopes_within_7_days_not_done(): void
    {
        $u = User::factory()->create();
        $this->task($u, ['title' => 'Soon', 'due_date' => today()->addDays(3)->toDateString()]);
        $this->task($u, ['title' => 'Far', 'due_date' => today()->addDays(20)->toDateString()]);
        $this->task($u, ['title' => 'Done', 'status' => 'done', 'completed_at' => now(), 'due_date' => today()->addDay()->toDateString()]);

        $rows = $this->svc->dueSoonFor($u);

        $this->assertCount(1, $rows);
        $this->assertSame('Soon', $rows->first()->title);
    }

    /** @test */
    public function notify_due_soon_is_idempotent(): void
    {
        // roleUsers() di deadlineReminder memanggil User::role([...]) — role harus ada agar Spatie tak melempar.
        foreach (['manager', 'superadmin', 'admin'] as $r) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $u = User::factory()->create();
        $t = $this->task($u, ['due_date' => today()->addDays(2)->toDateString()]);

        $this->svc->notifyDueSoon(new Notifier());
        $this->assertNotNull($t->fresh()->deadline_notified_at);
        $count = $u->notifications()->count();
        $this->assertSame(1, $count); // pemilik dapat 1 (tak ada pengawas di test ini)

        $this->svc->notifyDueSoon(new Notifier()); // panggilan kedua: tak menambah
        $this->assertSame($count, $u->notifications()->count());
    }
```

In `tests/Unit/NotifierTest.php`, add `use App\Models\Task;` and `use Spatie\Permission\Models\Role;` if missing, then add:

```php
    /** @test */
    public function deadline_reminder_notifies_owner_and_overseers(): void
    {
        foreach (['production', 'manager', 'superadmin', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $owner = User::factory()->create(); $owner->assignRole('production');
        $manager = User::factory()->create(); $manager->assignRole('manager');
        $super = User::factory()->create(); $super->assignRole('superadmin');
        $admin = User::factory()->create(); $admin->assignRole('admin');

        $task = Task::create(['user_id' => $owner->id, 'title' => 'X', 'status' => 'todo', 'priority' => 'normal', 'due_date' => today()->addDays(2)->toDateString()]);

        (new Notifier())->deadlineReminder($task);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(1, $super->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="TaskServiceTest|NotifierTest"`
Expected: FAIL — `Call to undefined method ...::isLocked()` / `deadlineReminder()`.

- [ ] **Step 3: Notifier::deadlineReminder**

In `app/Services/Notifier.php`, add `use App\Models\Task;` near the other model imports. Add this method (after `commissionPaid` or `taskAssigned`):

```php
    public function deadlineReminder(Task $task): void
    {
        $task->loadMissing('user');
        if (! $task->user) {
            return;
        }
        $recipients = $this->roleUsers(['manager', 'superadmin', 'admin'], $task->user)
            ->push($task->user)->unique('id')->values();

        $this->send($recipients, [
            'category' => 'deadline',
            'title'    => 'Tugas mendekati deadline',
            'message'  => $task->title . ' • ' . ($task->due_date?->format('d M Y') ?? '?'),
            'url'      => route('task.board'),
            'icon'     => 'clock',
        ]);
    }
```

(`roleUsers($roles, $actor)` returns role-holders EXCEPT `$actor` — passing the owner as `$actor` avoids a duplicate when the owner is also a manager; we then `push` the owner back.)

- [ ] **Step 4: TaskService methods**

In `app/Services/TaskService.php`, add `use App\Models\DailyReport;` near the top imports. Replace the existing `board()` method with:

```php
    /** Tugas user per kolom. Kolom done diurut completed_at desc + flag is_locked. */
    public function board(User $user): array
    {
        $tasks = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get();

        // Tanggal report submitted milik user → menandai tugas selesai yang terkunci.
        $lockedDates = DailyReport::where('user_id', $user->id)->where('status', 'submitted')
            ->get(['report_date'])->map(fn ($r) => $r->report_date->toDateString())->flip();

        $grouped = $tasks->groupBy('status');
        $done = $grouped->get('done', collect())->sortByDesc('completed_at')->values();
        $done->each(function (Task $t) use ($lockedDates) {
            $t->is_locked = $t->completed_at && $lockedDates->has($t->completed_at->toDateString());
        });

        return [
            'todo'        => $grouped->get('todo', collect())->values(),
            'in_progress' => $grouped->get('in_progress', collect())->values(),
            'done'        => $done,
        ];
    }
```

Add these methods to the class (e.g. after `monitor()`):

```php
    /** True bila task done & tanggal completed_at-nya punya report submitted. */
    public function isLocked(Task $task): bool
    {
        if ($task->status !== 'done' || ! $task->completed_at) {
            return false;
        }
        return DailyReport::where('user_id', $task->user_id)->where('status', 'submitted')
            ->whereDate('report_date', $task->completed_at)->exists();
    }

    /** Tugas user belum selesai dengan due_date dalam 7 hari ke depan. */
    public function dueSoonFor(User $user): \Illuminate\Support\Collection
    {
        return Task::forUser($user->id)->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->orderBy('due_date')->get();
    }

    /** Lintas user (untuk pengawas). */
    public function dueSoonAll(): \Illuminate\Support\Collection
    {
        return Task::query()->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->with('user')->orderBy('due_date')->get();
    }

    /** Kirim notifikasi deadline sekali per tugas (anti-spam via deadline_notified_at). */
    public function notifyDueSoon(Notifier $notifier): void
    {
        $tasks = Task::query()->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->whereNull('deadline_notified_at')->get();

        foreach ($tasks as $task) {
            $notifier->deadlineReminder($task);
            $task->update(['deadline_notified_at' => now()]);
        }
    }
```

`notifyDueSoon` references `Notifier` (same `App\Services` namespace — no import needed).

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter="TaskServiceTest|NotifierTest"`
Expected: PASS (all existing + 5 new).

- [ ] **Step 6: Commit**

```
git add app/Services/TaskService.php app/Services/Notifier.php tests/Unit/TaskServiceTest.php tests/Unit/NotifierTest.php
git commit -m "feat(task): board lock + due-soon + deadline notifier

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: TaskController lock guard + due_date reset (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/TaskController.php`
- Test: `tests/Feature/TaskControllerTest.php`

- [ ] **Step 1: Write failing feature tests**

In `tests/Feature/TaskControllerTest.php`, add `use App\Models\DailyReport;` if missing, then add:

```php
    /** @test */
    public function locked_task_cannot_change_status_or_be_deleted(): void
    {
        $u = $this->user('production');
        $today = today();
        $t = $this->task($u, ['status' => 'done', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->patch(route('task.status', $t->id), ['status' => 'todo'])->assertStatus(422);
        $this->actingAs($u)->delete(route('task.destroy', $t->id))->assertStatus(422);
        $this->assertDatabaseHas('tb_tasks', ['id' => $t->id, 'status' => 'done']);
    }

    /** @test */
    public function changing_due_date_resets_deadline_flag(): void
    {
        $u = $this->user('production');
        $t = $this->task($u, ['due_date' => today()->addDays(3)->toDateString(), 'deadline_notified_at' => now()]);

        $this->actingAs($u)->put(route('task.update', $t->id), [
            'title' => 'T', 'priority' => 'normal', 'due_date' => today()->addDays(10)->toDateString(),
        ])->assertRedirect();

        $this->assertNull($t->fresh()->deadline_notified_at);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TaskControllerTest`
Expected: FAIL — status/destroy return 200/redirect (no lock guard yet); due_date flag not reset.

- [ ] **Step 3: Add lock guard + reset**

In `app/Http/Controllers/Pages/TaskController.php`, add a private helper after `authorizeTask`:

```php
    private function abortIfLocked(Task $task): void
    {
        if ($this->service->isLocked($task)) {
            abort(422, 'Tugas sudah dikunci report terkirim.');
        }
    }
```

In `status()`, after `$this->authorizeTask($task);` add `$this->abortIfLocked($task);`. So:
```php
    public function status(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $this->abortIfLocked($task);
        $data = $request->validate([
            'status'   => 'required|in:todo,in_progress,done',
            'position' => 'nullable|integer|min:0',
        ]);
        $this->service->move($task, $data['status'], (int) ($data['position'] ?? 0));
        return response()->json(['ok' => true]);
    }
```

In `destroy()`, add the guard after authorize:
```php
    public function destroy(int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $this->abortIfLocked($task);
        $task->delete();
        return back()->with('success', 'Tugas dihapus.');
    }
```

In `update()`, add the guard after authorize, and reset `deadline_notified_at` when `due_date` changes. Replace the body of `update()` with:
```php
    public function update(Request $request, int $id, Notifier $notifier)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $this->abortIfLocked($task);
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request, $task->user_id);
        $reassigned = $assignee !== $task->user_id;
        $newDue = $data['due_date'] ?? null;
        $dueChanged = optional($task->due_date)->toDateString() !== $newDue;

        $task->update([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $newDue,
            'deadline_notified_at' => $dueChanged ? null : $task->deadline_notified_at,
        ]);

        // Pemilik lama sengaja TIDAK dinotifikasi saat tugas dialihkan; hanya penerima baru.
        if ($reassigned && $assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        return back()->with('success', 'Tugas diperbarui.');
    }
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TaskControllerTest`
Expected: PASS (all existing + 2 new).

- [ ] **Step 5: Commit**

```
git add app/Http/Controllers/Pages/TaskController.php tests/Feature/TaskControllerTest.php
git commit -m "feat(task): server lock guard + due-date deadline-flag reset

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Report wajib bukti + submissions kolom bukti (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/DailyReportController.php`, `app/Services/DailyReportService.php`
- Test: `tests/Feature/DailyReportControllerTest.php`, `tests/Unit/DailyReportServiceTest.php`

- [ ] **Step 1: Write failing tests**

In `tests/Feature/DailyReportControllerTest.php`, add:

```php
    /** @test */
    public function submit_requires_at_least_one_evidence(): void
    {
        $u = $this->user('production');

        // tanpa bukti → ditolak, tetap draft
        $this->actingAs($u)->post(route('report.submit'), ['date' => today()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['user_id' => $u->id, 'status' => 'draft']);

        // dengan bukti → submitted
        $report = \App\Models\DailyReport::where('user_id', $u->id)->first();
        $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);
        $this->actingAs($u)->post(route('report.submit'), ['date' => today()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['id' => $report->id, 'status' => 'submitted']);
    }
```

In `tests/Unit/DailyReportServiceTest.php`, add:

```php
    /** @test */
    public function submissions_include_evidence_count(): void
    {
        $u = User::factory()->create();
        $today = Carbon::today();
        $report = DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);
        $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);
        $report->files()->create(['drive_file_id' => 'd2', 'name' => 'b.jpg', 'url' => 'u']);

        $rows = $this->svc->submissionsForDate($today);

        $this->assertSame(2, $rows->firstWhere('id', $u->id)['bukti']);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="DailyReportControllerTest|DailyReportServiceTest"`
Expected: FAIL — submit submits without evidence; `bukti` key missing.

- [ ] **Step 3: submit requires evidence**

In `app/Http/Controllers/Pages/DailyReportController.php`, replace `submit()` with:

```php
    public function submit(Request $request)
    {
        $data = $request->validate(['date' => 'required|date']);
        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));

        if (! $report->isSubmitted()) {
            if ($report->files()->count() === 0) {
                return back()->with('error', 'Wajib lampirkan minimal 1 bukti sebelum mengirim report.');
            }
            $report->update(['status' => 'submitted', 'submitted_at' => now()]);
        }

        return back()->with('success', 'Report dikirim.');
    }
```

- [ ] **Step 4: submissions evidence count**

In `app/Services/DailyReportService.php`, update `submissionsForDate()` to compute a per-user evidence count and include `bukti`. Replace the method body with:

```php
    public function submissionsForDate(Carbon $date): Collection
    {
        $reports = DailyReport::whereDate('report_date', $date)->get(['id', 'user_id', 'status']);
        $submitted = $reports->where('status', 'submitted')->pluck('user_id')->flip();

        $buktiCounts = DailyReportFile::whereIn('daily_report_id', $reports->pluck('id'))
            ->selectRaw('daily_report_id, count(*) as c')->groupBy('daily_report_id')->pluck('c', 'daily_report_id');
        $buktiPerUser = $reports->mapWithKeys(fn ($r) => [$r->user_id => (int) ($buktiCounts[$r->id] ?? 0)]);

        $doneCounts = Task::whereBetween('completed_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        return User::orderBy('name')->get(['id', 'name'])->map(fn (User $u) => [
            'id'        => $u->id,
            'name'      => $u->name,
            'submitted' => $submitted->has($u->id),
            'selesai'   => (int) ($doneCounts[$u->id] ?? 0),
            'bukti'     => (int) ($buktiPerUser[$u->id] ?? 0),
        ])->values();
    }
```

Add `use App\Models\DailyReportFile;` to the imports at the top of `DailyReportService.php`.

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter="DailyReportControllerTest|DailyReportServiceTest"`
Expected: PASS (all existing + 2 new).

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/DailyReportController.php app/Services/DailyReportService.php tests/Feature/DailyReportControllerTest.php tests/Unit/DailyReportServiceTest.php
git commit -m "feat(report): submit requires evidence + submissions bukti count

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: SweetAlert2 global + deadline alert (partial/composer/dashboard) (TDD)

**Files:**
- Modify: `resources/views/layouts/master.blade.php`, `app/Providers/AppServiceProvider.php`, `resources/views/dashboard.blade.php`, `resources/views/tasks/board.blade.php`, `resources/views/tasks/index.blade.php`, `resources/views/reports/daily.blade.php`
- Create: `resources/views/dashboard/partials/deadlines.blade.php`
- Test: `tests/Feature/DeadlineAlertTest.php`

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/DeadlineAlertTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DeadlineAlertTest extends TestCase
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
    public function dashboard_shows_deadline_card_and_notifies_once(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'DEADLINE DEKAT', 'status' => 'todo', 'priority' => 'normal', 'due_date' => today()->addDays(2)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('DEADLINE DEKAT');

        // notifikasi dibuat sekali (idempoten saat dashboard dibuka lagi)
        $this->assertSame(1, $u->notifications()->count());
        $this->actingAs($u)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $u->notifications()->count());
    }

    /** @test */
    public function no_deadline_card_when_nothing_due_soon(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'JAUH', 'status' => 'todo', 'priority' => 'normal', 'due_date' => today()->addDays(30)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertDontSee('Tugas Mendekati Deadline');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=DeadlineAlertTest`
Expected: FAIL — deadline card not rendered ("DEADLINE DEKAT" absent), no notification.

- [ ] **Step 3: Deadline composer in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, inside `boot()`, after the existing `dashboard.partials.announcements` composer, add:

```php
        \Illuminate\Support\Facades\View::composer('dashboard.partials.deadlines', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $deadlines = collect();
            $isOverseer = false;
            if ($user) {
                try {
                    $svc = app(\App\Services\TaskService::class);
                    $svc->notifyDueSoon(app(\App\Services\Notifier::class));
                    $isOverseer = $user->hasAnyRole(['manager', 'superadmin', 'admin']);
                    $deadlines = $isOverseer ? $svc->dueSoonAll() : $svc->dueSoonFor($user);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Gagal memuat alert deadline: ' . $e->getMessage());
                }
            }
            $view->with('deadlines', $deadlines);
            $view->with('deadlineIsOverseer', $isOverseer);
        });
```

- [ ] **Step 4: Deadline partial**

Create `resources/views/dashboard/partials/deadlines.blade.php`:

```blade
@php $items = $deadlines ?? collect(); @endphp
@if($items->isNotEmpty())
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card border-start border-4 border-warning">
            <div class="card-body">
                <h6 class="mb-2 text-warning"><i data-feather="clock" class="icon-sm me-1"></i>Tugas Mendekati Deadline ({{ $items->count() }})</h6>
                <ul class="list-group list-group-flush">
                    @foreach($items as $t)
                        @php $days = (int) \Illuminate\Support\Carbon::today()->diffInDays($t->due_date, false); @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span style="font-size:13px">
                                {{ $t->title }}
                                @if($deadlineIsOverseer ?? false)<span class="text-muted">· {{ $t->user?->name }}</span>@endif
                            </span>
                            <span class="badge {{ $days <= 2 ? 'bg-danger' : 'bg-warning text-dark' }}">
                                {{ $days < 0 ? 'Lewat ' . abs($days) . 'h' : ($days === 0 ? 'Hari ini' : $days . ' hari lagi') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

@push('custom-scripts')
<script>
(function () {
    if (!window.Swal || sessionStorage.getItem('deadlineAlertShown')) return;
    sessionStorage.setItem('deadlineAlertShown', '1');
    var list = @json($items->map(fn ($t) => $t->title)->values());
    Swal.fire({
        icon: 'warning',
        title: 'Tugas mendekati deadline',
        html: '<ul style="text-align:left">' + list.map(function (t) { return '<li>' + t.replace(/</g, '&lt;') + '</li>'; }).join('') + '</ul>',
        confirmButtonText: 'Mengerti'
    });
})();
</script>
@endpush
@endif
```

- [ ] **Step 5: Include the partial on the dashboard**

In `resources/views/dashboard.blade.php`, change:
```blade
@section('content')
@include('dashboard.partials.announcements')
```
to:
```blade
@section('content')
@include('dashboard.partials.announcements')
@include('dashboard.partials.deadlines')
```

- [ ] **Step 6: SweetAlert2 global in master layout**

In `resources/views/layouts/master.blade.php`, add the SweetAlert2 CSS in `<head>` after the perfect-scrollbar css line (line 27):
```blade
    <link href="{{ asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" />
```
Add the SweetAlert2 JS after the perfect-scrollbar js line (line 58):
```blade
    <script src="{{ asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/sweetalert2/sweetalert2.min.js') }}"></script>
```
Add the global helper block immediately before `</body>` (after the `@stack('custom-scripts')` line):
```blade
    @stack('custom-scripts')

    <script>
    (function () {
        if (!window.Swal) return;
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('[data-confirm]') || form.dataset.confirmed === '1') return;
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi', text: form.getAttribute('data-confirm'), icon: 'warning',
                showCancelButton: true, confirmButtonText: 'Ya', cancelButtonText: 'Batal', confirmButtonColor: '#d33'
            }).then(function (res) { if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); } });
        }, true);
        window.swalError = function (msg) { Swal.fire({ icon: 'error', title: 'Gagal', text: msg }); };
        window.swalSuccess = function (msg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: msg, timer: 2000, showConfirmButton: false }); };
        @if(session('success')) window.swalSuccess(@json(session('success'))); @endif
        @if(session('error')) window.swalError(@json(session('error'))); @endif
    })();
    </script>
</body>
```
(Replace the existing `</body>` with the helper block + `</body>` as shown.)

- [ ] **Step 7: Replace native confirm() with data-confirm**

In `resources/views/tasks/board.blade.php`, change the delete form:
```blade
<form action="{{ route('task.destroy', $task->id) }}" method="POST" onsubmit="return confirm('Hapus tugas ini?')">@csrf @method('DELETE')
```
to:
```blade
<form action="{{ route('task.destroy', $task->id) }}" method="POST" data-confirm="Hapus tugas ini?">@csrf @method('DELETE')
```

In `resources/views/tasks/index.blade.php`, change the delete form `onsubmit="return confirm('Hapus tugas ini?')"` to `data-confirm="Hapus tugas ini?"` (remove the `onsubmit` attribute, add `data-confirm`).

In `resources/views/reports/daily.blade.php`, change the submit-report form:
```blade
<form method="POST" action="{{ route('report.submit') }}" onsubmit="return confirm('Kirim report hari ini? Setelah dikirim tidak bisa diubah.')">
```
to:
```blade
<form method="POST" action="{{ route('report.submit') }}" data-confirm="Kirim report hari ini? Setelah dikirim tidak bisa diubah.">
```

- [ ] **Step 8: Run, confirm PASS**

Run: `php artisan test --filter=DeadlineAlertTest`
Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```
git add resources/views/layouts/master.blade.php app/Providers/AppServiceProvider.php resources/views/dashboard.blade.php resources/views/dashboard/partials/deadlines.blade.php resources/views/tasks/board.blade.php resources/views/tasks/index.blade.php resources/views/reports/daily.blade.php tests/Feature/DeadlineAlertTest.php
git commit -m "feat(task): deadline dashboard alert + SweetAlert2 global confirms

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 6: View refinements — board lock UI, daily evidence UI, submissions bukti column (TDD)

**Files:**
- Modify: `resources/views/tasks/board.blade.php`, `resources/views/reports/daily.blade.php`, `resources/views/reports/submissions.blade.php`
- Test: `tests/Feature/TaskPagesTest.php`

- [ ] **Step 1: Write failing page test**

In `tests/Feature/TaskPagesTest.php`, add `use App\Models\DailyReport;` if missing, then add:

```php
    /** @test */
    public function board_marks_locked_done_task(): void
    {
        $u = $this->user('production');
        $today = today();
        Task::create(['user_id' => $u->id, 'title' => 'TugasTerkunci', 'status' => 'done', 'priority' => 'normal', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('task-locked');
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TaskPagesTest`
Expected: FAIL — `task-locked` class not present.

- [ ] **Step 3: Board lock UI**

In `resources/views/tasks/board.blade.php`, replace the done-card markup so locked cards get the `task-locked` class, a lock icon, and no actions. Replace the card `<div class="card mb-2 task-card" ...>` block (the `@foreach($board[$key] as $task)` body) with:

```blade
                    @foreach($board[$key] as $task)
                        @php $locked = $task->is_locked ?? false; @endphp
                        <div class="card mb-2 task-card {{ $locked ? 'task-locked' : '' }}" data-id="{{ $task->id }}" style="cursor:{{ $locked ? 'not-allowed' : 'grab' }};{{ $locked ? 'opacity:.75' : '' }}">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="fw-semibold" style="font-size:13px">
                                        @if($locked)<i data-feather="lock" class="icon-xs me-1 text-muted"></i>@endif{{ $task->title }}
                                    </span>
                                    @unless($locked)
                                        <div class="dropdown">
                                            <button class="btn btn-xs p-0 border-0 bg-transparent" data-bs-toggle="dropdown" aria-label="Menu"><i data-feather="more-vertical" class="icon-sm"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><button class="dropdown-item" type="button" data-edit-task
                                                    data-id="{{ $task->id }}" data-title="{{ $task->title }}"
                                                    data-description="{{ $task->description }}" data-priority="{{ $task->priority }}"
                                                    data-due="{{ optional($task->due_date)->toDateString() }}" data-assignee="{{ $task->user_id }}">Edit</button></li>
                                                <li>
                                                    <form action="{{ route('task.destroy', $task->id) }}" method="POST" data-confirm="Hapus tugas ini?">@csrf @method('DELETE')
                                                        <button class="dropdown-item text-danger">Hapus</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    @endunless
                                </div>
                                <div class="mt-1 d-flex gap-1 align-items-center flex-wrap">
                                    <span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span>
                                    @if($task->due_date)
                                        <span class="badge {{ $task->due_date->isPast() && $task->status !== 'done' ? 'bg-danger' : 'bg-light text-muted' }}">{{ $task->due_date->format('d M') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
```

In the SortableJS init, add `filter: '.task-locked'` so locked cards can't be dragged. Change:
```javascript
        new Sortable(col, {
            group: 'tasks', animation: 150, ghostClass: 'opacity-50',
```
to:
```javascript
        new Sortable(col, {
            group: 'tasks', animation: 150, ghostClass: 'opacity-50', filter: '.task-locked',
```

- [ ] **Step 4: Daily evidence keterangan + counter + empty-state**

In `resources/views/reports/daily.blade.php`, replace the Lampiran card body (the `<h6 class="card-title">Lampiran</h6>` ... saved files `<ul>` block) with:

```blade
            <h6 class="card-title">Bukti / Lampiran @if($files->count())<span class="badge bg-success">{{ $files->count() }} bukti terlampir</span>@endif</h6>
            @if($isOwner && ! $submitted)
                <p class="text-muted mb-2" style="font-size:12px">Lampirkan bukti pekerjaan (screenshot/file). <strong>Wajib minimal 1</strong> sebelum Kirim Report.</p>
                <div id="reportDropzone" class="dropzone mb-2" style="min-height:120px"></div>
            @endif
            <ul id="savedFiles" class="list-group list-group-flush">
                @forelse($files as $f)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0" data-file="{{ $f->id }}">
                        <a href="{{ $f->url }}" target="_blank" style="font-size:13px"><i data-feather="paperclip" class="icon-xs me-1"></i>{{ $f->name }}</a>
                        @if($isOwner && ! $submitted)<button class="btn btn-xs btn-outline-danger" data-del-file="{{ $f->id }}">Hapus</button>@endif
                    </li>
                @empty
                    <li class="list-group-item text-muted text-center px-0" style="font-size:12px">Belum ada bukti dilampirkan.</li>
                @endforelse
            </ul>
            @if(! $isOwner)
                <small class="text-muted d-block mt-1">Daftar bukti yang dikirim karyawan (read-only).</small>
            @endif
```

- [ ] **Step 5: Submissions bukti column**

In `resources/views/reports/submissions.blade.php`, add a "Bukti" column. In the `<thead>` row, add a `<th>Bukti</th>` after the "Selesai" header. In the `<tbody>` `@foreach`, add a cell after the `selesai` cell:
```blade
                        <td>{{ $row['selesai'] }}</td>
                        <td>@if($row['bukti'])<span class="badge bg-info">{{ $row['bukti'] }}</span>@else<span class="text-muted">0</span>@endif</td>
```
(Insert the new `<td>` between the existing `selesai` cell and the "Buka report" action cell; add the matching `<th>Bukti</th>` in the header.)

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=TaskPagesTest`
Expected: PASS (all existing + 1 new).

- [ ] **Step 7: Commit**

```
git add resources/views/tasks/board.blade.php resources/views/reports/daily.blade.php resources/views/reports/submissions.blade.php tests/Feature/TaskPagesTest.php
git commit -m "feat(task): board lock UI + evidence keterangan/empty-state + bukti column

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 7: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (262 sebelumnya + ~12 test baru ≈ 274). Tidak ada yang merah.

- [ ] **Step 2: Smoke manual (opsional)**

Login `pia` (production): board → tugas selesai yang report-nya sudah dikirim tampil **terkunci** (gembok, tak bisa drag). Buat tugas due ≤7 hari → buka dashboard → **kartu "Tugas Mendekati Deadline"** + popup SweetAlert + lonceng terisi (sekali). Report Harian → coba Kirim tanpa bukti → ditolak (SweetAlert error); upload bukti (Dropzone) → counter "1 bukti terlampir" → Kirim (konfirmasi SweetAlert). Login `manager` → Pemantauan Report → kolom **Bukti**; buka report karyawan → daftar kerjaan + bukti.

---

## Catatan & Risiko

- **Dev/prod: `php artisan migrate`** untuk `deadline_notified_at`. Lihat [[migrate-dev-db-after-new-migration]].
- Lock live dari `tb_daily_reports` (tanpa kolom baru di tasks); guard server (422) di status/update/destroy mencegah bypass JS.
- Notifikasi deadline dipicu saat dashboard dibuka (tanpa cron), idempoten via `deadline_notified_at`; composer di-guard try/catch.
- SweetAlert2 global di master via `form[data-confirm]`; popup deadline sekali per sesi (`sessionStorage`).
- Bukti wajib ditegakkan di server (`submit`), bukan hanya UI.
