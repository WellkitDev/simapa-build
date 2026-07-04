<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('jenis'); // pemasukan | pengeluaran
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        $seed = [
            ['pemasukan', 'Order Artikel Kolaborasi', 1],
            ['pemasukan', 'Order Artikel Mandiri', 2],
            ['pemasukan', 'Order Buku Kolaborasi', 3],
            ['pemasukan', 'Order Buku Mandiri', 4],
            ['pengeluaran', 'Biaya APC Jurnal', 1],
            ['pengeluaran', 'Fee Tim/Freelancer', 2],
            ['pengeluaran', 'Operational', 3],
            ['pengeluaran', 'PPn Bank', 4],
            ['pengeluaran', 'Saving', 5],
            ['pengeluaran', 'Dana Tak Terduga', 6],
        ];
        $rows = [];
        foreach ($seed as [$jenis, $name, $pos]) {
            $rows[] = ['jenis' => $jenis, 'name' => $name, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tb_cash_categories')->insert($rows);
    }

    public function down(): void { Schema::dropIfExists('tb_cash_categories'); }
};
