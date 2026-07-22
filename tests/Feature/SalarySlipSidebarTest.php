<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipSidebarTest extends TestCase
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
    public function accounting_sees_admin_salary_menu(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('dashboard'))
            ->assertOk()->assertSee(route('salary.slip.index'));
    }

    /** @test */
    public function every_logged_in_user_sees_self_service_menu_but_not_admin_menu(): void
    {
        // route('salary.slip.index') = .../salary/slip ; route('salary.slip.me') = .../slip-gaji-saya
        // (bukan saling substring, jadi assertion tegas).
        $this->actingAs($this->user('marketing'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('salary.slip.me'))
            ->assertDontSee(route('salary.slip.index'));
    }
}
