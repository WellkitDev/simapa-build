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
use Spatie\Permission\Models\Role;

class BookIsbnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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
        ])->assertForbidden();
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
}
