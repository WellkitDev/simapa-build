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

    // Papan & pengambilan tugas pindah ke modul Penugasan Naskah (NaskahMejaKerjaTest,
    // NaskahPelacakanTest). Yang tersisa di sini: dashboard per role.

    /** @test */
    public function production_dashboard_shows_production_kpis_not_financial(): void
    {
        $me = $this->user('production');
        $this->progress(['pelaksana_user_id' => $me->id, 'status' => 'editing']);

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
        // Sejak peta role eksplisit (dashboard per role): manager sekarang dapat
        // dashboardView 'company', bukan lagi kartu keuangan generik lama. Task 8 mengisi
        // partial `company` (sebelumnya penanda minimal) — narrowing sementara di sini
        // sekarang dipulihkan ke assertion positif nyata (bagian Produksi Global). Task 9
        // menambah assertion bahwa manager TIDAK melihat blok kas/laba (hanya superadmin)
        // — manager memang dirancang tetap melihat "Ringkasan Pemasukan" perusahaan, jadi
        // jangan assertDontSee itu di sini.
        $this->actingAs($this->user('manager'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Produksi Global')
            ->assertViewHas('dashboardView', 'company')
            ->assertDontSee('Antrian Saya');          // bukan dashboard produksi
    }

    /** @test */
    public function marketing_dashboard_shows_marketing_view_not_generic_or_production(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Pemasukan')   // dashboard marketing
            ->assertSee('Progres Naskah Saya')   // progres scoped marketing
            ->assertDontSee('Total Pembayaran')  // bukan finansial generik
            ->assertDontSee('Antrian Saya');     // bukan dashboard produksi
    }

}
