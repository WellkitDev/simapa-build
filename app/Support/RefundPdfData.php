<?php

namespace App\Support;

use App\Models\Invoice;

class RefundPdfData
{
    /** @return array{invoice:Invoice,order:?\App\Models\Order,detail:?\App\Models\OrderDetail,contact:mixed,payment:?\App\Models\Payment,amount:float,reason:?string,method:?string,account:?string,refunded_at:mixed} */
    public static function for(Invoice $invoice): array
    {
        $invoice->loadMissing('order.details', 'order.contact', 'refundPayment');
        $order   = $invoice->order;
        $detail  = $order?->details;
        $contact = $order?->contact;
        $payment = $invoice->refundPayment;

        return [
            'invoice'     => $invoice,
            'order'       => $order,
            'detail'      => $detail,
            'contact'     => $contact,
            'payment'     => $payment,
            'amount'      => (float) ($payment->amount ?? 0),
            'reason'      => $invoice->refund_reason,
            'method'      => $invoice->refund_method,
            'account'     => $invoice->refund_account,
            'refunded_at' => $invoice->refunded_at,
        ];
    }
}
