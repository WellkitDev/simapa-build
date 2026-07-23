<?php

namespace Tests\Feature;

use App\Models\ChapterProgress;
use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd', 'name' => 'n', 'url' => 'http://drive/n.pdf']);
        });
        foreach (['marketing','manager','superadmin','production','admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->seed(\Database\Seeders\AccessMatrixSeeder::class);
    }

    private function user(string $role): User { $u = User::factory()->create(); $u->assignRole($role); return $u; }

    /** Buku 2 bab + progress bab. */
    private function bookWithChapters(string $status = 'editing'): Title
    {
        $title = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title_id' => $title->id, 'title' => $title->title, 'chapters' => 2]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => 'production', 'started_at' => now()]);
        foreach ([1, 2] as $i) {
            $ch = TitleChapter::create(['title_id' => $title->id, 'judul' => 'Bab ' . $i, 'urutan' => $i]);
            ChapterProgress::create(['title_chapter_id' => $ch->id, 'status' => $status, 'started_at' => now()]);
        }
        return $title;
    }

    /** @test */
    public function index_lists_only_book_titles(): void
    {
        $book = $this->bookWithChapters();
        $this->actingAs($this->user('production'))
            ->get(route('distribusi.buku.index'))->assertOk()->assertSee($book->title);
    }

    /** @test */
    public function assign_editor_all_sets_every_chapter(): void
    {
        $book = $this->bookWithChapters();
        $editor = $this->user('production');

        $this->actingAs($this->user('manager'))
            ->post(route('distribusi.buku.editorSemua', $book->id), ['assigned_user_id' => $editor->id])
            ->assertRedirect();

        foreach ($book->chapters as $ch) {
            $this->assertDatabaseHas('tb_chapter_progress', ['title_chapter_id' => $ch->id, 'assigned_user_id' => $editor->id]);
        }
    }

    /** @test */
    public function move_chapter_advances_single_chapter(): void
    {
        $book = $this->bookWithChapters('editing');
        $cp = $book->chapters()->first()->progress;

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.chapter.tahap', $cp->id), ['status' => 'layout'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_chapter_progress', ['id' => $cp->id, 'status' => 'layout']);
    }

    /** @test */
    public function upload_chapter_file_is_versioned_per_chapter(): void
    {
        $book = $this->bookWithChapters();
        $ch = $book->chapters()->first();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.chapter.file', $ch->progress->id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('bab1.pdf', 10),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', [
            'title_id' => $book->id, 'title_chapter_id' => $ch->id, 'slot' => 'masuk', 'version' => 1,
        ]);
    }

    /** @test */
    public function upload_book_level_file_has_null_chapter(): void
    {
        $book = $this->bookWithChapters();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.buku.file', $book->id), [
                'slot' => 'final',
                'file' => UploadedFile::fake()->create('buku.pdf', 10),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', ['title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'final']);
    }

    /** @test */
    public function marketing_cannot_access_book_distribution(): void
    {
        $book = $this->bookWithChapters();
        $this->actingAs($this->user('marketing'))->get(route('distribusi.buku.index'))->assertStatus(403);
    }
}
