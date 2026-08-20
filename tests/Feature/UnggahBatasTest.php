<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batas unggahan: yang DIJANJIKAN aplikasi vs yang benar-benar diizinkan PHP.
 *
 * BATAS_KB = 20480 (20 MB) hanyalah plafon aplikasi. Yang menentukan lebih dulu
 * adalah `upload_max_filesize` dan `post_max_size` milik server — dan PHP menolak
 * berkas SEBELUM Laravel sempat menjalankan aturan `max:`, sehingga yang muncul ke
 * pengguna adalah pesan bawaan "The ebook failed to upload." tanpa satu pun petunjuk
 * bahwa penyebabnya batas server. Itulah yang terjadi pada PDF 2 MB di produksi.
 */
class UnggahBatasTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** Buku yang sudah mencapai tahap ISBN — syarat isbnEligible() di controller. */
    private function bukuLayakIsbn(): Title
    {
        $book = Title::create([
            'title' => 'Buku Batas ' . fake()->unique()->word(),
            'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 1,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'isbn',
            'assigned_role'   => TitleProgress::getHandlerForStatus('isbn'),
            'bidang'          => 'buku',
            'started_at'      => now(),
        ]);

        return $book->fresh();
    }

    /** @test */
    public function batas_efektif_tidak_pernah_melebihi_batas_php(): void
    {
        $efektif = ManuscriptFile::batasKb();

        $this->assertGreaterThan(0, $efektif);
        $this->assertLessThanOrEqual(ManuscriptFile::BATAS_KB, $efektif);

        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $batasPhp = ManuscriptFile::iniKeKb($ini);
            if ($batasPhp > 0) {
                $this->assertLessThanOrEqual($batasPhp, $efektif,
                    "Aturan validasi menjanjikan lebih besar dari yang diizinkan {$ini}.");
            }
        }
    }

    /** @test */
    public function aturan_validasi_memakai_batas_efektif_bukan_plafon_aplikasi(): void
    {
        $aturan = ManuscriptFile::rulesIsbn();

        $this->assertStringContainsString('max:' . ManuscriptFile::batasKb(), $aturan['ebook']);
    }

    /**
     * Mode gagal kedua: bila TOTAL body melebihi post_max_size, PHP membuang
     * $_POST dan $_FILES seluruhnya dan ValidatePostSize melempar 413 — halaman
     * galat telanjang yang tak menyebut ukuran, batas, maupun jalan keluar.
     * Formulir ISBN mengirim tiga slot berkas sekaligus, jadi yang menentukan
     * bukan ukuran satu berkas melainkan jumlah ketiganya.
     *
     * @test
     */
    public function body_yang_melebihi_post_max_size_dijelaskan_bukan_413_telanjang(): void
    {
        $jawab = $this->actingAs($this->user('superadmin'))->call(
            'POST', route('isbn.store'), [], [], [],
            [
                'CONTENT_TYPE'   => 'multipart/form-data; boundary=----uji',
                'CONTENT_LENGTH' => (string) (200 * 1024 * 1024),
            ]
        );

        $this->assertNotSame(413, $jawab->getStatusCode(),
            'Halaman 413 bawaan tak memberi tahu apa pun tentang ukuran unggahan.');

        $jawab->assertRedirect();
        $pesan = (string) (session('error') ?? session('errors')?->first());
        $this->assertStringContainsString('besar', mb_strtolower($pesan));
    }

    /** @test */
    public function post_biasa_tidak_ikut_terjaring_penjaga_ukuran(): void
    {
        $book = $this->bukuLayakIsbn();

        $this->actingAs($this->user('superadmin'))
            ->post(route('isbn.store'), [
                'title_id' => $book->id, 'status' => 'pendaftaran', 'no_pendaftaran' => 'REG-9',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id]);
    }

    /** @test */
    public function berkas_yang_ditolak_php_memberi_pesan_indonesia_yang_menyebut_batas(): void
    {
        $book = $this->bukuLayakIsbn();

        // Meniru penolakan PHP: berkas sampai ke Laravel dengan kode galat
        // UPLOAD_ERR_INI_SIZE, persis seperti saat melebihi upload_max_filesize.
        $ditolak = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'uji'), 'ebook.pdf', 'application/pdf',
            UPLOAD_ERR_INI_SIZE, true
        );

        $this->actingAs($this->user('superadmin'))
            ->post(route('isbn.store'), [
                'title_id' => $book->id, 'status' => 'pendaftaran',
                'no_pendaftaran' => 'REG-1', 'ebook' => $ditolak,
            ])
            ->assertSessionHasErrors('ebook');

        $pesan = session('errors')->first('ebook');

        $this->assertStringNotContainsString('failed to upload', $pesan,
            'Pesan bawaan Inggris tak memberi tahu apa pun yang bisa ditindaklanjuti.');
        $this->assertStringContainsString('batas', mb_strtolower($pesan));
        $this->assertStringContainsString(ManuscriptFile::batasManusia(), $pesan,
            'Pesan harus menyebut batas yang benar-benar berlaku di server ini.');
    }
}
