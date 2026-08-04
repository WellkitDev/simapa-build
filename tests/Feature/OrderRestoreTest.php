<?php

namespace Tests\Feature;

use App\Models\CashPeriodLock;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderRestoreTest extends TestCase
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

    private function makeOrder(User $owner, ?string $code = null): Order
    {
        $code ??= 'ORD-' . strtoupper(\Illuminate\Support\Str::random(8));

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

    private function addPayment(Order $order, string $type = 'dp', int $amount = 500000): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => $type,
            'amount'       => $amount,
            'status'       => 'paid',
            'paid_at'      => '2026-08-02',
        ]);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'pending']);

        Invoice::create([
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
            'invoice_no' => 'INV-' . $order->id . '-' . $payment->id,
            'issued_at'  => '2026-08-02',
            'due_at'     => '2026-08-09',
            'status'     => 'diterbitkan',
        ]);

        return $payment;
    }

    /** @test */
    public function pulihkan_mengembalikan_order_detail_progress_payment_dan_invoice(): void
    {
        $owner    = $this->user('marketing');
        $manager  = $this->user('manager');
        $order    = $this->makeOrder($owner);
        $payment  = $this->addPayment($order);
        $detailId = $order->details->id;
        $progId   = TitleProgress::where('order_detail_id', $detailId)->value('id');

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), 'salah', $owner);

        $service->restore(Order::withTrashed()->find($order->id), $manager);

        $restored = Order::find($order->id);
        $this->assertNotNull($restored);
        $this->assertSame('pending', $restored->status);
        $this->assertNull($restored->cancel_reason);
        $this->assertNull($restored->cancelled_by);
        $this->assertNull($restored->cancelled_at);

        $this->assertNotNull(OrderDetail::find($detailId));
        $this->assertNotNull(TitleProgress::find($progId));

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->approval->status);
        $this->assertSame('diterbitkan', Invoice::where('payment_id', $payment->id)->value('status'));
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);
    }

    /** @test */
    public function pulihkan_mengembalikan_status_lunas_bukan_pending(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $this->addPayment($order, 'lunas', 1000000);
        $order->update(['status' => 'lunas']); // seperti PaymentBookController::store()

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), null, $owner);
        $service->restore(Order::withTrashed()->find($order->id), $manager);

        $this->assertSame('lunas', Order::find($order->id)->status);
    }

    /** @test */
    public function pulihkan_ditolak_bila_order_tidak_dibatalkan(): void
    {
        $order = $this->makeOrder($this->user('marketing'));

        $this->expectException(\App\Exceptions\OrderCancellationException::class);
        app(OrderCancellationService::class)->restore($order, $this->user('manager'));
    }

    /** @test */
    public function pulihkan_ditolak_bila_periode_kas_terkunci(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order);

        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);

        // Periode dikunci SETELAH pembatalan: memulihkan akan membuat ulang entri kas di sana.
        CashPeriodLock::create([
            'year' => 2026, 'month' => 8,
            'locked_by' => $this->user('superadmin')->id, 'locked_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\OrderCancellationException::class);
        app(OrderCancellationService::class)->restore(Order::withTrashed()->find($order->id), $manager);
    }

    /** @test */
    public function tagihan_tidak_ditarik_kembali_saat_pulihkan(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);

        $tagihan = \App\Models\Tagihan::create([
            'tagihan_no'   => 'TGH-9001',
            'created_by'   => $owner->id,
            'client_name'  => 'Klien A',
            'client_email' => 'klien@example.com',
            'title'        => 'Judul Uji',
            'type'         => 'bk_mandiri',
            'amount'       => 1000000,
            'status'       => 'jadi_order',
            'order_id'     => $order->id,
            'order_code'   => $order->code_order,
        ]);

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), null, $owner);
        $service->restore(Order::withTrashed()->find($order->id), $manager);

        // SENGAJA tidak ditarik kembali: tagihan mungkin sudah dipakai order pengganti.
        $tagihan->refresh();
        $this->assertSame('disetujui', $tagihan->status);
        $this->assertNull($tagihan->order_id);
    }

    /** @test */
    public function batal_lalu_pulihkan_lalu_batal_lagi_tetap_konsisten(): void
    {
        $owner   = $this->user('marketing');
        $manager = $this->user('manager');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order);

        $service = app(OrderCancellationService::class);
        $service->cancel($order->fresh(), 'pertama', $owner);
        $service->restore(Order::withTrashed()->find($order->id), $manager);
        $service->cancel(Order::find($order->id), 'kedua', $owner);

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSame('kedua', Order::withTrashed()->find($order->id)->cancel_reason);
        $this->assertSame('batal', $payment->fresh()->status);
        $this->assertSame('dibatalkan', Invoice::where('payment_id', $payment->id)->value('status'));
        $this->assertDatabaseMissing('tb_cash_entries', ['payment_id' => $payment->id]);

        // Log invoice tidak boleh berisi transisi omong-kosong 'dibatalkan' → 'dibatalkan'.
        $this->assertDatabaseMissing('tb_invoice_logs', [
            'from_status' => 'dibatalkan',
            'to_status'   => 'dibatalkan',
        ]);
    }
}
