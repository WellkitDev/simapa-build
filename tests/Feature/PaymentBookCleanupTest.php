<?php
// tests/Feature/PaymentBookCleanupTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PaymentBookCleanupTest extends TestCase
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

    /** @test */
    public function approved_payments_page_has_clean_title_and_no_dead_buttons(): void
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');

        $this->actingAs($u);
        $this->get(route('payment.index'))
            ->assertOk()
            ->assertSee('Pembayaran Disetujui')
            ->assertDontSee('Management Order Books')
            ->assertDontSee('Trash');
    }
}
