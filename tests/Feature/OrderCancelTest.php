<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** @test */
    public function kolom_pembatalan_dan_soft_delete_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_orders', ['cancel_reason', 'cancelled_by', 'cancelled_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumn('tb_order_details', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('tb_title_progress', 'deleted_at'));
    }

    /** @test */
    public function tiga_model_memakai_soft_deletes(): void
    {
        $owner  = $this->user('marketing');
        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id]);
        $prog   = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        $order->delete();
        $detail->delete();
        $prog->delete();

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detail->id]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $prog->id]);
        $this->assertNull(Order::find($order->id));
        $this->assertNull(OrderDetail::find($detail->id));
        $this->assertNull(TitleProgress::find($prog->id));
    }

    /** @test */
    public function field_pembatalan_bisa_diisi_dan_cancelled_at_ter_cast(): void
    {
        $owner = $this->user('marketing');
        $order = Order::factory()->create([
            'user_id'       => $owner->id,
            'cancel_reason' => 'Salah input harga',
            'cancelled_by'  => $owner->id,
            'cancelled_at'  => '2026-08-03 10:00:00',
        ]);

        $fresh = $order->fresh();
        $this->assertSame('Salah input harga', $fresh->cancel_reason);
        $this->assertSame($owner->id, $fresh->cancelled_by);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->cancelled_at);
    }

    /** Order + detail + progress lengkap, milik $owner. */
    private function makeOrder(User $owner, string $code = 'ORD-202608-0001'): Order
    {
        $order = Order::create([
            'code_order' => $code,
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        $detail = OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        return $order->fresh();
    }

    /** Payment `paid` + approval berstatus $approvalStatus (seperti alur submit sungguhan). */
    private function addPayment(Order $order, string $approvalStatus = 'pending', int $amount = 500000): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => 'dp',
            'amount'       => $amount,
            'status'       => 'paid',
            'paid_at'      => '2026-08-02',
        ]);

        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approvalStatus]);

        return $payment;
    }

    /** @test */
    public function gerbang_batal_membaca_approval_bukan_status_payment(): void
    {
        $owner = $this->user('marketing');

        $polos = $this->makeOrder($owner, 'ORD-202608-0001');
        $this->assertTrue($polos->isCancellable());
        $this->assertTrue($polos->isEditable());
        $this->assertFalse($polos->hasApprovedPayment());

        // Bukti bayar sudah diunggah (payment 'paid') tapi approval MASIH pending →
        // tetap boleh dibatalkan. Inilah kasus yang paling butuh dibatalkan.
        $menunggu = $this->makeOrder($owner, 'ORD-202608-0002');
        $this->addPayment($menunggu, 'pending');
        $this->assertFalse($menunggu->fresh()->hasApprovedPayment());
        $this->assertTrue($menunggu->fresh()->isCancellable());

        // Approval sudah 'approved' → Batal tertutup, Edit TETAP terbuka.
        $disetujui = $this->makeOrder($owner, 'ORD-202608-0003');
        $this->addPayment($disetujui, 'approved');
        $this->assertTrue($disetujui->fresh()->hasApprovedPayment());
        $this->assertFalse($disetujui->fresh()->isCancellable());
        $this->assertTrue($disetujui->fresh()->isEditable());
    }

    /** @test */
    public function order_dibatalkan_tidak_editable_dan_tidak_cancellable(): void
    {
        $order = $this->makeOrder($this->user('marketing'));
        $order->update(['status' => 'dibatalkan']);
        $order->delete();

        $trashed = Order::withTrashed()->find($order->id);
        $this->assertTrue($trashed->isCancelled());
        $this->assertFalse($trashed->isEditable());
        $this->assertFalse($trashed->isCancellable());
    }
}
