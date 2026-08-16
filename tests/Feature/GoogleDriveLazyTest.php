<?php
// tests/Feature/GoogleDriveLazyTest.php

namespace Tests\Feature;

use App\Http\Controllers\Pages\OrderBookController;
use App\Services\GoogleDriveService;
use Tests\TestCase;

/**
 * GoogleDriveService dulu berotentikasi di constructor-nya. Karena ia disuntik ke
 * constructor enam controller, SETIAP pemuatan Daftar Order, Pembayaran, Profil,
 * Manajemen User, Submission Jurnal, dan Laporan Harian membayar round-trip OAuth
 * ~220 ms sebelum satu baris kode halaman berjalan — termasuk halaman yang tak
 * pernah menyentuh berkas.
 *
 * Ambangnya sengaja longgar (50 ms lawan ~220 ms terukur): selisihnya terlalu besar
 * untuk salah baca, bahkan di mesin yang sedang sibuk.
 */
class GoogleDriveLazyTest extends TestCase
{
    private const AMBANG_MS = 50;

    private function msUntuk(callable $fn): float
    {
        $t = microtime(true);
        $fn();

        return (microtime(true) - $t) * 1000;
    }

    /** @test */
    public function membangun_service_tidak_menyentuh_jaringan(): void
    {
        $ms = $this->msUntuk(fn () => new GoogleDriveService());

        $this->assertLessThan(self::AMBANG_MS, $ms,
            "Konstruksi memakan {$ms} ms — otentikasi masih terjadi di constructor.");
    }

    /** @test */
    public function controller_yang_menyuntiknya_ikut_bebas(): void
    {
        $ms = $this->msUntuk(fn () => app(OrderBookController::class));

        $this->assertLessThan(self::AMBANG_MS, $ms,
            "Resolusi OrderBookController memakan {$ms} ms — halaman Daftar Order masih menunggu Google.");
    }

    /** @test */
    public function service_dibagikan_satu_instance_per_request(): void
    {
        $this->assertSame(
            app(GoogleDriveService::class),
            app(GoogleDriveService::class),
            'Tanpa singleton, tiap pemakaian di satu request membayar otentikasi lagi.'
        );
    }

    /** @test */
    public function gagal_otentikasi_mengembalikan_null_bukan_melempar(): void
    {
        // Tanpa refresh token, client() melempar — uploadFile() harus tetap menelannya
        // jadi null, karena ManuscriptFileService mengandalkan itu untuk pesan Indonesia.
        config(['filesystems.disks.google.refreshToken' => null]);

        $hasil = (new GoogleDriveService())->uploadFile(
            \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')
        );

        $this->assertNull($hasil);
    }
}
