<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetMeTest extends TestCase
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
    public function marketing_sees_only_their_own_targets(): void
    {
        $me = $this->user('marketing');
        $other = $this->user('marketing');

        MarketingTarget::create(['user_id' => $me->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'target_amount' => 10000000, 'commission_rate' => 5]);
        MarketingTarget::create(['user_id' => $other->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'target_amount' => 77000000, 'commission_rate' => 5]);

        $this->actingAs($me)->get(route('marketing-target.me'))
            ->assertOk()
            ->assertSee('Target Saya')
            ->assertSee('10.000.000')
            ->assertDontSee('77.000.000');
    }

    /** @test */
    public function production_cannot_access_target_me(): void
    {
        $this->actingAs($this->user('production'))
            ->get(route('marketing-target.me'))->assertForbidden();
    }
}
