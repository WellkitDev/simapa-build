<?php
// tests/Unit/ProductionDashboardServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\ProductionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ProductionDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new ProductionDashboardService();
    }

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $attrs['type'] ?? 'bk_mandiri']);
        return TitleProgress::create(array_merge([
            'status'        => 'editing',
            'assigned_role' => 'production',
            'started_at'    => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function for_user_computes_queue_unclaimed_and_overdue(): void
    {
        $me = User::factory()->create();
        $me->assignRole('production');

        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing']); // antrian saya
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'layout', 'target_date' => now()->subDay()->toDateString()]); // antrian + lewat target
        $this->progress(['assigned_user_id' => null, 'status' => 'editing']);    // belum diambil
        $this->progress(['assigned_user_id' => null, 'status' => 'menunggu_proses']); // bukan stage produksi → bukan belum-diambil

        $d = $this->svc->forUser($me);

        $this->assertEquals(2, $d['antrian_saya']);
        $this->assertEquals(1, $d['belum_diambil']);
        $this->assertEquals(1, $d['lewat_target']);
    }

    /** @test */
    public function global_counts_all_in_production(): void
    {
        $this->progress(['assigned_user_id' => null, 'status' => 'editing']);
        $this->progress(['assigned_user_id' => null, 'status' => 'isbn']);
        $this->progress(['assigned_user_id' => null, 'status' => 'terbit']); // final → bukan in-production

        $g = $this->svc->global();
        $this->assertEquals(2, $g['total_in_production']);
    }

    /** @test */
    public function for_user_counts_due_soon_within_7_days(): void
    {
        $me = User::factory()->create();
        $me->assignRole('production');

        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing', 'target_date' => now()->addDays(3)->toDateString()]);  // jatuh tempo ≤7
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing', 'target_date' => now()->addDays(10)->toDateString()]); // di luar 7 hari

        $this->assertEquals(1, $this->svc->forUser($me)['jatuh_tempo_7']);
    }
}
