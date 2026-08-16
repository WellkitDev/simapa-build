<?php
// tests/Feature/BookIsbnBerkasTest.php

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
 * Berkas ISBN (e-book & sertifikat) menumpang tb_manuscript_files lewat slot khusus,
 * supaya versi, pengunggah, dan tautan Drive datang gratis. Yang dijaga di sini:
 * slot itu TIDAK bocor ke kartu berkas halaman Detail Naskah, dan tidak menggerakkan
 * tahap naskah.
 */
class BookIsbnBerkasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-9', 'url' => 'https://drive/9']);
        });
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** Buku yang manuskripnya sudah di tahap ISBN, jadi isbnEligible() true. */
    private function buku(string $status = 'isbn'): Title
    {
        $book = Title::create([
            'title'       => 'Buku ISBN ' . fake()->unique()->word(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 1,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'bidang'          => 'buku',
            'started_at'      => now(),
        ]);

        return $book->fresh();
    }

    /** @test */
    public function empat_berkas_isbn_terdaftar_tapi_tidak_bocor_ke_berkas_naskah(): void
    {
        $this->assertSame(
            ['ebook', 'sertifikat_isbn', 'barcode_isbn', 'sertifikat_hki'],
            ManuscriptFile::slotsIsbn()
        );

        // Slot ISBN hidup di BERKAS_ISBN, BUKAN di SLOTS — SLOTS khusus tahap naskah.
        foreach (ManuscriptFile::slotsIsbn() as $slot) {
            $this->assertArrayNotHasKey($slot, ManuscriptFile::SLOTS, "{$slot} tak boleh jadi slot tahap naskah");
            $this->assertArrayNotHasKey($slot, ManuscriptFile::slotsFor(true));
            $this->assertArrayNotHasKey($slot, ManuscriptFile::slotsFor(false));
        }

        // Labelnya tetap bisa dibaca lewat slotLabel().
        $f = new ManuscriptFile(['slot' => 'barcode_isbn']);
        $this->assertSame('Barcode ISBN', $f->slotLabel());
    }

    /** @test */
    public function hanya_barcode_yang_wajib_dari_dua_berkas_baru(): void
    {
        $this->assertTrue(ManuscriptFile::BERKAS_ISBN['barcode_isbn']['wajibCetak']);
        $this->assertFalse(ManuscriptFile::BERKAS_ISBN['sertifikat_hki']['wajibCetak'],
            'Sertifikat HKI opsional — jangan diam-diam jadi wajib.');
    }

    /** @test */
    public function berkas_isbn_mengambil_versi_terbaru_per_slot(): void
    {
        $book = $this->buku();
        $isbn = BookIsbn::create([
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'link_terbit' => 'https://avidpedia.com/buku-1',
        ]);

        foreach ([1, 2] as $versi) {
            ManuscriptFile::create([
                'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
                'version' => $versi, 'original_name' => "ebook-v{$versi}.pdf",
                'drive_url' => "https://drive/e{$versi}", 'uploaded_by' => $this->user('admin')->id,
            ]);
        }

        $this->assertSame('ebook-v2.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertNull($isbn->berkas('sertifikat_isbn'));
        $this->assertSame('https://avidpedia.com/buku-1', $isbn->fresh()->link_terbit);
    }

    /** @test */
    public function unggahan_tersimpan_sebagai_berkas_isbn(): void
    {
        $book = $this->buku();

        $this->actingAs($this->user('admin'))
            ->post(route('isbn.store'), [
                'title_id'        => $book->id,
                'status'          => 'ber_isbn',
                'no_isbn'         => '978-602-1234-56-7',
                'link_terbit'     => 'https://avidpedia.com/buku-uji',
                'ebook'           => UploadedFile::fake()->create('buku.pdf', 200, 'application/pdf'),
                'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 50, 'application/pdf'),
            ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();
        $this->assertSame('buku.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertSame('sertifikat.pdf', $isbn->berkas('sertifikat_isbn')?->original_name);
        $this->assertSame('https://avidpedia.com/buku-uji', $isbn->link_terbit);
    }

    /**
     * Berkas ISBN bukan slot `masuk`, jadi tak boleh memicu maju tahap. Diuji lewat
     * service langsung, BUKAN lewat controller: menyimpan registrasi ISBN memang
     * memajukan tahap naskah lewat syncManuscript(), dan itu perilaku lama yang
     * tidak sedang diubah — mencampur keduanya akan menguji hal yang salah.
     *
     * @test
     */
    public function berkas_isbn_tidak_menggerakkan_tahap_naskah(): void
    {
        $book   = $this->buku('editing');
        $detail = $book->orderDetails()->first();

        app(\App\Services\ManuscriptFileService::class)->upload(
            $book,
            null,
            'ebook',
            UploadedFile::fake()->create('ebook.pdf', 10, 'application/pdf'),
            $this->user('admin')
        );

        $this->assertSame('editing', $detail->titleProgress->fresh()->status);
    }

    /** @test */
    public function unggah_ulang_menaikkan_versi_bukan_menimpa(): void
    {
        $book = $this->buku();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'ebook' => UploadedFile::fake()->create('v1.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();

        $this->actingAs($admin)->put(route('isbn.update', $isbn->id), [
            'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'ebook' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(2, $isbn->berkas('ebook')?->version);
        $this->assertSame('v2.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertSame(2, ManuscriptFile::where('title_id', $book->id)->where('slot', 'ebook')->count());
    }

    /** @test */
    public function marketing_bisa_mengunduh_dari_direktori_tanpa_tombol_kelola(): void
    {
        $book = $this->buku();
        BookIsbn::create([
            'title_id' => $book->id, 'status' => 'cetak', 'no_isbn' => '978-602-1',
            'link_terbit' => 'https://avidpedia.com/terbit-1',
        ]);
        ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'version' => 1, 'original_name' => 'ebook.pdf',
            'drive_url' => 'https://drive/ebook-1', 'uploaded_by' => $this->user('admin')->id,
        ]);

        $isi = $this->actingAs($this->user('marketing'))
            ->get(route('isbn.index'))->assertOk()->getContent();

        $this->assertStringContainsString('https://drive/ebook-1', $isi);
        $this->assertStringContainsString('https://avidpedia.com/terbit-1', $isi);
        $this->assertStringContainsString('978-602-1', $isi);
        // Diperiksa lewat tautannya, bukan kata "Kelola" — kata itu bisa muncul di
        // menu samping dan membuat assertion lolos/gagal karena alasan yang salah.
        $this->assertStringNotContainsString(route('title.show', $book->id), $isi,
            'Marketing tak berhak mengubah registrasi.');
    }

    /** @test */
    public function pengelola_tetap_melihat_tombol_kelola(): void
    {
        $book = $this->buku();
        BookIsbn::create(['title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-9']);

        $this->actingAs($this->user('admin'))
            ->get(route('isbn.index'))->assertOk()->assertSee('Kelola');
    }

    /** @test */
    public function direktori_menampilkan_keempat_kolom_berkas(): void
    {
        $book = $this->buku();
        \App\Models\BookIsbn::create([
            'title_id' => $book->id, 'status' => 'cetak', 'no_isbn' => '978-602-2',
        ]);
        foreach (['ebook' => 'e', 'sertifikat_isbn' => 's', 'barcode_isbn' => 'b', 'sertifikat_hki' => 'h'] as $slot => $tanda) {
            ManuscriptFile::create([
                'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => $slot,
                'version' => 1, 'original_name' => $slot . '.pdf',
                'drive_url' => 'https://drive/' . $tanda, 'uploaded_by' => $this->user('admin')->id,
            ]);
        }

        $isi = $this->actingAs($this->user('marketing'))
            ->get(route('isbn.index'))->assertOk()->getContent();

        foreach (['E-book', 'Sertifikat ISBN', 'Barcode ISBN', 'Sertifikat HKI'] as $label) {
            $this->assertStringContainsString($label, $isi);
        }
        foreach (['https://drive/e', 'https://drive/s', 'https://drive/b', 'https://drive/h'] as $url) {
            $this->assertStringContainsString($url, $isi);
        }
    }
}
