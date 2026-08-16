<?php
// tests/Feature/BookIsbnValidasiTest.php

namespace Tests\Feature;

use App\Models\BookIsbn;
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
 * Status Cetak/Terbit berarti buku sudah terbit dan datanya dipakai marketing untuk
 * melayani klien — jadi seluruh kolom wajib, kecuali Catatan yang sifatnya keterangan
 * bebas. Berkas yang sudah pernah diunggah dihitung terisi.
 */
class BookIsbnValidasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-3', 'url' => 'https://drive/3']);
        });
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u->fresh();
    }

    private function buku(): Title
    {
        $book = Title::create([
            'title' => 'Buku Validasi ' . fake()->unique()->word(), 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
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

    /** Payload lengkap untuk status cetak, minus kunci yang sengaja dihilangkan. */
    private function lengkap(Title $book, array $tanpa = []): array
    {
        $data = [
            'title_id'        => $book->id,
            'status'          => 'cetak',
            'no_pendaftaran'  => 'REG-001',
            'no_isbn'         => '978-602-1234-56-7',
            'no_buku_cetak'   => 'CTK-001',
            'penerbit'        => 'Avidpedia Press',
            'tgl_daftar'      => '2026-01-10',
            'tgl_isbn'        => '2026-02-10',
            'tgl_terbit'      => '2026-03-10',
            'link_terbit'     => 'https://avidpedia.com/buku-terbit',
            'ebook'           => UploadedFile::fake()->create('ebook.pdf', 20, 'application/pdf'),
            'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 20, 'application/pdf'),
            'barcode_isbn'    => UploadedFile::fake()->create('barcode.png', 5, 'image/png'),
        ];

        foreach ($tanpa as $k) {
            unset($data[$k]);
        }

        return $data;
    }

    /** @test */
    public function status_cetak_menolak_data_yang_belum_lengkap(): void
    {
        $kolomWajib = ['no_pendaftaran', 'no_isbn', 'no_buku_cetak', 'penerbit',
                       'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'link_terbit'];

        foreach ($kolomWajib as $kolom) {
            $book = $this->buku();

            $this->actingAs($this->admin())
                ->post(route('isbn.store'), $this->lengkap($book, [$kolom]))
                ->assertSessionHasErrors($kolom);

            $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
        }
    }

    /** @test */
    public function status_cetak_menolak_bila_berkas_belum_ada(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book, ['ebook']))
            ->assertSessionHasErrors('ebook');

        $book2 = $this->buku();
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book2, ['sertifikat_isbn']))
            ->assertSessionHasErrors('sertifikat_isbn');
    }

    /** @test */
    public function berkas_yang_sudah_pernah_diunggah_dihitung_terisi(): void
    {
        $book  = $this->buku();
        $admin = $this->admin();

        // Simpan dulu di Ber-ISBN lengkap dengan berkasnya.
        $this->actingAs($admin)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-602-1234-56-7',
            'ebook' => UploadedFile::fake()->create('ebook.pdf', 20, 'application/pdf'),
            'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 20, 'application/pdf'),
            'barcode_isbn' => UploadedFile::fake()->create('barcode.png', 5, 'image/png'),
        ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();

        // Naik ke Cetak/Terbit TANPA memilih berkas lagi — harus lolos.
        $this->actingAs($admin)->put(route('isbn.update', $isbn->id), [
            'status' => 'cetak', 'no_pendaftaran' => 'REG-001', 'no_isbn' => '978-602-1234-56-7',
            'no_buku_cetak' => 'CTK-001', 'penerbit' => 'Avidpedia Press',
            'tgl_daftar' => '2026-01-10', 'tgl_isbn' => '2026-02-10', 'tgl_terbit' => '2026-03-10',
            'link_terbit' => 'https://avidpedia.com/buku-terbit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cetak', $isbn->fresh()->status);
        $this->assertSame(1, ManuscriptFile::where('title_id', $book->id)->where('slot', 'ebook')->count());
    }

    /** @test */
    public function catatan_kosong_tidak_pernah_menghalangi(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'cetak']);
    }

    /** @test */
    public function status_selain_cetak_tetap_memakai_aturan_lama(): void
    {
        $book = $this->buku();

        // Ber-ISBN cukup dengan no_isbn — penerbit, tanggal, berkas boleh menyusul.
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), [
                'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-602-1234-56-7',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'ber_isbn']);
    }

    /** @test */
    public function status_cetak_menolak_bila_barcode_belum_ada(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book, ['barcode_isbn']))
            ->assertSessionHasErrors('barcode_isbn');

        $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
    }

    /** @test */
    public function sertifikat_hki_tidak_pernah_menghalangi(): void
    {
        $book = $this->buku();

        // lengkap() memang tak pernah menyertakan HKI — ia opsional selamanya.
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'cetak']);
    }

    /** @test */
    public function sertifikat_hki_tersimpan_bila_diunggah(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())->post(route('isbn.store'), array_merge(
            $this->lengkap($book),
            ['sertifikat_hki' => UploadedFile::fake()->create('hki.pdf', 15, 'application/pdf')]
        ))->assertSessionHasNoErrors();

        $isbn = \App\Models\BookIsbn::where('title_id', $book->id)->firstOrFail();
        $this->assertSame('hki.pdf', $isbn->berkas('sertifikat_hki')?->original_name);
    }

    /**
     * Unggahan yang gagal tak boleh meninggalkan registrasi Cetak/Terbit tanpa berkas —
     * keadaan yang justru dilarang aturan kelengkapan yang baru saja dipasang.
     *
     * @test
     */
    public function unggahan_gagal_tidak_meninggalkan_registrasi_tanpa_berkas(): void
    {
        // Drive menolak: uploadFile() mengembalikan null, service melempar.
        $this->mock(\App\Services\GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(null);
        });

        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
    }
}
