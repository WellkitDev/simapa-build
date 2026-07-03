<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleArchiveEligibleTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(Title $book, int $cost, string $stage): Order
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => $stage, 'assigned_role' => TitleProgress::getHandlerForStatus($stage), 'started_at' => now()]);
        return $order;
    }

    private function book(string $stage = 'terbit'): Title
    {
        $b = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->orderFor($b, 100000, $stage);
        return $b->fresh();
    }

    /** @test */
    public function order_is_lunas_by_payment_or_invoice(): void
    {
        $book = $this->book();
        $order = $book->orderDetails->first()->order;
        $this->assertFalse($order->isLunas());

        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($order->fresh()->isLunas());

        $book2 = $this->book();
        $order2 = $book2->orderDetails->first()->order;
        Invoice::create(['order_id' => $order2->id, 'invoice_no' => 'INV-' . uniqid(), 'issued_at' => now(), 'status' => 'lunas']);
        $this->assertTrue($order2->fresh()->isLunas());
    }

    /** @test */
    public function archive_eligible_needs_paidoff_and_final(): void
    {
        $book = $this->book('terbit'); // final
        $order = $book->orderDetails->first()->order;
        $this->assertFalse($book->fresh()->archiveEligible()); // belum lunas

        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($book->fresh()->archiveEligible()); // lunas + final

        $notFinal = $this->book('editing');
        $o = $notFinal->orderDetails->first()->order;
        Payment::create(['order_id' => $o->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertFalse($notFinal->fresh()->archiveEligible()); // lunas tapi belum final
    }
}
