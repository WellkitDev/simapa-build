<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetTest extends TestCase
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
    public function manager_can_view_and_save_targets(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'MARKETING SATU']);

        $this->actingAs($manager)->get(route('marketing-target.index'))
            ->assertOk()
            ->assertSee('MARKETING SATU');

        $this->actingAs($manager)->post(route('marketing-target.save'), [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [$mkt->id => ['target' => 9000000, 'rate' => 5]],
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $mkt->id, 'year' => now()->year, 'month' => now()->month, 'target_amount' => 9000000,
        ]);
    }

    /** @test */
    public function marketing_cannot_access_target_admin(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('marketing-target.index'))->assertForbidden();
    }
}
