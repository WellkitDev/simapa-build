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

    /** @test */
    public function superadmin_melihat_tabel_target_tim_dan_dropdown_filter(): void
    {
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'Marketing Satu']);
        \App\Models\MarketingTarget::create([
            'user_id' => $mkt->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10_000_000, 'commission_rate' => 5,
        ]);

        $this->actingAs($this->user('superadmin'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target Tim')
            ->assertSee('Marketing Satu')
            ->assertSee('Semua marketing');
    }

    /** @test */
    public function filter_marketing_asing_jatuh_ke_semua(): void
    {
        $this->actingAs($this->user('superadmin'))->get(route('dashboard', ['marketing' => 999999]))
            ->assertOk()
            ->assertViewHas('filterId', null);
    }

    /** @test */
    public function filter_menolak_id_user_yang_bukan_marketing(): void
    {
        $prod = $this->user('production');

        $this->actingAs($this->user('superadmin'))->get(route('dashboard', ['marketing' => $prod->id]))
            ->assertOk()
            ->assertViewHas('filterId', null);
    }

    /** @test */
    public function manager_tidak_menerima_data_kas_sama_sekali(): void
    {
        $this->actingAs($this->user('manager'))->get(route('dashboard'))
            ->assertOk()
            // Bukan sekadar tersembunyi CSS — datanya memang tidak diambil.
            ->assertViewHas('cash', null)
            ->assertDontSee('Saldo Akhir')
            ->assertDontSee('Laba Tahun Berjalan');
    }

    /** @test */
    public function superadmin_menerima_blok_kas(): void
    {
        $res = $this->actingAs($this->user('superadmin'))->get(route('dashboard'))->assertOk();

        $this->assertNotNull($res->viewData('cash'));
        $res->assertSee('Saldo Akhir')->assertSee('Laba Tahun Berjalan');
    }

    /** @test */
    public function admin_melihat_papan_dokumen_tanpa_angka_uang(): void
    {
        $res = $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk();

        $res->assertSee('Dokumen Belum Lengkap')
            ->assertSee('Arsip Menunggu Artefak')
            ->assertSee('Pengumuman Aktif');

        // Cacat asal yang diperbaiki spec ini: admin tak pernah lagi melihat angka uang.
        $res->assertDontSee('Total Pemasukan')
            ->assertDontSee('Total Piutang');

        // viewData('mkt') melempar ErrorException (undefined array key) kalau key
        // sama sekali tak diset — ?? tak menangkapnya karena lemparannya di dalam
        // method call. admin() memang tak pernah menaruh 'mkt', jadi cek langsung
        // ke gatherData() daripada viewData().
        $this->assertArrayNotHasKey('mkt', $res->original->gatherData());
    }
}
