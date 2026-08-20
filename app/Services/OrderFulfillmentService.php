<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TitleProgress;
use Illuminate\Support\Facades\DB;

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
     * Selaraskan SELURUH order sekaligus, lewat SQL mentah.
     *
     * Dipakai dua jalur masuk yang tak bisa memakai hook per-baris:
     *  - migrasi backfill data lama (2026_08_20_000004), yang tak boleh menyentuh
     *    Eloquent sama sekali — Order/OrderDetail/TitleProgress semuanya SoftDeletes,
     *    dan migrasi yang meng-query modelnya pecah saat `migrate:fresh`;
     *  - `simapa:import-v1`, yang menulis tb_title_progress lewat insert massal sehingga
     *    syncFromProgress() tak pernah terpanggil.
     *
     * Urutan langkah dari yang paling lemah ke yang paling menang, karena tiap langkah
     * menimpa sebelumnya: selesai → ditarik → dibatalkan. Order yang naskahnya terbit
     * LALU di-refund berakhir `ditarik`; order yang dibatalkan menang atas keduanya.
     *
     * @return array{selesai:int, ditarik:int, dibatalkan:int} jumlah baris tiap keadaan sesudahnya
     */
    public static function reconcileAll(): array
    {
        DB::statement("
            UPDATE tb_orders o
            JOIN tb_order_details d ON d.order_id = o.id
            JOIN tb_title_progress p ON p.order_detail_id = d.id
            SET o.fulfillment_status = 'selesai',
                o.completed_at = COALESCE(o.completed_at, p.archived_at, p.updated_at)
            WHERE p.status IN ('terbit', 'publish')
        ");

        DB::statement("
            UPDATE tb_orders o
            JOIN tb_payments pay ON pay.order_id = o.id
            SET o.fulfillment_status = 'ditarik'
            WHERE pay.payment_type = 'refund' AND pay.status = 'paid'
        ");

        // `deleted_at` ikut diperiksa: cancel() men-soft-delete ordernya juga.
        DB::statement("
            UPDATE tb_orders
            SET fulfillment_status = 'dibatalkan'
            WHERE status = 'dibatalkan' OR deleted_at IS NOT NULL
        ");

        // Tandai progress milik order yang ditarik supaya scopeActive() dan
        // notWithdrawn() langsung sepakat dengan kolom order. Order yang dibatalkan
        // sudah keluar dari himpunan ini di langkah sebelumnya, dan progressnya memang
        // sudah soft-deleted.
        DB::statement("
            UPDATE tb_title_progress p
            JOIN tb_order_details d ON d.id = p.order_detail_id
            JOIN tb_orders o ON o.id = d.order_id
            SET p.withdrawn_at = COALESCE(p.withdrawn_at, o.updated_at),
                p.withdrawn_reason = COALESCE(p.withdrawn_reason, 'Refund (backfill)')
            WHERE o.fulfillment_status = 'ditarik'
        ");

        $hitung = fn (string $s) => (int) DB::table('tb_orders')->where('fulfillment_status', $s)->count();

        return [
            'selesai'    => $hitung('selesai'),
            'ditarik'    => $hitung('ditarik'),
            'dibatalkan' => $hitung('dibatalkan'),
        ];
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
