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
}
