<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tb_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('tb_orders')
                ->cascadeOnDelete();

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('tb_payments')
                ->nullOnDelete();

            $table->string('invoice_no')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->string('pdf_url')->nullable();
            $table->string('pdf_drive_id')->nullable();
            $table->string('status')->default('issued');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_invoices');
    }
};
