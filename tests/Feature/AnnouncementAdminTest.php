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

    /** @test */
    public function non_admin_roles_cannot_access(): void
    {
        $this->actingAs($this->user('production'))->get(route('announcement.index'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->get(route('announcement.index'))->assertForbidden();
    }
}
