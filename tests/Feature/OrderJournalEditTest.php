<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderContact;
use App\Models\Author;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class OrderJournalEditTest extends TestCase
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

    private function journalOrder(User $u): Order
    {
        $order = Order::create([
            'code_order' => 'ORD-TEST-0001', 'user_id' => $u->id, 'status' => 'pending',
            'note' => 'awal', 'ordered_at' => today()->toDateString(),
        ]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'title' => 'Judul Lama',
            'slug' => 'judul-lama-' . $order->id, 'indexation' => 'Scopus',
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
        OrderContact::create(['order_id' => $order->id, 'cp_phone' => '0811', 'cp_email' => 'cp@example.com']);
        $author = Author::create(['name' => 'Penulis A', 'email' => 'a@example.com', 'phone' => '0812', 'affiliation' => 'Univ']);
        $detail->authors()->attach($author->id, ['position' => 1]);

        return $order;
    }

    /** @test */
    public function marketing_can_open_and_update_journal_order(): void
    {
        $u = $this->user('marketing');
        $order = $this->journalOrder($u);

        $this->actingAs($u)->get(route('order.journal.edit', $order->code_order))
            ->assertOk()->assertSee('Judul Lama');

        $this->actingAs($u)->put(route('order.journal.update', $order->code_order), [
            'type' => 'at_kolab', 'title' => 'Judul Baru', 'scope_id' => '',
            'indexation' => 'Scopus', 'naskah_type' => 'mandiri', 'publication_type' => 'fastrack',
            'issued_at' => today()->toDateString(), 'cost_amount' => 2000000,
            'contact_phone' => '0899', 'contact_email' => 'new@example.com',
            'authors' => [['name' => 'Penulis B', 'email' => 'b@example.com', 'phone' => '0813', 'affiliation' => 'Univ2', 'position' => 1]],
            'note' => 'updated',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('Judul Baru', $order->details->title);
        $this->assertSame('fastrack', $order->details->publication_type);
        $this->assertSame('new@example.com', $order->contact->cp_email);
    }

    /** @test */
    public function unauthorized_role_cannot_edit_journal(): void
    {
        $this->actingAs($this->user('production'))
            ->get(route('order.journal.edit', 'ORD-TEST-0001'))->assertForbidden();
    }
}
