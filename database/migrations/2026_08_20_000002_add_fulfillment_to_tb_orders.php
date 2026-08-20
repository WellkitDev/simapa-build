<?php
// database/migrations/2026_08_20_000002_add_fulfillment_to_tb_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keadaan PEKERJAAN order, terpisah dari keadaan UANG di kolom `status`.
 *
 * Satu kolom `status` selama ini mencampur keduanya (pending/lunas = uang,
 * dibatalkan = keduanya) sehingga "naskahnya sudah terbit" tak punya tempat ditulis
 * dan `completed_at` tak pernah terisi. Memisahkannya membuat setiap query lama
 * `status = 'lunas'` tetap benar tanpa diaudit ulang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)
                  ->default('berjalan')
                  ->after('status')
                  ->index();
        });
    }

    public function down(): void
    {
        Schema::table('tb_orders', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn('fulfillment_status');
        });
    }
};
