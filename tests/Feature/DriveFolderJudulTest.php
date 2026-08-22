<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Services\DriveJudulFolderService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Folder Drive per judul.
 *
 * Yang dijaga: nama folder tak pernah kembar, tiap slot mendarat di tempat yang benar,
 * dan — yang terpenting — kegagalan Drive TIDAK boleh menggagalkan unggahan.
 */
class DriveFolderJudulTest extends TestCase
{
    use RefreshDatabase;

    private function judul(?string $kode = 'MAMT'): Title
    {
        return Title::create([
            'title' => 'Judul ' . fake()->unique()->words(3, true),
            'code'  => $kode,
            'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
    }

    // ─── nama folder ───

    /** @test */
    public function nama_folder_menggabungkan_kode_dan_id(): void
    {
        $t = $this->judul('MAMT');

        $this->assertSame("MAMT-{$t->id}", DriveJudulFolderService::namaFolder($t));
    }

    /**
     * `tb_titles.code` ternyata PUNYA unique constraint, jadi kode kembar dicegah basis
     * data — bukan alasan memakai id seperti yang sempat dikira saat merancang.
     *
     * Alasan sebenarnya tetap berlaku dan justru lebih kuat: kode boleh KOSONG (7 dari
     * 75 judul produksi), dan beberapa judul tanpa kode akan berebut nama folder yang
     * sama persis. Id-lah yang memisahkan mereka.
     *
     * @test
     */
    public function beberapa_judul_tanpa_kode_tetap_dapat_folder_berbeda(): void
    {
        $a = $this->judul(null);
        $b = $this->judul(null);

        $this->assertNotSame(
            DriveJudulFolderService::namaFolder($a),
            DriveJudulFolderService::namaFolder($b)
        );
    }

    /**
     * Kosong dalam tiga bentuknya. `''` dan `'   '` diuji lewat satu baris yang sama:
     * kolase PADSPACE MariaDB menganggap keduanya nilai yang SAMA, jadi dua baris
     * bertetangga akan bertabrakan di unique index — bukan kelemahan kodenya.
     *
     * @test
     */
    public function judul_tanpa_kode_ditandai_mencolok(): void
    {
        $t = $this->judul(null);
        $this->assertSame("TANPA-KODE-{$t->id}", DriveJudulFolderService::namaFolder($t));

        foreach (['', '   '] as $kosong) {
            $t->forceFill(['code' => $kosong])->save();
            $this->assertSame("TANPA-KODE-{$t->id}", DriveJudulFolderService::namaFolder($t->fresh()));
        }
    }

    // ─── peta slot ───

    /** @test */
    public function tiap_slot_memetakan_ke_folder_yang_benar(): void
    {
        $harapan = [
            'masuk'           => 'Naskah/Masuk',
            'hasil_editing'   => 'Naskah/Hasil Editing',
            'revisi_minta'    => 'Naskah/Revisi',
            'revisi_hasil'    => 'Naskah/Revisi',
            'final'           => 'Naskah/Final',
            'hasil_layout'    => 'Naskah/Layout',
            'hasil_proofread' => 'Naskah/Proofread',
            'cover'           => 'Naskah/Cover',
            'loa'             => 'Jurnal',
            'ebook'           => 'Berkas ISBN',
            'barcode_isbn'    => 'Berkas ISBN',
            'sertifikat_hki'  => 'Berkas ISBN',
        ];

        foreach ($harapan as $slot => $jalur) {
            $this->assertSame($jalur, DriveJudulFolderService::jalurSlot($slot), "slot {$slot}");
        }
    }

    /**
     * `sertifikat_isbn` tak lagi ditulis kode mana pun — slot ISBN diringkas jadi tiga —
     * tapi berkas lama di produksi masih memakainya. Tanpa pemetaan ini berkas itu
     * mendarat di akar folder judulnya, terpisah dari saudaranya di Berkas ISBN.
     *
     * Tes ini ada supaya baris yang "kelihatan mati" itu tak dibersihkan orang yang
     * mencari slot tak terpakai dan tak tahu ada data lama yang bergantung padanya.
     *
     * @test
     */
    public function slot_pensiunan_tetap_mendarat_di_berkas_isbn(): void
    {
        $this->assertSame(
            'Berkas ISBN',
            DriveJudulFolderService::jalurSlot('sertifikat_isbn'),
            'Slot pensiunan masih dipakai berkas lama; pemetaannya tak boleh dicabut.'
        );
    }

    /**
     * Slot yang belum terpetakan mendarat di folder judul, bukan melempar. Berkas yang
     * salah tempat masih jauh lebih baik daripada berkas yang tak terunggah.
     *
     * @test
     */
    public function slot_tak_dikenal_tidak_melempar(): void
    {
        $this->assertNull(DriveJudulFolderService::jalurSlot('slot_yang_belum_ada'));

        $t = $this->judul();
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-judul');

        $this->assertSame('folder-judul',
            (new DriveJudulFolderService($drive))->folderSlot($t, 'slot_yang_belum_ada'));
    }

    // ─── id folder disimpan ───

    /** @test */
    public function id_folder_judul_disimpan_dan_dipakai_ulang(): void
    {
        $t = $this->judul();

        $drive = Mockery::mock(GoogleDriveService::class);
        // Folder judul dicari SEKALI saja; panggilan kedua sudah membaca kolom tersimpan.
        $drive->shouldReceive('getOrCreateFolderByPath')
            ->with(Mockery::pattern('/^MAMT-/'), Mockery::any())
            ->once()->andReturn('folder-judul-1');
        $drive->shouldReceive('getOrCreateFolderByPath')
            ->with('Naskah/Masuk', 'folder-judul-1')
            ->twice()->andReturn('folder-masuk');

        $svc = new DriveJudulFolderService($drive);

        $this->assertSame('folder-masuk', $svc->folderSlot($t, 'masuk'));
        $this->assertSame('folder-masuk', $svc->folderSlot($t->fresh(), 'masuk'));

        $this->assertSame('folder-judul-1', $t->fresh()->drive_folder_id);
    }

    // ─── kegagalan tak boleh menular ───

    /**
     * Orang yang mengunggah naskah 20 MB tak boleh ditolak karena persoalan tata folder.
     * Drive yang bermasalah harus menghasilkan berkas di folder aplikasi, bukan berkas
     * yang hilang.
     *
     * @test
     */
    public function kegagalan_drive_saat_menyiapkan_folder_mengembalikan_null_bukan_melempar(): void
    {
        $t = $this->judul();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')
            ->andThrow(new \RuntimeException('Drive sedang tak bisa dihubungi'));

        $this->assertNull((new DriveJudulFolderService($drive))->folderSlot($t, 'masuk'));
        $this->assertNull($t->fresh()->drive_folder_id, 'Kegagalan tak boleh menyimpan id palsu.');
    }

    /** @test */
    public function folder_judul_null_membuat_folder_slot_ikut_null(): void
    {
        $t = $this->judul();

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn(null);

        $this->assertNull((new DriveJudulFolderService($drive))->folderSlot($t, 'masuk'));
    }

    // ─── perintah pemindah ───

    /**
     * Perintah yang berbahaya secara bawaan adalah perintah yang suatu saat dijalankan
     * orang yang cuma ingin melihat.
     *
     * @test
     */
    public function perintah_tanpa_argumen_tidak_memindahkan_apa_pun(): void
    {
        $t = $this->judul();
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => 'masuk', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'naskah.docx', 'drive_file_id' => 'file-1',
        ]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldNotReceive('moveFile');
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('simapa:drive-rapikan')
            ->expectsOutputToContain('MODE INTIP')
            ->expectsOutputToContain('akan dipindahkan')
            ->assertSuccessful();
    }

    /** @test */
    public function berkas_yang_belum_ada_di_drive_dilewati(): void
    {
        $t = $this->judul();
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => 'masuk', 'status' => 'antre',
            'version' => 1, 'original_name' => 'antre.docx', 'drive_file_id' => null,
        ]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldNotReceive('moveFile');
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('simapa:drive-rapikan --apply')
            ->expectsOutputToContain('1 berkas belum ada di Drive')
            ->assertSuccessful();
    }

    /** @test */
    public function apply_memindahkan_lewat_ganti_induk(): void
    {
        $t = $this->judul();
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => 'masuk', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'naskah.docx', 'drive_file_id' => 'file-1',
        ]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-x');
        $drive->shouldReceive('moveFile')->with('file-1', 'folder-x')->once()->andReturn(true);
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->artisan('simapa:drive-rapikan --apply')
            ->expectsOutputToContain('1 dipindahkan')
            ->assertSuccessful();
    }

    // ─── pemanggil lama tak boleh tersenggol ───

    /**
     * Delapan pemanggil lama (struk pembayaran, refund, slip gaji, invoice, laporan
     * harian) memakai getOrCreateFolderByPath() TANPA induk, dan folder mereka sudah
     * berdiri di root Google Drive. Parameter baru menambah kemampuan, bukan mengubah
     * arti — tanda tangannya harus tetap menerima satu argumen.
     *
     * @test
     */
    public function get_or_create_folder_tetap_bisa_dipanggil_dengan_satu_argumen(): void
    {
        $r = new \ReflectionMethod(GoogleDriveService::class, 'getOrCreateFolderByPath');

        $this->assertSame(1, $r->getNumberOfRequiredParameters(),
            'Pemanggil lama mengoper satu argumen saja.');
        $this->assertTrue($r->getParameters()[1]->isDefaultValueAvailable());
        $this->assertNull($r->getParameters()[1]->getDefaultValue(),
            'Bawaannya null = mulai dari root Drive, persis perilaku lama.');
    }
}
