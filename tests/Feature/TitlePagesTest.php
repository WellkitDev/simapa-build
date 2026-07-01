<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitlePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
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
    public function manager_can_open_index_and_create_pages(): void
    {
        $u = $this->user('manager');
        Title::create(['title' => 'JudulKu', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'draft', 'created_by' => $u->id]);

        $this->actingAs($u)->get(route('title.index'))->assertOk()->assertSee('JudulKu');
        $this->actingAs($u)->get(route('title.create'))->assertOk();
    }

    /** @test */
    public function marketing_can_open_index_but_not_create(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('title.index'))->assertOk();
        $this->actingAs($this->user('marketing'))->get(route('title.create'))->assertForbidden();
    }
}
