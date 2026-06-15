<?php
// tests/Feature/ProductionWorkspaceTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ProductionWorkspaceTest extends TestCase
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

    private function progress(array $attrs): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => $attrs['title'] ?? fake()->sentence(3)]);
        return TitleProgress::create(array_merge([
            'status'        => 'editing',
            'assigned_role' => 'production',
            'started_at'    => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function scope_mine_shows_only_my_work_and_unclaimed(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing', 'title' => 'MILIK SAYA']);
        $this->progress(['assigned_user_id' => null, 'status' => 'editing', 'title' => 'BELUM DIAMBIL']);
        $this->progress(['assigned_user_id' => $this->user('production')->id, 'status' => 'editing', 'title' => 'MILIK ORANG LAIN']);

        $this->actingAs($me);
        $this->get(route('manuscript.board', ['tipe' => 'buku', 'scope' => 'mine']))
            ->assertOk()
            ->assertSee('MILIK SAYA')
            ->assertSee('BELUM DIAMBIL')
            ->assertDontSee('MILIK ORANG LAIN');
    }

    /** @test */
    public function production_defaults_to_scope_mine(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $this->user('production')->id, 'status' => 'editing', 'title' => 'PUNYA EDITOR LAIN']);

        $this->actingAs($me);
        // tanpa param scope → production default 'mine' → tak melihat punya editor lain
        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertDontSee('PUNYA EDITOR LAIN');
    }

    /** @test */
    public function ambil_self_assigns_unclaimed_naskah(): void
    {
        $me = $this->user('production');
        $p  = $this->progress(['assigned_user_id' => null, 'status' => 'editing']);

        $this->actingAs($me);
        $this->post(route('manuscript.assign', $p->id), ['assigned_user_id' => $me->id])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'assigned_user_id' => $me->id]);
    }

    /** @test */
    public function production_dashboard_shows_production_kpis_not_financial(): void
    {
        $me = $this->user('production');
        $this->progress(['assigned_user_id' => $me->id, 'status' => 'editing']);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Antrian Saya')          // KPI produksi
            ->assertSee('Performa Saya')          // widget performa
            ->assertDontSee('total payment');     // blok finansial tidak ada untuk production
    }

    /** @test */
    public function manager_dashboard_shows_global_progress_section(): void
    {
        $this->actingAs($this->user('manager'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Progres Naskah')        // seksi global
            ->assertSee('total payment');         // finansial tetap ada
    }

    /** @test */
    public function marketing_dashboard_stays_financial_only(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('total payment')         // finansial
            ->assertDontSee('Antrian Saya')      // bukan dashboard produksi
            ->assertDontSee('Progres Naskah');   // bukan seksi global manager
    }
}
