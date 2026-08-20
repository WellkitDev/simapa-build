<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;

/**
 * Satu-satunya penulis `tb_orders.fulfillment_status` untuk perpindahan yang dipicu
 * NASKAH. Penarikan (refund) ditulis OrderWithdrawalService; pembatalan ditulis
 * OrderCancellationService.
 *
 * INVARIAN yang harus dijaga: setiap penulis `tb_title_progress.status` lewat Eloquent
 * yang bisa mendarat di tahap FINAL wajib memanggil syncFromProgress(). Sengaja
 * dinyatakan sebagai invarian, bukan daftar penulis — daftar semacam itu sudah pernah
 * usang diam-diam, dan justru itu yang membuat jalur ISBN lolos menutup ordernya.
 *
 * Menambah penulis status baru? Tanyakan satu hal: bisakah ia mendarat di `terbit` atau
 * `publish`? Kalau ya, kail ini wajib ikut.
 *
 * DIKECUALIKAN: penulisan massal yang menembus Eloquent — `ImportV1Command` mengisi
 * tb_title_progress lewat DB::table()->insert(), termasuk baris yang sudah final.
 * Perintah semacam itu membangun ulang data seutuhnya, jadi `fulfillment_status`
 * diselaraskan lewat backfill tersendiri, bukan lewat kail per-baris ini.
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
        // lain, dan isDirty() polos akan ikut MEMICU penyimpanan atas perubahan yang
        // belum tentu siap disimpan. Cakupan ini menentukan KAPAN save() dipanggil,
        // bukan apa yang ditulis — save() tetap menyimpan seluruh atribut yang kotor.
        if ($order->isDirty(['fulfillment_status', 'completed_at'])) {
            $order->save();
        }
    }
}
