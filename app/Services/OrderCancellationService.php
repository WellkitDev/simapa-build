<?php

namespace App\Services;

use App\Models\Order;
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
        if (! $order->isCancellable()) {
            throw new \DomainException(
                'Order ini tidak bisa dibatalkan karena pembayarannya sudah disetujui. Gunakan alur Refund.'
            );
        }

        DB::transaction(function () use ($order, $reason, $actor) {
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
        });
    }
}
