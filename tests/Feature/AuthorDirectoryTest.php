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
        $this->actingAs($this->user('accounting'))->get(route('author.index'))->assertForbidden();
    }
}
