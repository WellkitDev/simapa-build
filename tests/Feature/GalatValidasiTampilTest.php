<?php
// tests/Feature/GalatValidasiTampilTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Galat validasi harus SAMPAI ke pengguna, di halaman mana pun.
 *
 * Selama ini `$errors` tidak pernah dirender di layout mana pun: setiap `validate()`
 * yang gagal hanya memuat ulang halaman tanpa keterangan apa pun, sehingga penolakan
 * yang sebenarnya wajar (format file salah, ukuran lewat batas, isian kosong) terbaca
 * pengguna sebagai "gagal tanpa sebab" — persis keluhan yang dilaporkan untuk unggah
 * naskah. Ditangani terpusat di `layouts/master.blade.php`, jadi berlaku untuk seluruh
 * halaman tanpa menyentuh view satu per satu.
 */
class GalatValidasiTampilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** @test */
    public function layout_utama_menyalurkan_galat_validasi_ke_pengguna(): void
    {
        $isi = file_get_contents(resource_path('views/layouts/master.blade.php'));

        $this->assertStringContainsString('$errors->any()', $isi,
            'layouts/master.blade.php harus menampilkan galat validasi — tanpa itu setiap '
            . 'validate() yang gagal terlihat seperti halaman termuat ulang tanpa sebab.');
        $this->assertStringContainsString('swalValidation', $isi,
            'Galat validasi disalurkan lewat kanal yang sama dengan flash lain.');
    }

    /** @test */
    public function galat_validasi_hanya_dirender_satu_kali(): void
    {
        // Dua mekanisme sekaligus = satu kesalahan muncul dua kali di layar.
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));

        $this->assertStringNotContainsString('$errors->all()', $sidebar,
            'Galat validasi sudah ditangani master; jangan dirender ulang di sidebar.');
    }

    /** @test */
    public function halaman_yang_dimuat_ulang_setelah_validasi_gagal_membawa_pesannya(): void
    {
        $pelaksana = $this->user('production');

        // Halaman naskah: unggah dengan format yang ditolak.
        $res = $this->actingAs($pelaksana)
            ->from(route('naskah.workdesk'))
            ->post(route('naskah.file', 1), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('gambar.png', 10),
            ]);

        $res->assertRedirect(route('naskah.workdesk'));
        $res->assertSessionHasErrors('file');

        // Halaman tujuan redirect benar-benar memuat pesannya, bukan diam saja.
        $this->actingAs($pelaksana)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('swalValidation', false);
    }

    /**
     * Halaman auth memakai layout terpisah (master2) dan menampilkan galat per-field
     * lewat @error, jadi tidak ikut kanal SweetAlert — dijaga di sini supaya kalau
     * penanda itu terhapus, ketahuan.
     *
     * @test
     */
    public function halaman_login_tetap_menampilkan_galat_per_field(): void
    {
        $isi = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString('@error', $isi);
        $this->assertStringContainsString('invalid-feedback', $isi);
    }

    /**
     * Penjaga menyeluruh: setiap view yang memakai layout utama otomatis kebagian,
     * jadi yang perlu dipastikan hanyalah tidak ada layout lain yang dipakai luas
     * tanpa penanganan galat.
     *
     * @test
     */
    public function tidak_ada_layout_lain_yang_dipakai_luas_tanpa_penanganan_galat(): void
    {
        $pemakaian = [];
        foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/*.blade.php')) as $view) {
            if (preg_match("/@extends\(['\"]([^'\"]+)['\"]\)/", (string) file_get_contents($view), $m)) {
                $pemakaian[$m[1]] = ($pemakaian[$m[1]] ?? 0) + 1;
            }
        }

        foreach ($pemakaian as $layout => $jumlah) {
            $path = resource_path('views/' . str_replace('.', '/', $layout) . '.blade.php');
            if (! is_file($path)) {
                continue;
            }

            $isi = (string) file_get_contents($path);
            $menangani = str_contains($isi, '$errors')
                || $layout === 'layouts.master2'; // auth: galat per-field di viewnya

            $this->assertTrue($menangani,
                "Layout {$layout} dipakai {$jumlah} view tapi tidak menampilkan galat validasi.");
        }
    }
}
