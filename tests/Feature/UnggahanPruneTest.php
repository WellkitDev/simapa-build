<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pembersihan salinan lokal berkas yang gagal diunggah.
 *
 * Job yang gagal SENGAJA meninggalkan berkasnya di disk supaya unggahan bisa diulang.
 * Tanpa pembersihan berkala, keputusan itu berubah jadi kebocoran: berkas naskah
 * 20 MB menumpuk di hosting sampai kuotanya habis.
 */
class UnggahanPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function berkas(string $status, string $path, int $umurHari): ManuscriptFile
    {
        $title = Title::create([
            'title' => 'Buku ' . fake()->unique()->word(), 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        Storage::disk('local')->put($path, 'isi');

        $berkas = ManuscriptFile::create([
            'title_id' => $title->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'status' => $status, 'version' => 1, 'original_name' => 'e.pdf',
            'local_path' => $path, 'uploaded_by' => User::factory()->create()->id,
        ]);

        // created_at tak ada di $fillable, jadi harus dipaksa — kalau lewat create()
        // ia diam-diam diabaikan dan setiap baris uji tampak baru dibuat.
        $berkas->forceFill(['created_at' => now()->subDays($umurHari)])->save();

        return $berkas->fresh();
    }

    /** @test */
    public function berkas_gagal_yang_sudah_lama_dibersihkan(): void
    {
        $lama = $this->berkas('gagal', 'unggahan-antre/lama.pdf', 30);

        $this->artisan('unggahan:prune')->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists('unggahan-antre/lama.pdf'));
        $this->assertNull($lama->fresh()->local_path,
            'Penunjuk ke berkas yang sudah tak ada membuat percobaan ulang gagal membingungkan.');
    }

    /** @test */
    public function berkas_gagal_yang_masih_baru_dibiarkan(): void
    {
        $this->berkas('gagal', 'unggahan-antre/baru.pdf', 2);

        $this->artisan('unggahan:prune')->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists('unggahan-antre/baru.pdf'),
            'Kegagalan karena gangguan sesaat masih pantas diberi kesempatan diulang.');
    }

    /** @test */
    public function berkas_yang_masih_antre_tak_pernah_disentuh(): void
    {
        // Antrean bisa tertahan lama kalau cron mati; menghapus berkasnya berarti
        // job yang akhirnya jalan kehilangan bahan dan gagal tanpa sebab yang jelas.
        $this->berkas('antre', 'unggahan-antre/antre.pdf', 60);

        $this->artisan('unggahan:prune')->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists('unggahan-antre/antre.pdf'));
    }
}
