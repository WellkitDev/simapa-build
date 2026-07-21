<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipAccessTest extends TestCase
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
    public function accounting_can_view_index(): void
    {
        $this->actingAs($this->user('accounting'))
            ->get(route('salary.slip.index'))
            ->assertOk()
            ->assertSee('Slip Gaji Karyawan');
    }

    /** @test */
    public function marketing_cannot_view_index(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('salary.slip.index'))
            ->assertForbidden();
    }

    /** @test */
    public function manager_cannot_view_index(): void
    {
        $this->actingAs($this->user('manager'))
            ->get(route('salary.slip.index'))
            ->assertForbidden();
    }
}
