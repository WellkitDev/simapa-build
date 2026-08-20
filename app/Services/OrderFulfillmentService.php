<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;

/**
 * Satu-satunya penulis `tb_orders.fulfillment_status` untuk perpindahan yang dipicu
 * NASKAH. Penarikan (refund) ditulis OrderWithdrawalService; pembatalan ditulis
 * OrderCancellationService.
 *
 * Dikaitkan ke TitleProgressService::applyStatus() — satu-satunya tempat
 * tb_title_progress.status ditulis — sehingga tak ada jalur perpindahan tahap yang
 * bisa lolos tanpa memperbarui ordernya.
 */
class OrderFulfillmentService
{
    /**
     * Selaraskan order dengan tahap naskahnya.
     *
     * Order yang sudah `dibatalkan` atau `ditarik` TIDAK disentuh: keduanya keadaan
     * akhir yang ditetapkan manusia, dan naskah yang kebetulan masih bergerak (mis.
     * order lain sejudul memajukannya) tidak boleh menghidupkannya kembali.
     */
    public function syncFromProgress(TitleProgress $progress): void
    {
        $order = $progress->orderDetail?->order;
        if ($order === null) {
            return;
        }

        if (in_array($order->fulfillment_status, ['dibatalkan', 'ditarik'], true)) {
            return;
        }

        $final = TitleProgress::isFinal((string) $progress->status);

        $this->apply($order, $final ? 'selesai' : 'berjalan', $final ? now() : null);
    }

    /** Tulis hanya bila benar-benar berubah, supaya `updated_at` order tidak berisik. */
    private function apply(Order $order, string $status, $completedAt): void
    {
        if ($order->fulfillment_status === $status) {
            return;
        }

        $order->update([
            'fulfillment_status' => $status,
            'completed_at'       => $completedAt,
        ]);
    }
}
