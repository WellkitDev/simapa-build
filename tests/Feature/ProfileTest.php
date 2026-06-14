<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ProfileController's constructor injects GoogleDriveService — avoid real API calls.
        $this->mock(GoogleDriveService::class);
    }

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name'     => 'Test User',
            'username' => 'test_user_' . $user->id,
            'email'    => 'test@example.com',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test_user_' . $user->id, $user->username);
        $this->assertSame('test@example.com', $user->email);
    }
}
