<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\BookIsbn;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

class BookIsbnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-isbn', 'url' => 'https://drive/isbn']);
        });
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function bookAtStage(string $stage): Title
    {
        $book = Title::create(['title' => 'Buku ISBN ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-IS-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $stage, 'assigned_role' => 'production', 'started_at' => now()]);
        return $book;
    }

    /**
     * Payload lengkap untuk status Cetak/Terbit. Sejak seluruh kolom diwajibkan di
     * status itu, submission ala kadarnya tidak lagi mewakili "penyimpanan yang sah" —
     * yang diuji tetap sama, hanya masukannya yang menyusul aturan baru.
     */
    private function payloadCetak(Title $book): array
    {
        return [
            'title_id'        => $book->id,
            'status'          => 'cetak',
            'no_pendaftaran'  => 'REG-001',
            'no_isbn'         => '978-602-1234-56-7',
            'no_buku_cetak'   => 'BK-001',
            'penerbit'        => 'Avidpedia Press',
            'tgl_daftar'      => '2026-01-10',
            'tgl_isbn'        => '2026-02-10',
            'tgl_terbit'      => '2026-03-10',
            'link_terbit'     => 'https://avidpedia.com/buku-terbit',
            'ebook'           => UploadedFile::fake()->create('ebook.pdf', 20, 'application/pdf'),
            'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 20, 'application/pdf'),
            'barcode_isbn'    => UploadedFile::fake()->create('barcode.png', 5, 'image/png'),
        ];
    }

    /** @test */
    public function eligible_book_appears_in_directory_ineligible_hidden(): void
    {
        $eligible = $this->bookAtStage('isbn');
        $notYet   = $this->bookAtStage('editing');

        $this->actingAs($this->user('manager'))->get(route('isbn.index'))
            ->assertOk()
            ->assertSee($eligible->title)
            ->assertDontSee($notYet->title);
    }

    /** @test */
    public function production_registers_isbn_for_eligible_book(): void
    {
        $book = $this->bookAtStage('isbn');
        $prod = $this->user('production');

        $this->actingAs($prod)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-623-000-0', 'penerbit' => 'Avidpedia Press',
        ])->assertRedirect(route('title.show', $book->id));

        $isbn = BookIsbn::where('title_id', $book->id)->first();
        $this->assertNotNull($isbn);
        $this->assertSame('ber_isbn', $isbn->status);
        $this->assertSame('978-623-000-0', $isbn->no_isbn);
        $this->assertSame($prod->id, $isbn->created_by);
    }

    /** @test */
    public function store_rejected_for_ineligible_book(): void
    {
        $book = $this->bookAtStage('editing');
        $this->actingAs($this->user('production'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran',
        ])->assertForbidden();
        $this->assertSame(0, BookIsbn::where('title_id', $book->id)->count());
    }

    /** @test */
    public function duplicate_isbn_per_book_rejected(): void
    {
        $book = $this->bookAtStage('isbn');
        BookIsbn::create(['title_id' => $book->id, 'status' => 'pendaftaran']);

        $this->actingAs($this->user('production'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn',
        ]);

        $this->assertSame(1, BookIsbn::where('title_id', $book->id)->count());
    }

    /** @test */
    public function marketing_cannot_register(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('marketing'))->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'pendaftaran',
        ])->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function production_updates_and_deletes(): void
    {
        $book = $this->bookAtStage('isbn');
        $isbn = BookIsbn::create(['title_id' => $book->id, 'status' => 'pendaftaran']);

        $this->actingAs($this->user('production'))->put(route('isbn.update', $isbn->id), ['status' => 'ber_isbn', 'no_isbn' => '978-1'])
            ->assertRedirect(route('title.show', $book->id));
        $this->assertSame('ber_isbn', $isbn->fresh()->status);

        $this->actingAs($this->user('production'))->delete(route('isbn.destroy', $isbn->id))->assertRedirect();
        $this->assertNull(BookIsbn::find($isbn->id));
    }

    /** @test */
    public function panel_shows_form_when_eligible_and_note_when_not(): void
    {
        $eligible = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))->get(route('title.show', $eligible->id))
            ->assertOk()->assertSee('Registrasi ISBN')->assertSee('Simpan Registrasi ISBN');

        $notYet = $this->bookAtStage('editing');
        $this->actingAs($this->user('production'))->get(route('title.show', $notYet->id))
            ->assertOk()->assertSee('setelah manuskrip mencapai tahap ISBN');
    }

    /** @test */
    public function book_shows_isbn_card_but_not_publication_info(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))->get(route('title.show', $book->id))
            ->assertOk()
            ->assertSee('Registrasi ISBN')
            ->assertDontSee('Informasi Publikasi');
    }

    /** @test */
    public function article_shows_publication_info_but_not_isbn_card(): void
    {
        $article = Title::create(['title' => 'Artikel Publikasi ' . uniqid(), 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->actingAs($this->user('production'))->get(route('title.show', $article->id))
            ->assertOk()
            ->assertSee('Informasi Publikasi')
            ->assertDontSee('Registrasi ISBN');
    }

    /** @test */
    public function each_status_requires_its_matching_number_field(): void
    {
        $map = ['pendaftaran' => 'no_pendaftaran', 'ber_isbn' => 'no_isbn', 'cetak' => 'no_buku_cetak'];
        foreach ($map as $status => $field) {
            $book = $this->bookAtStage('isbn');
            $this->actingAs($this->user('production'))->from(route('title.show', $book->id))
                ->post(route('isbn.store'), ['title_id' => $book->id, 'status' => $status])
                ->assertSessionHasErrors($field);
            $this->assertSame(0, BookIsbn::where('title_id', $book->id)->count(), "record tak boleh dibuat saat {$field} kosong");
        }
    }

    /** @test */
    public function store_succeeds_when_cetak_payload_complete(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))->from(route('title.show', $book->id))
            ->post(route('isbn.store'), $this->payloadCetak($book))
            ->assertRedirect(route('title.show', $book->id))
            ->assertSessionHasNoErrors();
        $this->assertSame('cetak', BookIsbn::where('title_id', $book->id)->first()->status);
    }

    private function manuscriptStatus(Title $book): ?string
    {
        return $book->fresh()->load('orderDetails.titleProgress')->manuscriptStatus();
    }

    /** @test */
    public function ber_isbn_advances_manuscript_to_cetak(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))
            ->post(route('isbn.store'), ['title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-1']);
        $this->assertSame('cetak', $this->manuscriptStatus($book));
    }

    /** @test */
    public function cetak_advances_manuscript_to_terbit(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))
            ->post(route('isbn.store'), $this->payloadCetak($book));
        $this->assertSame('terbit', $this->manuscriptStatus($book));
    }

    /** @test */
    public function pendaftaran_does_not_advance_manuscript(): void
    {
        $book = $this->bookAtStage('isbn');
        $this->actingAs($this->user('production'))
            ->post(route('isbn.store'), ['title_id' => $book->id, 'status' => 'pendaftaran', 'no_pendaftaran' => 'REG-1']);
        $this->assertSame('isbn', $this->manuscriptStatus($book));
    }

    /** @test */
    public function sync_is_forward_only_never_regresses(): void
    {
        $book = $this->bookAtStage('isbn');
        $isbn = BookIsbn::create(['title_id' => $book->id, 'status' => 'cetak', 'no_buku_cetak' => 'BK-1']);
        // manuskrip dimajukan ke terbit lewat store-sync manual:
        app(\App\Services\ChapterManuscriptService::class)->advanceBookToStage($book, 'terbit', $this->user('superadmin'));
        $this->assertSame('terbit', $this->manuscriptStatus($book));

        // turunkan status ISBN ke ber_isbn (→cetak) — manuskrip TAK boleh mundur ke cetak
        $this->actingAs($this->user('production'))
            ->put(route('isbn.update', $isbn->id), ['status' => 'ber_isbn', 'no_isbn' => '978-2']);
        $this->assertSame('terbit', $this->manuscriptStatus($book));
    }
}
