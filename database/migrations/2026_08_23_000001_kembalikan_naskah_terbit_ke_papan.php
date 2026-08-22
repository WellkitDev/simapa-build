<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mengembalikan naskah terbit yang arsipnya BELUM disetujui ke papan Pelacakan.
 *
 * `archived_at` dulu disetel begitu tahap jadi final, padahal namanya menjanjikan
 * "sudah diarsipkan". Akibatnya naskah lenyap dari papan pada detik ia terbit — sebelum
 * ada yang mengajukan arsipnya, apalagi menyetujuinya.
 *
 * Keadaan produksi 2026-08-22: 24 naskah ber-`archived_at`, `tb_title_archives` KOSONG.
 * Modul arsipnya tak pernah dipakai sama sekali, karena naskahnya sudah hilang lebih
 * dulu dari tempat orang bekerja.
 *
 * Yang arsipnya benar-benar sudah `disetujui` DIBIARKAN terarsip — mereka memang sudah
 * selesai, dan menariknya kembali ke papan justru membingungkan.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh. Sudah terjadi tiga kali di repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sudahDisetujui = DB::table('tb_title_archives')
            ->where('status', 'disetujui')
            ->pluck('title_id');

        $dikembalikan = DB::table('tb_title_progress as tp')
            ->join('tb_order_details as od', 'od.id', '=', 'tp.order_detail_id')
            ->whereNotNull('tp.archived_at')
            ->whereNull('tp.cancelled_at')
            ->whereNull('tp.withdrawn_at')
            ->when(
                $sudahDisetujui->isNotEmpty(),
                fn ($q) => $q->whereNotIn('od.title_id', $sudahDisetujui)
            )
            ->pluck('tp.id');

        if ($dikembalikan->isEmpty()) {
            return;
        }

        DB::table('tb_title_progress')
            ->whereIn('id', $dikembalikan)
            ->update(['archived_at' => null]);

        // Perpindahan diam-diam tanpa jejak tak bisa ditelusuri enam bulan kemudian.
        DB::table('tb_title_progress_logs')->insert(
            $dikembalikan->map(fn ($id) => [
                'title_progress_id' => $id,
                'event'             => 'diarsipkan',
                'from_value'        => 'Arsip',
                'to_value'          => 'Papan Pelacakan',
                'changed_by'        => null,
                'note'              => 'Migrasi 2026-08-23: arsipnya belum disetujui, '
                                       . 'jadi naskahnya dikembalikan ke papan untuk diurus.',
                'is_correction'     => 0,
                'created_at'        => now(),
            ])->all()
        );
    }

    /**
     * Sengaja tanpa isi: sesudah dikembalikan, naskah ini tak bisa dibedakan lagi dari
     * naskah terbit lain yang arsipnya memang belum diajukan. Memutar balik akan menebak.
     */
    public function down(): void
    {
        //
    }
};
