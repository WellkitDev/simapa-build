<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetAdminTest extends TestCase
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
    public function manager_can_view_index_and_create_individual_target(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'MARKETING SATU']);

        $this->actingAs($manager)->get(route('marketing-target.index'))
            ->assertOk()->assertSee('MARKETING SATU');

        $this->actingAs($manager)->post(route('marketing-target.store'), [
            'assignee' => (string) $mkt->id,
            'target_amount' => 9000000, 'commission_type' => 'percent', 'commission_rate' => 5,
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $mkt->id, 'target_amount' => 9000000, 'batch_id' => null,
        ]);
    }

    /** @test */
    public function create_all_makes_one_row_per_marketing(): void
    {
        $manager = $this->user('manager');
        $this->user('marketing');
        $this->user('marketing');

        $this->actingAs($manager)->post(route('marketing-target.store'), [
            'assignee' => 'all',
            'target_amount' => 5000000, 'commission_type' => 'percent', 'commission_rate' => 4,
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(2, MarketingTarget::whereNotNull('batch_id')->count());
    }

    /** @test */
    public function create_flat_commission_target(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');

        $this->actingAs($manager)->post(route('marketing-target.store'), [
            'assignee' => (string) $mkt->id,
            'target_amount' => 45000000, 'commission_type' => 'flat', 'commission_flat' => 1000000,
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $mkt->id, 'commission_type' => 'flat', 'commission_flat' => 1000000,
        ]);
    }

    /** @test */
    public function mark_paid_and_delete(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $t = MarketingTarget::create([
            'user_id' => $mkt->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subDay()->toDateString(),
            'target_amount' => 1000000, 'commission_rate' => 5,
        ]);

        $this->actingAs($manager)->post(route('marketing-target.paid', $t->id))->assertRedirect();
        $this->assertTrue($t->fresh()->commission_paid);

        $this->actingAs($manager)->delete(route('marketing-target.destroy', $t->id))->assertRedirect();
        $this->assertDatabaseMissing('tb_marketing_targets', ['id' => $t->id]);
    }

    /** @test */
    public function marketing_cannot_access_admin(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('marketing-target.index'))->assertForbidden();
    }
}
