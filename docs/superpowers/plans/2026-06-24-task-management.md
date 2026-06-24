# Task Management (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sistem tugas/todolist generik per karyawan dengan tiga tampilan (Board kanban drag-and-drop, Kalender FullCalendar, Todo list), penugasan dua arah + notifikasi, dan dashboard Pemantauan Tugas untuk manager.

**Architecture:** Satu model `Task` (`tb_tasks`) + `TaskService` (board/calendar/move/reorder/monitor) + `TaskController` (HTML views + JSON endpoints untuk drag). Tiga Blade view membaca data yang sama; drag memanggil endpoint JSON. Kepemilikan ditegakkan di controller; manager/superadmin bypass. Notifikasi assign reuse `Notifier`.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie roles, Blade + Bootstrap 5, plugin ter-bundle: **SortableJS** (kanban), **FullCalendar v6** (`index.global.min.js`), **flatpickr**, **select2**, **DataTables** (`assets/libs/datatables.net*`). Tanpa dependency baru.

**Spec:** `docs/superpowers/specs/2026-06-24-task-management-design.md`

**Catatan env:** Tests pakai DB test via `.env.testing` (`RefreshDatabase`). Bila error koneksi DB, MySQL/XAMPP mungkin mati — jalankan `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden`, tunggu ~6 dtk, ulangi. Setelah selesai: `php artisan migrate` di dev (tabel `tb_tasks`) — jangan sentuh DB asli lewat test.

**Konvensi yang ditiru:** CSRF di JS ambil dari `<meta name="_token">` (lihat `resources/views/manuscript/board.blade.php`). DataTables list pakai `@foreach` + `language.emptyTable` (pola anti-error kosong). Hapus pakai `confirm()` di form (pola yang sudah dipakai Pengumuman/Target).

---

## File Structure

**Create:** migrasi `2026_06_24_000003_create_tb_tasks_table.php`; `app/Models/Task.php`; `app/Services/TaskService.php`; `app/Http/Controllers/Pages/TaskController.php`; `resources/views/tasks/{board,calendar,index,monitor}.blade.php` + `resources/views/tasks/partials/form-modal.blade.php`; tests `tests/Unit/TaskServiceTest.php`, `tests/Feature/TaskControllerTest.php`, `tests/Feature/TaskPagesTest.php`.

**Modify:** `routes/web.php` (grup route tasks), `resources/views/layouts/sidebar.blade.php` (menu Tugas), `app/Services/Notifier.php` (`taskAssigned`).

---

## Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_06_24_000003_create_tb_tasks_table.php`
- Create: `app/Models/Task.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_06_24_000003_create_tb_tasks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo');     // todo | in_progress | done
            $table->string('priority', 16)->default('normal'); // low | normal | high
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tasks');
    }
};
```

- [ ] **Step 2: Model**

Create `app/Models/Task.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tb_tasks';

    public const STATUSES = ['todo', 'in_progress', 'done'];
    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'user_id', 'title', 'description', 'status', 'priority',
        'due_date', 'position', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'position'     => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
```

- [ ] **Step 3: Verify migration healthy**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS (RefreshDatabase migrates `tb_tasks` cleanly).

- [ ] **Step 4: Commit**

```
git add database/migrations/2026_06_24_000003_create_tb_tasks_table.php app/Models/Task.php
git commit -m "feat(task): tb_tasks table and Task model

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `TaskService` (TDD)

**Files:**
- Create: `app/Services/TaskService.php`
- Test: `tests/Unit/TaskServiceTest.php`

- [ ] **Step 1: Write failing unit test**

Create `tests/Unit/TaskServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TaskService();
    }

    private function task(User $u, array $a = []): Task
    {
        return Task::create(array_merge([
            'user_id' => $u->id, 'title' => 'T', 'status' => 'todo', 'priority' => 'normal',
        ], $a));
    }

    /** @test */
    public function board_groups_and_orders_by_position(): void
    {
        $u = User::factory()->create();
        $this->task($u, ['status' => 'todo', 'position' => 1, 'title' => 'B']);
        $this->task($u, ['status' => 'todo', 'position' => 0, 'title' => 'A']);
        $this->task($u, ['status' => 'done', 'title' => 'C']);

        $board = $this->svc->board($u);

        $this->assertCount(2, $board['todo']);
        $this->assertSame('A', $board['todo'][0]->title); // position 0 first
        $this->assertCount(1, $board['done']);
        $this->assertCount(0, $board['in_progress']);
    }

    /** @test */
    public function move_to_done_sets_completed_at_and_back_clears(): void
    {
        $u = User::factory()->create();
        $t = $this->task($u);

        $this->svc->move($t, 'done', 0);
        $this->assertNotNull($t->fresh()->completed_at);

        $this->svc->move($t->fresh(), 'in_progress', 0);
        $this->assertNull($t->fresh()->completed_at);
    }

    /** @test */
    public function reorder_writes_positions(): void
    {
        $u = User::factory()->create();
        $a = $this->task($u, ['status' => 'todo']);
        $b = $this->task($u, ['status' => 'todo']);

        $this->svc->reorder($u, 'todo', [$b->id, $a->id]);

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    /** @test */
    public function calendar_events_scoped_and_dated(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $this->task($u, ['due_date' => today()->toDateString(), 'title' => 'Punya']);
        $this->task($u, ['due_date' => null, 'title' => 'TanpaTgl']);
        $this->task($other, ['due_date' => today()->toDateString(), 'title' => 'Orang']);

        $events = $this->svc->calendarEvents($u);

        $this->assertCount(1, $events);
        $this->assertSame('Punya', $events[0]['title']);
    }

    /** @test */
    public function monitor_kpi_and_filter(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->task($a, ['status' => 'todo', 'due_date' => today()->subDay()->toDateString()]); // overdue
        $this->task($a, ['status' => 'done']);
        $this->task($b, ['status' => 'in_progress']);

        $m = $this->svc->monitor();
        $this->assertSame(3, $m['kpi']['total']);
        $this->assertSame(1, $m['kpi']['overdue']);
        $this->assertCount(3, $m['rows']);

        $mb = $this->svc->monitor($b->id);
        $this->assertCount(1, $mb['rows']);
        $this->assertSame(1, $mb['kpi']['in_progress']);
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TaskServiceTest`
Expected: FAIL — `Class "App\Services\TaskService" not found`.

- [ ] **Step 3: Implement service**

Create `app/Services/TaskService.php`:

```php
<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskService
{
    /** Tugas user dikelompokkan per status (kolom board), diurut position lalu id. */
    public function board(User $user): array
    {
        $grouped = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get()->groupBy('status');

        return [
            'todo'        => $grouped->get('todo', collect())->values(),
            'in_progress' => $grouped->get('in_progress', collect())->values(),
            'done'        => $grouped->get('done', collect())->values(),
        ];
    }

    /** Event FullCalendar untuk tugas user yang punya due_date (opsional rentang). */
    public function calendarEvents(User $user, ?string $start = null, ?string $end = null): array
    {
        $query = Task::forUser($user->id)->whereNotNull('due_date');
        if ($start) {
            $query->where('due_date', '>=', $start);
        }
        if ($end) {
            $query->where('due_date', '<=', $end);
        }

        return $query->get()->map(fn (Task $t) => [
            'id'            => (string) $t->id,
            'title'         => $t->title,
            'start'         => $t->due_date->toDateString(),
            'allDay'        => true,
            'color'         => $t->status === 'done' ? '#94A3B8' : self::priorityColor($t->priority),
            'extendedProps' => [
                'priority'    => $t->priority,
                'description' => $t->description,
                'assignee'    => $t->user_id,
            ],
        ])->all();
    }

    /** Pindah status + posisi (drag board). Set/null completed_at sesuai status. */
    public function move(Task $task, string $status, int $position): void
    {
        if (! in_array($status, Task::STATUSES, true)) {
            return;
        }

        $task->update([
            'status'       => $status,
            'position'     => $position,
            'completed_at' => $status === 'done' ? ($task->completed_at ?? now()) : null,
        ]);
    }

    /** Tulis ulang position 0..n untuk satu kolom milik user. */
    public function reorder(User $user, string $status, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            Task::forUser($user->id)->where('status', $status)->where('id', (int) $id)->update(['position' => $i]);
        }
    }

    /** Pemantauan: KPI + baris tugas (opsional filter user/status). */
    public function monitor(?int $userId = null, ?string $status = null): array
    {
        $rows = Task::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('user')
            ->orderByRaw("FIELD(priority,'high','normal','low')")
            ->orderByRaw('due_date IS NULL, due_date')
            ->get();

        $scope  = Task::query()->when($userId, fn ($q) => $q->where('user_id', $userId));
        $counts = (clone $scope)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $overdue = (clone $scope)->whereNotNull('due_date')->whereDate('due_date', '<', today())->where('status', '!=', 'done')->count();

        return [
            'kpi' => [
                'total'       => (int) $counts->sum(),
                'todo'        => (int) ($counts['todo'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'done'        => (int) ($counts['done'] ?? 0),
                'overdue'     => $overdue,
            ],
            'rows' => $rows,
        ];
    }

    private static function priorityColor(string $priority): string
    {
        return ['high' => '#EF4444', 'normal' => '#4C5FD5', 'low' => '#22C55E'][$priority] ?? '#4C5FD5';
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TaskServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/TaskService.php tests/Unit/TaskServiceTest.php
git commit -m "feat(task): TaskService (board, calendar, move, reorder, monitor)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Routes + Controller + Notifier + endpoint tests (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/TaskController.php`
- Modify: `routes/web.php`, `app/Services/Notifier.php`
- Test: `tests/Feature/TaskControllerTest.php`

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/TaskControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TaskControllerTest extends TestCase
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

    private function task(User $u, array $a = []): Task
    {
        return Task::create(array_merge(['user_id' => $u->id, 'title' => 'T', 'status' => 'todo', 'priority' => 'normal'], $a));
    }

    /** @test */
    public function employee_creates_own_task(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('task.store'), ['title' => 'Tugas A', 'priority' => 'normal'])->assertRedirect();
        $this->assertDatabaseHas('tb_tasks', ['title' => 'Tugas A', 'user_id' => $u->id, 'status' => 'todo', 'created_by' => $u->id]);
    }

    /** @test */
    public function employee_cannot_assign_to_others(): void
    {
        $u = $this->user('production');
        $other = $this->user('production');
        $this->actingAs($u)->post(route('task.store'), ['title' => 'X', 'priority' => 'normal', 'assignee' => $other->id])->assertRedirect();
        // assignee diabaikan untuk non-manager → jadi milik pembuat
        $this->assertDatabaseHas('tb_tasks', ['title' => 'X', 'user_id' => $u->id]);
    }

    /** @test */
    public function manager_assigns_task_and_notifies_employee(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');
        $this->actingAs($manager)->post(route('task.store'), ['title' => 'Kerjakan', 'priority' => 'high', 'assignee' => $emp->id])->assertRedirect();
        $this->assertDatabaseHas('tb_tasks', ['title' => 'Kerjakan', 'user_id' => $emp->id, 'created_by' => $manager->id]);
        $this->assertSame(1, $emp->notifications()->count());
    }

    /** @test */
    public function employee_cannot_modify_others_task(): void
    {
        $a = $this->user('production');
        $b = $this->user('production');
        $t = $this->task($b);
        $this->actingAs($a)->patch(route('task.status', $t->id), ['status' => 'done'])->assertForbidden();
        $this->actingAs($a)->put(route('task.update', $t->id), ['title' => 'X', 'priority' => 'normal'])->assertForbidden();
    }

    /** @test */
    public function status_patch_updates_status_and_completed_at(): void
    {
        $u = $this->user('production');
        $t = $this->task($u);
        $this->actingAs($u)->patch(route('task.status', $t->id), ['status' => 'done', 'position' => 0])->assertOk();
        $t->refresh();
        $this->assertSame('done', $t->status);
        $this->assertNotNull($t->completed_at);
    }

    /** @test */
    public function schedule_patch_updates_due_date(): void
    {
        $u = $this->user('production');
        $t = $this->task($u);
        $this->actingAs($u)->patch(route('task.schedule', $t->id), ['due_date' => today()->toDateString()])->assertOk();
        $this->assertSame(today()->toDateString(), $t->fresh()->due_date->toDateString());
    }

    /** @test */
    public function events_json_scoped_to_user(): void
    {
        $u = $this->user('production');
        $other = $this->user('production');
        $this->task($u, ['title' => 'Mine', 'due_date' => today()->toDateString()]);
        $this->task($other, ['title' => 'Theirs', 'due_date' => today()->toDateString()]);
        $this->actingAs($u)->get(route('task.events'))->assertOk()
            ->assertJsonFragment(['title' => 'Mine'])
            ->assertJsonMissing(['title' => 'Theirs']);
    }

    /** @test */
    public function non_manager_cannot_open_monitor(): void
    {
        $this->actingAs($this->user('production'))->get(route('task.monitor'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TaskControllerTest`
Expected: FAIL — route `task.store` not defined.

- [ ] **Step 3: Notifier method**

In `app/Services/Notifier.php`, add the import near the other `use App\Models\...;` lines:

```php
use App\Models\Task;
```

Add this method (after the `commissionPaid` method, before the private `rp` helper):

```php
    public function taskAssigned(Task $task, User $actor): void
    {
        $task->loadMissing('user');
        $this->toOwner($task->user, $actor, [
            'category' => 'task',
            'title'    => 'Tugas baru ditugaskan',
            'message'  => $task->title,
            'url'      => route('task.board'),
            'icon'     => 'check-square',
        ]);
    }
```

(`toOwner` already no-ops when owner === actor, so self-created tasks never notify.)

- [ ] **Step 4: Controller**

Create `app/Http/Controllers/Pages/TaskController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    private function isManager(): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    private function authorizeTask(Task $task): void
    {
        if (! $this->isManager() && $task->user_id !== Auth::id()) {
            abort(403);
        }
    }

    /** User yang board/list-nya dilihat (manager boleh ?user_id=); selain itu diri sendiri. */
    private function targetUser(Request $request): User
    {
        if ($this->isManager() && $request->filled('user_id')) {
            return User::findOrFail((int) $request->input('user_id'));
        }
        return Auth::user();
    }

    private function assignableUsers()
    {
        return $this->isManager() ? User::orderBy('name')->get(['id', 'name']) : collect();
    }

    public function index(Request $request)
    {
        $user = $this->targetUser($request);
        $tasks = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get();
        return view('tasks.index', [
            'tasks' => $tasks, 'owner' => $user,
            'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function board(Request $request)
    {
        $user = $this->targetUser($request);
        return view('tasks.board', [
            'board' => $this->service->board($user), 'owner' => $user,
            'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function calendar(Request $request)
    {
        $user = $this->targetUser($request);
        return view('tasks.calendar', [
            'owner' => $user, 'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function events(Request $request)
    {
        $user = $this->targetUser($request);
        return response()->json($this->service->calendarEvents($user, $request->query('start'), $request->query('end')));
    }

    public function store(Request $request, Notifier $notifier)
    {
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request);

        $task = Task::create([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $data['due_date'] ?? null,
            'status'      => 'todo',
            'created_by'  => Auth::id(),
        ]);

        if ($assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        return back()->with('success', 'Tugas dibuat.');
    }

    public function update(Request $request, int $id, Notifier $notifier)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request, $task->user_id);
        $reassigned = $assignee !== $task->user_id;

        $task->update([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $data['due_date'] ?? null,
        ]);

        if ($reassigned && $assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        return back()->with('success', 'Tugas diperbarui.');
    }

    public function destroy(int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $task->delete();
        return back()->with('success', 'Tugas dihapus.');
    }

    public function status(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $request->validate([
            'status'   => 'required|in:todo,in_progress,done',
            'position' => 'nullable|integer|min:0',
        ]);
        $this->service->move($task, $data['status'], (int) ($data['position'] ?? 0));
        return response()->json(['ok' => true]);
    }

    public function schedule(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $request->validate(['due_date' => 'required|date']);
        $task->update(['due_date' => $data['due_date']]);
        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'status' => 'required|in:todo,in_progress,done',
            'ids'    => 'array',
            'ids.*'  => 'integer',
        ]);
        $this->service->reorder($this->targetUser($request), $data['status'], $data['ids'] ?? []);
        return response()->json(['ok' => true]);
    }

    public function monitor(Request $request)
    {
        $data = $this->service->monitor(
            $request->filled('user_id') ? (int) $request->input('user_id') : null,
            $request->filled('status') ? $request->input('status') : null,
        );
        return view('tasks.monitor', [
            'kpi' => $data['kpi'], 'rows' => $data['rows'],
            'employees' => $this->assignableUsers(),
            'fUser' => $request->input('user_id'), 'fStatus' => $request->input('status'),
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority'    => 'required|in:low,normal,high',
            'due_date'    => 'nullable|date',
            'assignee'    => 'nullable|integer|exists:users,id',
        ]);
    }

    /** Hanya manager/superadmin boleh assign ke orang lain; selain itu paksa diri sendiri / pemilik lama. */
    private function resolveAssignee(Request $request, ?int $fallback = null): int
    {
        if ($this->isManager() && $request->filled('assignee')) {
            return (int) $request->input('assignee');
        }
        return $fallback ?? Auth::id();
    }
}
```

- [ ] **Step 5: Routes**

In `routes/web.php`, add the import near the other `use App\Http\Controllers\Pages\...;` lines:

```php
use App\Http\Controllers\Pages\TaskController;
```

Inside the `Route::middleware('auth')->group(function () {` block, add (static routes before `{id}` routes; `monitor` gated to manager/superadmin):

```php
    Route::get('tasks', [TaskController::class, 'index'])->name('task.index');
    Route::get('tasks/board', [TaskController::class, 'board'])->name('task.board');
    Route::get('tasks/calendar', [TaskController::class, 'calendar'])->name('task.calendar');
    Route::get('tasks/events', [TaskController::class, 'events'])->name('task.events');
    Route::post('tasks/reorder', [TaskController::class, 'reorder'])->name('task.reorder');
    Route::post('tasks', [TaskController::class, 'store'])->name('task.store');
    Route::put('tasks/{id}', [TaskController::class, 'update'])->name('task.update');
    Route::delete('tasks/{id}', [TaskController::class, 'destroy'])->name('task.destroy');
    Route::patch('tasks/{id}/status', [TaskController::class, 'status'])->name('task.status');
    Route::patch('tasks/{id}/schedule', [TaskController::class, 'schedule'])->name('task.schedule');

    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('tasks/monitor', [TaskController::class, 'monitor'])->name('task.monitor');
    });
```

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=TaskControllerTest`
Expected: PASS (8 tests).

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/TaskController.php routes/web.php app/Services/Notifier.php tests/Feature/TaskControllerTest.php
git commit -m "feat(task): controller + routes + assign notification

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Views + sidebar + page smoke tests (TDD)

**Files:**
- Create: `resources/views/tasks/board.blade.php`, `calendar.blade.php`, `index.blade.php`, `monitor.blade.php`, `partials/form-modal.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/TaskPagesTest.php`

- [ ] **Step 1: Write failing page test**

Create `tests/Feature/TaskPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TaskPagesTest extends TestCase
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
    public function employee_can_open_board_and_list(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'Tugasku', 'status' => 'todo', 'priority' => 'normal']);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('Tugasku');
        $this->actingAs($u)->get(route('task.index'))->assertOk()->assertSee('Tugasku');
        $this->actingAs($u)->get(route('task.calendar'))->assertOk();
    }

    /** @test */
    public function manager_monitor_shows_all_employees_tasks(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');
        Task::create(['user_id' => $emp->id, 'title' => 'TugasEmp', 'status' => 'todo', 'priority' => 'normal']);

        $this->actingAs($manager)->get(route('task.monitor'))->assertOk()->assertSee('TugasEmp');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TaskPagesTest`
Expected: FAIL — `View [tasks.board] not found`.

- [ ] **Step 3: Form modal partial**

Create `resources/views/tasks/partials/form-modal.blade.php`:

```blade
<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="taskForm" method="POST" action="{{ route('task.store') }}">
        @csrf
        <input type="hidden" name="_method" id="taskMethod" value="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="taskModalTitle">Tugas Baru</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Judul</label>
            <input type="text" name="title" id="taskTitle" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Deskripsi</label>
            <textarea name="description" id="taskDesc" class="form-control" rows="2"></textarea></div>
          <div class="row">
            <div class="col-6 mb-2"><label class="form-label">Prioritas</label>
              <select name="priority" id="taskPriority" class="form-select">
                <option value="low">Rendah</option>
                <option value="normal" selected>Normal</option>
                <option value="high">Tinggi</option>
              </select></div>
            <div class="col-6 mb-2"><label class="form-label">Tenggat</label>
              <input type="text" name="due_date" id="taskDue" class="form-control" placeholder="Pilih tanggal"></div>
          </div>
          @if($isManager ?? false)
          <div class="mb-2"><label class="form-label">Tugaskan ke</label>
            <select name="assignee" id="taskAssignee" class="form-select select2-assignee">
              <option value="{{ auth()->id() }}">Saya sendiri</option>
              @foreach(($assignees ?? collect()) as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
              @endforeach
            </select></div>
          @endif
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet">
@if($isManager ?? false)<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet">@endif
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
@if($isManager ?? false)<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>@endif
@endpush
@push('custom-scripts')
<script>
(function () {
    const modalEl = document.getElementById('taskModal');
    const modal = (modalEl && window.bootstrap) ? new bootstrap.Modal(modalEl) : null;
    const form = document.getElementById('taskForm');
    const fp = window.flatpickr ? flatpickr('#taskDue', { dateFormat: 'Y-m-d' }) : null;
    @if($isManager ?? false)
    if (window.jQuery && jQuery.fn.select2) { jQuery('.select2-assignee').select2({ dropdownParent: jQuery(modalEl), width: '100%' }); }
    @endif

    window.openTaskModal = function (data) {
        data = data || {};
        document.getElementById('taskMethod').value = data.id ? 'PUT' : 'POST';
        form.setAttribute('action', data.id ? ("{{ url('tasks') }}/" + data.id) : "{{ route('task.store') }}");
        document.getElementById('taskModalTitle').textContent = data.id ? 'Edit Tugas' : 'Tugas Baru';
        document.getElementById('taskTitle').value = data.title || '';
        document.getElementById('taskDesc').value = data.description || '';
        document.getElementById('taskPriority').value = data.priority || 'normal';
        if (fp) { data.due ? fp.setDate(data.due) : fp.clear(); }
        @if($isManager ?? false)
        if (window.jQuery) jQuery('.select2-assignee').val(String(data.assignee || "{{ auth()->id() }}")).trigger('change');
        @endif
        if (modal) modal.show();
    };
})();
</script>
@endpush
```

- [ ] **Step 4: Board view**

Create `resources/views/tasks/board.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Tugas - Board - SiMAPA')

@section('content')
@php
    $cols = ['todo' => ['Menunggu', '#94A3B8'], 'in_progress' => ['Dikerjakan', '#F59E0B'], 'done' => ['Selesai', '#22C55E']];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Papan Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('task.board') }}" class="btn btn-primary">Board</a>
        <a href="{{ route('task.calendar') }}" class="btn btn-outline-primary">Kalender</a>
        <a href="{{ route('task.index') }}" class="btn btn-outline-primary">Todo</a>
    </div>
</div>

<div class="row g-3">
    @foreach($cols as $key => [$label, $color])
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="d-flex align-items-center gap-2">
                        <span style="width:9px;height:9px;border-radius:50%;background:{{ $color }}"></span>
                        <strong>{{ $label }}</strong>
                        <span class="badge bg-light text-muted" data-count>{{ $board[$key]->count() }}</span>
                    </span>
                    <button class="btn btn-xs btn-outline-primary" data-add-task data-status="{{ $key }}">+ Tambah</button>
                </div>
                <div data-column data-status="{{ $key }}" style="min-height:80px">
                    @foreach($board[$key] as $task)
                        <div class="card mb-2 task-card" data-id="{{ $task->id }}" style="cursor:grab">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="fw-semibold" style="font-size:13px">{{ $task->title }}</span>
                                    <div class="dropdown">
                                        <button class="btn btn-xs p-0 border-0 bg-transparent" data-bs-toggle="dropdown" aria-label="Menu"><i data-feather="more-vertical" class="icon-sm"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item" type="button" data-edit-task
                                                data-id="{{ $task->id }}" data-title="{{ $task->title }}"
                                                data-description="{{ $task->description }}" data-priority="{{ $task->priority }}"
                                                data-due="{{ optional($task->due_date)->toDateString() }}" data-assignee="{{ $task->user_id }}">Edit</button></li>
                                            <li>
                                                <form action="{{ route('task.destroy', $task->id) }}" method="POST" onsubmit="return confirm('Hapus tugas ini?')">@csrf @method('DELETE')
                                                    <button class="dropdown-item text-danger">Hapus</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
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
                </div>
            </div></div>
        </div>
    @endforeach
</div>

@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    function send(url, method, body) {
        return fetch(url, { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    }
    document.querySelectorAll('[data-column]').forEach(function (col) {
        new Sortable(col, {
            group: 'tasks', animation: 150, ghostClass: 'opacity-50',
            onEnd: function (evt) {
                const id = evt.item.getAttribute('data-id');
                const toStatus = evt.to.getAttribute('data-status');
                const fromStatus = evt.from.getAttribute('data-status');
                const ids = Array.from(evt.to.querySelectorAll('[data-id]')).map(function (n) { return n.getAttribute('data-id'); });
                if (toStatus === fromStatus) {
                    send("{{ route('task.reorder') }}", 'POST', { status: toStatus, ids: ids });
                } else {
                    send("{{ url('tasks') }}/" + id + "/status", 'PATCH', { status: toStatus, position: ids.indexOf(id) });
                }
            }
        });
    });
    document.querySelectorAll('[data-add-task]').forEach(function (b) {
        b.addEventListener('click', function () { window.openTaskModal({ status: b.getAttribute('data-status') }); });
    });
    document.querySelectorAll('[data-edit-task]').forEach(function (b) {
        b.addEventListener('click', function () {
            window.openTaskModal({ id: b.dataset.id, title: b.dataset.title, description: b.dataset.description, priority: b.dataset.priority, due: b.dataset.due, assignee: b.dataset.assignee });
        });
    });
})();
</script>
@endpush
```

- [ ] **Step 5: Calendar view**

Create `resources/views/tasks/calendar.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Tugas - Kalender - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Kalender Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('task.board') }}" class="btn btn-outline-primary">Board</a>
        <a href="{{ route('task.calendar') }}" class="btn btn-primary">Kalender</a>
        <a href="{{ route('task.index') }}" class="btn btn-outline-primary">Todo</a>
    </div>
</div>
<div class="card"><div class="card-body"><div id="taskCalendar"></div></div></div>
@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/fullcalendar/index.global.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    const el = document.getElementById('taskCalendar');
    if (!el || !window.FullCalendar) return;
    const cal = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        locale: 'id',
        height: 'auto',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
        events: "{{ route('task.events') }}",
        editable: true,
        dateClick: function (info) { window.openTaskModal({ due: info.dateStr }); },
        eventClick: function (info) {
            window.openTaskModal({
                id: info.event.id, title: info.event.title, due: info.event.startStr,
                priority: info.event.extendedProps.priority,
                description: info.event.extendedProps.description,
                assignee: info.event.extendedProps.assignee
            });
        },
        eventDrop: function (info) {
            fetch("{{ url('tasks') }}/" + info.event.id + "/schedule", {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ due_date: info.event.startStr })
            }).then(function (r) { if (!r.ok) info.revert(); }).catch(function () { info.revert(); });
        }
    });
    cal.render();
})();
</script>
@endpush
```

- [ ] **Step 6: Todo list view**

Create `resources/views/tasks/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Tugas - Todo - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['todo' => 'bg-secondary', 'in_progress' => 'bg-warning text-dark', 'done' => 'bg-success'];
    $sl = ['todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai'];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Daftar Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
            <a href="{{ route('task.board') }}" class="btn btn-outline-primary">Board</a>
            <a href="{{ route('task.calendar') }}" class="btn btn-outline-primary">Kalender</a>
            <a href="{{ route('task.index') }}" class="btn btn-primary">Todo</a>
        </div>
        <button class="btn btn-sm btn-primary" data-add-task data-status="todo">+ Tambah</button>
    </div>
</div>

<div class="card"><div class="card-body"><div class="table-responsive">
    <table class="table table-hover datatable" style="width:100%">
        <thead><tr><th>Judul</th><th>Status</th><th>Prioritas</th><th>Tenggat</th><th>Aksi</th></tr></thead>
        <tbody>
            @foreach($tasks as $task)
            <tr>
                <td>{{ $task->title }}</td>
                <td><span class="badge {{ $sb[$task->status] }}">{{ $sl[$task->status] }}</span></td>
                <td><span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span></td>
                <td>@if($task->due_date)<span class="@if($task->due_date->isPast() && $task->status !== 'done') text-danger fw-semibold @endif">{{ $task->due_date->format('d/m/Y') }}</span>@else<span class="text-muted">—</span>@endif</td>
                <td>
                    @if($task->status !== 'done')
                        <button class="btn btn-xs btn-outline-success" data-complete data-id="{{ $task->id }}">Selesai</button>
                    @endif
                    <button class="btn btn-xs btn-outline-primary" data-edit-task data-id="{{ $task->id }}" data-title="{{ $task->title }}" data-description="{{ $task->description }}" data-priority="{{ $task->priority }}" data-due="{{ optional($task->due_date)->toDateString() }}" data-assignee="{{ $task->user_id }}">Edit</button>
                    <form action="{{ route('task.destroy', $task->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Hapus tugas ini?')">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div></div></div>

@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada tugas.' } });
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    document.querySelectorAll('[data-add-task]').forEach(function (b) { b.addEventListener('click', function () { window.openTaskModal({ status: 'todo' }); }); });
    document.querySelectorAll('[data-edit-task]').forEach(function (b) {
        b.addEventListener('click', function () { window.openTaskModal({ id: b.dataset.id, title: b.dataset.title, description: b.dataset.description, priority: b.dataset.priority, due: b.dataset.due, assignee: b.dataset.assignee }); });
    });
    document.querySelectorAll('[data-complete]').forEach(function (b) {
        b.addEventListener('click', function () {
            fetch("{{ url('tasks') }}/" + b.dataset.id + "/status", { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ status: 'done', position: 0 }) })
                .then(function (r) { if (r.ok) location.reload(); });
        });
    });
});
</script>
@endpush
```

- [ ] **Step 7: Monitor view**

Create `resources/views/tasks/monitor.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Pemantauan Tugas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['todo' => 'bg-secondary', 'in_progress' => 'bg-warning text-dark', 'done' => 'bg-success'];
    $sl = ['todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai'];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<h5 class="mb-3">Pemantauan Tugas</h5>

<div class="row g-2 mb-3">
    @foreach(['total' => 'Total', 'todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai', 'overdue' => 'Lewat tenggat'] as $k => $lbl)
        <div class="col"><div class="card"><div class="card-body py-2 text-center">
            <div class="text-muted" style="font-size:11px">{{ $lbl }}</div>
            <div class="fw-bold {{ $k === 'overdue' ? 'text-danger' : '' }}" style="font-size:20px">{{ $kpi[$k] }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card"><div class="card-body">
    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <select name="user_id" class="form-select form-select-sm select2-filter">
                <option value="">Semua karyawan</option>
                @foreach($employees as $e)<option value="{{ $e->id }}" {{ (string) $fUser === (string) $e->id ? 'selected' : '' }}>{{ $e->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">Semua status</option>
                @foreach($sl as $k => $v)<option value="{{ $k }}" {{ $fStatus === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </form>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Karyawan</th><th>Judul</th><th>Status</th><th>Prioritas</th><th>Tenggat</th><th></th></tr></thead>
            <tbody>
                @foreach($rows as $task)
                <tr>
                    <td>{{ $task->user?->name ?? '—' }}</td>
                    <td>{{ $task->title }}</td>
                    <td><span class="badge {{ $sb[$task->status] }}">{{ $sl[$task->status] }}</span></td>
                    <td><span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span></td>
                    <td>@if($task->due_date)<span class="@if($task->due_date->isPast() && $task->status !== 'done') text-danger fw-semibold @endif">{{ $task->due_date->format('d/m/Y') }}</span>@else<span class="text-muted">—</span>@endif</td>
                    <td><a href="{{ route('task.board', ['user_id' => $task->user_id]) }}" class="btn btn-xs btn-outline-primary">Board</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.datatable').DataTable({ pageLength: 15, order: [], language: { emptyTable: 'Belum ada tugas.' } });
    $('.select2-filter').select2();
});
</script>
@endpush
```

- [ ] **Step 8: Sidebar menu**

In `resources/views/layouts/sidebar.blade.php`, find the `<li class="nav-item nav-category">Akun</li>` line. Insert this block immediately BEFORE it:

```blade
            <li class="nav-item nav-category">Tugas</li>
            <li class="nav-item {{ active_class(['tasks']) }}">
                <a href="{{ route('task.board') }}" class="nav-link">
                    <i class="link-icon" data-feather="trello"></i>
                    <span class="link-title">Board Tugas</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['tasks/calendar']) }}">
                <a href="{{ route('task.calendar') }}" class="nav-link">
                    <i class="link-icon" data-feather="calendar"></i>
                    <span class="link-title">Kalender</span>
                </a>
            </li>
            <li class="nav-item {{ active_class(['tasks']) }}">
                <a href="{{ route('task.index') }}" class="nav-link">
                    <i class="link-icon" data-feather="check-square"></i>
                    <span class="link-title">Todo</span>
                </a>
            </li>
            @role(['manager', 'superadmin'])
                <li class="nav-item {{ active_class(['tasks/monitor']) }}">
                    <a href="{{ route('task.monitor') }}" class="nav-link">
                        <i class="link-icon" data-feather="activity"></i>
                        <span class="link-title">Pemantauan Tugas</span>
                    </a>
                </li>
            @endrole
```

- [ ] **Step 9: Run, confirm PASS**

Run: `php artisan test --filter=TaskPagesTest`
Expected: PASS (2 tests).

- [ ] **Step 10: Commit**

```
git add resources/views/tasks/board.blade.php resources/views/tasks/calendar.blade.php resources/views/tasks/index.blade.php resources/views/tasks/monitor.blade.php resources/views/tasks/partials/form-modal.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/TaskPagesTest.php
git commit -m "feat(task): board/calendar/todo/monitor views + sidebar

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (225 sebelumnya + TaskServiceTest 5 + TaskControllerTest 8 + TaskPagesTest 2 = 240). Tidak ada yang merah.

- [ ] **Step 2: Smoke manual (opsional)**

Login `pia` (production) → menu **Board Tugas**: tambah tugas, geser kartu antar kolom (status berubah), buka **Kalender** (tambah/geser tanggal), **Todo** (tandai selesai). Login `manager` → assign tugas ke karyawan (cek notifikasi karyawan), buka **Pemantauan Tugas** (lihat semua, filter, buka board karyawan).

---

## Catatan & Risiko

- **Dev/prod: jalankan `php artisan migrate`** untuk `tb_tasks` (kalau tidak, halaman Tugas error). Lihat [[migrate-dev-db-after-new-migration]].
- Drag pakai SortableJS + FullCalendar dari plugin ter-bundle (pola CSRF `meta[name="_token"]` sama dengan manuscript board). Tanpa dependency baru.
- Kepemilikan ditegakkan di controller (`authorizeTask`); manager/superadmin bypass; `?user_id=` hanya dihormati untuk manager.
- `monitor` memakai `FIELD(priority,...)` (MySQL) untuk urutan prioritas — sesuai DB proyek (MySQL).
- Notifikasi assign reuse `Notifier` (kategori `task`); `toOwner` otomatis tidak mengirim ke diri sendiri.
- UI mengikuti `template-web` (gitignored) — kelas BS5/komponen yang sudah dipakai halaman lain; folder template tidak di-commit.
