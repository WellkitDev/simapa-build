<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ManuscriptFile;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Akses berkas naskah.
 *
 * Berkas naskah SENGAJA diunggah tanpa izin publik di Drive — beda dari struk dan
 * invoice. Naskah klien yang belum terbit tak boleh punya tautan yang bisa dibuka siapa
 * pun yang memegangnya. Konsekuensinya `drive_url` selalu ditolak Google, dan berkasnya
 * harus disalurkan lewat SiMAPA setelah izinnya diperiksa.
 */
class BerkasNaskahAksesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** @return array{0: Title, 1: TitleProgress, 2: \App\Models\TitleChapter} */
    private function buku(): array
    {
        $book = Title::create([
            'title' => 'Buku Berkas ' . fake()->unique()->words(2, true),
            'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        $bab = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $bab->progress()->create(['status' => 'pembuatan', 'started_at' => now()]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $book->title, 'title_id' => $book->id,
            'chapters' => 1, 'naskah_type' => 'dibuatkan',
        ]);
        $author = Author::create(['name' => 'Penulis', 'email' => uniqid() . '@uji.test']);
        $detail->authors()->attach($author->id, ['position' => 1]);
        $bab->authors()->attach($author->id, ['position' => 1]);

        $p = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'pembuatan',
            'assigned_role' => 'production', 'bidang' => 'buku', 'started_at' => now(),
        ]);

        return [$book->fresh(), $p, $bab];
    }

    private function berkas(Title $book, string $status = 'selesai', ?string $driveId = 'drive-1'): ManuscriptFile
    {
        return ManuscriptFile::create([
            'title_id'      => $book->id,
            'slot'          => 'masuk',
            'status'        => $status,
            'version'       => 1,
            'original_name' => 'naskah.docx',
            'drive_file_id' => $driveId,
            'drive_url'     => $driveId ? 'https://drive.google.com/file/d/' . $driveId . '/view' : null,
        ]);
    }

    // ─── penyaluran berkas ───

    /** @test */
    public function berkas_disalurkan_lewat_simapa_bukan_tautan_drive(): void
    {
        [$book] = $this->buku();
        $f = $this->berkas($book);

        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('streamFile')
                ->with('drive-1', 'naskah.docx', false)
                ->once()
                ->andReturn(response('isi berkas', 200));
        });

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.berkas', $f->id))
            ->assertOk()
            ->assertSee('isi berkas');
    }

    /** @test */
    public function parameter_unduh_memaksa_attachment(): void
    {
        [$book] = $this->buku();
        $f = $this->berkas($book);

        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('streamFile')
                ->with('drive-1', 'naskah.docx', true)
                ->once()
                ->andReturn(response('isi', 200));
        });

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.berkas', $f->id) . '?unduh=1')
            ->assertOk();
    }

    /** @test */
    public function berkas_yang_masih_antre_menolak_dengan_404(): void
    {
        [$book] = $this->buku();
        $f = $this->berkas($book, 'antre', null);

        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldNotReceive('streamFile');
        });

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.berkas', $f->id))
            ->assertNotFound();
    }

    /**
     * Drive yang menolak tak boleh menghasilkan halaman galat 500 — berkasnya memang
     * tak terbaca, dan itu 404.
     *
     * @test
     */
    public function kegagalan_drive_jadi_404_bukan_500(): void
    {
        [$book] = $this->buku();
        $f = $this->berkas($book);

        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('streamFile')->andThrow(new \RuntimeException('Drive menolak'));
        });

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.berkas', $f->id))
            ->assertNotFound();
    }

    /** @test */
    public function tamu_tak_bisa_mengambil_berkas(): void
    {
        [$book] = $this->buku();
        $f = $this->berkas($book);

        $this->get(route('naskah.berkas', $f->id))->assertRedirect();
    }

    // ─── tautan di layar ───

    /** @test */
    public function layar_naskah_menautkan_route_simapa_bukan_drive(): void
    {
        [$book, $p, $bab] = $this->buku();
        // shouldIgnoreMissing: halaman ini tak memanggil Drive, tapi ManuscriptFileService
        // menerimanya lewat konstruktor. Mock telanjang membuat panggilan tak terduga melempar.
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();

        $f = ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => $bab->id,
            'slot' => 'masuk', 'status' => 'selesai', 'version' => 1,
            'original_name' => 'bab1.docx', 'drive_file_id' => 'd1',
            'drive_url' => 'https://drive.google.com/file/d/d1/view',
        ]);

        $isi = $this->actingAs($this->user('superadmin'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString(route('naskah.berkas', $f->id), $isi);
        $this->assertStringNotContainsString('drive.google.com/file/d/d1', $isi,
            'Tautan Drive mentah tak boleh sampai ke peramban — berkasnya tak punya izin publik.');
    }

    /** @test */
    public function berkas_antre_tidak_ditautkan(): void
    {
        [$book, $p, $bab] = $this->buku();
        // shouldIgnoreMissing: halaman ini tak memanggil Drive, tapi ManuscriptFileService
        // menerimanya lewat konstruktor. Mock telanjang membuat panggilan tak terduga melempar.
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();

        $f = ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => $bab->id,
            'slot' => 'masuk', 'status' => 'antre', 'version' => 1,
            'original_name' => 'antre.docx',
        ]);

        $isi = $this->actingAs($this->user('superadmin'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('naskah.berkas', $f->id), $isi,
            'Berkas yang belum mendarat tak boleh ditautkan — tautannya cuma memuat ulang halaman.');
        $this->assertStringContainsString('(antre)', $isi);
    }

    // ─── izin unggah berkas final bab ───

    /** @test */
    public function produksi_bukan_pelaksana_tak_melihat_tombol_file_bab(): void
    {
        [$book, $p, $bab] = $this->buku();
        // shouldIgnoreMissing: halaman ini tak memanggil Drive, tapi ManuscriptFileService
        // menerimanya lewat konstruktor. Mock telanjang membuat panggilan tak terduga melempar.
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();

        $budi  = $this->user('production');
        $citra = $this->user('production');
        $bab->progress->update(['pelaksana_user_id' => $budi->id, 'status' => 'editing']);

        $isi = $this->actingAs($citra)
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('⬆ File bab', $isi,
            'Berkas bab milik orang lain tak boleh bisa ditimpa siapa saja.');
    }

    /** @test */
    public function pelaksana_bab_itu_sendiri_tetap_melihat_tombolnya(): void
    {
        [$book, $p, $bab] = $this->buku();
        // shouldIgnoreMissing: halaman ini tak memanggil Drive, tapi ManuscriptFileService
        // menerimanya lewat konstruktor. Mock telanjang membuat panggilan tak terduga melempar.
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();

        $budi = $this->user('production');
        $bab->progress->update(['pelaksana_user_id' => $budi->id, 'status' => 'editing']);

        $isi = $this->actingAs($budi)
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('⬆ File bab', $isi);
    }

    /** @test */
    public function pj_tetap_melihat_tombolnya_di_bab_mana_pun(): void
    {
        [$book, $p, $bab] = $this->buku();
        // shouldIgnoreMissing: halaman ini tak memanggil Drive, tapi ManuscriptFileService
        // menerimanya lewat konstruktor. Mock telanjang membuat panggilan tak terduga melempar.
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();

        $budi = $this->user('production');
        $bab->progress->update(['pelaksana_user_id' => $budi->id, 'status' => 'selesai']);

        $isi = $this->actingAs($this->user('superadmin'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('⬆ File bab', $isi,
            'PJ bertanggung jawab atas naskahnya — ia harus tetap bisa melengkapi berkas bab.');
    }
}
