<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migrasi yang berjalan sendiri lewat penjadwal.
 *
 * Server produksi tak punya terminal, jadi `php artisan migrate` tak bisa dijalankan
 * di sana. Sampai sekarang jalan keluarnya adalah memigrasikan salinan DB di lokal lalu
 * mengunggah dump — tujuh langkah manual yang harus benar semuanya.
 */
class MigrasiOtomatisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tanpa migrasi tertunda, perintahnya TIDAK bersuara.
     *
     * Ia dipanggil penjadwal berkali-kali sehari. Perintah yang menulis "tak ada yang
     * perlu dikerjakan" tiap kali akan menumbuhkan berkas log yang tak seorang pun mau
     * membacanya — dan satu-satunya baris yang penting akan tenggelam di dalamnya.
     *
     * @test
     */
    public function tanpa_migrasi_tertunda_perintahnya_diam(): void
    {
        // Dipanggil lewat Artisan::call, BUKAN $this->artisan(): yang kedua menulis ke
        // output tiruan, sehingga Artisan::output() tetap kosong apa pun yang terjadi
        // dan asersi atas isi log lolos semu. Penjadwal membaca stdout yang sama dengan
        // yang dikembalikan Artisan::call.
        $this->assertSame(0, Artisan::call('simapa:migrasi-otomatis'));

        $this->assertSame('', trim(Artisan::output()));
    }

    /**
     * Ada migrasi tertunda → dijalankan, dan hasilnya dicatat dengan namanya.
     *
     * Log yang cuma berkata "berhasil" tak bisa dipakai memeriksa apa pun. Yang perlu
     * terbaca adalah migrasi MANA yang mendarat.
     *
     * @test
     */
    public function migrasi_tertunda_dijalankan_dan_namanya_tercatat(): void
    {
        $berkas = database_path('migrations/2099_01_01_000000_create_tb_uji_migrasi_table.php');
        file_put_contents($berkas, <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        return new class extends Migration {
            public function up(): void { Schema::create('tb_uji_migrasi', fn (Blueprint $t) => $t->id()); }
            public function down(): void { Schema::dropIfExists('tb_uji_migrasi'); }
        };
        PHP);

        try {
            $this->assertSame(0, Artisan::call('simapa:migrasi-otomatis'));

            $keluaran = Artisan::output();
            $this->assertStringContainsString('2099_01_01_000000_create_tb_uji_migrasi_table', $keluaran);
            $this->assertStringContainsString('BERHASIL', $keluaran);
            $this->assertTrue(Schema::hasTable('tb_uji_migrasi'));
        } finally {
            Schema::dropIfExists('tb_uji_migrasi');
            @unlink($berkas);
        }
    }

    /**
     * Migrasi yang gagal harus BERSUARA, bukan diam-diam dilewati.
     *
     * Inilah satu-satunya alasan perintah ini boleh dipercaya berjalan tanpa ditonton:
     * kegagalannya meninggalkan jejak yang bisa dibaca, dan kode keluar bukan nol
     * supaya penjadwal tak menganggapnya sukses.
     *
     * @test
     */
    public function migrasi_yang_gagal_dicatat_dan_kode_keluarnya_bukan_nol(): void
    {
        // Migrasi yang pasti gagal: membuat tabel yang sudah ada.
        $berkas = database_path('migrations/2099_01_01_000001_create_tb_tasks_lagi_table.php');
        file_put_contents($berkas, <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        return new class extends Migration {
            public function up(): void { Schema::create('tb_tasks', fn (Blueprint $t) => $t->id()); }
            public function down(): void {}
        };
        PHP);

        try {
            $this->assertSame(1, Artisan::call('simapa:migrasi-otomatis'));
            $this->assertStringContainsString('GAGAL', Artisan::output());
        } finally {
            @unlink($berkas);
        }
    }
}
