<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingBudgetTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    public function accounting_opens_target_page(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.target', ['year' => 2026]))
            ->assertOk()->assertSee('Target Operasional')->assertSee('Minimum');
    }

    /** @test */
    public function set_target_saved(): void
    {
        $this->actingAs($this->user('superadmin'))->put(route('accounting.target.update'), [
            'target_operasional' => 80000000, 'target_order' => 200000000,
        ])->assertRedirect();

        $s = CashSetting::singleton();
        $this->assertSame('80000000.00', $s->target_operasional);
        $this->assertSame('200000000.00', $s->target_order);
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.target'))->assertForbidden();
    }
}
