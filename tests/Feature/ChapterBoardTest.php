<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterBoardTest extends TestCase
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
    public function book_board_renders_chapter_panel(): void
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Papan', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-BB-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Papan', 'slug' => 'buku-papan', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        app(\App\Services\TitleProgressService::class)->createForDetail($detail, $owner->id);

        $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()->assertSee('Bab')->assertSee('data-no-drag', false);
    }
}
