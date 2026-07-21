<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Scope;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleControllerTest extends TestCase
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

    private function title(User $creator, string $status, string $name = 'X'): Title
    {
        return Title::create(['title' => $name, 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => $status, 'created_by' => $creator->id]);
    }

    /** @test */
    public function production_creates_draft_then_submits_to_menunggu(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('title.store'), ['title' => 'Judul A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'])->assertRedirect();
        $title = Title::where('title', 'Judul A')->first();
        $this->assertNotNull($title);
        $this->assertSame('draft', $title->status);

        $this->actingAs($u)->post(route('title.submit', $title->id))->assertRedirect();
        $this->assertSame('menunggu', $title->fresh()->status);
    }

    /** @test */
    public function store_persists_bidang_ilmu_scope(): void
    {
        $u = $this->user('production');
        $this->actingAs($u)->post(route('title.store'), [
            'title' => 'ScopeTitle', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'scope_id' => 'Bioteknologi',
        ])->assertRedirect();

        $title = Title::where('title', 'ScopeTitle')->first();
        $this->assertNotNull($title->scope_id);
        $this->assertSame('Bioteknologi', $title->scope->scope);
        $this->assertSame(1, Scope::where('scope', 'Bioteknologi')->count());
    }

    /** @test */
    public function manager_approves_but_production_cannot(): void
    {
        $prod = $this->user('production');
        $mgr  = $this->user('manager');
        $title = $this->title($prod, 'menunggu');

        $this->actingAs($prod)->post(route('title.approve', $title->id))->assertRedirect()->assertSessionHas('error');
        $this->actingAs($mgr)->post(route('title.approve', $title->id))->assertRedirect();
        $this->assertSame('disetujui', $title->fresh()->status);
    }

    /** @test */
    public function reject_records_note(): void
    {
        $prod = $this->user('production');
        $title = $this->title($prod, 'menunggu');

        $this->actingAs($this->user('superadmin'))->post(route('title.reject', $title->id), ['reject_note' => 'perbaiki judul'])->assertRedirect();
        $this->assertSame('ditolak', $title->fresh()->status);
        $this->assertSame('perbaiki judul', $title->fresh()->reject_note);
    }

    /** @test */
    public function marketing_index_sees_only_approved(): void
    {
        $prod = $this->user('production');
        $this->title($prod, 'draft', 'DRAF-RAHASIA');
        $this->title($prod, 'disetujui', 'JUDUL-SIAP');

        $this->actingAs($this->user('marketing'))->get(route('title.index'))
            ->assertOk()->assertSee('JUDUL-SIAP')->assertDontSee('DRAF-RAHASIA');
    }

    /** @test */
    public function edit_blocked_when_approved(): void
    {
        $prod = $this->user('production');
        $title = $this->title($prod, 'disetujui');
        $this->actingAs($prod)->get(route('title.edit', $title->id))->assertForbidden();
    }

    /** @test */
    public function assigned_title_visible_only_to_that_marketing(): void
    {
        $prod = $this->user('production');
        $mkt1 = $this->user('marketing');
        $mkt2 = $this->user('marketing');

        $assigned = Title::create(['title' => 'ASSIGNED-M1', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'created_by' => $prod->id, 'assigned_to' => $mkt1->id]);
        Title::create(['title' => 'FOR-ALL', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'created_by' => $prod->id]);

        // mkt1: melihat judul miliknya + yang untuk semua
        $this->actingAs($mkt1)->get(route('title.index'))->assertOk()->assertSee('ASSIGNED-M1')->assertSee('FOR-ALL');
        // mkt2: tidak melihat judul yang di-assign ke mkt1, tapi tetap melihat yang untuk semua
        $this->actingAs($mkt2)->get(route('title.index'))->assertOk()->assertDontSee('ASSIGNED-M1')->assertSee('FOR-ALL');
        // mkt2 tak boleh membuka detail judul milik mkt1
        $this->actingAs($mkt2)->get(route('title.show', $assigned->id))->assertForbidden();
    }

    /** @test */
    public function store_persists_assigned_marketing(): void
    {
        $prod = $this->user('production');
        $mkt  = $this->user('marketing');

        $this->actingAs($prod)->post(route('title.store'), [
            'title' => 'DistTitle', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'assigned_to' => $mkt->id,
        ])->assertRedirect();

        $this->assertSame($mkt->id, Title::where('title', 'DistTitle')->first()->assigned_to);
    }
}
