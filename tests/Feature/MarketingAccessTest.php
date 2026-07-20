<?php
// tests/Feature/MarketingAccessTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /**
     * Detail progres naskah sengaja terbuka bagi production/admin (mereka butuh konteks
     * naskah, dan papan Manuscript menaut ke sini), TAPI nilai order tidak boleh terlihat
     * oleh mereka — sejalan dengan aturan "admin tak pernah melihat angka uang".
     *
     * @test
     */
    public function production_dan_admin_tidak_melihat_nilai_order_di_detail_progres(): void
    {
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'cost_amount' => 1234567,
        ]);
        \App\Models\TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'editing',
            'assigned_role'   => 'production',
            'started_at'      => now(),
        ]);

        // Halaman tetap dapat dibuka, tapi tanpa angka uang.
        foreach (['production', 'admin'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('order.indexJudul.progress', $detail->id))
                ->assertOk()
                ->assertDontSee('Total Biaya')
                ->assertDontSee('1.234.567');
        }

        // Role yang berwenang atas order tetap melihat nilainya.
        foreach (['manager', 'superadmin'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('order.indexJudul.progress', $detail->id))
                ->assertOk()
                ->assertSee('Total Biaya')
                ->assertSee('1.234.567');
        }
    }

    /** @test */
    public function marketing_cannot_approve_or_reject_payments(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->post(route('payment.approve', 1))->assertForbidden();
        $this->post(route('payment.reject', 1))->assertForbidden();
    }

    /** @test */
    public function marketing_cannot_mutate_invoice(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('invoice.edit', 1))->assertForbidden();
        $this->put(route('invoice.update', 1))->assertForbidden();
        $this->post(route('invoice.updateStatus', 1))->assertForbidden();
        $this->post(route('invoice.cancel', 1))->assertForbidden();
        $this->get(route('order.refund.form', 'X'))->assertForbidden();
    }

    /** @test */
    public function production_cannot_reach_marketing_listings(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('payment.index'))->assertForbidden();
        $this->get(route('order.book.index'))->assertForbidden();
        $this->get(route('invoice.index'))->assertForbidden();
    }

    /** @test */
    public function production_keeps_read_access_to_title_detail(): void
    {
        // Papan Manuscript produksi menaut ke order.indexJudul.detail — route TIDAK boleh 403.
        $this->actingAs($this->user('production'));
        $response = $this->get(route('order.indexJudul.detail', 999999));
        $this->assertNotEquals(403, $response->getStatusCode()); // 404/200 boleh; 403 berarti ter-gate salah
    }

    /** @test */
    public function marketing_can_reach_allowed_pages(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('payment.index'))->assertOk();
        $this->get(route('order.book.index'))->assertOk();
        $this->get(route('invoice.index'))->assertOk();
    }
}
