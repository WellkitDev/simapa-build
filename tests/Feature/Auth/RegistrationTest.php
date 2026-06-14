<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    // NOTE: Public self-registration is disabled in this app (the register view
    // renders an "Access Denied" page and users are provisioned by admins via
    // User Management). The Breeze "new users can register" test was removed
    // because that flow is intentionally not supported here.
}
