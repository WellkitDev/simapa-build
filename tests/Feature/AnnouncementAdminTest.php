<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AnnouncementAdminTest extends TestCase
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

    /** @test */
    public function admin_can_create_publish_archive_delete(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('announcement.index'))->assertOk();

        $this->actingAs($admin)->post(route('announcement.store'), [
            'title' => 'PENGUMUMAN UJI', 'body' => '<p>halo</p>', 'status' => 'published', 'is_pinned' => 1,
        ])->assertRedirect();

        $a = Announcement::where('title', 'PENGUMUMAN UJI')->first();
        $this->assertNotNull($a);
        $this->assertSame('published', $a->status);
        $this->assertNotNull($a->published_at);
        $this->assertTrue($a->is_pinned);

        $this->actingAs($admin)->post(route('announcement.status', $a->id), ['status' => 'archived'])->assertRedirect();
        $this->assertSame('archived', $a->fresh()->status);

        $this->actingAs($admin)->delete(route('announcement.destroy', $a->id))->assertRedirect();
        $this->assertDatabaseMissing('tb_announcements', ['id' => $a->id]);
    }

    /** @test */
    public function store_strips_script_tags(): void
    {
        $this->actingAs($this->user('superadmin'))->post(route('announcement.store'), [
            'title' => 'XSS', 'body' => '<p>ok</p><script>alert(1)</script>', 'status' => 'draft',
        ])->assertRedirect();

        $this->assertStringNotContainsString('<script>', Announcement::where('title', 'XSS')->first()->body);
    }

    /**
     * Penyaring lama hanya regex penghapus pasangan <script>/<style>. Isi
     * pengumuman dirender mentah di dashboard SEMUA user, jadi muatan yang
     * melewati regex itu berarti pengambilalihan sesi superadmin.
     *
     * @test
     */
    public function store_menetralkan_muatan_xss_yang_melewati_penyaring_lama(): void
    {
        $this->actingAs($this->user('superadmin'))->post(route('announcement.store'), [
            'title'  => 'XSS lanjut',
            'body'   => '<img src=x onerror="fetch(\'//penyerang\')">'
                      . '<svg onload="alert(1)"></svg>'
                      . '<a href="javascript:alert(2)">klik</a>',
            'status' => 'draft',
        ])->assertRedirect();

        $body = Announcement::where('title', 'XSS lanjut')->first()->body;

        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('onload', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('penyerang', $body);
        $this->assertStringContainsString('klik', $body, 'Teks yang sah harus tetap ada.');
    }

    /** @test */
    public function update_juga_menetralkan_muatan_xss(): void
    {
        $a = Announcement::create([
            'title' => 'Awal', 'body' => '<p>bersih</p>', 'status' => 'draft',
            'created_by' => $this->user('superadmin')->id,
        ]);

        $this->actingAs($this->user('superadmin'))->put(route('announcement.update', $a->id), [
            'title'  => 'Awal',
            'body'   => '<img src=x onerror="alert(1)">',
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertStringNotContainsString('onerror', $a->fresh()->body);
    }

    /** @test */
    public function non_admin_roles_cannot_access(): void
    {
        $this->actingAs($this->user('production'))->get(route('announcement.index'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->get(route('announcement.index'))->assertForbidden();
    }

    /** @test */
    public function all_roles_can_mark_seen(): void
    {
        $this->actingAs($this->user('marketing'))
            ->post(route('announcement.seen'), ['ids' => []])
            ->assertNoContent();
    }
}
