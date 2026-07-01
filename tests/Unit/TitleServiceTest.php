<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
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
}
