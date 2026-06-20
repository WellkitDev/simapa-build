<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class SidebarTagihanTest extends TestCase
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
    public function marketing_sidebar_shows_tagihan_link(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('tagihan.index'))
            ->assertSee('Tagihan');
    }

    /** @test */
    public function production_sidebar_hides_tagihan(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('tagihan.index'));
    }
}
