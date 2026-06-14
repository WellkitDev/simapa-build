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

    /** @test */
    public function board_renders_for_production(): void
    {
        $this->progress('editing'); // satu kartu di kolom editing (buku)
        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Manuscript Tracker')
            ->assertSee('Editing');
    }

    /** @test */
    public function marketing_cannot_access_board(): void
    {
        $this->actingAs($this->user('marketing'));
        $this->get(route('manuscript.board'))->assertStatus(403);
    }

    /** @test */
    public function guest_is_redirected_from_board(): void
    {
        $this->get(route('manuscript.board'))->assertRedirect(route('login'));
    }

    /** @test */
    public function list_view_renders(): void
    {
        $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['view' => 'list']))
            ->assertOk()
            ->assertSee('Manuscript Tracker');
    }

    /** @test */
    public function priority_filter_narrows_results(): void
    {
        $high = $this->progress('editing');
        $high->update(['priority' => 'high']);
        $high->orderDetail->update(['title' => 'NASKAH PRIORITAS TINGGI']);

        $normal = $this->progress('editing');
        $normal->orderDetail->update(['title' => 'NASKAH BIASA']);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku', 'priority' => 'high']))
            ->assertOk()
            ->assertSee('NASKAH PRIORITAS TINGGI')
            ->assertDontSee('NASKAH BIASA');
    }

    /** @test */
    public function board_card_shows_author_editor_priority_and_next_action(): void
    {
        $editor = $this->user('production');

        $author = \App\Models\Author::create([
            'name'        => 'Dr. Faizul Husnayain',
            'email'       => 'faizul@example.com',
            'phone'       => '08123456789',
            'affiliation' => 'UIN Antasari',
        ]);

        $detail = OrderDetail::factory()->create([
            'type'  => 'bk_mandiri',
            'title' => 'Adaptive Fuzzy Control of UAV',
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => 'editing',
            'assigned_role'    => 'production',
            'assigned_user_id' => $editor->id,
            'priority'         => 'high',
            'started_at'       => now(),
        ]);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Adaptive Fuzzy Control of UAV')
            ->assertSee('Dr. Faizul Husnayain')
            ->assertSee('UIN Antasari')
            ->assertSee($editor->name)
            ->assertSee('High')
            ->assertSee('Majukan ke Layout'); // next stage after editing (buku)
    }
}
