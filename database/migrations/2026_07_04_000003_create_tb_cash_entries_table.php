<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('kode')->nullable();
            $table->string('keterangan');
            $table->string('jenis'); // pemasukan | pengeluaran
            $table->decimal('amount', 15, 2);
            $table->foreignId('cash_category_id')->nullable()->constrained('tb_cash_categories')->nullOnDelete();
            $table->string('produk')->nullable(); // artikel | buku | operasional
            $table->string('ref')->nullable();
            $table->text('catatan')->nullable();
            $table->string('source')->default('manual'); // manual | payment
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tanggal', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_cash_entries'); }
};
