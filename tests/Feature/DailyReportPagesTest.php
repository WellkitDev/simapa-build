<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DailyReportPagesTest extends TestCase
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
    public function owner_can_open_daily_report(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'TugasSelesai', 'status' => 'done', 'priority' => 'normal', 'completed_at' => now()]);

        $this->actingAs($u)->get(route('report.daily'))->assertOk()->assertSee('TugasSelesai');
    }

    /** @test */
    public function manager_can_open_monthly_and_submissions(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');

        $this->actingAs($manager)->get(route('report.monthly'))->assertOk();
        $this->actingAs($manager)->get(route('report.submissions'))->assertOk()->assertSee($emp->name);
    }
}
