<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function actingAsRole(string $role): void
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $this->actingAs($u);
    }

    /** @test */
    public function superadmin_sidebar_renders_every_section(): void
    {
        // superadmin ada di setiap @role → me-render seluruh section sidebar,
        // menangkap route() bernama salah (akan 500) di section mana pun.
        $this->actingAsRole('superadmin');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Buat Order')->assertSee('Daftar Order')
            ->assertSee('Tagihan')->assertSee('Invoice')
            ->assertSee('Direktori Judul')->assertSee('Direktori ISBN')->assertSee('Arsip Judul')
            ->assertSee('Pelacak Naskah')
            ->assertSee('Jurnal Kas')->assertSee('Distribusi Profit')->assertSee('Anggaran')
            ->assertSee('Laporan Keuangan')->assertSee('Laporan Harian')->assertSee('Pemantauan Laporan')
            ->assertSee('Papan Tugas')->assertSee('Daftar Tugas')->assertSee('Pemantauan Tugas')
            ->assertSee('Manajemen User')->assertSee('Pengumuman')->assertSee('Profil');
    }

    /** @test */
    public function accounting_sees_finance_section(): void
    {
        $this->actingAsRole('accounting');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Jurnal Kas')->assertSee('Distribusi Profit')->assertSee('Ringkasan');
    }

    /** @test */
    public function marketing_sees_order_and_own_target_not_finance_or_production(): void
    {
        $this->actingAsRole('marketing');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Daftar Order')->assertSee('Target Saya')->assertSee('Laporan Keuangan')
            ->assertDontSee('Distribusi Profit')   // section Keuangan (accounting)
            ->assertDontSee('Meja Kerja Saya');    // section Produksi
    }

    /** @test */
    public function production_sees_own_workbench_label(): void
    {
        $this->actingAsRole('production');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Meja Kerja Saya')->assertSee('Direktori Judul')
            ->assertDontSee('Direktori Author');   // dibatasi untuk production
    }
}
