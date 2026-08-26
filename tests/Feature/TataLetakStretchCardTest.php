<?php
// tests/Feature/TataLetakStretchCardTest.php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `stretch-card` hanya sah untuk kolom berisi SATU kartu.
 *
 * Aturannya di `public/css/app.css`:
 *
 *     .stretch-card         { display: flex; align-items: stretch; }
 *     .stretch-card > .card { width: 100%; min-width: 100%; }
 *
 * `display:flex` tanpa `flex-direction` berarti ARAH BARIS. Kolom dengan dua kartu
 * karena itu menempatkan keduanya BERSEBELAHAN, masing-masing dipaksa selebar penuh —
 * jadi kartu kedua meluber keluar kolom dan menabrak kolom di sebelahnya.
 *
 * Ditemukan di /layanan/invoice/{id} 2026-08-26: kolom kiri memuat kartu invoice dan
 * kartu Riwayat Pembayaran, dan keduanya menindih blok Status Pengerjaan.
 *
 * Tes ini memindai SELURUH view, bukan cuma yang itu — kesalahan kelas ini tak
 * menimbulkan galat apa pun, hanya halaman yang terlihat rusak, jadi ia hanya ketahuan
 * kalau ada yang membukanya dan melapor.
 */
class TataLetakStretchCardTest extends TestCase
{
    /** @test */
    public function tak_ada_kolom_stretch_card_yang_memuat_lebih_dari_satu_kartu(): void
    {
        $pelanggar = [];

        foreach ($this->semuaView() as $berkas) {
            $isi = file_get_contents($berkas);

            if (! preg_match_all('/<div\b[^>]*\bstretch-card\b[^>]*>/', $isi, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$tag, $posisi]) {
                $jumlah = $this->hitungKartuAnakLangsung($isi, $posisi);
                if ($jumlah > 1) {
                    $baris = substr_count(substr($isi, 0, $posisi), "\n") + 1;
                    $pelanggar[] = sprintf(
                        '%s:%d memuat %d kartu',
                        str_replace(base_path() . DIRECTORY_SEPARATOR, '', $berkas),
                        $baris,
                        $jumlah
                    );
                }
            }
        }

        $this->assertSame([], $pelanggar, implode("\n", array_merge(
            ['Kolom `stretch-card` dengan lebih dari satu kartu akan menampilkan kartunya '
             . 'bersebelahan selebar penuh dan meluber menabrak kolom sebelahnya.',
             'Buang `stretch-card` dari kolomnya — kelas itu hanya untuk kolom berisi satu kartu.',
             ''],
            $pelanggar
        )));
    }

    /**
     * Hitung `<div class="card ...">` yang jadi ANAK LANGSUNG kolom yang dimulai di
     * `$mulai`. Kedalaman dilacak supaya kartu bersarang di dalam card-body tak ikut
     * terhitung — yang bermasalah hanya yang jadi flex item langsung.
     */
    private function hitungKartuAnakLangsung(string $isi, int $mulai): int
    {
        preg_match_all('/<div\b[^>]*>|<\/div>/', substr($isi, $mulai), $tag, PREG_OFFSET_CAPTURE);

        $depth = 0;
        $kartu = 0;

        foreach ($tag[0] as [$teks, $_]) {
            if (str_starts_with($teks, '</')) {
                if (--$depth === 0) {
                    break;
                }
                continue;
            }

            $depth++;
            if ($depth === 2 && preg_match('/class="[^"]*\bcard\b/', $teks)) {
                $kartu++;
            }
        }

        return $kartu;
    }

    /** @return array<int,string> */
    private function semuaView(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        $berkas = [];
        foreach ($iterator as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $berkas[] = $f->getPathname();
            }
        }

        // Kalau pemindaiannya sendiri tak menemukan berkas apa pun, tesnya lolos tanpa
        // menguji apa-apa — dan itu justru lebih buruk daripada gagal.
        $this->assertGreaterThan(100, count($berkas), 'Pemindaian view tidak menemukan berkas.');

        return $berkas;
    }
}
