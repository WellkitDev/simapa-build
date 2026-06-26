<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
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

    /** @test */
    public function board_marks_locked_done_task(): void
    {
        $u = $this->user('production');
        $today = today();
        Task::create(['user_id' => $u->id, 'title' => 'TugasTerkunci', 'status' => 'done', 'priority' => 'normal', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('task-locked');
    }
}
