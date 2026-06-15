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

    /** @test */
    public function rejected_web_move_redirects_back_with_flash_error(): void
    {
        $p = $this->progress('cetak'); // milik superadmin — production tak boleh
        $this->actingAs($this->user('production'));

        // POST biasa (bukan JSON) = jalur tombol fallback "Majukan"
        $this->post(route('manuscript.move', $p->id), ['status' => 'terbit'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'cetak']);
    }

    /** @test */
    public function board_groups_columns_into_role_zones(): void
    {
        $this->progress('editing'); // buku: punya zona Antrian + Produksi + Finalisasi
        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Antrian')
            ->assertSee('Produksi')
            ->assertSee('Finalisasi');
    }

    /** Buat beberapa order untuk judul yang sama (satu grup). Kembalikan TitleProgress-nya. */
    private function groupOrders(string $title, array $statuses, string $type = 'bk_mandiri'): array
    {
        $progresses = [];
        foreach ($statuses as $st) {
            $detail = OrderDetail::factory()->create(['type' => $type, 'title' => $title]);
            $progresses[] = TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $st,
                'assigned_role'   => TitleProgress::getHandlerForStatus($st),
                'started_at'      => now(),
            ]);
        }
        return $progresses;
    }

    /** @test */
    public function board_shows_one_card_per_title_group(): void
    {
        $this->groupOrders('Judul Kembar Tiga', ['editing', 'editing', 'editing']);
        $this->actingAs($this->user('production'));

        $content = $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()->getContent();

        // 3 order judul sama → hanya 1 kartu (judul tampil sekali, bukan tiga kali).
        $this->assertEquals(1, substr_count($content, 'Judul Kembar Tiga'));
    }

    /** @test */
    public function group_move_advances_all_orders_of_the_title(): void
    {
        $progresses = $this->groupOrders('Judul Serempak', ['editing', 'editing']);
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $progresses[0]->id), ['status' => 'layout'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'layout']);

        foreach ($progresses as $p) {
            $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'layout']);
        }
    }

    /** @test */
    public function group_assign_sets_editor_on_all_orders(): void
    {
        $editor = $this->user('production');
        $progresses = $this->groupOrders('Judul Assign Grup', ['editing', 'editing']);
        $this->actingAs($this->user('manager'));

        $this->postJson(route('manuscript.assign', $progresses[0]->id), ['assigned_user_id' => $editor->id])
            ->assertOk();

        foreach ($progresses as $p) {
            $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'assigned_user_id' => $editor->id]);
        }
    }

    /** @test */
    public function production_jump_with_note_sets_review_flag(): void
    {
        $p = $this->progress('editing'); // handler production
        $this->actingAs($this->user('production'));

        // Lompat editing → isbn (skip layout/proofreading) dengan catatan.
        $this->postJson(route('manuscript.move', $p->id), ['status' => 'isbn', 'note' => 'lompat oke'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'isbn']);

        $this->assertDatabaseHas('tb_title_progress', [
            'id' => $p->id, 'status' => 'isbn', 'needs_review' => true,
        ]);
    }

    /** @test */
    public function production_jump_without_note_is_rejected(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));

        $this->postJson(route('manuscript.move', $p->id), ['status' => 'isbn'])
            ->assertStatus(422);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $p->id, 'status' => 'editing']);
    }

    /** @test */
    public function board_shows_review_badge_when_flagged(): void
    {
        $p = $this->progress('editing');
        $p->update(['needs_review' => true]);
        $p->orderDetail->update(['title' => 'NASKAH PERLU TINJAU']);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('NASKAH PERLU TINJAU')
            ->assertSee('tinjau');
    }
}
