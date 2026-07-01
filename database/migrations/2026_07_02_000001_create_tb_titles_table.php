<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_titles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('jenis', 16);              // artikel | buku
            $table->string('indeksasi', 64)->nullable();
            $table->string('tipe_naskah', 16);        // mandiri | kolaborasi
            $table->string('status', 16)->default('draft'); // draft|menunggu|disetujui|ditolak
            $table->string('asal', 16)->default('distribusi'); // distribusi|order (Fase 2)
            $table->string('slug')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_titles');
    }
};
