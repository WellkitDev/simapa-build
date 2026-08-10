<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleDirectoryManuscriptTest extends TestCase
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

    private function titleWithProgress(string $status = 'editing'): Title
    {
        $owner = $this->user('production');
        $title = Title::create(['title' => 'Naskah Uji', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-M1-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Naskah Uji', 'slug' => 'naskah-uji', 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => 'production', 'started_at' => now()]);

        return $title;
    }

    /** @test */
    public function index_shows_manuscript_stage_label(): void
    {
        $this->titleWithProgress('editing');
        $this->actingAs($this->user('manager'))->get(route('title.index'))
            ->assertOk()->assertSee('Manuskrip')->assertSee('Editing');
    }

    /** @test */
    public function show_shows_board_link_for_production_not_marketing(): void
    {
        // Papan Manuskrip lama diganti Pelacakan Naskah (2026-08-10); tautannya tetap
        // hanya untuk yang menggarap naskah, bukan marketing.
        $title = $this->titleWithProgress('editing');

        $this->actingAs($this->user('production'))->get(route('title.show', $title->id))
            ->assertOk()->assertSee('Buka Pelacakan Naskah');

        $this->actingAs($this->user('marketing'))->get(route('title.show', $title->id))
            ->assertOk()->assertDontSee('Buka Pelacakan Naskah');
    }
}
