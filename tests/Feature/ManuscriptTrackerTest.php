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

        // scope=all: kartu ditugaskan ke editor lain → uji "papan tim", bukan default Meja Saya
        $this->get(route('manuscript.board', ['tipe' => 'buku', 'scope' => 'all']))
            ->assertOk()
            ->assertSee('Adaptive Fuzzy Control of UAV')
            ->assertSee('Dr. Faizul Husnayain')
            ->assertSee('UIN Antasari')
            ->assertSee($editor->name)
            ->assertSee('High');
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
    public function log_view_lists_activity_in_a_table(): void
    {
        $p = $this->progress('editing');
        $p->orderDetail->update(['title' => 'NASKAH LOG TABEL']);
        \App\Models\TitleProgressLog::create([
            'title_progress_id' => $p->id, 'event' => 'priority_changed',
            'from_value' => 'Normal', 'to_value' => 'High', 'changed_by' => $this->user('manager')->id,
        ]);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku', 'view' => 'log']))
            ->assertOk()
            ->assertSee('NASKAH LOG TABEL')
            ->assertSee('Ubah prioritas');
    }

    /** @test */
    public function board_card_shows_target_date(): void
    {
        $p = $this->progress('editing');
        $p->update(['target_date' => '2026-11-20']);
        $p->orderDetail->update(['title' => 'NASKAH BERTARGET']);

        $this->actingAs($this->user('production'));

        $this->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('NASKAH BERTARGET')
            ->assertSee('20 Nov 2026'); // format d M Y
    }

    /** @test */
    public function board_is_read_only_no_action_forms(): void
    {
        $p = $this->progress('editing');
        $this->actingAs($this->user('production'));
        $html = $this->get(route('manuscript.board', ['tipe' => 'buku']))->assertOk()->getContent();
        $this->assertStringNotContainsString('manuscript.move', $html);
        $this->assertStringNotContainsString('Ambil naskah ini', $html);
    }
}
