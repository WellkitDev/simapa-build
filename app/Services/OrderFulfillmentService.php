<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;

/**
 * Satu-satunya penulis `tb_orders.fulfillment_status` untuk perpindahan yang dipicu
 * NASKAH. Penarikan (refund) ditulis OrderWithdrawalService; pembatalan ditulis
 * OrderCancellationService.
 *
 * `tb_title_progress.status` ditulis di TIGA tempat, dan kail ini dipasang di
 * ketiganya supaya tak ada jalur perpindahan tahap yang lolos tanpa memperbarui
 * ordernya:
 *  - TitleProgressService::applyStatus()   — advance/correct/auto-advance/grup;
 *  - TitleProgressService::createForDetail() — progress baru mewarisi tahap grup,
 *    yang bisa saja sudah final;
 *  - ChapterManuscriptService::advanceBookToStage() — sinkron dari registrasi ISBN,
 *    jalur normal buku mencapai `terbit`.
 *
 * ChapterRollupService juga menulis status, tapi hanya di dalam wilayah bab
 * (menunggu_proses..editing) yang tak pernah final — jadi tak perlu dikaili.
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

        // Tanggal terbit yang sudah tercatat dipertahankan — sinkron ulang (grup maju
        // lagi, form ISBN disimpan dua kali) tidak boleh menggeser tanggalnya ke now().
        // `?? now()` juga yang menyembuhkan baris `selesai` bertanggal kosong.
        $this->apply($order, $final ? 'selesai' : 'berjalan', $final ? ($order->completed_at ?? now()) : null);
    }

    /**
     * Tulis hanya bila benar-benar berubah, supaya `updated_at` order tidak berisik.
     *
     * Sengaja fill()+isDirty(), bukan membandingkan `fulfillment_status` saja: baris
     * yang sudah `selesai` tapi `completed_at`-nya kosong (jalur ISBN sebelum dikaili,
     * dan backfill data lama) harus tetap bisa diperbaiki.
     */
    private function apply(Order $order, string $status, ?\DateTimeInterface $completedAt): void
    {
        $order->fill([
            'fulfillment_status' => $status,
            'completed_at'       => $completedAt,
        ]);

        // Dibatasi ke dua kolom itu saja: layanan ini menerima order milik pemanggil
        // lain, dan isDirty() polos akan ikut menyimpan perubahan yang belum tentu
        // siap disimpan.
        if ($order->isDirty(['fulfillment_status', 'completed_at'])) {
            $order->save();
        }
    }
}
