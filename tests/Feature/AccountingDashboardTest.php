<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingDashboardTest extends TestCase
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
    public function accounting_can_open_dashboard(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.dashboard', ['year' => 2026]))
            ->assertOk()->assertSee('Dashboard Keuangan')->assertSee('Total Pemasukan');
    }

    /** @test */
    public function marketing_cannot_access_dashboard(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.dashboard'))->assertForbidden();
    }
}
