<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AnnouncementDashboardTest extends TestCase
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
    public function published_announcement_shows_for_all_roles_draft_hidden(): void
    {
        Announcement::create(['title' => 'INFO PENTING', 'body' => '<p>isi</p>', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'DRAF RAHASIA', 'body' => '<p>x</p>', 'status' => 'draft']);

        foreach (['marketing', 'production', 'manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard'))
                ->assertOk()
                ->assertSee('INFO PENTING')
                ->assertDontSee('DRAF RAHASIA');
        }
    }

    /** @test */
    public function seen_endpoint_marks_read(): void
    {
        $u = $this->user('marketing');
        $a = Announcement::create(['title' => 'A', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($u)->post(route('announcement.seen'), ['ids' => [$a->id]])->assertNoContent();

        $this->assertDatabaseHas('tb_announcement_reads', ['announcement_id' => $a->id, 'user_id' => $u->id]);
    }

    /** @test */
    public function new_badge_shows_for_fresh_then_hidden_after_seen(): void
    {
        $u = $this->user('marketing');
        $a = Announcement::create(['title' => 'PENGUMUMAN SATU', 'body' => '<p>isi</p>', 'status' => 'published', 'published_at' => now()]);

        // Pengumuman baru & belum dibaca → badge "Baru" tampil.
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('bg-danger ms-1">Baru', false);

        // Setelah ditandai dibaca → badge "Baru" hilang.
        $this->actingAs($u)->post(route('announcement.seen'), ['ids' => [$a->id]])->assertNoContent();
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertDontSee('bg-danger ms-1">Baru', false);
    }
}
