<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tombol "Kembali" pada halaman yang sebelumnya tak punya jalan pulang selain
 * tombol Back peramban.
 *
 * Yang diuji terutama bagian yang tak kelihatan benar-salahnya: partial memilih
 * tujuan dari halaman sebelumnya, dan halaman sebelumnya TIDAK selalu bisa
 * dipercaya — sesudah redirect galat validasi, ia menunjuk balik ke halaman ini
 * sendiri, sehingga tombolnya tampak rusak karena tak memindahkan ke mana-mana.
 */
class TombolKembaliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /** @test */
    public function halaman_tambah_data_punya_tombol_kembali_ke_gudang(): void
    {
        $isi = $this->actingAs($this->superadmin())
            ->get(route('data.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Kembali', $isi);
        $this->assertStringContainsString(route('data.index'), $isi);
    }

    /** @test */
    public function halaman_buat_slip_gaji_punya_tombol_kembali(): void
    {
        $isi = $this->actingAs($this->superadmin())
            ->get(route('salary.slip.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Kembali', $isi);
        $this->assertStringContainsString(route('salary.slip.index'), $isi);
    }

    /**
     * Rute sementara yang merender partial TANPA $ke, satu-satunya bentuk yang
     * memakai jalur "ikut halaman sebelumnya". Halaman ber-$ke tetap (data.create,
     * slip gaji) tak pernah menyentuh cabang ini, jadi mengujinya lewat halaman itu
     * hanya akan menguji hal lain sambil mengaku menguji ini.
     */
    private function rutePartialTanpaKe(): string
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/_uji-tombol-kembali', function () {
            return view('partials.tombol-kembali', ['cadangan' => route('data.index')]);
        });

        return url('/_uji-tombol-kembali');
    }

    /** @test */
    public function tanpa_ke_tujuan_mengikuti_halaman_sebelumnya(): void
    {
        $uji  = $this->rutePartialTanpaKe();
        $asal = route('naskah.workdesk');

        $isi = $this->actingAs($this->superadmin())
            ->withHeaders(['referer' => $asal])
            ->get($uji)->assertOk()->getContent();

        $this->assertStringContainsString($asal, $isi,
            'Halaman yang bisa dicapai dari beberapa arah harus pulang ke arah datangnya.');
    }

    /** @test */
    public function halaman_sebelumnya_yang_menunjuk_diri_sendiri_diabaikan(): void
    {
        $uji = $this->rutePartialTanpaKe();

        // Meniru keadaan sesudah redirect galat validasi: referer = halaman ini juga.
        $isi = $this->actingAs($this->superadmin())
            ->withHeaders(['referer' => $uji])
            ->get($uji)->assertOk()->getContent();

        $this->assertStringContainsString(route('data.index'), $isi,
            'Tombol tak boleh menunjuk balik ke halaman yang sedang dibuka.');
        $this->assertStringNotContainsString('href="' . $uji . '"', $isi);
    }
}
