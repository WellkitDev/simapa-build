<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('tagihan_no', 50)->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->string('client_phone', 50)->nullable();
            $table->string('client_institution')->nullable();
            $table->string('title');
            $table->enum('type', ['buku', 'jurnal'])->default('buku');
            $table->text('author_names')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('due_at')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('diajukan');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_note')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('tb_orders')->nullOnDelete();
            $table->string('order_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tagihan');
    }
};
