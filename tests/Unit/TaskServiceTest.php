<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\Notifier;
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
    public function reorder_does_not_touch_other_users_tasks(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $ta = $this->task($a, ['status' => 'todo', 'position' => 5]);

        // User B mencoba mengurutkan task milik A → harus diabaikan (scope forUser).
        $this->svc->reorder($b, 'todo', [$ta->id]);

        $this->assertSame(5, $ta->fresh()->position);
    }

    /** @test */
    public function move_throws_on_invalid_status(): void
    {
        $u = User::factory()->create();
        $t = $this->task($u);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->move($t, 'flying', 0);
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
}
