<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `revisi` pindah ke belakang `submit`, jadi ARTINYA berubah: dulu "belum disubmit",
 * kini "sudah disubmit dan diminta diperbaiki". Baris yang duduk di sana harus dinilai
 * ulang, bukan dibiarkan — membiarkannya memajukan naskah satu tahap secara palsu.
 *
 * Aturannya dibaca dari riwayat: baris yang pernah punya jejak `submit` memang sudah
 * disubmit dan tetap di `revisi`; yang tak punya dikembalikan ke `editing`.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh dan gejalanya menyesatkan. Sudah terjadi tiga kali di repo ini.
 *
 * Di DB dev per 2026-08-22 tak ada satu pun baris berstatus `revisi`, jadi migrasi ini
 * tak berbuat apa-apa di sana. Ia tetap ditulis karena produksi belum diperiksa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kandidat = DB::table('tb_title_progress')
            ->where('status', 'revisi')
            ->pluck('id');

        if ($kandidat->isEmpty()) {
            return;
        }

        $pernahSubmit = DB::table('tb_title_progress_logs')
            ->whereIn('title_progress_id', $kandidat)
            ->where('to_value', 'submit')
            ->distinct()
            ->pluck('title_progress_id')
            ->all();

        $dimundurkan = $kandidat->reject(
            fn ($id) => in_array($id, $pernahSubmit, true)
        )->values();

        if ($dimundurkan->isEmpty()) {
            return;
        }

        DB::table('tb_title_progress')
            ->whereIn('id', $dimundurkan)
            ->update(['status' => 'editing', 'assigned_role' => 'admin']);

        // Perpindahan diam-diam tanpa jejak tak bisa ditelusuri enam bulan kemudian.
        DB::table('tb_title_progress_logs')->insert(
            $dimundurkan->map(fn ($id) => [
                'title_progress_id' => $id,
                'event'             => 'status_corrected',
                'from_value'        => 'revisi',
                'to_value'          => 'editing',
                'changed_by'        => null,
                'note'              => 'Migrasi 2026-08-22: revisi pindah ke belakang submit. '
                                       . 'Baris ini belum pernah disubmit, jadi dikembalikan ke Editing.',
                'is_correction'     => 1,
                'created_at'        => now(),
            ])->all()
        );
    }

    /**
     * Sengaja tanpa isi: baris yang dikembalikan ke `editing` tak bisa dibedakan lagi
     * dari baris yang memang selalu di `editing`, jadi memutar balik akan menebak.
     * Jejaknya ada di tb_title_progress_logs bila benar-benar perlu dipulihkan tangan.
     */
    public function down(): void
    {
        //
    }
};
