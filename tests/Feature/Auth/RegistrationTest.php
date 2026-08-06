<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pendaftaran mandiri TIDAK didukung aplikasi ini: user dibuatkan admin lewat
 * Manajemen User. Sebelumnya hanya VIEW-nya yang diganti halaman "Access Denied",
 * sementara endpoint POST-nya tetap hidup — tetap membuat akun lalu me-login-kan
 * pembuatnya, jadi siapa pun di internet bisa masuk. Route-nya kini dicabut
 * seluruhnya; test ini menjaga agar tidak dihidupkan lagi tanpa sengaja.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** Tidak ada lagi formulir pendaftaran: URL tak dikenal jatuh ke Route::fallback. */
    public function test_registration_screen_is_gone(): void
    {
        $this->get('/register')->assertRedirect('/');
    }

    /** Tidak ada handler POST sama sekali — 405, bukan sekadar view yang diganti. */
    public function test_registration_endpoint_cannot_create_an_account(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Penyusup',
            'email'                 => 'penyusup@example.com',
            'password'              => 'Passw0rd!secret',
            'password_confirmation' => 'Passw0rd!secret',
        ]);

        $response->assertMethodNotAllowed();
        $this->assertDatabaseMissing('users', ['email' => 'penyusup@example.com']);
        $this->assertGuest();
    }
}
