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

class ManuscriptFinalizeTest extends TestCase
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

    private function articleAt(string $status, ?\Carbon\Carbon $startedAt = null): TitleProgress
    {
        $owner = $this->user('production');
        $title = Title::create(['title' => 'Artikel ' . uniqid(), 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-FA-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $title->id, 'type' => 'at_mandiri', 'title' => $title->title, 'slug' => 'a-' . uniqid(), 'chapters' => 0, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        return TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => TitleProgress::getHandlerForStatus($status), 'started_at' => $startedAt ?? now()]);
    }

    private function bookChapterAt(string $status): ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-FB-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        $cp = $book->chapters()->with('progress')->first()->progress;
        $cp->update(['status' => $status]);
        return $cp;
    }

    /** @test */
    public function manager_cannot_change_final_article_but_superadmin_can(): void
    {
        $tp = $this->articleAt('publish');
        $this->actingAs($this->user('manager'))
            ->postJson(route('manuscript.move', $tp->id), ['status' => 'loa', 'note' => 'koreksi'])
            ->assertStatus(403);

        $tp2 = $this->articleAt('publish');
        $this->actingAs($this->user('superadmin'))
            ->postJson(route('manuscript.move', $tp2->id), ['status' => 'loa', 'note' => 'koreksi'])
            ->assertOk();
        $this->assertSame('loa', $tp2->fresh()->status);
    }

    /** @test */
    public function production_and_manager_cannot_change_final_chapter_but_superadmin_can(): void
    {
        foreach (['production', 'manager'] as $role) {
            $cp = $this->bookChapterAt('terbit');
            $this->actingAs($this->user($role))
                ->postJson(route('chapter.advance', $cp->id), ['status' => 'cetak', 'note' => 'koreksi'])
                ->assertStatus(403);
            $this->assertSame('terbit', $cp->fresh()->status);
        }

        $cp = $this->bookChapterAt('terbit');
        $this->actingAs($this->user('superadmin'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'cetak', 'note' => 'koreksi'])
            ->assertOk();
        $this->assertSame('cetak', $cp->fresh()->status);
    }
}
