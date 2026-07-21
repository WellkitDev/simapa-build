<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\ChapterManuscriptService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterProgressControllerTest extends TestCase
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

    private function firstChapter(): \App\Models\ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Bab', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-CP-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Bab', 'slug' => 'buku-bab', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        return $book->chapters()->with('progress')->orderBy('urutan')->first()->progress;
    }

    /** @test */
    public function production_advances_chapter(): void
    {
        $cp = $this->firstChapter(); // editing
        $this->actingAs($this->user('production'))
            ->post(route('chapter.advance', $cp->id), ['status' => 'layout'])
            ->assertRedirect();
        $this->assertSame('layout', $cp->fresh()->status);
    }

    /** @test */
    public function marketing_cannot_advance_chapter(): void
    {
        $cp = $this->firstChapter();
        $this->actingAs($this->user('marketing'))
            ->post(route('chapter.advance', $cp->id), ['status' => 'layout'])
            ->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function manager_assigns_chapter_editor(): void
    {
        $cp = $this->firstChapter();
        $editor = $this->user('production');
        $this->actingAs($this->user('manager'))
            ->post(route('chapter.assign', $cp->id), ['assigned_user_id' => $editor->id])
            ->assertRedirect();
        $this->assertSame($editor->id, $cp->fresh()->assigned_user_id);
    }
}
