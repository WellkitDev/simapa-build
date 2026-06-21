<?php
// tests/Feature/MarketingDashboardTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingDashboardTest extends TestCase
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
    public function marketing_sees_marketing_dashboard_not_generic(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ringkasan Pemasukan')   // dashboard marketing
            ->assertSee('Progres Naskah Saya')
            ->assertDontSee('total payment');     // blok generik approve/pending/reject tidak ada
    }

    /** @test */
    public function manager_dashboard_unchanged_generic_plus_global(): void
    {
        $this->actingAs($this->user('manager'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('total payment')          // generik tetap
            ->assertSee('Progres Naskah')         // seksi global
            ->assertDontSee('Ringkasan Pemasukan'); // bukan dashboard marketing
    }

    /** @test */
    public function arsip_judul_shows_target_column(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'NASKAH BERTARGET']);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production',
            'started_at' => now(), 'target_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($me);
        $this->get(route('order.book.indexJudul'))
            ->assertOk()
            ->assertSee('NASKAH BERTARGET')
            ->assertSee('Target')      // header kolom
            ->assertSee('lewat');      // badge overdue (target kemarin, belum final)
    }

    /** @test */
    public function marketing_dashboard_shows_new_kpis_and_deadline_table(): void
    {
        $me = $this->user('marketing');
        $order = Order::factory()->create(['user_id' => $me->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'NASKAH DEADLINE',
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production',
            'started_at' => now(), 'target_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($me);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Piutang')
            ->assertSee('Rata-rata Nilai Order')
            ->assertSee('Naskah Mendekati Deadline')
            ->assertSee('Lewat target')          // label tab
            ->assertSee('NASKAH DEADLINE')        // baris naskah overdue
            ->assertSee('Lewat 1 hari');          // badge sisa hari
    }
}
