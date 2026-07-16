<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_logs', function (Blueprint $table) {
            $table->id();
            // SENGAJA tanpa foreign key: audit log harus hidup lebih lama dari
            // baris yang dicatatnya. FK cascade akan menghapus bukti bersama
            // barang buktinya; nullOnDelete membuang tautannya.
            $table->unsignedBigInteger('cash_entry_id')->nullable();
            $table->string('action', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('cash_entry_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_logs');
    }
};
