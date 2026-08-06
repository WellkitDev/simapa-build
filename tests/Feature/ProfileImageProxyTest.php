<?php
// tests/Feature/ProfileImageProxyTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `profile.image` meneruskan ID apa pun ke Google Drive tanpa memeriksa bahwa
 * ID itu memang foto profil. Drive yang sama menyimpan naskah, bukti bayar,
 * LOA, dan lampiran laporan harian — jadi endpoint ini adalah pembaca isi Drive
 * perusahaan untuk siapa pun yang punya sesi. Kini ID wajib benar-benar
 * terdaftar sebagai foto profil.
 */
class ProfileImageProxyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function id_yang_bukan_foto_profil_ditolak(): void
    {
        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldNotReceive('getImageStream');

        $this->actingAs(User::factory()->create())
            ->get(route('profile.image', 'IdNaskahRahasia1234567890'))
            ->assertNotFound();
    }

    /** @test */
    public function foto_profil_yang_sah_tetap_tampil(): void
    {
        $pemilik = User::factory()->create();
        UserProfile::create([
            'user_id'            => $pemilik->id,
            'profile_picture_id' => 'FotoProfilSah1234567890',
        ]);

        $drive = $this->mock(GoogleDriveService::class);
        $drive->shouldReceive('getImageStream')
            ->once()
            ->with('FotoProfilSah1234567890')
            ->andReturn(response('gambar', 200));

        $this->actingAs(User::factory()->create())
            ->get(route('profile.image', 'FotoProfilSah1234567890'))
            ->assertOk();
    }

    /** @test */
    public function tamu_tidak_bisa_mengakses_proxy(): void
    {
        $this->mock(GoogleDriveService::class)->shouldNotReceive('getImageStream');

        $this->get(route('profile.image', 'FotoProfilSah1234567890'))
            ->assertRedirect(route('login'));
    }
}
