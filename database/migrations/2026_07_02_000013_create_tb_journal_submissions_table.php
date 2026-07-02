<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_journal_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('tb_journals')->cascadeOnDelete();
            $table->foreignId('title_id')->nullable()->constrained('tb_titles')->nullOnDelete();
            $table->date('tgl_submit')->nullable();
            $table->date('tgl_terbit')->nullable();
            $table->string('ojs_akun')->nullable();
            $table->text('ojs_password')->nullable();
            $table->string('loa_url')->nullable();
            $table->string('bukti_bayar_url')->nullable();
            $table->string('link_publish')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_journal_submissions');
    }
};
