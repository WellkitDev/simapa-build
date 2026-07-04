<?php

namespace App\Services;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Payment;
use Carbon\Carbon;

class PaymentCashSyncService
{
    /** Sinkron satu entri kas dari Payment (idempotent per payment_id). */
    public function sync(Payment $payment): void
    {
        if ($payment->status !== 'paid') {
            CashEntry::where('payment_id', $payment->id)->delete();
            return;
        }

        $order  = $payment->order;
        $detail = optional($order)->details;
        $type   = optional($detail)->type;
        $refund = $payment->payment_type === 'refund';

        $produk = null;
        if ($type) {
            $produk = str_starts_with($type, 'bk_') ? 'buku' : (str_starts_with($type, 'at_') ? 'artikel' : null);
        }
        $mapKey = $refund ? 'refund' : $type;
        $catId  = $mapKey ? optional(CashCategory::where('map_key', $mapKey)->first())->id : null;
        $ref    = optional($payment->invoice)->invoice_no ?: optional($order)->code_order;
        $tgl    = $payment->paid_at ?: now();
        $ket    = trim(ucfirst((string) $payment->payment_type) . ' — ' . ($ref ?: '-') . ' — ' . (optional($detail)->title ?? optional($order)->code_order ?? ''));

        CashEntry::updateOrCreate(
            ['payment_id' => $payment->id],
            [
                'tanggal'          => $tgl,
                'jenis'            => $refund ? 'pengeluaran' : 'pemasukan',
                'amount'           => $payment->amount,
                'cash_category_id' => $catId,
                'produk'           => $produk,
                'ref'              => $ref,
                'keterangan'       => $ket,
                'source'           => 'payment',
                'kode'             => app(CashJournalService::class)->deriveKode(Carbon::parse($tgl)),
            ]
        );
    }
}
