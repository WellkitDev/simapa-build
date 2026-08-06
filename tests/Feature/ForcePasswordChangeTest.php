<?php
// tests/Feature/ForcePasswordChangeTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin mencentang "wajib ganti password" saat membuatkan akun, dan password
 * sementaranya dikirim polos lewat surel. Bendera itu tersimpan di basis data
 * tapi TIDAK PERNAH dibaca middleware mana pun — jadi password sementara
 * tersebut berlaku selamanya, mengendap permanen di kotak masuk.
 */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
    }

    /** @test */
    public function user_berbendera_dialihkan_dari_halaman_lain(): void
    {
        $user = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile'));
    }

    /** @test */
    public function halaman_profil_tetap_terbuka_agar_tidak_terkunci(): void
    {
        $user = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($user)->get(route('profile'))->assertOk();
    }

    /** @test */
    public function mengganti_password_mencabut_bendera_dan_membuka_akses(): void
    {
        $user = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'password',
            'password'              => 'PasswordBaru123!',
            'password_confirmation' => 'PasswordBaru123!',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->force_password_change, 'Bendera harus tercabut.');

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    /** @test */
    public function user_tanpa_bendera_tidak_terganggu(): void
    {
        $user = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
