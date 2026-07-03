<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterStageJumpTest extends TestCase
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

    /** Buku + 2 bab; kembalikan ChapterProgress bab pertama (status awal 'editing'). */
    private function firstChapter(): ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Lompat', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-SJ-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Lompat', 'slug' => 'buku-lompat', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        return $book->chapters()->with('progress')->orderBy('urutan')->first()->progress;
    }

    /** @test */
    public function manager_moves_chapter_backward_with_note(): void
    {
        $cp = $this->firstChapter();
        $cp->update(['status' => 'layout']); // maju dulu supaya bisa mundur

        $this->actingAs($this->user('manager'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'editing', 'note' => 'perlu perbaikan'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'editing']);

        $fresh = $cp->fresh();
        $this->assertSame('editing', $fresh->status);
        $this->assertSame('perlu perbaikan', $fresh->note);
        $this->assertTrue((bool) $fresh->needs_review); // manager (non-superadmin) + koreksi
    }

    /** @test */
    public function jump_without_note_is_rejected(): void
    {
        $cp = $this->firstChapter(); // editing; next = layout

        $this->actingAs($this->user('manager'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'terbit']) // lompat tanpa catatan
            ->assertStatus(422);

        $this->assertSame('editing', $cp->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_superadmin_stage_chapter(): void
    {
        $cp = $this->firstChapter();
        $cp->update(['status' => 'cetak']); // handler 'cetak' = superadmin

        $this->actingAs($this->user('production'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'isbn', 'note' => 'mundur'])
            ->assertStatus(403);

        $this->assertSame('cetak', $cp->fresh()->status);
    }
}
