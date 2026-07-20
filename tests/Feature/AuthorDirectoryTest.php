<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AuthorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @return array{0:Author,1:Order} */
    private function authorWithOrder(): array
    {
        $author = Author::create([
            'name' => 'Budi Santoso', 'email' => 'budi@example.com',
            'phone' => '08123456789', 'affiliation' => 'Universitas Contoh',
        ]);
        $order = Order::factory()->create(['code_order' => 'ORD-AUTH-1', 'status' => 'pending']);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku Uji Author',
            'slug' => 'buku-uji-author-' . uniqid(), 'chapters' => 1, 'cost_amount' => 500000,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        return [$author, $order];
    }

    /** Tautkan sebuah author ke satu order milik $owner (order baru tiap panggilan). */
    private function linkAuthorToOrderOf(Author $author, User $owner, string $code): Order
    {
        $order = Order::factory()->create([
            'code_order' => $code, 'status' => 'pending', 'user_id' => $owner->id,
        ]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul ' . $code,
            'slug' => 'judul-' . uniqid(), 'chapters' => 1, 'cost_amount' => 500000,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        return $order;
    }

    private function makeAuthor(string $name): Author
    {
        return Author::create([
            'name' => $name, 'email' => \Illuminate\Support\Str::slug($name) . '@example.com',
            'phone' => '08120000000', 'affiliation' => 'Kampus ' . $name,
        ]);
    }

    /** @test */
    public function index_lists_authors_with_order_count(): void
    {
        [$author] = $this->authorWithOrder();

        $this->actingAs($this->user('superadmin'))->get(route('author.index'))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Universitas Contoh');

        $this->assertSame(1, (int) Author::withCount('orderDetails')->find($author->id)->order_details_count);
    }

    /** @test */
    public function show_displays_order_history(): void
    {
        [$author] = $this->authorWithOrder();

        $this->actingAs($this->user('manager'))->get(route('author.show', $author->id))
            ->assertOk()
            ->assertSee('Riwayat Order')
            ->assertSee('ORD-AUTH-1')
            ->assertSee('Buku Uji Author');
    }

    /** @test */
    public function disallowed_role_forbidden(): void
    {
        // Direktori Author dibatasi: production & accounting tidak boleh akses.
        $this->actingAs($this->user('accounting'))->get(route('author.index'))->assertForbidden();
        $this->actingAs($this->user('production'))->get(route('author.index'))->assertForbidden();
        $this->actingAs($this->user('production'))->get(route('author.show', 1))->assertForbidden();
    }

    /** @test */
    public function marketing_index_only_shows_authors_from_own_orders(): void
    {
        $mine = $this->user('marketing');
        $other = $this->user('marketing');

        $ownAuthor = $this->makeAuthor('Ada Lovelace');
        $this->linkAuthorToOrderOf($ownAuthor, $mine, 'ORD-MINE-1');

        $otherAuthor = $this->makeAuthor('Grace Hopper');
        $this->linkAuthorToOrderOf($otherAuthor, $other, 'ORD-OTHER-1');

        $this->actingAs($mine)->get(route('author.index'))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertDontSee('Grace Hopper');
    }

    /** @test */
    public function marketing_order_count_reflects_only_own_orders(): void
    {
        $mine = $this->user('marketing');
        $other = $this->user('marketing');

        $author = $this->makeAuthor('Ada Lovelace');
        $this->linkAuthorToOrderOf($author, $mine, 'ORD-MINE-1');
        $this->linkAuthorToOrderOf($author, $other, 'ORD-OTHER-1'); // tidak dihitung untuk $mine

        $response = $this->actingAs($mine)->get(route('author.index'))->assertOk();
        $authors = $response->viewData('authors');

        $this->assertSame(1, (int) $authors->firstWhere('id', $author->id)->order_details_count);
    }

    /** @test */
    public function superadmin_index_shows_all_authors(): void
    {
        $m = $this->user('marketing');
        $mineAuthor = $this->makeAuthor('Ada Lovelace');
        $this->linkAuthorToOrderOf($mineAuthor, $m, 'ORD-MINE-1');
        $otherAuthor = $this->makeAuthor('Grace Hopper');
        $this->linkAuthorToOrderOf($otherAuthor, $this->user('marketing'), 'ORD-OTHER-1');

        $this->actingAs($this->user('superadmin'))->get(route('author.index'))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Grace Hopper');
    }

    /** @test */
    public function marketing_show_forbidden_for_author_outside_own_orders(): void
    {
        $mine = $this->user('marketing');
        $otherAuthor = $this->makeAuthor('Grace Hopper');
        $this->linkAuthorToOrderOf($otherAuthor, $this->user('marketing'), 'ORD-OTHER-1');

        $this->actingAs($mine)->get(route('author.show', $otherAuthor->id))->assertNotFound();
    }

    /** @test */
    public function marketing_show_lists_only_own_orders(): void
    {
        $mine = $this->user('marketing');
        $other = $this->user('marketing');

        $author = $this->makeAuthor('Ada Lovelace');
        $this->linkAuthorToOrderOf($author, $mine, 'ORD-MINE-9');
        $this->linkAuthorToOrderOf($author, $other, 'ORD-OTHER-9');

        $this->actingAs($mine)->get(route('author.show', $author->id))
            ->assertOk()
            ->assertSee('ORD-MINE-9')
            ->assertDontSee('ORD-OTHER-9');
    }
}
