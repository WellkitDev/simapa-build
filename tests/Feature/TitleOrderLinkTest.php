<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleOrderLinkTest extends TestCase
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

    private function bookPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'bk_mandiri', 'title_id' => 'Judul Order Buku', 'scope_id' => '',
            'chapters' => 3, 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 1000000,
            'contact_phone' => '08123', 'contact_email' => 'c@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ], $over);
    }

    /** @test */
    public function book_store_with_new_name_creates_order_title(): void
    {
        $mkt = $this->user('marketing');
        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload());

        $title = Title::where('title', 'Judul Order Buku')->first();
        $this->assertNotNull($title);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
        $this->assertSame('buku', $title->jenis);

        $detail = OrderDetail::where('title', 'Judul Order Buku')->first();
        $this->assertSame($title->id, $detail->title_id);
    }

    /** @test */
    public function book_store_with_existing_id_links_without_new_title(): void
    {
        $mkt = $this->user('marketing');
        $existing = Title::create(['title' => 'Judul Sudah Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'created_by' => $mkt->id]);

        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload(['title_id' => (string) $existing->id]));

        $this->assertSame(1, Title::count());
        $detail = OrderDetail::where('title_id', $existing->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('Judul Sudah Ada', $detail->title);
    }

    /** @test */
    public function journal_store_with_new_name_creates_article_title(): void
    {
        $mkt = $this->user('marketing');
        $payload = [
            'type' => 'at_kolab', 'title_id' => 'Artikel Order', 'scope_id' => '',
            'indexation' => 'sinta 2', 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 500000,
            'contact_phone' => '08123', 'contact_email' => 'j@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ];
        $this->actingAs($mkt)->post(route('order.journal.store'), $payload);

        $title = Title::where('title', 'Artikel Order')->first();
        $this->assertNotNull($title);
        $this->assertSame('artikel', $title->jenis);
        $this->assertSame('kolaborasi', $title->tipe_naskah);
    }

    /** @test */
    public function journal_update_relinks_title(): void
    {
        $mkt = $this->user('marketing');
        $order = Order::create(['code_order' => 'ORD-202607-0009', 'user_id' => $mkt->id, 'status' => 'pending', 'ordered_at' => '2026-07-02']);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'title' => 'Judul Lama',
            'slug' => 'judul-lama-' . $order->id, 'indexation' => 'sinta 3',
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 100,
        ]);
        \App\Models\OrderContact::create(['order_id' => $order->id, 'cp_phone' => '08', 'cp_email' => 'e@example.com']);

        $this->actingAs($mkt)->put(route('order.journal.update', $order->code_order), [
            'type' => 'at_kolab', 'title_id' => 'Judul Baru Relink', 'scope_id' => '',
            'indexation' => 'sinta 2', 'naskah_type' => 'mandiri', 'publication_type' => 'fastrack',
            'issued_at' => '2026-07-03', 'cost_amount' => 200,
            'contact_phone' => '09', 'contact_email' => 'e2@example.com',
            'authors' => [['name' => 'B', 'email' => 'b@example.com', 'position' => 1]],
        ])->assertRedirect();

        $detail->refresh();
        $title = Title::where('title', 'Judul Baru Relink')->first();
        $this->assertNotNull($title);
        $this->assertSame($title->id, $detail->title_id);
        $this->assertSame('Judul Baru Relink', $detail->title);
    }
}
