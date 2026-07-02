<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Scope;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new TitleService();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function create_buku_stores_ordered_chapters(): void
    {
        $title = $this->svc->create(
            ['title' => 'Buku A', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'indeksasi' => 'none'],
            [['judul' => 'Bab 1'], ['judul' => 'Bab 2']],
            $this->user('production'),
        );

        $this->assertSame('draft', $title->status);
        $this->assertNotNull($title->slug);
        $this->assertSame(2, $title->chapters()->count());
        $this->assertSame('Bab 1', $title->chapters()->first()->judul);
    }

    /** @test */
    public function submit_by_production_goes_to_menunggu(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'Art', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $prod);

        $this->svc->submit($title, $prod);

        $this->assertSame('menunggu', $title->fresh()->status);
    }

    /** @test */
    public function submit_by_superadmin_auto_approves(): void
    {
        $sa = $this->user('superadmin');
        $title = $this->svc->create(['title' => 'Art', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $sa);

        $this->svc->submit($title, $sa);

        $title->refresh();
        $this->assertSame('disetujui', $title->status);
        $this->assertSame($sa->id, $title->approved_by);
    }

    /** @test */
    public function reject_then_resubmit_then_approve(): void
    {
        $prod = $this->user('production');
        $mgr  = $this->user('manager');
        $title = $this->svc->create(['title' => 'X', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $prod);

        $this->svc->submit($title, $prod);                    // menunggu
        $this->svc->reject($title->fresh(), $mgr, 'kurang lengkap');
        $this->assertSame('ditolak', $title->fresh()->status);
        $this->assertSame('kurang lengkap', $title->fresh()->reject_note);

        $this->svc->submit($title->fresh(), $prod);           // menunggu lagi
        $this->svc->approve($title->fresh(), $mgr);
        $this->assertSame('disetujui', $title->fresh()->status);
    }

    /** @test */
    public function update_to_artikel_removes_chapters(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'B', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'], [['judul' => 'Bab 1']], $prod);

        $this->svc->update($title, ['title' => 'B', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], []);

        $this->assertSame(0, $title->chapters()->count());
    }

    /** @test */
    public function create_with_new_scope_name_creates_and_links_it(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(
            ['title' => 'S', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'scope_id' => 'Teknik Informatika'],
            [],
            $prod,
        );

        $this->assertNotNull($title->scope_id);
        $this->assertSame('Teknik Informatika', $title->scope->scope);
        $this->assertSame(1, Scope::where('scope', 'Teknik Informatika')->count());
    }

    /** @test */
    public function create_with_existing_scope_id_links_without_duplicating(): void
    {
        $prod = $this->user('production');
        $scope = Scope::create(['scope' => 'Kedokteran']);

        $title = $this->svc->create(
            ['title' => 'S', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'scope_id' => $scope->id],
            [],
            $prod,
        );

        $this->assertSame($scope->id, $title->scope_id);
        $this->assertSame(1, Scope::count());
    }

    /** @test */
    public function update_changes_scope(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'S', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'scope_id' => 'Fisika'], [], $prod);

        $this->svc->update($title, ['title' => 'S', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'scope_id' => 'Kimia'], []);

        $this->assertSame('Kimia', $title->fresh()->scope->scope);
    }

    /** @test */
    public function assigned_to_defaults_null_and_can_be_set_then_cleared(): void
    {
        $prod = $this->user('production');
        $mkt  = $this->user('marketing');

        $unassigned = $this->svc->create(['title' => 'U', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $prod);
        $this->assertNull($unassigned->assigned_to);

        $assigned = $this->svc->create(['title' => 'A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'assigned_to' => $mkt->id], [], $prod);
        $this->assertSame($mkt->id, $assigned->assigned_to);

        $this->svc->update($assigned, ['title' => 'A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'assigned_to' => ''], []);
        $this->assertNull($assigned->fresh()->assigned_to);
    }

    /** @test */
    public function resolve_for_order_returns_existing_title_by_id(): void
    {
        $prod = $this->user('production');
        $existing = $this->svc->create(['title' => 'Judul Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri'], [], $prod);

        $resolved = $this->svc->resolveForOrder((string) $existing->id, ['jenis' => 'buku', 'order_type' => 'bk_mandiri'], $prod);

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, \App\Models\Title::count());
    }

    /** @test */
    public function resolve_for_order_creates_new_title_from_order_fields(): void
    {
        $mkt = $this->user('marketing');
        $scope = \App\Models\Scope::create(['scope' => 'Hukum']);

        $resolved = $this->svc->resolveForOrder('Judul Baru Order', [
            'jenis' => 'buku', 'order_type' => 'bk_kolab', 'scope_id' => $scope->id, 'indeksasi' => null,
        ], $mkt);

        $this->assertSame('Judul Baru Order', $resolved->title);
        $this->assertSame('buku', $resolved->jenis);
        $this->assertSame('kolaborasi', $resolved->tipe_naskah);
        $this->assertSame('order', $resolved->asal);
        $this->assertSame('disetujui', $resolved->status);
        $this->assertSame($scope->id, $resolved->scope_id);
        $this->assertSame($mkt->id, $resolved->created_by);
        $this->assertSame($mkt->id, $resolved->approved_by);
    }

    /** @test */
    public function resolve_for_order_new_article_title_is_mandiri_from_at_mandiri(): void
    {
        $mkt = $this->user('marketing');
        $resolved = $this->svc->resolveForOrder('Artikel X', ['jenis' => 'artikel', 'order_type' => 'at_mandiri'], $mkt);

        $this->assertSame('artikel', $resolved->jenis);
        $this->assertSame('mandiri', $resolved->tipe_naskah);
    }

    /** @test */
    public function resolve_for_order_reuses_same_name_only_within_same_jenis(): void
    {
        $mkt = $this->user('marketing');

        $book = $this->svc->resolveForOrder('Judul Ganda', ['jenis' => 'buku', 'order_type' => 'bk_mandiri'], $mkt);
        // nama sama, jenis sama → pakai ulang
        $bookAgain = $this->svc->resolveForOrder('Judul Ganda', ['jenis' => 'buku', 'order_type' => 'bk_kolab'], $mkt);
        $this->assertSame($book->id, $bookAgain->id);

        // nama sama, jenis beda (artikel) → judul baru, bukan menaut ke judul buku
        $article = $this->svc->resolveForOrder('Judul Ganda', ['jenis' => 'artikel', 'order_type' => 'at_mandiri'], $mkt);
        $this->assertNotSame($book->id, $article->id);
        $this->assertSame('artikel', $article->jenis);
    }

    /** @test */
    public function create_assigns_a_code(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'Blockchain dalam Fintech Syariah', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri'], [], $prod);
        $this->assertSame('BDFS', $title->fresh()->code);
    }

    /** @test */
    public function resolve_for_order_assigns_a_code(): void
    {
        $mkt = $this->user('marketing');
        $title = $this->svc->resolveForOrder('Analisis Data Besar Nasional', ['jenis' => 'artikel', 'order_type' => 'at_mandiri'], $mkt);
        $this->assertSame('ADBN', $title->code);
    }
}
