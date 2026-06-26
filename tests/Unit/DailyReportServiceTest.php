<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DailyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DailyReportService();
    }

    private function task(User $u, array $a = []): Task
    {
        return Task::create(array_merge(['user_id' => $u->id, 'title' => 'T', 'status' => 'todo', 'priority' => 'normal'], $a));
    }

    /** @test */
    public function recap_buckets_by_date_and_scope(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $today = Carbon::today();
        $this->task($u, ['title' => 'Sel', 'status' => 'done', 'completed_at' => $today]);
        $this->task($u, ['title' => 'Ker', 'status' => 'in_progress']);
        $this->task($other, ['title' => 'Orang', 'status' => 'done', 'completed_at' => $today]);

        $r = $this->svc->recapFor($u, $today);

        $this->assertSame(1, $r['counts']['selesai']);
        $this->assertSame('Sel', $r['selesai']->first()->title);
        $this->assertSame(2, $r['counts']['dibuat']);     // Sel + Ker dibuat hari ini (milik $u), task $other tidak ikut
        $this->assertSame(1, $r['counts']['dikerjakan']); // hari ini → in_progress tampil
    }

    /** @test */
    public function in_progress_excluded_for_past_dates(): void
    {
        $u = User::factory()->create();
        $this->task($u, ['status' => 'in_progress']);

        $r = $this->svc->recapFor($u, Carbon::yesterday());

        $this->assertCount(0, $r['dikerjakan']);
    }

    /** @test */
    public function monthly_recap_on_time_and_late(): void
    {
        $u = User::factory()->create();
        $today = Carbon::today();
        $this->task($u, ['status' => 'done', 'completed_at' => $today, 'due_date' => $today->toDateString()]);
        $this->task($u, ['status' => 'done', 'completed_at' => $today, 'due_date' => $today->copy()->subDay()->toDateString()]);
        $this->task($u, ['status' => 'done', 'completed_at' => $today]);

        $m = $this->svc->monthlyRecap($u, (int) $today->year, (int) $today->month);

        $this->assertSame(3, $m['selesai']);
        $this->assertSame(1, $m['tepat_waktu']);
        $this->assertSame(1, $m['telat']);
        $this->assertSame(50.0, $m['on_time_rate']);
    }

    /** @test */
    public function submissions_flags_submitted_and_counts(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $today = Carbon::today();
        $this->task($a, ['status' => 'done', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $a->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $rows = $this->svc->submissionsForDate($today);
        $ra = $rows->firstWhere('id', $a->id);
        $rb = $rows->firstWhere('id', $b->id);

        $this->assertTrue($ra['submitted']);
        $this->assertSame(1, $ra['selesai']);
        $this->assertFalse($rb['submitted']);
    }

    /** @test */
    public function get_or_create_report_idempotent(): void
    {
        $u = User::factory()->create();
        $today = Carbon::today();

        $r1 = $this->svc->getOrCreateReport($u, $today);
        $r2 = $this->svc->getOrCreateReport($u, $today);

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, DailyReport::where('user_id', $u->id)->count());
    }

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
}
