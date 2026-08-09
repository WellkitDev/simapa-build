<?php
// tests/Feature/NaskahIstilahTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kosakata modul Penugasan Naskah.
 *
 * Ini bukan soal selera: istilah "editor", "tracker", dan "aging" terbukti TIDAK
 * dikenali penggunanya (Fitri sampai bertanya "editor ni apa?"), dan itulah salah satu
 * akar kebingungan modul lama. Penjaga statis di sini mencegahnya menyelinap kembali
 * lewat perubahan kecil di kemudian hari.
 */
class NaskahIstilahTest extends TestCase
{
    use RefreshDatabase;

    /** Istilah terlarang di permukaan modul baru → penggantinya. */
    private const TERLARANG = [
        'editor'  => 'PJ / Pelaksana',
        'tracker' => 'Pelacakan Naskah',
        'aging'   => 'sudah X hari di tahap ini',
    ];

    private function berkasModul(): array
    {
        $base  = base_path();
        $paths = array_merge(
            glob("$base/resources/views/naskah/*.blade.php") ?: [],
            glob("$base/resources/views/naskah/partials/*.blade.php") ?: [],
            glob("$base/app/Http/Controllers/Pages/Naskah/*.php") ?: [],
            [
                "$base/app/Services/AssignmentService.php",
                "$base/app/Services/ChapterRollupService.php",
            ]
        );

        return array_filter($paths, 'is_file');
    }

    /** @test */
    public function modul_naskah_tidak_memakai_istilah_yang_tak_dikenali_pengguna(): void
    {
        $temuan = [];

        foreach ($this->berkasModul() as $path) {
            $isi = file_get_contents($path);
            foreach (array_keys(self::TERLARANG) as $kata) {
                if (preg_match('/\b' . $kata . '\b/i', $isi)) {
                    $temuan[] = basename($path) . ' memakai "' . $kata . '"'
                              . ' — pakai "' . self::TERLARANG[$kata] . '"';
                }
            }
        }

        $this->assertSame([], $temuan, implode("\n", $temuan));
    }

    /** @test */
    public function layar_naskah_memakai_bahasa_indonesia_yang_dikenali_tim(): void
    {
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(\App\Services\GoogleDriveService::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $isi = $this->actingAs($admin)->get(route('naskah.pelacakan'))->assertOk()->getContent();

        $this->assertStringContainsString('Pelacakan Naskah', $isi);
        $this->assertStringContainsString('PJ', $isi);
        $this->assertStringContainsString('Pelaksana', $isi);
    }

    /** @test */
    public function sidebar_menampilkan_seksi_naskah_dengan_nama_menu_yang_sama_untuk_semua_role(): void
    {
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(\App\Services\GoogleDriveService::class);

        // Produksi & admin: ketiga menu. Marketing: tanpa Meja Kerja (tak punya tugas naskah).
        foreach (['production', 'admin'] as $role) {
            $u = User::factory()->create();
            $u->assignRole($role);
            $this->actingAs($u)->get(route('dashboard'))->assertOk()
                ->assertSee('Meja Kerja Saya')
                ->assertSee('Pelacakan Naskah')
                ->assertSee('Arsip Naskah');
        }

        $mkt = User::factory()->create();
        $mkt->assignRole('marketing');
        $this->actingAs($mkt)->get(route('dashboard'))->assertOk()
            ->assertSee('Pelacakan Naskah')
            ->assertSee('Arsip Naskah')
            ->assertDontSee('Meja Kerja Saya');
    }
}
