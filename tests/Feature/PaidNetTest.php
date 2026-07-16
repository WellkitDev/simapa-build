<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengunci definisi "sudah dibayar" = BERSIH (pembayaran masuk - refund).
 * Beda dari Payment::income() (pelaporan, refund dikecualikan) — di sini
 * refund DIKURANGKAN, karena satu aturan harus benar untuk dua kasus:
 * pembatalan (belum lunas) dan kelebihan bayar (tetap lunas).
 */
class PaidNetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function order(int $cost = 10_000_000): Order
    {
        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul Uji',
            'slug' => 'j-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        return $order->fresh();
    }

    private function pay(Order $order, int $amount, string $type = 'dp'): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_type' => $type, 'amount' => $amount,
            'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /** @test */
    public function partial_refund_makes_order_not_lunas(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');

        $this->assertSame(6_000_000, $order->fresh()->paidNet());
        $this->assertFalse($order->fresh()->isLunas(), 'Uang dikembalikan 4jt → belum lunas.');
    }

    /** @test */
    public function full_refund_makes_order_not_lunas(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 10_000_000, 'refund');

        $this->assertSame(0, $order->fresh()->paidNet());
        $this->assertFalse($order->fresh()->isLunas(), 'Uang kembali semua → jelas belum lunas.');
    }

    /** @test */
    public function overpayment_refund_stays_lunas(): void
    {
        // Kasus kedua yang harus benar dengan aturan yang SAMA.
        $order = $this->order(10_000_000);
        $this->pay($order, 14_000_000);
        $this->pay($order, 4_000_000, 'refund');

        $this->assertSame(10_000_000, $order->fresh()->paidNet());
        $this->assertTrue($order->fresh()->isLunas(), 'Kelebihan bayar dikembalikan → tetap lunas.');
    }

    /** @test */
    public function no_refund_is_unaffected(): void
    {
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);

        $this->assertSame(10_000_000, $order->fresh()->paidNet());
        $this->assertTrue($order->fresh()->isLunas());
    }

    /** @test */
    public function lunas_invoice_shortcut_still_wins(): void
    {
        // Jalan pintas invoice 'lunas' sengaja DIPERTAHANKAN (di luar scope).
        $order = $this->order(10_000_000);
        $this->pay($order, 10_000_000);
        $this->pay($order, 4_000_000, 'refund');
        Invoice::create([
            'order_id' => $order->id, 'invoice_no' => 'INV-' . uniqid(),
            'status' => 'lunas', 'amount' => 10_000_000,
        ]);

        $this->assertTrue($order->fresh()->isLunas(), 'Invoice lunas tetap menang — perilaku lama dipertahankan.');
    }

    /** @test */
    public function sql_and_php_agree_on_paid_net(): void
    {
        // Dua versi definisi yang sama tak boleh berpisah diam-diam.
        $kombinasi = [
            [10_000_000, 0],           // tanpa refund
            [10_000_000, 4_000_000],   // refund sebagian
            [10_000_000, 10_000_000],  // refund penuh
            [14_000_000, 4_000_000],   // kelebihan bayar
        ];

        foreach ($kombinasi as [$bayar, $refund]) {
            $order = $this->order(10_000_000);
            $this->pay($order, $bayar);
            if ($refund > 0) {
                $this->pay($order, $refund, 'refund');
            }

            $php = $order->fresh()->paidNet();
            $sql = (int) DB::table('tb_orders')->where('id', $order->id)
                ->selectRaw(Order::PAID_NET_SQL . ' as net')->value('net');

            $this->assertSame($php, $sql, "SQL dan PHP harus sepakat (bayar $bayar, refund $refund).");
        }
    }

    /** @test */
    public function approved_scope_is_gone(): void
    {
        // Jebakan lama: nama "approved" mengundang pemakaian utk menjumlahkan uang.
        $this->expectException(\BadMethodCallException::class);
        Payment::query()->approved();
    }
}
