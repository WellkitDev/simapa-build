<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderContact;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderEditGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function makeOrder(User $owner): Order
    {
        $order = Order::create([
            'code_order' => 'ORD-202608-0001',
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        OrderContact::create([
            'order_id' => $order->id, 'cp_phone' => '0811', 'cp_email' => 'cp@example.com',
        ]);

        return $order->fresh();
    }

    /** @test */
    public function edit_terbuka_sejak_order_dibuat(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))
            ->assertOk()->assertSee('Judul Uji');
    }

    /** @test */
    public function edit_tetap_terbuka_setelah_payment_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $payment = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000,
            'status' => 'paid', 'paid_at' => '2026-08-02',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'approved']);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))->assertOk();
    }

    /** @test */
    public function edit_ditolak_untuk_order_yang_dibatalkan(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->actingAs($owner)->get(route('order.book.edit', $order->code_order))
            ->assertForbidden();

        $this->actingAs($owner)->put(route('order.book.update', $order->id), [
            'type' => 'bk_mandiri', 'title_id' => 'Judul Lain', 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'issued_at' => '2026-08-01', 'cost_amount' => 1000000,
            'contact_phone' => '0811', 'contact_email' => 'cp@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ])->assertForbidden();
    }
}
