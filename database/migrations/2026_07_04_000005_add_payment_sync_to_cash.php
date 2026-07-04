<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->unique()->after('created_by')->constrained('tb_payments')->cascadeOnDelete();
        });
        Schema::table('tb_cash_categories', function (Blueprint $table) {
            $table->string('map_key')->nullable()->after('jenis');
        });

        $map = [
            'Order Artikel Kolaborasi' => 'at_kolab',
            'Order Artikel Mandiri'    => 'at_mandiri',
            'Order Buku Kolaborasi'    => 'bk_kolab',
            'Order Buku Mandiri'       => 'bk_mandiri',
        ];
        foreach ($map as $name => $key) {
            DB::table('tb_cash_categories')->where('name', $name)->update(['map_key' => $key]);
        }

        DB::table('tb_cash_categories')->insert([
            'jenis' => 'pengeluaran', 'name' => 'Refund', 'map_key' => 'refund',
            'active' => true, 'position' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
        Schema::table('tb_cash_categories', function (Blueprint $table) {
            $table->dropColumn('map_key');
        });
        DB::table('tb_cash_categories')->where('map_key', 'refund')->where('name', 'Refund')->delete();
    }
};
