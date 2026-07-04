<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\CashEntry;
use App\Models\CashCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentCashSyncTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $type = 'at_kolab'): Order
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'type' => $type, 'title' => 'Judul Uji', 'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => 500000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        return $order;
    }

    private function pay(Order $order, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-05',
        ], $attrs));
    }

    /** @test */
    public function paid_payment_creates_income_entry(): void
    {
        $order = $this->order('at_kolab');
        $payment = $this->pay($order);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertNotNull($e);
        $this->assertSame('pemasukan', $e->jenis);
        $this->assertSame('artikel', $e->produk);
        $this->assertSame('500000.00', $e->amount);
        $this->assertSame('payment', $e->source);
        $this->assertSame($order->code_order, $e->ref);
        $this->assertSame('B626', $e->kode);
        $this->assertSame(CashCategory::where('map_key', 'at_kolab')->first()->id, $e->cash_category_id);
    }

    /** @test */
    public function refund_creates_expense_entry(): void
    {
        $order = $this->order('bk_mandiri');
        $payment = $this->pay($order, ['payment_type' => 'refund']);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertSame('pengeluaran', $e->jenis);
        $this->assertSame(CashCategory::where('map_key', 'refund')->first()->id, $e->cash_category_id);
    }

    /** @test */
    public function approve_then_update_keeps_single_entry(): void
    {
        $order = $this->order();
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 300000, 'status' => 'pending', 'paid_at' => '2026-06-05']);
        $this->assertSame(0, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['status' => 'paid']);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['amount' => 700000]);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());
        $this->assertSame('700000.00', CashEntry::where('payment_id', $payment->id)->first()->amount);
    }

    /** @test */
    public function rejected_or_deleted_removes_entry(): void
    {
        $order = $this->order();
        $payment = $this->pay($order);
        $this->assertSame(1, CashEntry::where('payment_id', $payment->id)->count());

        $payment->update(['status' => 'rejected']);
        $this->assertSame(0, CashEntry::where('payment_id', $payment->id)->count());

        $order2 = $this->order();
        $payment2 = $this->pay($order2);
        $this->assertSame(1, CashEntry::where('payment_id', $payment2->id)->count());
        $payment2->delete();
        $this->assertSame(0, CashEntry::where('payment_id', $payment2->id)->count());
    }

    /** @test */
    public function sync_is_null_safe_without_order_details(): void
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-ND-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => '2026-06-05']);

        $e = CashEntry::where('payment_id', $payment->id)->first();
        $this->assertNotNull($e);
        $this->assertNull($e->cash_category_id);
        $this->assertNull($e->produk);
        $this->assertSame('pemasukan', $e->jenis);
    }
}
