<?php

namespace App\Support;

use App\Models\Payment;

class RefundPdfData
{
    /** Data bukti refund dari Payment refund (order-based) + riwayat pembayaran. */
    public static function for(Payment $refund): array
    {
        $refund->loadMissing('order.details', 'order.contact', 'order.payments');
        $order    = $refund->order;
        $detail   = $order?->details;
        $contact  = $order?->contact;
        $payments = $order ? $order->payments->sortBy('paid_at')->values() : collect();
        $paidIn   = (float) ($order ? $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount') : 0);

        return [
            'refund'       => $refund,
            'order'        => $order,
            'detail'       => $detail,
            'contact'      => $contact,
            'payments'     => $payments,
            'paidIn'       => $paidIn,
            'refundAmount' => (float) $refund->amount,
            'reason'       => $refund->refund_reason,
            'method'       => $refund->refund_method,
            'account'      => $refund->refund_account,
            'refunded_at'  => $refund->paid_at,
        ];
    }
}
