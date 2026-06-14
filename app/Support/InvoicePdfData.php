<?php

namespace App\Support;

use App\Models\Invoice;

class InvoicePdfData
{
    /**
     * Build the data array for the invoice PDF view, counting ONLY payments
     * whose approval status is 'approved'. Used by the download route and the
     * SendInvoiceJob so both stay consistent.
     *
     * @return array{invoice: Invoice, order: \App\Models\Order, detail: \App\Models\OrderDetail, totalCost: float|int, alreadyPaid: float|int, remainingBalance: float|int}
     */
    public static function for(Invoice $invoice): array
    {
        $invoice->load([
            'order.details.authors',
            'order.details.scopes',
            'order.contact',
            'order.payments' => fn ($q) =>
                $q->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
                  ->orderBy('paid_at', 'asc'),
        ]);

        $order  = $invoice->order;
        $detail = $order->details;

        $totalCost        = $detail->cost_amount ?? 0;
        $alreadyPaid      = $order->payments->sum('amount');
        $remainingBalance = $totalCost - $alreadyPaid;

        return compact('invoice', 'order', 'detail', 'totalCost', 'alreadyPaid', 'remainingBalance');
    }
}
