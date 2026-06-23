<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AnnouncementServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AnnouncementService();
    }

    /** @test */
    public function for_dashboard_returns_published_ordered_pinned_then_recent(): void
    {
        $u = User::factory()->create();
        Announcement::create(['title' => 'Draf', 'body' => 'x', 'status' => 'draft']);
        Announcement::create(['title' => 'Lama', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(2)]);
        Announcement::create(['title' => 'Dipin', 'body' => 'x', 'status' => 'published', 'is_pinned' => true, 'published_at' => now()->subDay()]);

        $rows = $this->svc->forDashboard($u);

        $this->assertCount(2, $rows);           // draft dikecualikan
        $this->assertSame('Dipin', $rows[0]['title']); // pinned dulu
        $this->assertSame('Lama', $rows[1]['title']);
    }

    /** @test */
    public function is_new_reflects_recency_and_read_state(): void
    {
        $u = User::factory()->create();
        $fresh = Announcement::create(['title' => 'Baru', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'Lawas', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDays(5)]);

        $rows = $this->svc->forDashboard($u);
        $this->assertTrue($rows->firstWhere('title', 'Baru')['is_new']);
        $this->assertFalse($rows->firstWhere('title', 'Lawas')['is_new']);

        $this->svc->markSeen($u, [$fresh->id]);
        $rows = $this->svc->forDashboard($u);
        $this->assertFalse($rows->firstWhere('title', 'Baru')['is_new']);
    }

    /** @test */
    public function mark_seen_is_idempotent(): void
    {
        $u = User::factory()->create();
        $a = Announcement::create(['title' => 'A', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

        $this->svc->markSeen($u, [$a->id]);
        $this->svc->markSeen($u, [$a->id]);

        $this->assertSame(1, AnnouncementRead::where(['announcement_id' => $a->id, 'user_id' => $u->id])->count());
    }

    /** @test */
    public function publish_sets_published_at_once(): void
    {
        $a = Announcement::create(['title' => 'A', 'body' => 'x', 'status' => 'draft']);

        $this->svc->publish($a);
        $first = $a->fresh()->published_at;
        $this->assertNotNull($first);

        $this->svc->archive($a);
        $this->svc->publish($a);
        $this->assertSame($first->format('Y-m-d H:i:s'), $a->fresh()->published_at->format('Y-m-d H:i:s'));
    }
}
