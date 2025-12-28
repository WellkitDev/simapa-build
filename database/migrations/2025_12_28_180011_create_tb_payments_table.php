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
        Schema::create('tb_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('tb_orders')
                ->cascadeOnDelete();

            $table->string('payment_type'); // dp, pelunasan, refund
            $table->decimal('amount', 15, 0);
            $table->timestamp('paid_at')->nullable();
            $table->string('proof_url')->nullable();
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['order_id', 'payment_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_payments');
    }
};
