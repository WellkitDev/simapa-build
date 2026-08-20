<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Jobs\SendRefundJob;
use App\Services\Notifier;
use App\Support\RefundPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    private function findOrder(string $code): Order
    {
        return Order::with('details', 'contact', 'payments')->where('code_order', $code)->firstOrFail();
    }

    private function paidIn(Order $order): int
    {
        return (int) $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');
    }

    private function alreadyRefunded(Order $order): bool
    {
        return $order->payments->where('payment_type', 'refund')->isNotEmpty();
    }

    public function form(string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order = $this->findOrder($code);

        if ($this->alreadyRefunded($order)) {
            return back()->withErrors(['refund' => 'Order ini sudah pernah di-refund.']);
        }
        $paidIn = $this->paidIn($order);
        if ($paidIn <= 0) {
            return back()->withErrors(['refund' => 'Order belum memiliki pembayaran untuk di-refund.']);
        }

        return view('payments.refunds.refund_form', compact('order', 'paidIn'));
    }

    public function store(Request $request, string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order = $this->findOrder($code);

        if ($this->alreadyRefunded($order)) {
            return back()->withErrors(['refund' => 'Order ini sudah pernah di-refund.']);
        }
        $paidIn = $this->paidIn($order);

        $data = $request->validate([
            'amount'  => 'required|numeric|min:1|max:' . $paidIn,
            'reason'  => 'required|string',
            'method'  => 'required|in:transfer,tunai,lainnya',
            'account' => 'nullable|string|max:150',
            'tanggal' => 'required|date',
        ]);

        $payment = Payment::create([
            'order_id'       => $order->id,
            'payment_type'   => 'refund',
            'amount'         => $data['amount'],
            'status'         => 'paid',
            'paid_at'        => $data['tanggal'],
            'refund_reason'  => $data['reason'],
            'refund_method'  => $data['method'],
            'refund_account' => $data['account'] ?? null,
            'refunded_by'    => Auth::id(),
        ]);

        // Refund PENUH = penulisnya mundur: ordernya ditarik dari judul supaya tak lagi
        // menahan bottleneck naskah atau menuntut pelunasan penulis lain sejudul.
        // Refund sebagian sengaja tidak menarik apa pun.
        app(\App\Services\OrderWithdrawalService::class)
            ->withdraw($order, $payment, Auth::user());

        SendRefundJob::dispatch($payment->id);
        app(Notifier::class)->refundIssued($payment, Auth::user());

        return redirect()->route('order.book.index')->with('success', 'Refund diproses. Bukti refund dikirim ke customer.');
    }

    public function pdf(string $code)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $order  = $this->findOrder($code);
        $refund = $order->payments()->where('payment_type', 'refund')->latest('paid_at')->first();
        abort_if(! $refund, 404, 'Belum ada refund untuk order ini.');

        return Pdf::loadView('payments.refunds.refund_pdf', RefundPdfData::for($refund))
            ->stream('Refund_' . $order->code_order . '.pdf');
    }
}
