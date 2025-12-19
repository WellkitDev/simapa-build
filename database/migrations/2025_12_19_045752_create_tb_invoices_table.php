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

            $table->string('inv_no')->unique();
            $table->text('details')->nullable(); // JSON opsional

            $table->dateTime('issued_at');
            $table->dateTime('dued_at')->nullable();

            $table->string('inv_pdf_url')->nullable();
            $table->string('inv_pdf_id')->nullable();

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
