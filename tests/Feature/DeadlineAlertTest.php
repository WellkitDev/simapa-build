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
