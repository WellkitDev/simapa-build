<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
            $table->date('paid_at');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('method', 20)->default('transfer');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoice_payments');
    }
};
