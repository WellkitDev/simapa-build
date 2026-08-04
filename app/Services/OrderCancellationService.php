<?php

namespace App\Services;

use App\Exceptions\OrderCancellationException;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\Tagihan;
use App\Models\TagihanLog;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pembatalan & pemulihan order.
 *
 * Semua penghapusan bersifat SOFT: nomor ORD-xxxx tidak pernah dipakai ulang, dan
 * soft delete berjenjang (order → detail → progress) membuat order yang dibatalkan
 * hilang sendirinya dari papan manuskrip, distribusi, dan dashboard produksi lewat
 * global scope Eloquent — tanpa satu pun call site OrderDetail::/TitleProgress::
 * yang tersebar di 12+ tempat perlu disentuh (spec §0.3).
 */
class OrderCancellationService
{
    public function cancel(Order $order, ?string $reason, User $actor): void
    {
        if ($order->hasRefund()) {
            throw OrderCancellationException::alreadyRefunded();
        }

        if (! $order->isCancellable()) {
            throw OrderCancellationException::notCancellable();
        }

        DB::transaction(function () use ($order, $reason, $actor) {
            $this->cancelPayments($order, $actor);
            $this->cancelInvoices($order, $actor);

            $detailIds = $order->details()->pluck('id');

            TitleProgress::whereIn('order_detail_id', $detailIds)->delete();
            $order->details()->delete();

            $order->update([
                'status'        => 'dibatalkan',
                'cancel_reason' => $reason,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
            ]);
            $order->delete();

            $this->releaseTagihan($order, $actor);
        });
    }

    /**
     * Semua payment order → 'batal', approval-nya → 'rejected'.
     * PaymentObserver::saved() otomatis menghapus CashEntry-nya, karena
     * PaymentCashSyncService::sync() membuang entri untuk payment ber-status != 'paid'.
     *
     * Tidak menyaring payment_type: order yang sudah di-refund sudah ditolak lebih
     * dulu oleh isCancellable() (lihat Order::hasRefund()). Kalau gerbang itu suatu
     * saat dilonggarkan, penyaringan refund HARUS ditambahkan di sini.
     */
    private function cancelPayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->with('approval')->get() as $payment) {
            $payment->update(['status' => 'batal']);

            if ($payment->approval) {
                $payment->approval->update([
                    'status'      => 'rejected',
                    'note'        => 'Order dibatalkan',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
            }
        }
    }

    /**
     * Invoice order → 'dibatalkan' (kosakata Invoice::STATUSES; 'batal' di rancangan
     * awal bukan status yang dikenal model — lihat catatan penyimpangan di rencana).
     */
    private function cancelInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->get() as $invoice) {
            $from = $invoice->status;

            $invoice->update([
                'status'       => 'dibatalkan',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $from,
                'to_status'   => 'dibatalkan',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan.',
            ]);
        }
    }

    /**
     * Tagihan yang sudah "jadi_order" dikembalikan ke 'disetujui'. Tanpa ini, tagihan
     * yang sudah disetujui ikut mati bersama order dan tidak bisa dipakai membuat
     * order pengganti.
     */
    private function releaseTagihan(Order $order, User $actor): void
    {
        $tagihans = Tagihan::where('order_id', $order->id)->where('status', 'jadi_order')->get();

        foreach ($tagihans as $tagihan) {
            $tagihan->update([
                'status'     => 'disetujui',
                'order_id'   => null,
                'order_code' => null,
            ]);

            TagihanLog::create([
                'tagihan_id'  => $tagihan->id,
                'from_status' => 'jadi_order',
                'to_status'   => 'disetujui',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan; tagihan bisa dipakai lagi.',
            ]);
        }
    }
}
