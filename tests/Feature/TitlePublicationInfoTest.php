<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitlePublicationInfoTest extends TestCase
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

    private function title(): Title
    {
        return Title::create(['title' => 'Judul Publikasi', 'code' => 'JDPB', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function manager_updates_info_logs_and_notifies_superadmin(): void
    {
        $sa = $this->user('superadmin');
        $mgr = $this->user('manager');
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'code' => 'JDPB',
            'target_terbit' => '2026-10-01',
            'jurnal_target' => 'Jurnal Riset',
            'template_link' => 'https://j.test/tpl',
            'journal_options' => [['nama_jurnal' => 'Alt J', 'link' => 'https://alt.test', 'apc' => 'gratis']],
        ])->assertRedirect();

        $title->refresh();
        $this->assertSame('Jurnal Riset', $title->jurnal_target);
        $this->assertSame(1, $title->journalOptions()->count());
        $this->assertSame(1, $title->logs()->count());
        $this->assertSame(1, $sa->notifications()->count());
    }

    /** @test */
    public function production_can_view_panel_but_cannot_update(): void
    {
        $prod = $this->user('production');
        $title = $this->title();

        $this->actingAs($prod)->get(route('title.show', $title->id))->assertOk()->assertSee('Informasi Publikasi');
        $this->actingAs($prod)->put(route('title.info.update', $title->id), ['jurnal_target' => 'X'])->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function marketing_does_not_see_panel(): void
    {
        $title = $this->title();
        $this->actingAs($this->user('marketing'))->get(route('title.show', $title->id))
            ->assertOk()->assertDontSee('Informasi Publikasi');
    }

    /** @test */
    public function duplicate_code_is_rejected(): void
    {
        Title::create(['title' => 'Lain', 'code' => 'DUP', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $title = $this->title();

        $this->actingAs($this->user('superadmin'))
            ->put(route('title.info.update', $title->id), ['code' => 'DUP'])
            ->assertSessionHasErrors('code');
    }
}
