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
        $this->post(route('invoice.refund', 1))->assertForbidden();
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
