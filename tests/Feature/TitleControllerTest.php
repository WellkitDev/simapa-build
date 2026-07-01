<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
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
    public function manager_approves_but_production_cannot(): void
    {
        $prod = $this->user('production');
        $mgr  = $this->user('manager');
        $title = $this->title($prod, 'menunggu');

        $this->actingAs($prod)->post(route('title.approve', $title->id))->assertForbidden();
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
}
