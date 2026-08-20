<?php

namespace Tests\Feature;

use App\Jobs\UnggahBerkasKeDrive;
use App\Models\BookIsbn;
use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Unggahan berkas kerja tak lagi menahan request.
 *
 * Sebelumnya ManuscriptFileService memanggil Google Drive di dalam request, jadi
 * halaman menggantung selama berkas 20 MB dikirim ke jaringan. Kini berkasnya
 * mendarat di disk server, barisnya dibuat berstatus 'antre', dan job yang
 * menyelesaikannya.
 *
 * Yang dijaga ketat: baris 'antre' TIDAK dihitung sebagai berkas yang sudah ada.
 * Kalau dihitung, order bisa berstatus Cetak/Terbit sementara unggahannya kemudian
 * gagal — persis keadaan yang assertBerkasLengkap() dibuat untuk mencegah.
 */
class UnggahAntreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    private function bukuLayakIsbn(): Title
    {
        $book = Title::create([
            'title' => 'Buku Antre ' . fake()->unique()->word(),
            'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 1,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'isbn',
            'assigned_role' => TitleProgress::getHandlerForStatus('isbn'),
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        return $book->fresh();
    }

    /** @test */
    public function unggahan_masuk_antrean_tanpa_memanggil_drive_di_dalam_request(): void
    {
        Queue::fake();
        // Kalau Drive tersentuh selama request, request-nya masih menunggu jaringan.
        $this->mock(GoogleDriveService::class)->shouldNotReceive('uploadFile');

        $book = $this->bukuLayakIsbn();

        $this->actingAs($this->user('superadmin'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran', 'no_pendaftaran' => 'REG-1',
            'ebook' => UploadedFile::fake()->create('naskah.pdf', 120, 'application/pdf'),
        ]);

        $berkas = ManuscriptFile::where('title_id', $book->id)->where('slot', 'ebook')->first();

        $this->assertNotNull($berkas, 'Baris berkas harus langsung ada supaya pengguna melihat prosesnya.');
        $this->assertSame('antre', $berkas->status);
        $this->assertNull($berkas->drive_url);
        $this->assertNotNull($berkas->local_path, 'Berkas harus tersimpan di disk: berkas sementara PHP lenyap saat worker jalan.');

        Queue::assertPushed(UnggahBerkasKeDrive::class);
    }

    /** @test */
    public function berkas_yang_masih_antre_belum_memenuhi_syarat_cetak(): void
    {
        Queue::fake();
        $book  = $this->bukuLayakIsbn();
        $super = $this->user('superadmin');

        // Ebook + barcode diunggah, tapi keduanya masih antre.
        $this->actingAs($super)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran', 'no_pendaftaran' => 'REG-2',
            'ebook'        => UploadedFile::fake()->create('e.pdf', 20, 'application/pdf'),
            'barcode_isbn' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'),
        ]);

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();

        $this->actingAs($super)->put(route('isbn.update', $isbn->id), [
            'title_id' => $book->id, 'status' => 'cetak',
            'no_pendaftaran' => 'REG-2', 'no_isbn' => '978-1', 'no_buku_cetak' => 'BC-1',
            'penerbit' => 'Avidpedia', 'tgl_daftar' => '2026-08-01', 'tgl_isbn' => '2026-08-02',
            'tgl_terbit' => '2026-08-03', 'link_terbit' => 'https://avidpedia.com/x',
        ])->assertSessionHasErrors('ebook');

        $this->assertSame('pendaftaran', $isbn->fresh()->status,
            'Status tak boleh berubah selagi berkasnya belum benar-benar terunggah.');
    }

    /** @test */
    public function job_yang_berhasil_mengisi_url_dan_menandai_selesai(): void
    {
        $book   = $this->bukuLayakIsbn();
        $berkas = ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'status' => 'antre', 'version' => 1, 'original_name' => 'e.pdf',
            'local_path' => 'unggahan-antre/uji.pdf', 'uploaded_by' => $this->user('admin')->id,
        ]);
        Storage::disk('local')->put('unggahan-antre/uji.pdf', 'isi');

        $this->mock(GoogleDriveService::class)
            ->shouldReceive('uploadFile')->once()
            ->andReturn(['id' => 'drive-1', 'url' => 'https://drive/e', 'name' => 'e.pdf']);

        (new UnggahBerkasKeDrive($berkas->id))->handle(app(GoogleDriveService::class));

        $berkas->refresh();
        $this->assertSame('selesai', $berkas->status);
        $this->assertSame('https://drive/e', $berkas->drive_url);
        $this->assertFalse(Storage::disk('local')->exists('unggahan-antre/uji.pdf'),
            'Salinan lokal harus dibersihkan supaya berkas 20 MB tak menumpuk di hosting.');
    }

    /** @test */
    public function job_yang_gagal_menandai_gagal_dan_memberi_notifikasi(): void
    {
        $book       = $this->bukuLayakIsbn();
        $pengunggah = $this->user('admin');
        $berkas     = ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'status' => 'antre', 'version' => 1, 'original_name' => 'e.pdf',
            'local_path' => 'unggahan-antre/gagal.pdf', 'uploaded_by' => $pengunggah->id,
        ]);

        (new UnggahBerkasKeDrive($berkas->id))->failed(new \RuntimeException('token kedaluwarsa'));

        $berkas->refresh();
        $this->assertSame('gagal', $berkas->status);
        $this->assertStringContainsString('token', (string) $berkas->upload_error);
        $this->assertSame(1, $pengunggah->notifications()->count(),
            'Kegagalan yang tak diberitahukan sama saja dengan gagal senyap.');
    }
}
