<?php
// tests/Feature/DashboardRoleRoutingTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardRoleRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        // firstOrCreate: role 'accounting' sudah di-seed permanen oleh migrasi
        // 2026_07_04_000001_add_accounting_role.php, jadi Role::create() akan selalu
        // tabrakan dengan RoleAlreadyExists pada migrate:fresh. Pola sama dgn IncomeDefinitionTest.
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->create();
        foreach ($roles as $r) {
            $u->assignRole($r);
        }
        return $u;
    }

    /** @test */
    public function tiap_role_mendapat_dashboard_view_yang_benar(): void
    {
        $peta = [
            'superadmin' => 'company',
            'manager'    => 'company',
            'accounting' => 'accounting',
            'admin'      => 'admin',
            'marketing'  => 'sales',
            'production' => 'production',
        ];

        foreach ($peta as $role => $view) {
            $this->actingAs($this->user($role))->get(route('dashboard'))
                ->assertOk()
                ->assertViewHas('dashboardView', $view);
        }
    }

    /** @test */
    public function role_paling_tinggi_menang_untuk_user_multi_role(): void
    {
        // Superadmin yang juga marketing tetap dapat dashboard superadmin.
        $this->actingAs($this->user('marketing', 'superadmin'))->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardView', 'company');
    }

    /** @test */
    public function user_tanpa_role_tidak_error(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }
}
