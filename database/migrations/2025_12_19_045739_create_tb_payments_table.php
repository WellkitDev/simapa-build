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

            $table->string('type'); // dp, pelunasan, refund
            $table->decimal('amount', 15, 0);
            $table->dateTime('date')->nullable();

            $table->string('struk_url')->nullable();
            $table->string('struk_id')->nullable();

            $table->string('status')->nullable(); // paid, pending

            $table->timestamps();
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
