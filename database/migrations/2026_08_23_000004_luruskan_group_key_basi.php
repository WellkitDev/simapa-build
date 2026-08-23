<?php
// database/migrations/2026_08_23_000004_luruskan_group_key_basi.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Meluruskan `group_key` yang tertinggal di judul lama.
 *
 * `group_key` adalah satu-satunya hal yang menyatukan beberapa order menjadi SATU kartu
 * di papan Pelacakan. `OrderDetail::booted()` menjaganya lewat hook `saving` — tapi hook
 * itu hanya berbunyi bila penyimpanannya lewat model, dan kedua controller order dulu
 * memakai `$order->details()->update([...])`: update query-builder yang menulis langsung
 * ke basis data dan melewati seluruh event Eloquent tanpa satu pun peringatan.
 *
 * Akibatnya, order yang judulnya diganti membawa `title_id` baru tapi `group_key` lama.
 * Bila judul barunya sudah dipakai order lain, satu buku pecah jadi dua kartu — persis
 * yang dilaporkan sebagai "judul sama muncul dua kali, satu mandiri dan lima dibuatkan".
 *
 * Di dump produksi 2026-08-23 ada SATU baris seperti itu: order_detail 124 ber-`title_id`
 * 95 yang masih memegang `group_key` 'title:66'.
 *
 * Sumbernya sudah ditutup di kedua controller; migrasi ini membereskan yang telanjur.
 * Baris ber-`title_id` kosong tidak disentuh — kunci grupnya memang diturunkan dari
 * tipe dan judul, bukan dari id.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh. Sudah terjadi tiga kali di repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $basi = DB::table('tb_order_details')
            ->whereNotNull('title_id')
            ->where(function ($q) {
                $q->whereNull('group_key')
                  ->orWhereRaw("group_key <> CONCAT('title:', title_id)");
            })
            ->get(['id', 'title_id', 'group_key']);

        foreach ($basi as $baris) {
            DB::table('tb_order_details')
                ->where('id', $baris->id)
                ->update(['group_key' => 'title:' . $baris->title_id]);
        }
    }

    /**
     * Sengaja tanpa isi: nilai lamanya justru yang salah, dan memulihkannya berarti
     * memecah kartunya lagi. Jejaknya ada di riwayat git bila benar-benar perlu dilihat.
     */
    public function down(): void
    {
        //
    }
};
