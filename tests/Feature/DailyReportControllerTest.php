<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DailyReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class); // default; sebagian test override
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
    public function save_note_creates_report(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('report.note'), ['date' => today()->toDateString(), 'note' => 'Kerja hari ini'])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['user_id' => $u->id, 'note' => 'Kerja hari ini', 'status' => 'draft']);
    }

    /** @test */
    public function submit_locks_report(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('report.submit'), ['date' => today()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('tb_daily_reports', ['user_id' => $u->id, 'status' => 'submitted']);

        $this->actingAs($u)->post(route('report.note'), ['date' => today()->toDateString(), 'note' => 'x'])->assertStatus(422);
    }

    /** @test */
    public function upload_file_stores_record(): void
    {
        $u = $this->user('production');
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-1');
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'name' => 'foto.jpg', 'url' => 'https://drive/foto']);
        });

        $this->actingAs($u)->post(route('report.files.store'), [
            'date' => today()->toDateString(),
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertOk()->assertJsonFragment(['name' => 'foto.jpg']);

        $this->assertDatabaseHas('tb_daily_report_files', ['drive_file_id' => 'drive-1', 'name' => 'foto.jpg']);
    }

    /** @test */
    public function cannot_upload_after_submit(): void
    {
        $u = $this->user('production');
        DailyReport::create(['user_id' => $u->id, 'report_date' => today()->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->post(route('report.files.store'), [
            'date' => today()->toDateString(),
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertStatus(422);
    }

    /** @test */
    public function delete_file_removes_record(): void
    {
        $u = $this->user('production');
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('deleteFile')->andReturn(true);
        });
        $report = DailyReport::create(['user_id' => $u->id, 'report_date' => today()->toDateString()]);
        $file = $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);

        $this->actingAs($u)->delete(route('report.files.destroy', $file->id))->assertOk();
        $this->assertDatabaseMissing('tb_daily_report_files', ['id' => $file->id]);
    }

    /** @test */
    public function cannot_delete_others_file(): void
    {
        $a = $this->user('production');
        $b = $this->user('production');
        $report = DailyReport::create(['user_id' => $b->id, 'report_date' => today()->toDateString()]);
        $file = $report->files()->create(['drive_file_id' => 'd1', 'name' => 'a.jpg', 'url' => 'u']);

        $this->actingAs($a)->delete(route('report.files.destroy', $file->id))->assertForbidden();
    }

    /** @test */
    public function non_manager_cannot_open_submissions(): void
    {
        $this->actingAs($this->user('production'))->get(route('report.submissions'))->assertForbidden();
    }

    /** @test */
    public function daily_with_invalid_date_redirects(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->get(route('report.daily', ['date' => 'garbage']))->assertStatus(302);
    }
}
