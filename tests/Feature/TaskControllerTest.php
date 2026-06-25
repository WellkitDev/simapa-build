<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
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
    public function store_honors_column_status(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('task.store'), ['title' => 'Langsung kerja', 'priority' => 'normal', 'status' => 'in_progress'])->assertRedirect();
        $this->assertDatabaseHas('tb_tasks', ['title' => 'Langsung kerja', 'user_id' => $u->id, 'status' => 'in_progress']);
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
    public function manager_reassign_updates_owner_and_notifies_new_assignee(): void
    {
        $manager = $this->user('manager');
        $a = $this->user('production');
        $b = $this->user('production');
        $t = $this->task($a, ['title' => 'Pindah']);

        $this->actingAs($manager)->put(route('task.update', $t->id), [
            'title' => 'Pindah', 'priority' => 'normal', 'assignee' => $b->id,
        ])->assertRedirect();

        $this->assertSame($b->id, $t->fresh()->user_id);
        $this->assertSame(1, $b->notifications()->count());
        $this->assertSame(0, $a->notifications()->count());
    }

    /** @test */
    public function non_manager_cannot_read_other_users_events(): void
    {
        $a = $this->user('production');
        $b = $this->user('production');
        $this->task($b, ['title' => 'EventB', 'due_date' => today()->toDateString()]);

        $this->actingAs($a)->get(route('task.events', ['user_id' => $b->id]))->assertOk()
            ->assertJsonMissing(['title' => 'EventB']);
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
    public function employee_cannot_destroy_or_schedule_others_task(): void
    {
        $a = $this->user('production');
        $b = $this->user('production');
        $t = $this->task($b);

        $this->actingAs($a)->delete(route('task.destroy', $t->id))->assertForbidden();
        $this->actingAs($a)->patch(route('task.schedule', $t->id), ['due_date' => today()->toDateString()])->assertForbidden();
    }

    /** @test */
    public function manager_can_modify_any_users_task(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');
        $t = $this->task($emp);

        $this->actingAs($manager)->patch(route('task.status', $t->id), ['status' => 'done', 'position' => 0])->assertOk();
        $this->assertSame('done', $t->fresh()->status);
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
}
