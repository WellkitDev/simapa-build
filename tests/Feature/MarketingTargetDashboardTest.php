<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function dashboard_shows_active_target_with_period(): void
    {
        $mkt = $this->user('marketing');
        MarketingTarget::create([
            'user_id' => $mkt->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5,
        ]);

        $this->actingAs($mkt)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target Berjalan')
            ->assertSee('Periode')
            ->assertSee('Capaian');
    }

    /** @test */
    public function dashboard_shows_empty_state_without_active_target(): void
    {
        $mkt = $this->user('marketing');
        $this->actingAs($mkt)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tidak ada target berjalan');
    }
}
