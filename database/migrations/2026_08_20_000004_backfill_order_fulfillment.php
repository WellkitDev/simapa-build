<?php
// database/migrations/2026_08_20_000004_backfill_order_fulfillment.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Isi fulfillment_status untuk order yang sudah ada.
 *
 * WAJIB memakai DB::statement()/DB::table(), BUKAN model Eloquent: Order, OrderDetail,
 * dan TitleProgress ketiganya memakai SoftDeletes, dan migrasi yang meng-query modelnya
 * pecah saat `migrate:fresh` — membuat seluruh suite merah dengan gejala yang
 * menyesatkan. Sudah terjadi tiga kali di repo ini.
 *
 * Urutan penilaian dari yang paling lemah ke yang paling menang, karena tiap langkah
 * menimpa langkah sebelumnya: selesai → ditarik → dibatalkan. Order yang naskahnya
 * terbit LALU di-refund tercatat `ditarik`; order yang dibatalkan menang atas keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Naskah yang sudah final → selesai. completed_at diambil dari jejak yang
        //    paling dekat dengan saat naskahnya benar-benar selesai.
        DB::statement("
            UPDATE tb_orders o
            JOIN tb_order_details d ON d.order_id = o.id
            JOIN tb_title_progress p ON p.order_detail_id = d.id
            SET o.fulfillment_status = 'selesai',
                o.completed_at = COALESCE(o.completed_at, p.archived_at, p.updated_at)
            WHERE p.status IN ('terbit', 'publish')
        ");

        // 2. Order yang pernah di-refund → ditarik (menang atas 'selesai').
        DB::statement("
            UPDATE tb_orders o
            JOIN tb_payments pay ON pay.order_id = o.id
            SET o.fulfillment_status = 'ditarik'
            WHERE pay.payment_type = 'refund' AND pay.status = 'paid'
        ");

        // 3. Order dibatalkan → dibatalkan (menang atas semuanya). `deleted_at` ikut
        //    diperiksa karena cancel() men-soft-delete ordernya juga.
        DB::statement("
            UPDATE tb_orders
            SET fulfillment_status = 'dibatalkan'
            WHERE status = 'dibatalkan' OR deleted_at IS NOT NULL
        ");

        // 4. Tandai withdrawn_at pada progress milik order yang ditarik, supaya
        //    scopeActive() dan notWithdrawn() langsung konsisten dengan kolom order.
        //    Order yang dibatalkan sudah keluar dari himpunan ini di langkah 3, dan
        //    progressnya memang sudah soft-deleted.
        DB::statement("
            UPDATE tb_title_progress p
            JOIN tb_order_details d ON d.id = p.order_detail_id
            JOIN tb_orders o ON o.id = d.order_id
            SET p.withdrawn_at = COALESCE(p.withdrawn_at, o.updated_at),
                p.withdrawn_reason = COALESCE(p.withdrawn_reason, 'Refund (backfill)')
            WHERE o.fulfillment_status = 'ditarik'
        ");
    }

    public function down(): void
    {
        DB::table('tb_orders')->update(['fulfillment_status' => 'berjalan', 'completed_at' => null]);
        DB::table('tb_title_progress')->update(['withdrawn_at' => null, 'withdrawn_reason' => null]);
    }
};
