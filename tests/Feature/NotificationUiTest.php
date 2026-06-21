<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class NotificationUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function notify(User $u, array $payload = []): void
    {
        $u->notify(new DatabaseNotification(array_merge([
            'category' => 'payment', 'title' => 'Tes', 'message' => 'Pesan tes',
            'url' => route('dashboard'), 'icon' => 'bell',
        ], $payload)));
    }

    /** @test */
    public function bell_shows_notification_in_navbar(): void
    {
        $u = $this->user('marketing');
        $this->notify($u, ['title' => 'Notif Penting']);

        $this->actingAs($u)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notif Penting');
    }

    /** @test */
    public function index_lists_only_own_notifications(): void
    {
        $me = $this->user('marketing');
        $other = $this->user('marketing');
        $this->notify($me, ['title' => 'Punya Saya']);
        $this->notify($other, ['title' => 'Punya Orang Lain']);

        $this->actingAs($me)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Punya Saya')
            ->assertDontSee('Punya Orang Lain');
    }

    /** @test */
    public function read_marks_as_read_and_redirects_to_url(): void
    {
        $u = $this->user('marketing');
        $this->notify($u, ['url' => route('profile')]);
        $id = $u->notifications()->first()->id;

        $this->actingAs($u)->post(route('notifications.read', $id))
            ->assertRedirect(route('profile'));

        $this->assertNotNull($u->fresh()->notifications()->first()->read_at);
    }

    /** @test */
    public function read_all_clears_unread(): void
    {
        $u = $this->user('marketing');
        $this->notify($u);
        $this->notify($u);

        $this->actingAs($u)->post(route('notifications.readAll'))->assertRedirect();

        $this->assertSame(0, $u->fresh()->unreadNotifications()->count());
    }
}
