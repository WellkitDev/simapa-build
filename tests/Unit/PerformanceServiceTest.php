<?php
// tests/Unit/PerformanceServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new PerformanceService();
    }

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $attrs['type'] ?? 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id,
            'status'          => 'editing',
            'assigned_role'   => 'production',
            'started_at'      => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    private function editor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('production');
        return $u;
    }

    /** @test */
    public function on_time_rate_counts_only_completed_with_target(): void
    {
        $ed = $this->editor();

        // 2 selesai tepat waktu (started_at <= target), 1 telat → rate 66.7%
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-10', 'target_date' => '2026-06-15']);
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-10', 'target_date' => '2026-06-12']);
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-20', 'target_date' => '2026-06-15']); // telat
        // selesai tanpa target → tidak masuk rate, masuk completed
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => now(), 'target_date' => null]);
        // milik editor lain → diabaikan
        $this->progress(['pelaksana_user_id' => $this->editor()->id, 'status' => 'terbit', 'started_at' => now(), 'target_date' => '2026-01-01']);

        $r = $this->svc->forEditor($ed, 3650); // periode besar agar semua masuk

        $this->assertEquals(4, $r['completed']);
        $this->assertEquals(3, $r['with_target']);
        $this->assertEquals(2, $r['on_time']);
        $this->assertEquals(66.7, $r['on_time_rate']);
    }

    /** @test */
    public function on_time_rate_is_null_when_no_completed_with_target(): void
    {
        $ed = $this->editor();
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'target_date' => null]);

        $r = $this->svc->forEditor($ed, 3650);
        $this->assertNull($r['on_time_rate']);
        $this->assertEquals(1, $r['completed']);
    }

    /** @test */
    public function active_queue_counts_assigned_not_final(): void
    {
        $ed = $this->editor();
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'editing']);   // aktif
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'layout']);    // aktif
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit']);    // final → bukan antrian

        $r = $this->svc->forEditor($ed, 30);
        $this->assertEquals(2, $r['active_queue']);
    }

    /** @test */
    public function completion_exactly_on_target_counts_as_on_time(): void
    {
        $ed = $this->editor();
        $this->progress(['pelaksana_user_id' => $ed->id, 'status' => 'terbit', 'started_at' => '2026-06-15', 'target_date' => '2026-06-15']);

        $r = $this->svc->forEditor($ed, 3650);
        $this->assertEquals(1, $r['on_time']);
        $this->assertEquals(100.0, $r['on_time_rate']);
    }
}
