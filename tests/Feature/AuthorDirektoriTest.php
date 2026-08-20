<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Direktori Author: kolom Affiliasi tak boleh merentang tanpa batas.
 *
 * Tabelnya berkelas `nowrap` (bawaan DataTables di aplikasi ini), yang memaksa
 * tiap sel jadi satu baris. Afiliasi terpanjang di data nyata 111 karakter, jadi
 * tanpa pembatas ia merentang ratusan piksel dan mendorong kolom Email, Telp, dan
 * Aksi keluar layar.
 */
class AuthorDirektoriTest extends TestCase
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
    public function kolom_afiliasi_dibatasi_lebarnya(): void
    {
        Author::create([
            'name'        => 'Budi Santoso',
            'affiliation' => 'Prodi Pendidikan Teknologi Informasi Fakultas Keguruan dan Ilmu '
                . 'Pendidikan Universitas Muhammadiyah Muara Bungo',
        ]);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('author.index'))->assertOk()->getContent();

        $this->assertStringContainsString('dt-afiliasi', $isi,
            'Sel Affiliasi harus memakai pembatas lebar, kalau tidak `nowrap` merentangkannya.');
    }

    /** @test */
    public function afiliasi_panjang_tetap_tampil_utuh_tidak_dipotong(): void
    {
        $panjang = 'Prodi Pendidikan Teknologi Informasi Fakultas Keguruan dan Ilmu '
            . 'Pendidikan Universitas Muhammadiyah Muara Bungo';

        Author::create(['name' => 'Siti Aminah', 'affiliation' => $panjang]);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('author.index'))->assertOk()->getContent();

        // Dibungkus, bukan dipotong: nama universitasnya ada di BELAKANG, jadi
        // memotong di tengah justru membuang bagian yang membedakan orang.
        //
        // Diperiksa DI DALAM selnya, bukan di seluruh halaman: "..." muncul juga di
        // tempat lain (paginasi DataTables), jadi mencarinya di seluruh HTML hanya
        // menghasilkan kegagalan palsu.
        $this->assertMatchesRegularExpression(
            '/<td class="dt-afiliasi">\s*' . preg_quote($panjang, '/') . '\s*<\/td>/',
            $isi,
            'Afiliasi harus utuh di dalam selnya, tanpa Str::limit.'
        );
    }
}
