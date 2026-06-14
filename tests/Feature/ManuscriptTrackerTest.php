<?php
// tests/Feature/ManuscriptTrackerTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ManuscriptTrackerTest extends TestCase
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

    private function progress(string $status, string $type = 'bk_mandiri'): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $type]);
        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    /** @test */
    public function production_moves_card_via_ajax(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'layout'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'layout']);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'layout']);
    }

    /** @test */
    public function rejected_move_keeps_status(): void
    {
        $p = $this->progress('cetak'); // milik superadmin
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'terbit'])
            ->assertStatus(403);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'cetak']);
    }

    /** @test */
    public function assign_endpoint_sets_editor(): void
    {
        $p = $this->progress('editing');
        $editor = $this->user('production');
        $this->actingAs($this->user('manager'));

        $this->postJson(route('manuscript.assign', $p->id), ['assigned_user_id' => $editor->id])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'assigned_user_id' => $editor->id]);
    }

    /** @test */
    public function priority_endpoint_sets_priority(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.priority', $p->id), ['priority' => 'high'])
            ->assertOk()->assertJson(['ok' => true, 'priority' => 'high']);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'priority' => 'high']);
    }

    /** @test */
    public function marketing_cannot_use_move_endpoint(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('marketing'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'layout'])
            ->assertStatus(403);
    }
}
