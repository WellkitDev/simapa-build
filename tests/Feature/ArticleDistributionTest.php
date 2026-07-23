<?php

namespace Tests\Feature;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArticleDistributionTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create(); $u->assignRole($role); return $u;
    }

    private function articleTitle(string $status = 'templating'): Title
    {
        $title = Title::create(['title' => 'Artikel ' . uniqid(), 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create(['type' => 'at_mandiri', 'title_id' => $title->id, 'title' => $title->title]);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => TitleProgress::getHandlerForStatus($status), 'started_at' => now()]);
        return $title;
    }

    /** @test */
    public function index_lists_only_article_titles_for_production(): void
    {
        $art = $this->articleTitle();
        $book = Title::create(['title' => 'BUKU BUKAN ARTIKEL', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title_id' => $book->id, 'title' => $book->title]);

        $this->actingAs($this->user('production'))
            ->get(route('distribusi.artikel.index'))
            ->assertOk()
            ->assertSee($art->title)
            ->assertDontSee('BUKU BUKAN ARTIKEL');
    }

    /** @test */
    public function assign_editor_sets_all_variants_and_admin_is_eligible(): void
    {
        $title = $this->articleTitle('editing');
        $editorAdmin = $this->user('admin');

        $this->actingAs($this->user('manager'))
            ->post(route('distribusi.artikel.editor', $title->id), ['assigned_user_id' => $editorAdmin->id])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', [
            'order_detail_id' => $title->orderDetails()->first()->id,
            'assigned_user_id' => $editorAdmin->id,
        ]);
    }

    /** @test */
    public function move_stage_advances_progress(): void
    {
        $title = $this->articleTitle('editing');

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.artikel.tahap', $title->id), ['status' => 'revisi'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', ['order_detail_id' => $title->orderDetails()->first()->id, 'status' => 'revisi']);
    }

    /** @test */
    public function upload_file_creates_versioned_row(): void
    {
        $title = $this->articleTitle();

        $this->actingAs($this->user('production'))
            ->post(route('distribusi.artikel.file', $title->id), [
                'slot' => 'final',
                'file' => UploadedFile::fake()->create('naskah.pdf', 20),
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_manuscript_files', ['title_id' => $title->id, 'slot' => 'final', 'version' => 1]);
    }

    /** @test */
    public function marketing_cannot_access_distribution(): void
    {
        $title = $this->articleTitle();
        $this->actingAs($this->user('marketing'))
            ->get(route('distribusi.artikel.index'))->assertStatus(403);
    }
}
