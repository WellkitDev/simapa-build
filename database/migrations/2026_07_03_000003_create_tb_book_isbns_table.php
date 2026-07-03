<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_book_isbns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
            $table->string('status')->default('pendaftaran'); // pendaftaran | ber_isbn | cetak
            $table->string('no_pendaftaran')->nullable();
            $table->string('no_isbn')->nullable();
            $table->string('no_buku_cetak')->nullable();
            $table->string('penerbit')->nullable();
            $table->date('tgl_daftar')->nullable();
            $table->date('tgl_isbn')->nullable();
            $table->date('tgl_terbit')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_book_isbns');
    }
};
