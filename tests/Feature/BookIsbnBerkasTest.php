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
    public function slot_isbn_terdaftar_tapi_tidak_bocor_ke_berkas_naskah(): void
    {
        $this->assertSame(
            ['ebook', 'sertifikat_isbn'],
            ManuscriptFile::SLOTS_ISBN
        );
        $this->assertArrayHasKey('ebook', ManuscriptFile::SLOTS);
        $this->assertArrayHasKey('sertifikat_isbn', ManuscriptFile::SLOTS);

        // Kartu berkas Detail Naskah hanya menampilkan slotsFor(); slot ISBN tak boleh ikut.
        $this->assertArrayNotHasKey('ebook', ManuscriptFile::slotsFor(true));
        $this->assertArrayNotHasKey('sertifikat_isbn', ManuscriptFile::slotsFor(true));
        $this->assertArrayNotHasKey('ebook', ManuscriptFile::slotsFor(false));
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
}
