<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_journals', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('akreditasi', 64)->nullable();
            $table->foreignId('scope_id')->nullable()->constrained('tb_scopes')->nullOnDelete();
            $table->string('apc_reguler')->nullable();
            $table->string('apc_fastrack')->nullable();
            $table->string('link')->nullable();
            $table->string('kontak_wa')->nullable();
            $table->string('kontak_email')->nullable();
            $table->json('terbitan_bulan')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_journals');
    }
};
