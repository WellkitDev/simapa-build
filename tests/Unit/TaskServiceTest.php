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
